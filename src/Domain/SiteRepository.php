<?php

declare (strict_types = 1);

namespace Phpcp\Domain;

use Phpcp\Kernel\Db;

/**
 * เว็บไซต์ในฐานข้อมูลของ panel
 *
 * แถวในตารางนี้คือ "ความจริงที่ panel รู้" ส่วนไฟล์ vhost กับ pool คือ "ความจริงบนเครื่อง"
 * สองอย่างนี้ต้องตรงกันเสมอ ทุกคำสั่งที่แก้อย่างหนึ่งจึงต้องแก้อีกอย่างในทรานแซกชันเดียวกัน
 */
final class SiteRepository
{
    /**
     * @param Db $db
     */
    public function __construct(private readonly Db $db)
    {
    }

    /** @return array<string,mixed>|null */
    /**
     * แถวของเว็บพร้อมชื่อบัญชีระบบของเจ้าของเสมอ
     *
     * ตั้งแต่ migration 0006 แถว sites เพียว ๆ ประกอบเส้นทางไฟล์ไม่ได้เลย เพราะเส้นทาง
     * ทุกอย่างอนุมานจากบ้านของเจ้าของ · การ join จึงเป็นส่วนหนึ่งของ "โหลดเว็บหนึ่งแห่ง"
     * ไม่ใช่ทางเลือกเสริม — เคยลืม join แล้วได้ docroot เป็น /srv/phpcp/users//domains/…
     * โผล่ออก API จริง โดยไม่มีอะไรฟ้อง
     *
     * owner_user_id เป็น NOT NULL และมี FK อยู่แล้ว การ join จึงไม่ทำให้แถวหายไปไหน
     */
    private const WITH_OWNER = 'SELECT s.*, u.username AS owner_username, u.system_user AS owner_system_user,
                    u.site_layout AS owner_site_layout, u.main_domain AS owner_main_domain
             FROM sites s JOIN users u ON u.id = s.owner_user_id';

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->first(self::WITH_OWNER.' WHERE s.id = :id', ['id' => $id]);
    }

    /** @return array<string,mixed>|null */
    public function findByDomain(string $domain): ?array
    {
        return $this->db->first(self::WITH_OWNER.' WHERE s.primary_domain = :d', ['d' => $domain]);
    }

    /**
     * แถวของเว็บพร้อมชื่อบัญชีระบบของเจ้าของ
     *
     * เส้นทางทุกอย่างของเว็บอนุมานจากชื่อเจ้าของ การโหลดเว็บโดยไม่รู้เจ้าของจึงประกอบ
     * เส้นทางไม่ได้เลย — ใช้ query นี้ทุกครั้งที่จะสร้าง Site เป็น value object
     *
     * @return array<string,mixed>|null
     */
    public function findWithOwner(int $id): ?array
    {
        return $this->find($id);
    }

    /**
     * โหลดเป็น value object พร้อม alias — ใช้เวลาจะสร้าง vhost หรือ pool
     */
    public function load(int $id): ?Site
    {
        $row = $this->findWithOwner($id);

        if ($row === null) {
            return null;
        }

        /*
         * **ห้ามประกอบ UserAccount เองที่นี่** — ปล่อยให้ Site::fromRow อ่านจากแถวเดียวกัน
         *
         * เดิมที่นี่เรียก `new UserAccount($id, $username)` ด้วยสองอาร์กิวเมนต์ ซึ่งทิ้ง
         * เลย์เอาต์กับโดเมนหลักไปเงียบ ๆ · ผลคือ `load()` คืน Site ที่เจ้าของถูกมองเป็น
         * เลย์เอาต์เริ่มต้นเสมอ แม้ฐานข้อมูลจะบอกว่า cpanel — เส้นทางที่ได้จึงเป็นของ
         * เลย์เอาต์ผิด ทั้งที่ทุกอย่างอื่นในระบบเห็นถูก
         *
         * เจอบนเซิร์ฟเวอร์จริงตอนทดสอบการย้ายเลย์เอาต์: ไฟล์ไม่ถูกย้าย vhost ชี้ไปที่
         * ไดเรกทอรีเปล่า แล้วเว็บ 404 ทั้งที่ทุกขั้นตอนรายงานว่าสำเร็จ
         */
        return Site::fromRow($row, $this->aliasesOf($id), $this->subdomainPathsOf($id));
    }

    public function subdomainPathsOf(int $siteId): array
    {
        $rows = $this->db->all(
            "SELECT domain, redirect_target FROM domains WHERE site_id = :id AND type = 'subdomain' AND redirect_target IS NOT NULL AND redirect_target != ''",
            ['id' => $siteId],
        );

        $paths = [];
        foreach ($rows as $row) {
            $paths[$row['domain']] = $row['redirect_target'];
        }

        return $paths;
    }

    /** @return list<string> */
    public function aliasesOf(int $siteId): array
    {
        $rows = $this->db->all(
            // wildcard รวมอยู่ด้วยเพราะต้องไปโผล่ใน ServerAlias และในใบรับรอง
            // เหมือน alias ทุกประการ — ต่างกันแค่วิธีพิสูจน์ตอนขอใบ (DNS-01)
            "SELECT domain FROM domains WHERE site_id = :id AND type IN ('alias','subdomain','wildcard') ORDER BY domain",
            ['id' => $siteId],
        );

        return array_column($rows, 'domain');
    }

    /**
     * รายการเว็บไซต์พร้อมตัวเลขที่หน้าจอต้องใช้ — query เดียว ไม่มี N+1
     *
     * @return list<array<string,mixed>>
     */
    public function listWithCounts(?int $ownerId = null): array
    {
        $where = $ownerId === null ? '' : ' WHERE s.owner_user_id = :owner';
        $params = $ownerId === null ? [] : ['owner' => $ownerId];

        // ต้อง join users มาด้วยเสมอ — เส้นทางทุกอย่างของเว็บอนุมานจากบ้านของเจ้าของ
        // ถ้าไม่มีชื่อเจ้าของมาด้วย Site จะประกอบเส้นทางออกมาเป็น /srv/phpcp/users//domains/…
        // ซึ่งเป็นเส้นทางที่ผิดแต่ไม่มีอะไรฟ้อง (เคยหลุดไปโผล่บน API จริงมาแล้ว)
        return $this->db->all(
            'SELECT s.*,
                    u.username     AS owner_username,
                    u.system_user  AS owner_system_user,
                    u.site_layout  AS owner_site_layout,
                    u.main_domain  AS owner_main_domain,
                    (SELECT count(*) FROM domains d WHERE d.site_id = s.id)      AS domain_count,
                    (SELECT count(*) FROM databases_ b WHERE b.site_id = s.id)   AS database_count,
                    (SELECT count(*) FROM cron_jobs c WHERE c.site_id = s.id)    AS cron_count,
                    (SELECT c.status FROM certificates c WHERE c.domain = s.primary_domain) AS cert_status,
                    (SELECT c.not_after FROM certificates c WHERE c.domain = s.primary_domain) AS cert_expires
             FROM sites s
             JOIN users u ON u.id = s.owner_user_id'.$where.' ORDER BY s.primary_domain',
            $params,
        );
    }

    /**
     * A short list of websites for building choices — id, domain, owner name
     *
     * Much lighter than {@see self::listWithCounts()} because it has no subqueries at
     * all — use it where a page only needs "what sites exist, whose are they", and
     * needs it often, such as the list of log sources rebuilt on every single log read.
     *
     * Sorted by owner before domain, so one customer's sites sit next to each other.
     *
     * @param int|null $ownerId null = every owner
     * @return list<array{id:int,domain:string,owner:string}>
     */
    public function listBrief(?int $ownerId = null): array
    {
        $where = $ownerId === null ? '' : ' WHERE s.owner_user_id = :owner';
        $params = $ownerId === null ? [] : ['owner' => $ownerId];

        $rows = $this->db->all(
            'SELECT s.id, s.primary_domain, u.username AS owner_username
             FROM sites s JOIN users u ON u.id = s.owner_user_id'.$where.'
             ORDER BY u.username, s.primary_domain',
            $params,
        );

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'domain' => (string) $row['primary_domain'],
            'owner' => (string) $row['owner_username'],
        ], $rows);
    }

    /**
     * @param string $domain
     * @return mixed
     */
    public function domainExists(string $domain): bool
    {
        $inSites = (int) $this->db->value('SELECT count(*) FROM sites WHERE primary_domain = :d', ['d' => $domain], 0);
        $inDomains = (int) $this->db->value('SELECT count(*) FROM domains WHERE domain = :d', ['d' => $domain], 0);

        return $inSites + $inDomains > 0;
    }

    /**
     * จองแถวไว้ก่อนเพื่อให้ได้ id สำหรับตั้งชื่อผู้ใช้ระบบ (web_<id>)
     *
     * ต้องทำก่อนงานฝั่งระบบปฏิบัติการ เพราะชื่อผู้ใช้ต้องไม่ซ้ำและต้องคาดเดาได้
     * ถ้างานฝั่งระบบล้ม ผู้เรียกมีหน้าที่ลบแถวนี้ทิ้ง
     */
    public function reserve(
        string $domain,
        string $phpVersion,
        int $ownerId,
        string $docrootOverride = '',
    ): int {
        $now = time();

        return $this->db->insert('sites', [
            'primary_domain' => $domain,
            'docroot' => '',
            'docroot_override' => $docrootOverride,
            'php_version' => $phpVersion,
            'status' => 'active',
            'owner_user_id' => $ownerId,
            'created_at' => $now,
            'updated_at' => $now
        ]);
    }

    /**
     * @param Site $site
     * @param int $uid
     * @param int $gid
     */
    /** จำนวนเว็บที่ผู้ใช้คนนี้เป็นเจ้าของอยู่ — ใช้ตัดสินว่าจะคืนบัญชีระบบได้หรือยัง */
    public function countOwnedBy(int $userId): int
    {
        return (int) $this->db->value(
            'SELECT count(*) FROM sites WHERE owner_user_id = :u',
            ['u' => $userId],
            0,
        );
    }

    /**
     * Domain Pointer ของทุกเว็บที่ใช้ pool เดียวกัน (เจ้าของ + เวอร์ชัน PHP เดียวกัน)
     *
     * pool ใช้ร่วมกันทั้งบัญชี open_basedir จึงต้องครอบโฟลเดอร์นอกบ้านของทุกเว็บที่ใช้ pool นั้น
     * ไม่ใช่แค่ของเว็บที่กำลังแก้อยู่ ไม่งั้นการแก้เว็บหนึ่งจะไปตัดสิทธิ์การอ่านของอีกเว็บ
     *
     * @return list<string>
     */
    public function pointerDocrootsOwnedBy(int $userId, string $phpVersion): array
    {
        $rows = $this->db->all(
            "SELECT DISTINCT docroot_override FROM sites
             WHERE owner_user_id = :u AND php_version = :v AND docroot_override <> ''",
            ['u' => $userId, 'v' => $phpVersion],
        );

        return array_map(strval(...), array_column($rows, 'docroot_override'));
    }

    /**
     * เวอร์ชัน PHP ที่ผู้ใช้คนนี้ใช้อยู่จริง
     *
     * ใช้ตัดสินว่าไฟล์ FPM pool ของเวอร์ชันไหนยังต้องอยู่ต่อ — pool ใช้ร่วมกันทั้งบัญชี
     * การลบไฟล์ของเวอร์ชันที่ยังมีเว็บใช้อยู่ = เว็บพี่น้องดับทันที
     *
     * @return list<string>
     */
    public function phpVersionsOwnedBy(int $userId, int $exceptSiteId = 0): array
    {
        $rows = $this->db->all(
            'SELECT DISTINCT php_version FROM sites WHERE owner_user_id = :u AND id <> :except',
            ['u' => $userId, 'except' => $exceptSiteId],
        );

        return array_map(strval(...), array_column($rows, 'php_version'));
    }

    /**
     * เว็บทุกแห่งของผู้ใช้ที่ใช้ PHP เวอร์ชันนี้ — ใช้เขียนไฟล์ FPM pool ที่ใช้ร่วมกัน
     *
     * @return list<int>
     */
    public function idsOwnedBy(int $userId, ?string $phpVersion = null): array
    {
        $sql = 'SELECT id FROM sites WHERE owner_user_id = :u';
        $params = ['u' => $userId];

        if ($phpVersion !== null) {
            $sql .= ' AND php_version = :v';
            $params['v'] = $phpVersion;
        }

        return array_map(intval(...), array_column($this->db->all($sql.' ORDER BY id', $params), 'id'));
    }

    /**
     * บันทึกเส้นทางจริงหลัง provision เสร็จ
     *
     * uid/gid ไม่ได้อยู่ที่เว็บอีกแล้ว — ย้ายไปอยู่กับผู้ใช้ตั้งแต่ migration 0006
     * เพราะบัญชีระบบหนึ่งบัญชีรับหลายเว็บของเจ้าของคนเดียวกัน
     */
    public function completeProvisioning(Site $site): void
    {
        $this->db->update('sites', [
            'docroot' => $site->docroot(),
            'updated_at' => time()
        ], ['id' => $site->id]);
    }

    /**
     * @param int $siteId
     * @param string $version
     */
    public function setPhpVersion(int $siteId, string $version): void
    {
        $this->db->update('sites', ['php_version' => $version, 'updated_at' => time()], ['id' => $siteId]);
    }

    /**
     * @param int $siteId
     * @param string $mode
     */
    public function setSslMode(int $siteId, string $mode): void
    {
        $this->db->update(
            'sites',
            ['ssl_mode' => Site::assertSslMode($mode), 'updated_at' => time()],
            ['id' => $siteId],
        );
    }

    /**
     * @param int $siteId
     * @param string $status
     */
    public function setStatus(int $siteId, string $status): void
    {
        $this->db->update('sites', ['status' => $status, 'updated_at' => time()], ['id' => $siteId]);
    }

    /**
     * @param int $siteId
     */
    public function delete(int $siteId): void
    {
        $this->db->run('DELETE FROM sites WHERE id = :id', ['id' => $siteId]);
    }

    /** @return array<string,int> เวอร์ชัน PHP => จำนวนเว็บไซต์ที่ใช้ */
    public function countByPhpVersion(): array
    {
        $rows = $this->db->all('SELECT php_version, count(*) AS n FROM sites GROUP BY php_version');

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['php_version']] = (int) $row['n'];
        }

        return $out;
    }

    /**
     * @param int $siteId
     * @param int $userId
     */
    public function isOwnedBy(int $siteId, int $userId): bool
    {
        return (int) $this->db->value(
            'SELECT count(*) FROM sites WHERE id = :id AND owner_user_id = :u',
            ['id' => $siteId, 'u' => $userId],
            0,
        ) > 0;
    }
}
