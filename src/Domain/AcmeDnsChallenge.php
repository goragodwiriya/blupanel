<?php

declare(strict_types=1);

namespace Phpcp\Domain;

use Phpcp\Agent\ExecutionFailed;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Driver\Dns\BindZoneManager;
use Phpcp\Kernel\Config;
use Phpcp\Kernel\Db;
use Phpcp\Support\BinaryPath;

/**
 * เพิ่ม/ลบเรกคอร์ด `_acme-challenge` สำหรับการพิสูจน์แบบ DNS-01 — PLAN-V2 เฟส E7
 *
 * ใช้กลไกเดิมทั้งหมด: เขียนแถวลง `dns_records` แล้วให้ {@see BindZoneManager} สร้าง
 * zone file ใหม่และสั่ง `rndc reload` — ไม่มีเส้นทางพิเศษที่ข้ามการตรวจ `named-checkzone`
 *
 * **หัวใจอยู่ที่การรอ ไม่ใช่การเขียน** — certbot ถือว่า hook ที่จบแล้วแปลว่าเรกคอร์ด
 * พร้อมใช้ แล้วบอก Let's Encrypt ให้มาถามทันที · ถ้าคืนค่าก่อนที่ BIND9 จะเสิร์ฟ
 * เรกคอร์ดใหม่จริง จะได้ error "DNS problem: NXDOMAIN looking up TXT" ซึ่งชี้ไปที่
 * การตั้งค่าที่ผิด ทั้งที่จริง ๆ แค่ถามเร็วไปหนึ่งวินาที
 *
 * **ข้อจำกัดที่ต้องรู้:** ตรวจกับ nameserver ในเครื่องเท่านั้น (`@127.0.0.1`) ไม่ได้ตรวจว่า
 * โลกภายนอกเห็นแล้วหรือยัง · ถ้าโดเมนนี้มี secondary NS ที่ยังไม่ได้ transfer หรือ
 * resolver ของ Let's Encrypt แคชคำตอบ NXDOMAIN ไว้ก่อนหน้า การขอใบอาจยังล้มได้
 * — ค่า TTL ที่ตั้งไว้ต่ำ ({@see TTL}) ช่วยลดโอกาสนั้นแต่ไม่ได้กันทั้งหมด
 */
final class AcmeDnsChallenge
{
    /** ชื่อเรกคอร์ดตามมาตรฐาน ACME — ต้องเป็นชื่อนี้เท่านั้น */
    public const RECORD_NAME = '_acme-challenge';

    /**
     * TTL ต่ำสุดที่สมเหตุสมผล — เรกคอร์ดนี้มีอายุแค่ไม่กี่นาที
     *
     * ตั้งต่ำเพราะถ้า resolver ของ Let's Encrypt เคยถามชื่อนี้แล้วได้ NXDOMAIN
     * มันจะแคชคำตอบนั้นไว้ตาม negative TTL ของ SOA · ค่าต่ำทำให้รอบถัดไปถามใหม่เร็วขึ้น
     */
    public const TTL = 60;

    /** รอไม่เกินเท่านี้ให้ BIND9 เสิร์ฟเรกคอร์ดใหม่ — เกินกว่านี้แปลว่ามีอะไรผิดจริง */
    private const MAX_WAIT = 60;

    /** ถามซ้ำทุกกี่วินาที */
    private const POLL_INTERVAL = 2;

    /** @var list<string> */
    private const DIG_PATHS = ['/usr/bin/dig', '/usr/sbin/dig'];

    public function __construct(
        private readonly Db $db,
        private readonly Config $config,
        private readonly Executor $executor,
    ) {
    }

    /**
     * เพิ่มเรกคอร์ดแล้วรอจนถามเจอจริง
     *
     * @return array{domain:string,serial:int,waited:int}
     */
    public function publish(string $domain, string $validation): array
    {
        $row = $this->requireZone($domain);

        if ($validation === '' || preg_match('/^[A-Za-z0-9_-]{20,128}$/', $validation) !== 1) {
            // ค่านี้มาจาก certbot ไม่ใช่จากผู้ใช้ แต่มันจะถูกเขียนลง zone file
            // ที่ BIND9 อ่าน — ค่าที่ผิดรูปแบบต้องไม่มีทางไปถึงไฟล์นั้น
            throw new ValidationError('ค่า CERTBOT_VALIDATION ผิดรูปแบบ');
        }

        // ลบของเก่าก่อนเสมอ — certbot เรียก auth สองครั้งเมื่อขอทั้ง example.com
        // และ *.example.com ในใบเดียว · **ครั้งที่สองต้องไม่ทับครั้งแรก** เพราะ
        // Let's Encrypt ตรวจทั้งสองค่าที่ชื่อเดียวกัน · จึงลบเฉพาะค่าที่ซ้ำกันเป๊ะ
        $this->db->run(
            'DELETE FROM dns_records WHERE domain_id = :id AND type = :t AND name = :n AND value = :v',
            ['id' => (int) $row['id'], 't' => 'TXT', 'n' => self::RECORD_NAME, 'v' => $validation],
        );

        $this->db->insert('dns_records', [
            'domain_id' => (int) $row['id'],
            'type' => 'TXT',
            'name' => self::RECORD_NAME,
            'value' => $validation,
            'ttl' => self::TTL,
            'priority' => null,
        ]);

        $result = $this->zoneManager()->writeZone($this->reload($row));

        if (($result['pushed'] ?? false) !== true) {
            throw new ExecutionFailed(
                'ส่ง zone ไปยัง BIND9 ไม่สำเร็จ: ' . (string) ($result['message'] ?? 'ไม่ทราบสาเหตุ'),
            );
        }

        return [
            'domain' => (string) $row['domain'],
            'serial' => (int) ($result['serial'] ?? 0),
            'waited' => $this->waitUntilVisible((string) $row['domain'], $validation),
        ];
    }

    /**
     * ลบเรกคอร์ดที่ใช้พิสูจน์ออกให้หมด
     *
     * ลบ**ทุกค่า**ของชื่อนี้ ไม่ใช่เฉพาะค่าล่าสุด เพราะการขอใบที่มีทั้งโดเมนหลัก
     * และ wildcard ทิ้งไว้สองแถว และ certbot เรียก cleanup แยกกันสองครั้ง
     * ซึ่งครั้งแรกควรกวาดให้หมดเลย — เรกคอร์ดนี้ไม่มีความหมายอื่นนอกจากการพิสูจน์
     *
     * @return array{domain:string,removed:int}
     */
    public function cleanup(string $domain): array
    {
        $row = $this->findZone($domain);

        if ($row === null) {
            // ไม่มี zone แล้ว = ไม่มีอะไรให้ลบ · cleanup ต้องไม่ล้มเหลวเพราะเรื่องนี้
            // ไม่งั้น certbot จะรายงานว่าการขอใบล้มเหลวทั้งที่ใบออกมาแล้ว
            return ['domain' => $domain, 'removed' => 0];
        }

        $removed = $this->db->run(
            'DELETE FROM dns_records WHERE domain_id = :id AND type = :t AND name = :n',
            ['id' => (int) $row['id'], 't' => 'TXT', 'n' => self::RECORD_NAME],
        )->rowCount();

        if ($removed > 0) {
            $this->zoneManager()->writeZone($this->reload($row));
        }

        return ['domain' => (string) $row['domain'], 'removed' => $removed];
    }

    /**
     * ถาม nameserver ในเครื่องซ้ำ ๆ จนกว่าจะเห็นค่าที่เพิ่งเขียน
     *
     * @return int วินาทีที่รอไปทั้งหมด
     */
    private function waitUntilVisible(string $domain, string $validation): int
    {
        $dig = BinaryPath::resolve($this->executor, self::DIG_PATHS, 'dnsutils');
        $name = self::RECORD_NAME . '.' . $domain;
        $waited = 0;

        while ($waited < self::MAX_WAIT) {
            $result = $this->executor->exec(
                [$dig, '@127.0.0.1', '+short', '+time=2', '+tries=1', 'TXT', $name],
                timeout: 10,
            );

            // dig คืนค่า TXT พร้อมเครื่องหมายคำพูด — ตัดออกก่อนเทียบ
            if (str_contains(str_replace('"', '', $result->output()), $validation)) {
                return $waited;
            }

            sleep(self::POLL_INTERVAL);
            $waited += self::POLL_INTERVAL;
        }

        throw new ExecutionFailed(sprintf(
            "BIND9 ยังไม่เสิร์ฟเรกคอร์ด %s หลังรอ %d วินาที\n\n"
            . "ตรวจด้วย: dig @127.0.0.1 TXT %s\n"
            . 'ถ้าไม่มีคำตอบ แปลว่า zone ถูกเขียนแล้วแต่ BIND9 ยังไม่ได้โหลด — ดู `rndc status`',
            $name,
            self::MAX_WAIT,
            $name,
        ));
    }

    /**
     * หาแถวโดเมนที่เป็นเจ้าของ zone ของชื่อนี้
     *
     * certbot ส่งชื่อที่กำลังพิสูจน์มา ซึ่งอาจเป็น subdomain ที่ไม่มี zone ของตัวเอง
     * (เช่น `blog.example.com` อยู่ใน zone ของ `example.com`) · ต้องไล่หาขึ้นไป
     * ทีละระดับจนเจอโดเมนที่มี zone จริง ไม่งั้นจะเขียนเรกคอร์ดผิดที่
     *
     * @return array<string,mixed>|null
     */
    private function findZone(string $domain): ?array
    {
        $candidate = ltrim($domain, '*.');

        while (str_contains($candidate, '.')) {
            $row = $this->db->first(
                'SELECT * FROM domains WHERE domain = :d AND zone_serial > 0',
                ['d' => $candidate],
            );

            if ($row !== null) {
                return $row;
            }

            $candidate = substr($candidate, (int) strpos($candidate, '.') + 1);
        }

        return null;
    }

    /** @return array<string,mixed> */
    private function requireZone(string $domain): array
    {
        if (!$this->config->dnsEnabled()) {
            throw new ValidationError(
                "ใบรับรอง wildcard ต้องพิสูจน์ผ่าน DNS ซึ่งต้องเปิด `dns.enabled` ก่อน\n\n"
                . 'ตั้งค่าใน /etc/phpcp/config.php แล้วสั่ง `phpcp dns:sync` เพื่อส่ง zone ที่มีอยู่ออกไปให้ครบ',
            );
        }

        $row = $this->findZone($domain);

        if ($row === null) {
            throw new ValidationError(sprintf(
                "ไม่พบ zone ของ %s บนเครื่องนี้ — ใบรับรอง wildcard ต้องให้ BIND9 ในเครื่อง"
                . " เป็นเจ้าของโซนของโดเมนนั้น\n\n"
                . 'เพิ่มเรกคอร์ด DNS ของโดเมนนี้ใน panel แล้วกด "ส่งไปยัง DNS" ก่อน',
                $domain,
            ));
        }

        return $row;
    }

    /**
     * โหลดแถวโดเมนใหม่จากฐานข้อมูลก่อนส่งให้ BindZoneManager
     *
     * จำเป็นเพราะ `writeZone()` อ่าน `zone_serial` จากแถวที่ได้รับ ไม่ใช่จากฐานข้อมูล —
     * แถวที่ค้างในตัวแปรตั้งแต่ก่อนการเขียนครั้งก่อนจะมี serial เก่า แล้ว serial ใหม่
     * จะไม่เพิ่มขึ้นจริง ซึ่งทำให้ secondary NS ไม่รู้ว่ามีการเปลี่ยนแปลง
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function reload(array $row): array
    {
        return $this->db->first('SELECT * FROM domains WHERE id = :id', ['id' => (int) $row['id']]) ?? $row;
    }

    private function zoneManager(): BindZoneManager
    {
        return new BindZoneManager($this->executor, $this->config, $this->db);
    }
}
