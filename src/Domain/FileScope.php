<?php

declare (strict_types = 1);

namespace Phpcp\Domain;

use Phpcp\Kernel\Paths;

/**
 * ขอบเขตหนึ่งขอบเขตที่ตัวจัดการไฟล์เปิดดูได้
 *
 * มีสองชนิด และต่างกันตรงสิทธิ์ที่ใช้ทำงานไฟล์:
 *
 *   site   — ไดเรกทอรีของเว็บไซต์ ทำงานในสิทธิ์ของเจ้าของเว็บ (web_<id>)
 *            บั๊กเรื่องเส้นทางที่หลุดรอด (ถ้ามี) จึงมีผลได้แค่ในขอบเขตเว็บนั้น
 *
 *   server — เส้นทางระดับเครื่อง ทำงานในสิทธิ์ของ agent เอง (root บนเซิร์ฟเวอร์จริง)
 *            เปิดให้เฉพาะผู้ดูแลระดับเซิร์ฟเวอร์ และทุกการกระทำถูกบันทึก audit
 *
 * การแยกเป็น object แทนที่จะส่ง site_id ไปทุกที่ ทำให้ capability ชุดเดียว
 * ใช้ได้กับทั้งสองชนิดโดยไม่ต้องรู้ว่ากำลังทำงานกับอะไรอยู่
 */
final readonly class FileScope
{
    public const KIND_SITE = 'site';
    public const KIND_SERVER = 'server';

    /**
     * @param string $key
     * @param string $label
     * @param string $root
     * @param string $kind
     * @param string $group
     * @param string $systemUser
     * @param int $siteId
     * @param bool $writable
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $root,
        public string $kind,
        public string $group,
        /** ผู้ใช้ระบบที่จะลดสิทธิ์ไปเป็น — null = ทำงานด้วยสิทธิ์ของ agent เอง */
        public ?string $systemUser = null,
        public int $siteId = 0,
        public bool $writable = true,
    ) {
    }

    /**
     * บ้านของเว็บหนึ่งแห่ง — อยู่ใต้บ้านของเจ้าของตั้งแต่ migration 0006
     *
     * @param array<string,mixed> $site แถวที่ join users มาแล้ว จึงมี system_user ของเจ้าของ
     */
    private static function siteRoot(array $site): string
    {
        return self::owner($site)->siteRoot((string) $site['primary_domain']);
    }

    /**
     * เจ้าของเว็บพร้อมเลย์เอาต์ — **ต้องมีเลย์เอาต์เสมอ ไม่ใช่สร้าง UserAccount เปล่า**
     *
     * เดิมสร้างด้วย `new UserAccount(0, $systemUser)` ซึ่งได้เลย์เอาต์เริ่มต้นของระบบ
     * ติดมาด้วยเสมอ · เจ้าของที่ตั้ง cpanel ไว้จึงถูกมองเป็น phpcp ที่นี่ที่เดียว
     * แล้วตัวจัดการไฟล์จะเปิดไปที่ `<บ้าน>/domains/<โดเมน>` ที่ไม่มีอยู่จริง
     * — ผู้ใช้เห็นโฟลเดอร์ว่างเปล่าและไม่มีอะไรบอกว่าทำไม
     *
     * @param array<string,mixed> $site แถวที่ join users มาแล้ว
     */
    private static function owner(array $site): UserAccount
    {
        return new UserAccount(
            (int) ($site['owner_user_id'] ?? 0),
            (string) $site['system_user'],
            SiteLayout::tryFrom(trim((string) ($site['site_layout'] ?? ''))),
            trim((string) ($site['main_domain'] ?? '')),
        );
    }

    /**
     * @param array $site
     */
    public static function forSite(array $site): self
    {
        return new self(
            key: 'site-'.$site['id'],
            label: $site['name'].' — '.$site['primary_domain'],
            root: self::siteRoot($site),
            kind: self::KIND_SITE,
            group: 'เว็บไซต์',
            systemUser: (string) $site['system_user'],
            siteId: (int) $site['id'],
        );
    }

    /**
     * ขอบเขตของ "ไฟล์เว็บจริง" เมื่อเว็บนี้ใช้ Domain Pointer
     *
     * เว็บที่ตั้ง docroot_override เก็บไฟล์ไว้นอกบ้านของตัวเอง (เช่นชี้ไปยังโปรเจกต์เดิม
     * ที่มีอยู่ก่อนย้ายเข้า panel) ถ้าไม่มีขอบเขตนี้ ตัวจัดการไฟล์จะเปิดได้แค่บ้าน
     * ซึ่งมีแต่ backups/logs/tmp กับ __suspended.html — เข้าไม่ถึงไฟล์เว็บของตัวเองเลย
     *
     * คืน null เมื่อไม่ได้ตั้ง pointer หรือ docroot ยังอยู่ใต้บ้านอยู่แล้ว
     * กรณีหลังเปิดจากขอบเขตบ้านได้ตรง ๆ อยู่แล้ว ไม่ต้องมีรายการซ้ำให้สับสน
     *
     * @param array $site
     */
    public static function forSiteDocroot(array $site): ?self
    {
        $docroot = rtrim((string) ($site['docroot_override'] ?? ''), '/');

        if ($docroot === '') {
            return null;
        }

        $home = rtrim(self::siteRoot($site), '/');

        if ($docroot === $home || str_starts_with($docroot.'/', $home.'/')) {
            return null;
        }

        return new self(
            key: 'site-'.$site['id'].'-docroot',
            label: $site['name'].' — '.$site['primary_domain'].' (ไฟล์เว็บ)',
            root: $docroot,
            kind: self::KIND_SITE,
            group: 'เว็บไซต์',
            systemUser: (string) $site['system_user'],
            siteId: (int) $site['id'],
        );
    }

    /**
     * @param string $key
     * @param string $label
     * @param string $path
     * @param bool $writable
     */
    public static function forServer(string $key, string $label, string $path, bool $writable = true): self
    {
        return new self(
            key: $key,
            label: $label,
            root: $path,
            kind: self::KIND_SERVER,
            group: 'เซิร์ฟเวอร์',
            writable: $writable,
        );
    }

    /**
     * @return mixed
     */
    public function isSite(): bool
    {
        return $this->kind === self::KIND_SITE;
    }

    /** @return array<string,mixed> ข้อมูลที่ส่งไปให้หน้าเว็บ */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'root' => $this->root,
            'kind' => $this->kind,
            'group' => $this->group,
            'site_id' => $this->siteId,
            'writable' => $this->writable,
            'runs_as' => $this->systemUser ?? 'root'
        ];
    }
}
