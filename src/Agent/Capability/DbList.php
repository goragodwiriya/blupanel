<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Security\Permissions;

/**
 * รายการฐานข้อมูลพร้อมผู้ใช้และขนาดจริง — อ่านอย่างเดียว
 *
 * รวมสองแหล่งเข้าด้วยกันแล้วบอกให้ชัดว่าอะไรมาจากไหน:
 *   panel  — แถวใน databases_ ที่ panel เป็นคนสร้างและผูกกับเว็บไซต์ไว้
 *   server — ฐานข้อมูลที่มีอยู่จริงบน MariaDB
 *
 * ฐานข้อมูลที่มีบนเครื่องแต่ไม่มีใน panel จะถูกทำเครื่องหมาย "ไม่ได้จัดการโดย panel"
 * แทนที่จะถูกซ่อน — ผู้ดูแลควรเห็นความจริงทั้งหมดของเครื่อง
 */
final class DbList extends DbCapability
{
    public static function name(): string
    {
        return 'db.list';
    }

    public function permission(): string
    {
        return 'db.view';
    }

    public function isMutating(): bool
    {
        return false;
    }

    public function summary(): string
    {
        return 'อ่านรายการฐานข้อมูลและผู้ใช้';
    }

    public function validate(array $args): array
    {
        return [];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $manager = $this->manager();
        $installed = $manager->isInstalled($executor);

        $sizes = [];
        $onServer = [];
        $error = '';

        if ($installed) {
            try {
                $sizes = $this->cachedSizes($executor, $context);
                $onServer = $manager->databases($executor);
            } catch (\Throwable $e) {
                // ติดต่อ MariaDB ไม่ได้ — ยังแสดงข้อมูลจาก panel ได้ แต่ต้องบอกให้รู้
                $error = $e->getMessage();
            }
        }

        $isAdmin = in_array($context->actor->role, [Permissions::SUPERADMIN, Permissions::SYSADMIN], true);

        $where = $isAdmin ? '' : ' WHERE s.owner_user_id = :user';
        $params = $isAdmin ? [] : ['user' => $context->actor->userId];

        $rows = $context->db->all(
            'SELECT d.*, s.primary_domain, s.name AS site_name
             FROM databases_ d LEFT JOIN sites s ON s.id = d.site_id' . $where . '
             ORDER BY d.db_name',
            $params,
        );

        // ดึงผู้ใช้ทั้งหมดครั้งเดียว แล้วจัดกลุ่มตาม db_id — กัน N+1 ตอนมีฐานข้อมูลเยอะ
        $usersByDb = $this->usersByDatabase($context);

        $databases = [];
        $known = [];

        foreach ($rows as $row) {
            $name = (string) $row['db_name'];
            $dbId = (int) $row['id'];
            $known[] = $name;

            $databases[] = [
                'name' => $name,
                'site_id' => (int) ($row['site_id'] ?? 0),
                'site' => $row['primary_domain'] ?? null,
                'charset' => $row['charset'] ?? 'utf8mb4',
                'size' => $sizes[$name] ?? (int) $row['size_bytes'],
                'created_at' => (int) $row['created_at'],
                'exists_on_server' => !$installed || in_array($name, $onServer, true),
                'managed' => true,
                'users' => $usersByDb[$dbId] ?? [],
            ];
        }

        // ฐานข้อมูลที่มีบนเครื่องแต่ panel ไม่รู้จัก — แสดงให้ผู้ดูแลเห็นด้วย
        if ($isAdmin) {
            foreach ($onServer as $name) {
                if (in_array($name, $known, true)) {
                    continue;
                }

                $databases[] = [
                    'name' => $name,
                    'site_id' => 0,
                    'site' => null,
                    'charset' => '',
                    'size' => $sizes[$name] ?? 0,
                    'created_at' => 0,
                    'exists_on_server' => true,
                    'managed' => false,
                    'users' => [],
                ];
            }
        }

        usort($databases, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return [
            'databases' => $databases,
            'total' => count($databases),
            'installed' => $installed,
            'error' => $error,
            'total_size' => array_sum(array_column($databases, 'size')),
        ];
    }

    /**
     * ผู้ใช้ของทุกฐานข้อมูลในคำสั่งเดียว
     *
     * @return array<int, list<array<string,mixed>>>
     */
    private function usersByDatabase(Context $context): array
    {
        $rows = $context->db->all(
            'SELECT g.db_id, u.username, u.host, g.privileges
             FROM db_grants g JOIN db_users u ON u.id = g.db_user_id
             ORDER BY u.username',
        );

        $grouped = [];
        foreach ($rows as $row) {
            $dbId = (int) $row['db_id'];
            $grouped[$dbId][] = [
                'username' => $row['username'],
                'host' => $row['host'],
                'privileges' => $row['privileges'],
            ];
        }

        return $grouped;
    }
}
