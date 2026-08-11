<?php

declare(strict_types=1);

namespace Phpcp\Driver;

use Phpcp\Agent\ExecutionFailed;
use Phpcp\Agent\ValidationError;

/**
 * อัปเดตตัวเอง พร้อมตรวจลายเซ็น — ARCHITECTURE §13
 *
 * ตัวอัปเดตของโปรแกรมที่รันด้วยสิทธิ์ root คือช่องทางยึดเครื่องที่ตรงที่สุดที่มี:
 * ใครแทรกไฟล์เข้ามาในขั้นตอนนี้ได้ ก็ได้ root ทันทีบนทุกเครื่องที่ติดตั้งไว้
 * ไฟล์นี้จึงยึดกฎสามข้อ ห้ามผ่อนข้อใดข้อหนึ่งเพื่อความสะดวก:
 *
 *  1. **ตรวจลายเซ็นก่อนแตะไฟล์เสมอ** — ไม่ใช่หลังแตกไฟล์ ไม่ใช่ "ตรวจถ้ามีลายเซ็น"
 *     แพ็กเกจที่ไม่มีลายเซ็นคือแพ็กเกจที่ปฏิเสธ ไม่ใช่แพ็กเกจที่ข้ามการตรวจ
 *
 *  2. **กุญแจสาธารณะฝังอยู่ในโค้ด** ไม่ได้ดาวน์โหลดมาพร้อมแพ็กเกจ
 *     ถ้าดาวน์โหลดกุญแจมาด้วย ผู้โจมตีก็ส่งกุญแจของตัวเองมาคู่กับแพ็กเกจของตัวเอง
 *     แล้วลายเซ็นก็ผ่านทุกครั้ง — การตรวจที่ไม่มีจุดยึดที่เชื่อถือได้ ไม่ใช่การตรวจ
 *
 *  3. **ห้ามลดเวอร์ชัน** — การยัดเวอร์ชันเก่าที่มีช่องโหว่ที่แก้ไปแล้วกลับเข้าไป
 *     เป็นการโจมตีที่ใช้ได้ผลจริงแม้ลายเซ็นจะถูกต้องทุกประการ
 *
 * ใช้ Ed25519 ผ่าน ext-sodium ที่มากับ PHP อยู่แล้ว ไม่ต้องพึ่ง gpg บนเครื่องปลายทาง
 */
final class Updater
{
    /**
     * กุญแจสาธารณะของผู้เผยแพร่ (base64 ของ 32 ไบต์)
     *
     * ค่าว่าง = ยังไม่ได้ตั้งกุญแจสำหรับ build นี้ ซึ่งทำให้ self-update ใช้ไม่ได้เลย
     * เป็นค่าเริ่มต้นที่ถูกต้องแล้ว — ปลอดภัยกว่าการแถมกุญแจตัวอย่างที่ใครก็มีคู่ส่วนตัว
     */
    public const PUBLIC_KEY = '';

    /** เวลาสูงสุดที่ยอมให้ดาวน์โหลด */
    private const TIMEOUT = 120;

    /** ขนาดแพ็กเกจสูงสุดที่ยอมรับ กันไฟล์ยักษ์ที่ทำให้ดิสก์เต็ม */
    private const MAX_SIZE = 64 * 1024 * 1024;

    public function __construct(private readonly string $publicKey = self::PUBLIC_KEY)
    {
    }

    public function isConfigured(): bool
    {
        return $this->publicKey !== '';
    }

    /**
     * ตรวจว่าแพ็กเกจนี้เชื่อถือได้และควรติดตั้งหรือไม่
     *
     * แยกจากการติดตั้งจริงเพื่อให้ทดสอบได้โดยไม่ต้องแตะระบบไฟล์ของเครื่อง
     * และเพื่อให้ `phpcp self-update --check` บอกผลได้โดยไม่เปลี่ยนอะไรเลย
     *
     * @param string $archive   เนื้อไฟล์แพ็กเกจ
     * @param string $signature ลายเซ็น Ed25519 แบบ base64
     */
    public function verify(string $archive, string $signature, string $version, string $current): void
    {
        if (!$this->isConfigured()) {
            throw new ValidationError(
                'build นี้ไม่ได้ฝังกุญแจสาธารณะไว้ จึงตรวจลายเซ็นของแพ็กเกจไม่ได้ — '
                . 'self-update ถูกปิดไว้เพื่อความปลอดภัย ให้อัปเดตด้วยการติดตั้งใหม่จากแหล่งที่เชื่อถือได้แทน',
            );
        }

        if (!extension_loaded('sodium')) {
            throw new ValidationError('ไม่มีส่วนขยาย sodium จึงตรวจลายเซ็นไม่ได้');
        }

        if ($archive === '') {
            throw new ValidationError('แพ็กเกจว่างเปล่า');
        }

        if (strlen($archive) > self::MAX_SIZE) {
            throw new ValidationError('แพ็กเกจใหญ่เกินกว่าที่ยอมรับ');
        }

        $key = base64_decode($this->publicKey, true);
        $sig = base64_decode($signature, true);

        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new ValidationError('กุญแจสาธารณะที่ฝังไว้ผิดรูปแบบ');
        }

        if ($sig === false || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES) {
            throw new ValidationError('ลายเซ็นผิดรูปแบบ — ปฏิเสธแพ็กเกจ');
        }

        if (!sodium_crypto_sign_verify_detached($sig, $archive, $key)) {
            throw new ValidationError(
                'ลายเซ็นไม่ตรงกับแพ็กเกจ — ไฟล์อาจถูกแก้ระหว่างทางหรือไม่ได้มาจากผู้เผยแพร่ตัวจริง '
                . 'ยกเลิกการอัปเดตทั้งหมด',
            );
        }

        $this->assertUpgrade($version, $current);
    }

    /**
     * ห้ามลดเวอร์ชัน แม้ลายเซ็นจะถูกต้อง
     *
     * แพ็กเกจเก่าที่เคยเซ็นไว้ยังมีลายเซ็นที่ถูกต้องตลอดไป ผู้โจมตีที่ดักการเชื่อมต่อได้
     * จึงส่งเวอร์ชันเก่าที่มีช่องโหว่ซึ่งแก้ไปแล้วกลับมาให้ติดตั้งซ้ำได้
     * ลายเซ็นอย่างเดียวกันเรื่องนี้ไม่ได้ ต้องเทียบเวอร์ชันเพิ่ม
     */
    public function assertUpgrade(string $version, string $current): void
    {
        if (preg_match('/^\d+\.\d+\.\d+(-[0-9A-Za-z.]+)?$/', $version) !== 1) {
            throw new ValidationError('หมายเลขเวอร์ชันของแพ็กเกจผิดรูปแบบ');
        }

        if (version_compare($version, $current, '<')) {
            throw new ValidationError(sprintf(
                'แพ็กเกจเป็นเวอร์ชัน %s ซึ่งเก่ากว่าที่ติดตั้งอยู่ (%s) — ปฏิเสธเพื่อกันการถูกย้อนกลับ'
                . 'ไปยังเวอร์ชันที่มีช่องโหว่',
                $version,
                $current,
            ));
        }

        if (version_compare($version, $current, '=')) {
            throw new ValidationError(sprintf('ติดตั้งเวอร์ชัน %s อยู่แล้ว', $current));
        }
    }

    /**
     * ดาวน์โหลดข้อมูลจาก URL ที่ต้องเป็น HTTPS เท่านั้น
     *
     * บังคับ HTTPS ที่นี่ ไม่ใช่หวังว่าคนตั้งค่าจะใส่ https มาเอง —
     * ถึงจะมีลายเซ็นกันการแก้ไฟล์อยู่แล้ว แต่ HTTP เปิดช่องให้ผู้ดักฟังรู้ว่า
     * เครื่องไหนใช้เวอร์ชันอะไร ซึ่งเป็นข้อมูลตั้งต้นของการเลือกเป้าโจมตี
     */
    public function fetch(string $url): string
    {
        if (!str_starts_with($url, 'https://')) {
            throw new ValidationError('ที่อยู่ของแพ็กเกจต้องเป็น https:// เท่านั้น');
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new ValidationError('ที่อยู่ของแพ็กเกจผิดรูปแบบ');
        }

        $handle = curl_init($url);

        if ($handle === false) {
            throw new ExecutionFailed('เริ่มการดาวน์โหลดไม่สำเร็จ');
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_FOLLOWLOCATION => false,   // redirect ไป http:// จะข้ามการบังคับ HTTPS ข้างบน
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'phpcp/' . PHPCP_VERSION,
        ]);

        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($body === false || $error !== '') {
            throw new ExecutionFailed('ดาวน์โหลดไม่สำเร็จ: ' . $error);
        }

        if ($status !== 200) {
            throw new ExecutionFailed("ดาวน์โหลดไม่สำเร็จ: เซิร์ฟเวอร์ตอบรหัส {$status}");
        }

        return (string) $body;
    }

    /**
     * อ่านข้อมูลรุ่นล่าสุดจากไฟล์ manifest
     *
     * @return array{version:string,url:string,signature:string,notes:string}
     */
    public function parseManifest(string $json): array
    {
        $data = json_decode($json, true);

        if (!is_array($data)) {
            throw new ValidationError('ข้อมูลรุ่นล่าสุดผิดรูปแบบ');
        }

        foreach (['version', 'url', 'signature'] as $field) {
            if (!isset($data[$field]) || !is_string($data[$field]) || $data[$field] === '') {
                throw new ValidationError("ข้อมูลรุ่นล่าสุดไม่มีฟิลด์ {$field}");
            }
        }

        return [
            'version' => $data['version'],
            'url' => $data['url'],
            'signature' => $data['signature'],
            'notes' => is_string($data['notes'] ?? null) ? $data['notes'] : '',
        ];
    }
}
