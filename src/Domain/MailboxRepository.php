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

    public function deleteMailbox(int $mailboxId): void
    {
        $this->db->run('DELETE FROM mailboxes WHERE id = :id', ['id' => $mailboxId]);
    }
}
