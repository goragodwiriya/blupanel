<?php

declare(strict_types=1);

namespace Phpcp\Http\Resource;

use Phpcp\Security\Permissions;

/**
 * ผู้ใช้ panel หนึ่งคน
 *
 * ไม่มี `password_hash` และ `totp_secret` อยู่ในผลลัพธ์เด็ดขาด — การเลือกฟิลด์แบบระบุชื่อ
 * ทีละตัว (ไม่ใช่ `...$row` แล้วค่อย unset) คือเหตุผลหลักที่ชั้น Resource มีอยู่:
 * คอลัมน์ที่เพิ่มเข้ามาในอนาคตจะไม่หลุดออก API เองโดยไม่มีใครตั้งใจ
 */
final class UserResource extends Resource
{
    public static function one(array $row): array
    {
        $role = self::string($row['role'] ?? '');

        return [
            'id' => (int) ($row['id'] ?? 0),
            'username' => self::string($row['username'] ?? ''),
            'display_name' => self::string($row['display_name'] ?? '') ?: self::string($row['username'] ?? ''),
            'role' => $role,
            // ชื่อบทบาทภาษาไทยยังส่งไปด้วยเพื่อไม่ให้ SPA ต้องรู้จักตารางแปลงของฝั่ง PHP
            // เมื่อ i18n ของเฟส C เสร็จ ฝั่งหน้าเว็บจะแปลจาก `role` เองแล้วเลิกใช้ค่านี้
            'role_label' => Permissions::roleLabel($role),
            // สองแกนที่ตั้งใจให้แยกกัน: status คุมสิทธิ์ล็อกอิน · service_status คุมบริการโฮสติ้ง
            // ก่อน migration 0005 สองอย่างนี้อยู่คนละตารางและขัดกันเองได้จริง
            'status' => self::string($row['status'] ?? 'active'),
            'service_status' => self::string($row['service_status'] ?? 'active'),
            // สีของป้ายสถานะ — ส่งมาจากที่นี่เพื่อให้เทมเพลตเขียน
            // `pill-${service_tone}` ได้ตรง ๆ โดยไม่ต้องมีตารางแปลงฝั่ง JS
            'service_tone' => match (self::string($row['service_status'] ?? 'active')) {
                'active' => 'ok',
                'suspended' => 'warn',
                'expired' => 'danger',
                default => 'muted',
            },
            'email' => self::string($row['email'] ?? ''),
            'expiry_at' => self::intOrNull($row['expiry_at'] ?? null),
            'two_factor_enabled' => self::bool($row['totp_enabled'] ?? 0),
            'must_change_password' => self::bool($row['must_change_password'] ?? 0),
            'last_login_at' => self::intOrNull($row['last_login_at'] ?? null),
            'last_login_ip' => self::string($row['last_login_ip'] ?? ''),
            'created_at' => self::intOrNull($row['created_at'] ?? null),
        ];
    }

    /**
     * ผู้ใช้พร้อมข้อมูลโฮสติ้ง — โควตาที่ตั้งไว้ จำนวนที่ใช้ไป และจำนวนเว็บ
     *
     * แยกจาก one() เพราะบัญชีผู้ดูแลไม่มีความหมายของโควตา การยัดคีย์ที่เป็น 0 ทั้งแถว
     * ให้ทุกคนจะทำให้หน้าจอแยกไม่ออกว่า "ไม่จำกัด" กับ "ไม่เกี่ยวข้อง" ต่างกันอย่างไร
     *
     * @param array<string,mixed> $row
     * @param array<string,array{used:int,limit:int,label:string,unlimited:bool,disabled:bool}> $quota
     * @return array<string,mixed>
     */
    public static function withHosting(array $row, array $quota, int $siteCount): array
    {
        $diskQuotaMb = (int) ($row['disk_quota_mb'] ?? -1);
        $diskUsedMb = (int) ($row['disk_used_mb'] ?? 0);
        $diskPercent = $diskQuotaMb > 0 ? min(999, (int) round($diskUsedMb / $diskQuotaMb * 100)) : 0;

        return self::one($row) + [
            'quota' => $quota,
            'site_count' => $siteCount,
            // MB ไม่ใช่ item count จึงไม่อยู่ใน `quota` ร่วมกับโดเมน/ฐานข้อมูล — PLAN-V2 เฟส E2
            'disk_quota_mb' => $diskQuotaMb,
            'disk_used_mb' => $diskUsedMb,
            'disk_quota_unlimited' => $diskQuotaMb === -1,
            'disk_quota_percent' => $diskPercent,
            // สีป้ายตามเกณฑ์เดียวกับที่ quota.disk_check ใช้แจ้งเตือน (80/90/100%)
            'disk_quota_tone' => match (true) {
                $diskQuotaMb === -1 => 'muted',
                $diskPercent >= 100 => 'danger',
                $diskPercent >= 80 => 'warn',
                default => 'ok',
            },
            // SFTP — PLAN-V2 เฟส E4 · `sftp_available` แยกจาก `sftp_enabled` เพราะหน้าจอ
            // ต้องบอกต่างกันระหว่าง "แพ็กเกจไม่รวม" (โควตา 0) กับ "รวมแต่ยังไม่ได้เปิด"
            'sftp_enabled' => self::bool($row['sftp_enabled'] ?? 0),
            'sftp_available' => (int) ($row['quota_ftp_users'] ?? 0) !== 0,
            'sftp_enabled_at' => self::intOrNull($row['sftp_enabled_at'] ?? null),

            /*
             * รูปทรงไฟล์ — ส่งสามค่าเพราะหน้าจอต้องบอกสามเรื่องที่ต่างกัน
             *
             *   site_layout        ค่าที่บัญชีนี้เลือกไว้ (ว่าง = ยังไม่เคยเลือก) → ใช้ตั้งค่า select
             *   site_layout_actual ค่าที่ใช้จริงหลังเติมค่าเริ่มต้นของระบบ → ใช้บอกผู้ดูแลว่าตอนนี้เป็นอะไร
             *   site_layout_docroot ตัวอย่างเส้นทางจริงของบัญชีนี้ → คำตอบที่ผู้ดูแลอยากรู้ที่สุด
             *
             * ถ้าส่งแค่ค่าแรก หน้าจอจะแสดงช่องว่างให้บัญชีที่ยังไม่เคยเลือก โดยไม่บอกว่า
             * "ว่าง" หมายถึงอะไร ซึ่งเป็นคำถามแรกที่ผู้ดูแลจะถาม
             */
            'site_layout' => (string) ($row['site_layout'] ?? ''),
            'site_layout_actual' => self::account($row)->layout()->value,
            'site_layout_docroot' => self::account($row)->siteDocroot(
                (string) ($row['main_domain'] ?? '') !== '' ? (string) $row['main_domain'] : 'example.com',
            ),
            'main_domain' => (string) ($row['main_domain'] ?? ''),
        ];
    }

    /**
     * บัญชีระบบของแถวนี้ — คืน null ไม่ได้เพราะหน้าจอต้องแสดงเส้นทางเสมอ
     *
     * ผู้ใช้ที่ยังไม่มีบัญชีระบบ (`system_user` ว่าง) ใช้ชื่อผู้ใช้แทน ซึ่งเป็นชื่อเดียวกับ
     * ที่จะถูกใช้ตอน provision จริง — เส้นทางที่แสดงจึงเป็นเส้นทางที่จะได้จริง ไม่ใช่ค่าหลอก
     *
     * @param array<string,mixed> $row
     */
    private static function account(array $row): \Phpcp\Domain\UserAccount
    {
        return \Phpcp\Domain\UserAccount::fromRow($row);
    }

    /**
     * ผู้ใช้ที่กำลังล็อกอินอยู่ในมุมมองของ SPA
     *
     * ต่างจาก one() ตรงที่แนบ `permissions` มาด้วย — SPA ใช้ซ่อน/แสดงเมนูเท่านั้น
     * **การบังคับสิทธิ์จริงยังอยู่ที่ middleware และ agent เหมือนเดิม** (PLAN-V2 §4.4)
     * รายการนี้จึงเป็นเรื่องของ UX ล้วน ๆ ต่อให้ผู้ใช้แก้ค่าในเบราว์เซอร์ก็ไม่ได้สิทธิ์เพิ่ม
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function withPermissions(array $row): array
    {
        return self::one($row) + [
            'permissions' => Permissions::forRole(self::string($row['role'] ?? '')),
        ];
    }
}
