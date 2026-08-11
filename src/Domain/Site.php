<?php

declare (strict_types = 1);

namespace Phpcp\Domain;

use Phpcp\Kernel\Paths;
use Phpcp\Support\Validator;

/**
 * เว็บไซต์หนึ่งเว็บ พร้อมเส้นทางทั้งหมดที่อนุมานจากชื่อโดเมน
 *
 * เหตุผลที่รวมการคำนวณเส้นทางไว้ที่นี่ที่เดียว: ถ้าปล่อยให้แต่ละที่ประกอบ path เอง
 * จะเกิดกรณีที่ vhost ชี้ socket หนึ่งแต่ pool สร้างอีก socket หนึ่งโดยไม่มีใครรู้
 * จนกว่าเว็บจะขึ้น 502 — บั๊กแบบนี้หายากและเจ็บ
 *
 * ชื่อโดเมนถูก validate ก่อนสร้าง object เสมอ เส้นทางที่อนุมานออกมาจึงปลอดภัย
 */
final readonly class Site
{
    /** @param list<string> $aliases */
    public function __construct(
        public int $id,
        public string $name,
        public string $domain,
        /** เจ้าของเว็บ — เส้นทางและ uid ทั้งหมดของเว็บนี้อนุมานจากบัญชีของเขา */
        public UserAccount $owner,
        public string $phpVersion,
        public string $sslMode = 'off',
        public string $status = 'active',
        public array $aliases = [],
        public int $memoryLimitMb = 256,
        public int $uploadLimitMb = 64,
        public int $maxChildren = 5,
        /** ว่าง = ใช้ <บ้าน>/public ตามปกติ */
        public string $docrootOverride = '',
        /** โดเมนย่อย => path ปลายทาง */
        public array $subdomainPaths = [],
    ) {
    }

    /**
     * @param array<string,mixed> $row
     * @param list<string> $aliases
     */
    public static function fromRow(
        array $row,
        array $aliases = [],
        array $subdomainPaths = [],
        ?UserAccount $owner = null,
    ): self {
        return new self(
            id: (int) $row['id'],
            name: (string) $row['name'],
            domain: Validator::domain((string) $row['primary_domain']),
            owner: $owner ?? UserAccount::fromRow([
                'id' => $row['owner_user_id'] ?? 0,
                // แถวของ sites ที่ join users มาแล้วจะมีคีย์พวกนี้ · ถ้าไม่มี UserAccount
                // จะโยนทิ้งทันทีแทนที่จะสร้างเส้นทางอย่าง /srv/phpcp/users//domains/…
                // ซึ่งผิดแต่ไม่มีอะไรฟ้อง — เคยหลุดไปโผล่บน API จริงมาแล้ว
                'system_user' => $row['owner_system_user'] ?? null,
                'username' => $row['owner_username'] ?? '',
            ]),
            phpVersion: Validator::phpVersion((string) $row['php_version']),
            sslMode: (string) ($row['ssl_mode'] ?? 'off'),
            status: (string) ($row['status'] ?? 'active'),
            aliases: array_map(Validator::wildcardDomain(...), $aliases),
            docrootOverride: (string) ($row['docroot_override'] ?? ''),
            subdomainPaths: $subdomainPaths,
        );
    }

    /**
     * บัญชีระบบที่เว็บนี้รันด้วย — เป็นของ**เจ้าของ** ไม่ใช่ของเว็บ
     *
     * เดิมเป็น `web_<siteId>` หนึ่งบัญชีต่อหนึ่งเว็บ · ตั้งแต่ migration 0006 เว็บทุกแห่ง
     * ของผู้ใช้คนเดียวกันใช้บัญชีเดียวกัน ซึ่งเป็นเหตุผลที่คอลัมน์ `sites.system_user`
     * ถูกลบทิ้ง (ข้อจำกัด UNIQUE ของมันขัดกับความจริงใหม่นี้โดยตรง)
     */
    public function systemUser(): string
    {
        return $this->owner->username;
    }

    /** บ้านของเว็บไซต์ — log, tmp, backup อยู่ใต้เส้นทางนี้เสมอ แม้ docroot จะชี้ออกไปที่อื่น */
    public function root(): string
    {
        return $this->owner->siteRoot($this->domain);
    }

    /**
     * ไดเรกทอรีที่เว็บเซิร์ฟเวอร์เสิร์ฟจริง
     *
     * ปกติคือ <บ้าน>/public — ตั้ง docrootOverride ได้เมื่อต้องการชี้โดเมนไปที่
     * โฟลเดอร์ที่มีอยู่ก่อนแล้ว (Domain Pointer) เส้นทางที่ชี้ได้ถูกจำกัดด้วย
     * Config::docrootRoots() ตอนสร้าง/แก้ไขเสมอ
     */
    public function docroot(): string
    {
        return $this->docrootOverride !== ''
            ? $this->docrootOverride
            : $this->root().'/public';
    }

    /**
     * @return mixed
     */
    public function tmpDir(): string
    {
        return $this->root().'/tmp';
    }

    /** ที่พักไฟล์ชั่วคราวของ pool ซึ่งใช้ร่วมกับเว็บอื่นของเจ้าของคนเดียวกัน */
    public function poolTmpDir(): string
    {
        return $this->owner->tmpDir();
    }

    /**
     * @return mixed
     */
    public function logDir(): string
    {
        return $this->root().'/logs';
    }

    /**
     * @return mixed
     */
    public function backupDir(): string
    {
        return $this->root().'/backups';
    }

    /**
     * @return mixed
     */
    public function errorLog(): string
    {
        return $this->logDir().'/error.log';
    }

    /**
     * @return mixed
     */
    public function accessLog(): string
    {
        return $this->logDir().'/access.log';
    }

    /**
     * @return mixed
     */
    public function phpErrorLog(): string
    {
        return $this->owner->phpErrorLog($this->phpVersion);
    }

    /**
     * @return mixed
     */
    public function slowLog(): string
    {
        return $this->owner->phpSlowLog($this->phpVersion);
    }

    /**
     * @return mixed
     */
    public function suspendedPage(): string
    {
        return $this->root().'/__suspended.html';
    }

    /**
     * socket ของ FPM pool — หนึ่งตัวต่อ (เจ้าของ × เวอร์ชัน PHP)
     *
     * เว็บของเจ้าของคนเดียวกันที่ใช้ PHP เวอร์ชันเดียวกันจึงใช้ socket และ pool ร่วมกัน
     * ผลคือลูกค้าที่มี 5 เว็บบน PHP 8.4 ใช้ pool เดียว ไม่ใช่ 5 pool
     */
    public function fpmSocket(): string
    {
        return $this->owner->fpmSocket($this->phpVersion);
    }

    public function fpmPoolFile(): string
    {
        return $this->owner->fpmPoolFile($this->phpVersion);
    }

    /**
     * @param string $phpVersion
     */
    public function fpmPoolFileFor(string $phpVersion): string
    {
        return $this->owner->fpmPoolFile(Validator::phpVersion($phpVersion));
    }

    public function fpmUnit(): string
    {
        return ServiceCatalog::fpmUnit($this->phpVersion);
    }

    /**
     * @return mixed
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** ทุกโดเมนที่ vhost นี้ต้องรับผิดชอบ */
    public function allDomains(): array
    {
        return array_values(array_unique([$this->domain, ...$this->aliases]));
    }

    /**
     * เว็บนี้รับ subdomain แบบ wildcard หรือไม่ (PLAN-V2 เฟส E7)
     *
     * มีผลสองอย่าง: ต้องขอใบรับรองด้วย DNS-01 (HTTP-01 ใช้กับ wildcard ไม่ได้)
     * และ vhost ต้องถูกอ่าน**ท้ายสุด** ไม่งั้นมันจะกลืนคำขอของ subdomain
     * ที่เว็บอื่นระบุชื่อเต็มไว้แล้ว (ดู `ApacheDriver::vhostPath()`)
     */
    public function hasWildcard(): bool
    {
        foreach ($this->aliases as $alias) {
            if (str_starts_with($alias, '*.')) {
                return true;
            }
        }

        return false;
    }

    /** เว็บนี้ยังเป็นเว็บเดียวของเจ้าของที่ใช้ PHP เวอร์ชันนี้อยู่หรือไม่ */
    public function sharesPoolWith(self $other): bool
    {
        return $this->owner->username === $other->owner->username
            && $this->phpVersion === $other->phpVersion;
    }

    public function withPhpVersion(string $phpVersion): self
    {
        return new self(
            id: $this->id,
            name: $this->name,
            domain: $this->domain,
            owner: $this->owner,
            phpVersion: Validator::phpVersion($phpVersion),
            sslMode: $this->sslMode,
            status: $this->status,
            aliases: $this->aliases,
            memoryLimitMb: $this->memoryLimitMb,
            uploadLimitMb: $this->uploadLimitMb,
            maxChildren: $this->maxChildren,
            docrootOverride: $this->docrootOverride,
            subdomainPaths: $this->subdomainPaths,
        );
    }

    /**
     * @param string $sslMode
     */
    public function withSslMode(string $sslMode): self
    {
        return new self(
            id: $this->id,
            name: $this->name,
            domain: $this->domain,
            owner: $this->owner,
            phpVersion: $this->phpVersion,
            sslMode: self::assertSslMode($sslMode),
            status: $this->status,
            aliases: $this->aliases,
            memoryLimitMb: $this->memoryLimitMb,
            uploadLimitMb: $this->uploadLimitMb,
            maxChildren: $this->maxChildren,
            docrootOverride: $this->docrootOverride,
            subdomainPaths: $this->subdomainPaths,
        );
    }

    /** ค่าเดียวกับ CHECK constraint ของคอลัมน์ ssl_mode ในฐานข้อมูล */
    public static function assertSslMode(string $mode): string
    {
        if (!in_array($mode, ['off', 'on', 'forced'], true)) {
            throw new \Phpcp\Agent\ValidationError('โหมด SSL ต้องเป็น off, on หรือ forced');
        }

        return $mode;
    }

    /**
     * @return mixed
     */
    public function usesSsl(): bool
    {
        return $this->sslMode !== 'off';
    }

    /**
     * @param string $status
     */
    public function withStatus(string $status): self
    {
        return new self(
            id: $this->id,
            name: $this->name,
            domain: $this->domain,
            owner: $this->owner,
            phpVersion: $this->phpVersion,
            sslMode: $this->sslMode,
            status: $status,
            aliases: $this->aliases,
            memoryLimitMb: $this->memoryLimitMb,
            uploadLimitMb: $this->uploadLimitMb,
            maxChildren: $this->maxChildren,
            docrootOverride: $this->docrootOverride,
            subdomainPaths: $this->subdomainPaths,
        );
    }
}
