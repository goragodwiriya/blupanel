<?php

declare(strict_types=1);

namespace Phpcp\Driver\Dns;

use Phpcp\Agent\ExecutionFailed;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\DnsRecord;
use Phpcp\Driver\ConfigTransaction;
use Phpcp\Kernel\Config;
use Phpcp\Kernel\Db;
use Phpcp\Support\BinaryPath;

/**
 * เขียน zone file ของโดเมนแล้วสั่ง BIND9 โหลดใหม่จริง — PLAN-V2 เฟส E3
 *
 * **ทำไมเพิ่งเชื่อมตอนนี้:** `dns_records` มีมาตั้งแต่ migration แรก แต่เดิมเป็นแค่ "ค่าที่
 * ตั้งใจให้เป็น" ที่ส่งออกเป็นข้อความให้ผู้ใช้ไปวางที่ DNS provider ภายนอกเอง
 * ({@see DnsRecord::toZoneFile()}) — `install.sh` ติดตั้งแพ็กเกจ `bind9` ให้แต่ไม่มีโค้ด
 * จุดไหนเขียนไฟล์หรือสั่ง reload เลย ผู้ดูแลเข้าใจผิดได้ง่ายว่า "มี DNS server ใช้งานได้แล้ว"
 * ทั้งที่ยังไม่มี
 *
 * **ปิดไว้เป็นค่าเริ่มต้นเสมอ (`dns.enabled = false`)** — เหตุผลเดียวกับ
 * `Config::sharedOwner()`: เป็นการตัดสินใจเชิงโครงสร้างพื้นฐานที่ต้องเปิดเองหลังตรวจสอบ
 * ด้วยมือว่าเครื่องนี้พร้อมจริง ทุก endpoint ที่เรียกคลาสนี้ต้องบอกชัดเจนเมื่อปิดอยู่
 * ไม่ใช่ทำเหมือนสำเร็จแล้วเงียบ ๆ (ARCHITECTURE §10, กฎ fail-closed แบบ `sites.shared_owner`)
 *
 * **ลำดับการตรวจก่อนบังคับใช้จริงเสมอ (ผ่าน {@see ConfigTransaction}):**
 *   1. เขียนไฟล์ zone (และ `named.conf.local` ทั้งไฟล์ถ้าเป็นโดเมนที่ยังไม่เคยมี zone)
 *   2. `named-checkzone` ตรวจไฟล์ zone
 *   3. ถ้าเป็นโดเมนใหม่ `named-checkconf` ตรวจทั้งชุด (จับ stanza ซ้ำ/ผิดไวยากรณ์ข้ามไฟล์)
 *   4. ไม่ผ่านข้อใดข้อหนึ่ง → คืนไฟล์เดิมทั้งหมดอัตโนมัติ ไม่ reload ไม่ทำลาย zone เดิม
 *   5. ผ่านครบ → `rndc reload` แล้วบันทึก serial ใหม่ลงฐานข้อมูล
 *
 * **`named.conf.local` เป็นไฟล์ที่ phpcp จัดการทั้งไฟล์** ไม่ใช่แค่เติม stanza — เขียนทับ
 * ใหม่ทั้งไฟล์จากรายชื่อโดเมนที่มี `zone_serial > 0` ในฐานข้อมูลทุกครั้ง (แบบเดียวกับที่
 * vhost/FPM pool ถูก derive จากฐานข้อมูลเสมอ ไม่ใช่ patch ทีละจุด) — **ถ้าเครื่องนี้เคยมี
 * zone ที่ตั้งเองด้วยมือมาก่อนเปิด `dns.enabled` zone เหล่านั้นจะหายไปจาก
 * `named.conf.local`** ต้องเตือนผู้ดูแลเรื่องนี้ก่อนเปิดใช้งานเสมอ
 *
 * ## ไฟล์ส่วนเสริมของผู้ดูแล — ทำไมของ BIND ต้องระวังกว่าบริการอื่น
 *
 * บริการอื่นในโปรเจกต์นี้ผูกไฟล์ของผู้ดูแลด้วย include ที่ทนไฟล์หาย (`IncludeOptional`
 * ของ Apache, `!include_try` ของ Dovecot) หรือไม่ใช้ include เลย (Postfix ผนวกเนื้อไฟล์
 * ลงท้าย `main.cf`) · **BIND ไม่มีทางเลือกทั้งสองแบบ** — `include` ของมันเข้มงวด ไฟล์หาย
 * แปลว่า `parsing failed: file not found` แล้ว named ไม่สตาร์ต (ทดสอบกับ named-checkconf
 * 9.18 แล้ว ยืนยันว่าไม่มี `include_try`)
 *
 * ที่นี่จึงทำสองอย่างที่บริการอื่นไม่ต้องทำ:
 *
 *   1. **บรรทัด include เป็นค่าที่ derive จากการมีอยู่ของไฟล์** ไม่ใช่บรรทัดตายตัว —
 *      ไม่มีไฟล์ก็ไม่มี include · ผลคือเครื่องที่ยังไม่เคยใช้ฟีเจอร์นี้ไม่มีอะไรให้พังเลย
 *      และถ้าไฟล์ถูกลบทิ้งด้วยมือ การเขียน zone ครั้งถัดไปจะถอด include ออกให้เอง
 *   2. **การคืนค่าต้องคืนสองไฟล์พร้อมกัน** ({@see writeCustomConfig()}) — RollbackGuard
 *      *ลบ* ไฟล์ทิ้งเมื่อเดิมไม่มีไฟล์นั้น แล้วสั่ง `systemctl reload-or-restart` ต่อ
 *      ถ้าคืนแค่ไฟล์ส่วนเสริมโดยไม่คืน `named.conf.local` ด้วย บรรทัด include จะชี้ไป
 *      ไฟล์ที่เพิ่งถูกลบ แล้ว **การคืนค่าเพื่อความปลอดภัยจะกลายเป็นตัวที่ทำ DNS ทั้งเครื่องล่ม**
 *
 * **ข้อจำกัดที่ยังไม่ได้ทำ:** glue record (A/AAAA ของ nameserver ที่อยู่ในโซนเดียวกัน) ไม่ได้
 * สร้างให้อัตโนมัติ — ถ้าตั้ง `dns.nameservers` เป็นชื่อที่อยู่ในโซนที่กำลังจะสร้าง ผู้ดูแล
 * ต้องเพิ่มเรกคอร์ด A ของ nameserver นั้นเป็น DNS record ปกติเอง ไม่งั้น `named-checkzone`
 * จะเตือน (ไม่ถึงกับปฏิเสธ) ว่า "has no address records"
 */
final class BindZoneManager
{
    /**
     * เครื่องมือของ BIND9 อยู่คนละที่กันตาม distro — Debian/Ubuntu วาง `named-checkzone`
     * และ `named-checkconf` ไว้ที่ `/usr/bin` (แพ็กเกจ `bind9-utils`) ส่วน RHEL/Alma/Rocky
     * วางไว้ที่ `/usr/sbin` · `rndc` อยู่ `/usr/sbin` ทั้งสองฝั่ง
     *
     * เคยฮาร์ดโค้ดเป็น `/usr/sbin` ทั้งสามตัวแล้วพังบน Ubuntu จริง — เทสต์จับไม่ได้เพราะ
     * `DryRunExecutor` ไม่รันคำสั่งจริง และเทสต์ที่ยิง `named-checkzone` จริงเรียกผ่าน PATH
     * ไม่ได้ผ่านค่าคงที่พวกนี้ · ตอนนี้ไล่หาจากรายการเหมือนที่ `MariaDbManager` ทำ
     * และมีเทสต์ตรึงไว้ว่าไฟล์ที่ระบุต้องมีอยู่จริงบนเครื่องที่รันเทสต์
     *
     * @var list<string>
     */
    public const CHECKZONE_PATHS = ['/usr/bin/named-checkzone', '/usr/sbin/named-checkzone'];

    /** @var list<string> */
    public const CHECKCONF_PATHS = ['/usr/bin/named-checkconf', '/usr/sbin/named-checkconf'];

    /** @var list<string> */
    public const RNDC_PATHS = ['/usr/sbin/rndc', '/usr/bin/rndc'];

    public function __construct(
        private readonly Executor $executor,
        private readonly Config $config,
        private readonly Db $db,
    ) {
    }

    /**
     * ไฟล์ส่วนเสริมของผู้ดูแล — อยู่ **ข้าง `named.conf.local`** ไม่ใช่ใต้ `/etc/phpcp`
     *
     * เหตุผลเป็นเรื่องสิทธิ์การอ่านล้วน ๆ และวัดจากเครื่องจริงมาแล้ว: named ทิ้งสิทธิ์ root
     * ทันทีที่สตาร์ต (`named -u bind`) เหลือความสามารถแค่ `cap_net_bind_service` กับ
     * `cap_sys_resource` — **ไม่มี `cap_dac_read_search`** · `/etc/phpcp` เป็น
     * 750 root:phpcp มันจึงเดินผ่านไดเรกทอรีไม่ได้เลย แล้ว `rndc reload` จะล้มด้วย
     * permission denied ทุกครั้ง ทั้งที่ไฟล์ถูกเขียนสำเร็จและเทสต์ผ่านหมด
     *
     * การเปิดสิทธิ์ `/etc/phpcp` ให้ bind อ่านได้แลกไม่คุ้ม — ที่นั่นมี `config.php`
     * ที่เก็บกุญแจของ panel · ไดเรกทอรีของ BIND เป็นที่ที่ panel เขียน zone file
     * กับ `named.conf.local` ลงไปอยู่แล้ว การวางไฟล์นี้ไว้ด้วยกันจึงไม่ได้เพิ่ม
     * ขอบเขตอะไรใหม่เลย
     *
     * อิงจากที่อยู่ของ `named.conf.local` ไม่ใช่ `/etc/bind` ตายตัว — เครื่องที่ย้าย
     * ที่อยู่ผ่าน `dns.named_conf_local` ต้องได้ไฟล์ทั้งสองอยู่ด้วยกันเสมอ
     */
    public static function customConfigPath(Config $config): string
    {
        return dirname($config->dnsNamedConfLocal()) . '/phpcp-custom.conf';
    }

    /**
     * เขียน zone ของโดเมนเดียวจากเรกคอร์ดปัจจุบันในฐานข้อมูล แล้วสั่งโหลดใหม่
     *
     * @param array<string,mixed> $domain แถวจากตาราง `domains`
     * @return array{pushed:bool,message:string,domain?:string,serial?:int,record_count?:int}
     */
    public function writeZone(array $domain): array
    {
        if (!$this->config->dnsEnabled()) {
            return [
                'pushed' => false,
                'message' => 'ยังไม่ได้เปิดใช้งานการเชื่อม BIND9 (dns.enabled = false) — '
                    . 'เรกคอร์ดถูกบันทึกไว้ในระบบแล้ว แต่ยังไม่ถูกส่งออกไปยัง DNS server จริง',
            ];
        }

        $nameservers = $this->config->dnsNameservers();

        if ($nameservers === []) {
            throw new ValidationError(
                'ยังไม่ได้ตั้งค่า dns.nameservers — ต้องมีอย่างน้อยหนึ่งเครื่องก่อนสร้าง zone ได้ '
                . '(BIND9 ปฏิเสธ zone ที่ไม่มี NS record เสมอ)',
            );
        }

        $domainName = (string) $domain['domain'];
        $domainId = (int) $domain['id'];
        $isNewZone = (int) $domain['zone_serial'] === 0;
        $serial = $this->nextSerial((int) $domain['zone_serial']);

        $records = $this->db->all(
            'SELECT * FROM dns_records WHERE domain_id = :id ORDER BY type, name',
            ['id' => $domainId],
        );

        $content = DnsRecord::toAuthoritativeZoneFile(
            $domainName,
            $records,
            $serial,
            $nameservers,
            $this->config->dnsSoaEmail(),
        );

        $zoneDir = $this->config->dnsZoneDir();
        $zonePath = $zoneDir . '/' . $domainName . '.zone';

        $this->executor->makeDirectory($this->executor->path($zoneDir), 0755);

        $tx = new ConfigTransaction($this->executor);
        $tx->write($zonePath, $content, 0644);

        if ($isNewZone) {
            $tx->write($this->config->dnsNamedConfLocal(), $this->buildNamedConfLocal($domainId, $domainName, $zonePath));
        }

        $tx->commit(function () use ($domainName, $zonePath): array {
            return $this->validate($domainName, $zonePath);
        });

        $this->reload();

        $this->db->update('domains', ['zone_serial' => $serial], ['id' => $domainId]);

        return [
            'pushed' => true,
            'domain' => $domainName,
            'serial' => $serial,
            'record_count' => count($records),
            'message' => sprintf('ส่ง zone ของ %s ไปยัง BIND9 แล้ว (serial %d)', $domainName, $serial),
        ];
    }

    /**
     * เขียน zone ของทุกโดเมนที่มีอยู่ใหม่ทั้งหมดแล้วสั่งโหลดครั้งเดียวตอนจบ — ใช้เมื่อผู้ดูแล
     * แก้ไขอะไรที่ฝั่ง BIND9 ตรง ๆ แล้วต้องการให้ panel เขียนทับคืนสภาพที่ควรจะเป็น หรือใช้ครั้ง
     * แรกหลังเปิด `dns.enabled` เพื่อผลักเรกคอร์ดที่มีอยู่ก่อนแล้วทั้งหมดออกไปให้ครบ
     *
     * @return array{pushed:bool,message:string,domains?:int,failed?:list<array{domain:string,error:string}>}
     */
    public function reloadAll(): array
    {
        if (!$this->config->dnsEnabled()) {
            return [
                'pushed' => false,
                'message' => 'ยังไม่ได้เปิดใช้งานการเชื่อม BIND9 (dns.enabled = false)',
            ];
        }

        // เฉพาะโดเมนที่มีเรกคอร์ด DNS อยู่จริง — โดเมนที่ไม่เคยเพิ่มเรกคอร์ดเลยไม่ต้องมี zone
        $domains = $this->db->all(
            'SELECT DISTINCT d.* FROM domains d
             JOIN dns_records r ON r.domain_id = d.id',
        );

        $pushed = 0;
        $failed = [];

        foreach ($domains as $domain) {
            try {
                $this->writeZone($domain);
                $pushed++;
            } catch (\Throwable $e) {
                // โดเมนเดียวที่ผิดพลาด (เช่นเรกคอร์ดเก่าที่ข้อมูลเพี้ยน) ต้องไม่ทำให้โดเมนอื่น
                // ที่เหลือไม่ถูกซิงก์ไปด้วย
                $failed[] = ['domain' => (string) $domain['domain'], 'error' => $e->getMessage()];
            }
        }

        // ล้มทั้งหมด = ล้มเหลว ไม่ใช่ "สำเร็จบางส่วน" — เคยคืน pushed=true เสมอ ทำให้หน้าจอ
        // ขึ้นแถบเขียว "สำเร็จ" ทั้งที่ซิงก์ไม่ได้สักโดเมน ซึ่งเป็นการล้มเงียบแบบเดียวกับที่
        // เฟส E1 เตือนไว้เองว่า "ปลายทางที่ล้มเงียบอันตรายพอ ๆ กับไม่มีปลายทางเลย"
        // (เจอจากการทดสอบบนเซิร์ฟเวอร์จริง 2026-08-10)
        $allFailed = $pushed === 0 && $failed !== [];

        if ($allFailed) {
            throw new ExecutionFailed(sprintf(
                "ซิงก์ zone ไม่สำเร็จสักโดเมน (%d โดเมน)\n\n%s",
                count($failed),
                implode("\n", array_map(
                    static fn (array $f): string => "· {$f['domain']}: {$f['error']}",
                    $failed,
                )),
            ));
        }

        return [
            'pushed' => true,
            'domains' => $pushed,
            'failed' => $failed,
            'message' => $failed === []
                ? sprintf('ซิงก์ zone ครบ %d โดเมนแล้ว', $pushed)
                : sprintf('ซิงก์ zone สำเร็จ %d โดเมน · ล้มเหลว %d โดเมน', $pushed, count($failed)),
        ];
    }

    /**
     * เลข serial ถัดไป — เพิ่มขึ้นอย่างเดียวเสมอ (ห้ามซ้ำ/ย้อนกลับ ไม่งั้น secondary/resolver
     * บางตัวจะไม่ดึงข้อมูลใหม่ไปเพราะคิดว่าเป็นสำเนาที่เก่ากว่าที่ถืออยู่) เริ่มจากรูปแบบ
     * YYYYMMDDnn ตามธรรมเนียมของ DNS เพื่อให้อ่านแล้วรู้วันที่แก้ล่าสุดได้ทันที แต่ไม่ยึดติด
     * กับรูปแบบนั้นถ้าแก้มากกว่า 100 ครั้งในวันเดียว (`+1` ตรง ๆ ยังถูกต้องเสมอ)
     */
    private function nextSerial(int $current): int
    {
        $dateSeed = (int) date('Ymd') * 100;

        return max($current + 1, $dateSeed);
    }

    /**
     * เขียนทั้งไฟล์ `named.conf.local` ใหม่จากรายชื่อโดเมนที่มี zone อยู่แล้วในฐานข้อมูล
     * (`zone_serial > 0`) รวมกับโดเมนที่กำลังจะสร้างใหม่ (ยังไม่ commit จึง zone_serial
     * เป็น 0 อยู่ ต้อง union เข้าไปเอง)
     */
    private function buildNamedConfLocal(int $newDomainId = 0, string $newDomainName = '', string $newZonePath = '', ?bool $withCustom = null): string
    {
        $existing = $this->db->all('SELECT domain FROM domains WHERE zone_serial > 0 AND id != :id', ['id' => $newDomainId]);

        $lines = [
            '// จัดการโดย phpcp โดยอัตโนมัติทั้งไฟล์ — ห้ามแก้ไขด้วยมือ',
            '// การแก้จะหายไปทันทีที่มีการเพิ่ม/ลบ zone ครั้งถัดไปผ่าน panel',
            '// เพิ่ม/แก้ zone ผ่านหน้า DNS ของ panel เท่านั้น',
            '',
        ];

        foreach ($existing as $row) {
            $domainName = (string) $row['domain'];
            $lines[] = $this->zoneStanza($domainName, $this->config->dnsZoneDir() . '/' . $domainName . '.zone');
        }

        if ($newDomainName !== '') {
            $lines[] = $this->zoneStanza($newDomainName, $newZonePath);
        }

        /*
         * บรรทัด include ของไฟล์ส่วนเสริม — **มีได้ก็ต่อเมื่อไฟล์นั้นมีอยู่จริง**
         *
         * `include` ของ BIND ไม่ทนไฟล์หาย ไฟล์ที่ถูกอ้างแต่ไม่มีอยู่ทำให้ named ไม่สตาร์ต
         * ทั้งเครื่อง · การผูกกับสภาพจริงบนดิสก์แทนที่จะเขียนบรรทัดนี้ตายตัวทำให้เครื่อง
         * ที่ไม่เคยใช้ฟีเจอร์นี้ไม่มีอะไรให้พัง และเครื่องที่ไฟล์ถูกลบทิ้งด้วยมือได้รับ
         * การซ่อมให้เองในการเขียน zone ครั้งถัดไป
         *
         * `$withCustom` ระบุตรง ๆ ได้สำหรับผู้เรียกที่กำลังเขียนไฟล์นั้นอยู่ในทรานแซกชัน
         * เดียวกัน — ตอนประกอบข้อความไฟล์ยังไม่ถูกเขียนลงดิสก์ การถามดิสก์จึงได้คำตอบผิด
         */
        $customPath = self::customConfigPath($this->config);

        if ($withCustom ?? $this->executor->exists($this->executor->path($customPath))) {
            $lines[] = '// ค่าตั้งเพิ่มเติมของผู้ดูแล — อ่านท้ายสุด แก้จากหน้าโดเมนของ panel';
            $lines[] = sprintf('include "%s";', $customPath);
            $lines[] = '';
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * เขียนไฟล์ส่วนเสริมของผู้ดูแล แล้วผูกบรรทัด include ใน `named.conf.local` ให้ตรงกัน
     *
     * ทั้งสองไฟล์อยู่ในทรานแซกชันเดียวกันเพราะมันถูกต้องหรือผิดพร้อมกันเสมอ — ไฟล์ที่ถูก
     * include แต่ไม่มีอยู่ทำให้ named ไม่สตาร์ต และบรรทัด include ที่หายไปทำให้ค่าที่
     * ผู้ดูแลเพิ่งเขียนไม่มีผลโดยไม่มีอะไรฟ้อง
     *
     * คืน**สภาพเดิมของทั้งสองไฟล์**ให้ผู้เรียกไปตั้ง RollbackGuard — ดูเหตุผลที่หัวคลาส
     * ว่าทำไมการคืนแค่ไฟล์เดียวถึงอันตรายกว่าไม่คืนเลย
     *
     * @return array{path:string,files:array<string,string|null>}
     */
    public function writeCustomConfig(string $content): array
    {
        if (!$this->config->dnsEnabled()) {
            throw new ValidationError(
                'ยังไม่ได้เปิดใช้งานการเชื่อม BIND9 (dns.enabled = false) — '
                . 'panel ยังไม่ได้จัดการ named.conf.local ของเครื่องนี้ จึงยังผูกไฟล์ส่วนเสริมเข้าไปไม่ได้ '
                . 'เปิดใช้งาน DNS จากหน้าตั้งค่าก่อน',
            );
        }

        $customPath = self::customConfigPath($this->config);
        $namedConfLocal = $this->config->dnsNamedConfLocal();

        // เก็บสภาพเดิมก่อนแตะอะไรทั้งสิ้น · null = เดิมไม่มีไฟล์นี้ (RollbackGuard จะลบทิ้ง)
        $previous = [
            $customPath => $this->currentContents($customPath),
            $namedConfLocal => $this->currentContents($namedConfLocal),
        ];

        $this->executor->makeDirectory($this->executor->path(dirname($customPath)), 0755);

        $transaction = new ConfigTransaction($this->executor);
        $transaction->write($customPath, $content, 0644);
        $transaction->write($namedConfLocal, $this->buildNamedConfLocal(withCustom: true));

        // ตัวตรวจของ BIND เองอ่านทั้งชุดตั้งแต่ named.conf ลงมา จึงเห็นทั้งไฟล์ที่เพิ่งเขียน
        // และ zone เดิมทุกตัว — จับ zone ซ้ำและ options ซ้ำที่ข้ามไฟล์กันได้
        $transaction->commit(fn (): array => $this->checkConf());

        $this->reload();

        return ['path' => $customPath, 'files' => $previous];
    }

    /** เนื้อไฟล์ปัจจุบัน — null เมื่อยังไม่มีไฟล์ ซึ่งต่างจากไฟล์ว่างอย่างมีความหมาย */
    private function currentContents(string $path): ?string
    {
        $resolved = $this->executor->path($path);

        if (!$this->executor->exists($resolved)) {
            return null;
        }

        try {
            return $this->executor->readFile($resolved);
        } catch (\Throwable) {
            return null;
        }
    }

    private function zoneStanza(string $domain, string $zonePath): string
    {
        return sprintf(
            "zone \"%s\" {\n    type master;\n    file \"%s\";\n};\n",
            $domain,
            $zonePath,
        );
    }

    /**
     * หาไฟล์โปรแกรมตัวแรกที่มีอยู่จริงจากรายการ
     *
     * @param list<string> $candidates
     */
    private function binary(array $candidates, string $package): string
    {
        return BinaryPath::resolve($this->executor, $candidates, $package);
    }

    /** @return array{0:bool,1:string} */
    private function validate(string $domainName, string $zonePath): array
    {
        $zoneCheck = $this->executor->exec(
            [$this->binary(self::CHECKZONE_PATHS, 'bind9-utils'), $domainName, $this->executor->path($zonePath)],
            timeout: 30,
        );

        if (!$zoneCheck->ok()) {
            return [false, 'named-checkzone: ' . trim($zoneCheck->output() . $zoneCheck->stderr)];
        }

        // ตรวจทั้งชุด (named.conf หลักซึ่ง include named.conf.local อยู่แล้ว) เสมอ ไม่ใช่แค่
        // ตอนโดเมนใหม่ — การแก้ไฟล์ zone เดิมไม่ควรกระทบ แต่ตรวจฟรีไม่มีต้นทุนเพิ่มที่คุ้มจะข้าม
        return $this->checkConf();
    }

    /**
     * ตรวจค่าตั้งทั้งชุดด้วย `named-checkconf`
     *
     * ไม่รับพารามิเตอร์เส้นทางโดยตั้งใจ — มันอ่านจาก `named.conf` หลักลงมาเองทั้งลำดับชั้น
     * จึงเห็นทุกไฟล์ที่ named จะเห็นจริงตอนสตาร์ต รวมถึงไฟล์ส่วนเสริมที่ถูก include
     * และ zone ของโดเมนอื่นที่อาจชนกัน · การตรวจทีละไฟล์แยกกันมองไม่เห็นการชนกันแบบนั้น
     *
     * @return array{0:bool,1:string}
     */
    private function checkConf(): array
    {
        $check = $this->executor->exec([$this->binary(self::CHECKCONF_PATHS, 'bind9-utils')], timeout: 15);

        if (!$check->ok()) {
            return [false, 'named-checkconf: ' . trim($check->output() . $check->stderr)];
        }

        return [true, ''];
    }

    private function reload(): void
    {
        $result = $this->executor->exec([$this->binary(self::RNDC_PATHS, 'bind9'), 'reload'], timeout: 30);

        if (!$result->ok()) {
            throw new ExecutionFailed(
                'เขียนไฟล์ zone ผ่านการตรวจสอบแล้ว แต่สั่ง BIND9 โหลดค่าใหม่ไม่สำเร็จ: '
                . trim($result->output() . $result->stderr)
                . "\n\nไฟล์บนดิสก์ถูกต้องแล้ว ลองสั่งซิงก์ใหม่อีกครั้งภายหลัง",
            );
        }
    }
}
