<?php

declare(strict_types=1);

namespace Phpcp\Domain;

use Phpcp\Agent\Executor\Executor;

/**
 * ไอพีสาธารณะของเครื่องนี้ — ค่าที่ A record ทุกตัวต้องชี้ไป
 *
 * ## ทำไมถามการ์ดเน็ตตรง ๆ ไม่ได้
 *
 * เครื่องบนคลาวด์เกือบทุกเจ้าอยู่หลัง NAT: การ์ดเน็ตเห็นแต่ไอพีภายใน (เช่น
 * `172.26.15.166` บน Lightsail) ส่วนไอพีที่โลกใช้ติดต่อเป็นคนละเบอร์ (`18.142.27.80`)
 * · การใช้ค่าจากการ์ดเน็ตทำ A record แปลว่าโดเมนทุกโดเมนบนเครื่องชี้ไปยังที่อยู่ที่
 * ไม่มีใครนอกวงเข้าถึงได้ — เว็บล่มทั้งเครื่องโดยที่ทุกอย่างในระบบดู "สำเร็จ"
 *
 * ## ลำดับการหา
 *
 *   1. `server.public_ip` ที่ผู้ดูแลตั้งเอง — ชนะเสมอ · เครื่องที่อยู่หลัง proxy หรือมี
 *      หลายไอพีต้องมีทางบอกให้ชัด ไม่ใช่ให้ระบบเดา
 *   2. metadata ของคลาวด์ (IMDSv2) — ตอบไอพีสาธารณะจริงโดยไม่ต้องออกอินเทอร์เน็ต
 *      ใช้ได้กับ AWS EC2 และ Lightsail ซึ่งเป็นที่ที่ผู้ใช้ส่วนใหญ่ติดตั้ง
 *   3. ที่อยู่ต้นทางของเส้นทางออกเน็ต — ถูกต้องบนเครื่องที่มีไอพีสาธารณะติดการ์ดจริง
 *      (VPS ทั่วไป, เครื่องในองค์กร) และเป็นคำตอบที่ดีที่สุดที่เหลืออยู่
 *
 * **ไม่ถามบริการภายนอกอย่าง ifconfig.me** — การตั้งค่า DNS ไม่ควรขึ้นกับว่าเว็บของ
 * คนอื่นยังอยู่ไหม และไม่ควรส่งสัญญาณออกไปบอกใครว่าเครื่องนี้เพิ่งตั้งโดเมนอะไร
 */
final class ServerAddress
{
    /** metadata ของ AWS/Lightsail — link-local จึงไม่ออกไปนอกเครื่อง */
    private const METADATA_HOST = 'http://169.254.169.254';

    /** สั้นมากโดยตั้งใจ — เครื่องที่ไม่ใช่คลาวด์จะไม่มีใครตอบ ต้องไม่ค้างรอ */
    private const METADATA_TIMEOUT = 2;

    /**
     * ไอพีที่ควรใช้ทำ A record — คืน '' เมื่อหาไม่ได้เลย
     *
     * ผู้เรียกต้องจัดการกรณีค่าว่างเอง (ถามผู้ดูแล) ไม่ใช่ได้ค่ามั่ว ๆ ไปเขียนลง zone
     */
    public static function detect(Executor $executor, string $configured = ''): string
    {
        $configured = trim($configured);

        if ($configured !== '') {
            return self::isIpv4($configured) ? $configured : '';
        }

        return self::fromMetadata() ?: self::fromRoute($executor);
    }

    /**
     * ถาม metadata ของคลาวด์ · IMDSv2 ต้องขอ token ก่อนถึงจะอ่านได้
     *
     * IMDSv1 (อ่านตรงไม่ต้องมี token) ถูกปิดเป็นค่าเริ่มต้นบนอินสแตนซ์ใหม่แล้ว
     * จึงต้องเดินทาง v2 เป็นหลัก ไม่ใช่ทางสำรอง
     */
    private static function fromMetadata(): string
    {
        $token = self::http('PUT', self::METADATA_HOST . '/latest/api/token', [
            'X-aws-ec2-metadata-token-ttl-seconds: 60',
        ]);

        if ($token === '') {
            return '';
        }

        $ip = self::http('GET', self::METADATA_HOST . '/latest/meta-data/public-ipv4', [
            'X-aws-ec2-metadata-token: ' . $token,
        ]);

        return self::isIpv4($ip) ? $ip : '';
    }

    /**
     * ที่อยู่ต้นทางที่ kernel จะใช้ตอนออกเน็ต
     *
     * ถาม kernel ว่า "ถ้าจะไป 1.1.1.1 จะออกด้วยที่อยู่ไหน" ซึ่งตอบถูกแม้เครื่องมีหลาย
     * การ์ดหรือหลายที่อยู่ · ไม่ได้ส่งอะไรออกไปจริง เป็นการถามตารางเส้นทางเฉย ๆ
     */
    private static function fromRoute(Executor $executor): string
    {
        $result = $executor->exec([$executor->path('/usr/sbin/ip'), '-4', 'route', 'get', '1.1.1.1'], timeout: 5);

        if (!$result->ok() || preg_match('/\bsrc\s+(\d+\.\d+\.\d+\.\d+)/', $result->output(), $m) !== 1) {
            return '';
        }

        return self::isIpv4($m[1]) ? $m[1] : '';
    }

    /**
     * @param list<string> $headers
     */
    private static function http(string $method, string $url, array $headers): string
    {
        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'timeout' => self::METADATA_TIMEOUT,
            'ignore_errors' => true,
        ]]);

        $body = @file_get_contents($url, false, $context);

        return $body === false ? '' : trim($body);
    }

    /** IPv4 ที่ใช้ได้จริงบนอินเทอร์เน็ต — ตัดที่อยู่ส่วนตัวและ loopback ทิ้ง */
    public static function isIpv4(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }

    /**
     * ที่อยู่นี้เป็นไอพีส่วนตัวหรือไม่ — ใช้เตือนผู้ดูแล ไม่ใช่ปฏิเสธ
     *
     * เครื่องในองค์กรที่ให้บริการเฉพาะวงในใช้ที่อยู่ส่วนตัวอย่างถูกต้อง การห้ามจึงผิด
     * แต่บนคลาวด์มันแทบทุกครั้งแปลว่าตรวจไอพีผิด — บอกให้รู้ดีกว่าปล่อยผ่านเงียบ ๆ
     */
    public static function isPrivate(string $value): bool
    {
        return self::isIpv4($value)
            && filter_var(
                $value,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            ) === false;
    }
}
