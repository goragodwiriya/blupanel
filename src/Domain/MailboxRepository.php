<?php

declare(strict_types=1);

namespace Phpcp\Domain;

use Phpcp\Driver\Mail\MailboxManager;
use Phpcp\Kernel\Db;

/**
 * กล่องจดหมายและที่อยู่ส่งต่อ — PLAN-MAIL เฟส M1
 *
 * **หน้าที่หลักคือประกอบ "ภาพรวมทั้งเครื่อง" ให้ตัวเขียนไฟล์** ไม่ใช่แค่ CRUD ·
 * ตารางค้นหาของ Postfix ถูกเขียนใหม่ทั้งไฟล์ทุกครั้ง ผู้เขียนจึงต้องได้รายการที่
 * ครบทั้งเครื่องเสมอ ไม่ใช่เฉพาะสิ่งที่เพิ่งเปลี่ยน — ไม่งั้นกล่องที่ถูกลบไปแล้ว
 * จะยังรับเมลต่อได้เพราะยังค้างอยู่ในไฟล์
 */
final class MailboxRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /**
     * โดเมนทั้งหมดที่เปิดเมลไว้
     *
     * @return list<string>
     */
    public function enabledDomains(): array
    {
        $rows = $this->db->all('SELECT domain FROM domains WHERE mail_enabled = 1 ORDER BY domain');

        return array_map(static fn (array $row): string => (string) $row['domain'], $rows);
    }

    /**
     * กล่องทั้งหมดในรูปที่ตัวเขียนไฟล์ใช้ได้ทันที
     *
     * กล่องของโดเมนที่ถูกปิดเมลไปแล้วต้องไม่ติดมาด้วย — แถวยังอยู่ในฐานข้อมูล
     * (เผื่อเปิดกลับ) แต่ต้องไม่มีผลกับเครื่องระหว่างที่ปิดอยู่
     *
     * @return list<array{address:string,maildir:string,password:string,quota_mb:int}>
     */
    public function activeMailboxes(): array
    {
        $rows = $this->db->all(
            'SELECT m.local_part, m.password_hash, m.quota_mb, d.domain
               FROM mailboxes m
               JOIN domains d ON d.id = m.domain_id
              WHERE m.enabled = 1 AND d.mail_enabled = 1
              ORDER BY d.domain, m.local_part',
        );

        return array_map(
            static function (array $row): array {
                $address = new MailAddress((string) $row['local_part'], (string) $row['domain']);

                return [
                    'address' => $address->full(),
                    'maildir' => $address->maildir(MailboxManager::MAIL_ROOT),
                    'password' => (string) $row['password_hash'],
                    'quota_mb' => (int) $row['quota_mb'],
                ];
            },
            $rows,
        );
    }

    /**
     * ที่อยู่ส่งต่อทั้งหมด — `source` ว่างคือ catch-all ของโดเมนนั้น
     *
     * @return list<array{source:string,destination:string}>
     */
    public function activeAliases(): array
    {
        $rows = $this->db->all(
            'SELECT a.source, a.destination, d.domain
               FROM mail_aliases a
               JOIN domains d ON d.id = a.domain_id
              WHERE d.mail_enabled = 1
              ORDER BY d.domain, a.source',
        );

        return array_map(
            static fn (array $row): array => [
                // Postfix เขียน catch-all เป็น `@โดเมน` ไม่ใช่ `*@โดเมน`
                'source' => ((string) $row['source'] === '' ? '' : (string) $row['source'])
                    . '@' . (string) $row['domain'],
                'destination' => (string) $row['destination'],
            ],
            $rows,
        );
    }

    /** @return array<string,mixed>|null */
    public function findDomain(string $domain): ?array
    {
        $row = $this->db->first('SELECT * FROM domains WHERE domain = :d', ['d' => strtolower(trim($domain))]);

        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    public function findMailbox(int $domainId, string $localPart): ?array
    {
        $row = $this->db->first(
            'SELECT * FROM mailboxes WHERE domain_id = :d AND local_part = :l',
            ['d' => $domainId, 'l' => $localPart],
        );

        return is_array($row) ? $row : null;
    }

    /** จำนวนกล่องที่บัญชีนี้เป็นเจ้าของ — ใช้กับโควตา `quota_emails` */
    public function countOwnedBy(int $userId): int
    {
        return (int) $this->db->value(
            'SELECT count(*)
               FROM mailboxes m
               JOIN domains d ON d.id = m.domain_id
               JOIN sites s   ON s.id = d.site_id
              WHERE s.owner_user_id = :u',
            ['u' => $userId],
            0,
        );
    }

    public function setDomainMail(int $domainId, bool $enabled): void
    {
        $this->db->update('domains', ['mail_enabled' => $enabled ? 1 : 0], ['id' => $domainId]);
    }

    public function createMailbox(int $domainId, string $localPart, string $passwordHash, int $quotaMb): int
    {
        return $this->db->insert('mailboxes', [
            'domain_id' => $domainId,
            'local_part' => $localPart,
            'password_hash' => $passwordHash,
            'quota_mb' => $quotaMb,
            'enabled' => 1,
            'created_at' => time(),
        ]);
    }

    public function setPassword(int $mailboxId, string $passwordHash): void
    {
        $this->db->update('mailboxes', ['password_hash' => $passwordHash], ['id' => $mailboxId]);
    }

    public function setQuota(int $mailboxId, int $quotaMb): void
    {
        $this->db->update('mailboxes', ['quota_mb' => $quotaMb], ['id' => $mailboxId]);
    }

    /** @return array<string,mixed>|null */
    public function findAlias(int $id): ?array
    {
        $row = $this->db->first(
            'SELECT a.*, d.domain FROM mail_aliases a JOIN domains d ON d.id = a.domain_id WHERE a.id = :id',
            ['id' => $id],
        );

        return is_array($row) ? $row : null;
    }

    /**
     * ตั้งที่อยู่ส่งต่อ — ชื่อเดิมของโดเมนเดียวกันคือการแก้ ไม่ใช่การเพิ่มซ้ำ
     *
     * ตาราง unique ที่ (domain_id, source) อยู่แล้ว การ insert ซ้ำจะล้ม · ที่นี่จึง
     * อัปเดตแทน เพื่อให้ฟอร์มเดียวใช้ได้ทั้งเพิ่มและแก้ เหมือนฟอร์มอื่นในระบบ
     */
    public function setAlias(int $domainId, string $source, string $destination): int
    {
        $existing = $this->db->first(
            'SELECT id FROM mail_aliases WHERE domain_id = :d AND source = :s',
            ['d' => $domainId, 's' => $source],
        );

        if (is_array($existing)) {
            $this->db->update('mail_aliases', ['destination' => $destination], ['id' => (int) $existing['id']]);

            return (int) $existing['id'];
        }

        return $this->db->insert('mail_aliases', [
            'domain_id' => $domainId,
            'source' => $source,
            'destination' => $destination,
            'created_at' => time(),
        ]);
    }

    public function deleteAlias(int $id): void
    {
        $this->db->run('DELETE FROM mail_aliases WHERE id = :id', ['id' => $id]);
    }

    /**
     * กล่องและที่อยู่ส่งต่อทั้งหมดของเจ้าของคนหนึ่ง — ใช้แสดงบนหน้าเว็บ
     *
     * `$ownerUserId` เป็น 0 = ผู้ดูแลระบบ เห็นของทุกคน · ค่าอื่น = เห็นเฉพาะของตัวเอง
     * การกรองทำที่ query ไม่ใช่กรองหลังดึงมาแล้ว — กล่องของลูกค้ารายอื่นต้องไม่ถูก
     * อ่านขึ้นมาในหน่วยความจำตั้งแต่แรก
     *
     * @return list<array<string,mixed>>
     */
    public function listMailboxes(int $ownerUserId = 0): array
    {
        $sql = 'SELECT m.id, m.local_part, m.quota_mb, m.enabled, m.created_at, d.domain, d.id AS domain_id
                  FROM mailboxes m
                  JOIN domains d ON d.id = m.domain_id
                  JOIN sites s   ON s.id = d.site_id';
        $params = [];

        if ($ownerUserId > 0) {
            $sql .= ' WHERE s.owner_user_id = :o';
            $params['o'] = $ownerUserId;
        }

        return $this->db->all($sql . ' ORDER BY d.domain, m.local_part', $params);
    }

    /** @return list<array<string,mixed>> */
    public function listAliases(int $ownerUserId = 0): array
    {
        $sql = 'SELECT a.id, a.source, a.destination, a.created_at, d.domain
                  FROM mail_aliases a
                  JOIN domains d ON d.id = a.domain_id
                  JOIN sites s   ON s.id = d.site_id';
        $params = [];

        if ($ownerUserId > 0) {
            $sql .= ' WHERE s.owner_user_id = :o';
            $params['o'] = $ownerUserId;
        }

        return $this->db->all($sql . ' ORDER BY d.domain, a.source', $params);
    }

    /**
     * โดเมนที่เปิดเมลแล้วและผู้เรียกเป็นเจ้าของ — ใช้เติมตัวเลือกในฟอร์ม
     *
     * @return list<array<string,mixed>>
     */
    public function selectableDomains(int $ownerUserId = 0): array
    {
        $sql = 'SELECT d.id, d.domain
                  FROM domains d
                  JOIN sites s ON s.id = d.site_id
                 WHERE d.mail_enabled = 1';
        $params = [];

        if ($ownerUserId > 0) {
            $sql .= ' AND s.owner_user_id = :o';
            $params['o'] = $ownerUserId;
        }

        return $this->db->all($sql . ' ORDER BY d.domain', $params);
    }

    /** เจ้าของโดเมนนี้คือใคร — ใช้ตรวจสิทธิ์ก่อนแตะกล่องของโดเมน */
    public function ownerOf(int $domainId): int
    {
        return (int) $this->db->value(
            'SELECT s.owner_user_id FROM domains d JOIN sites s ON s.id = d.site_id WHERE d.id = :d',
            ['d' => $domainId],
            0,
        );
    }

    public function deleteMailbox(int $mailboxId): void
    {
        $this->db->run('DELETE FROM mailboxes WHERE id = :id', ['id' => $mailboxId]);
    }
}
