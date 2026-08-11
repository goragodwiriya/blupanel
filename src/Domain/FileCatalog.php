<?php

declare (strict_types = 1);

namespace Phpcp\Domain;

/**
 * กติกาของตัวจัดการไฟล์: อะไรแก้ไขได้ ใหญ่ได้แค่ไหน และแสดงผลอย่างไร
 *
 * แยกออกมาจาก capability เพราะทั้งฝั่งเว็บ (เพื่อบอกผู้ใช้ล่วงหน้า) และฝั่ง agent
 * (เพื่อบังคับใช้จริง) ต้องใช้กติกาชุดเดียวกัน ถ้ามีสองชุดจะเพี้ยนกันเมื่อแก้ที่เดียว
 */
final class FileCatalog
{
    /** เพดานไฟล์ที่เปิดในตัวแก้ไขข้อความ — SECURITY §2.7 */
    public const MAX_EDIT_BYTES = 5_242_880; // 5 MB

    /**
     * เพดานไฟล์ที่อัปโหลด/ดาวน์โหลดผ่าน agent ได้ในครั้งเดียว
     *
     * Protocol::MAX_FRAME คือ 4 MB และ base64 ขยายข้อมูล 4/3 เท่า
     * เผื่อส่วนหัวของ frame ไว้ด้วยจึงตั้งไว้ที่ 2.5 MB
     */
    public const MAX_TRANSFER_BYTES = 2_621_440; // 2.5 MB

    /** นามสกุลที่เปิดในตัวแก้ไขข้อความได้ — allowlist ไม่ใช่ blocklist */
    private const EDITABLE = [
        'txt', 'md', 'markdown', 'log', 'csv', 'tsv',
        'html', 'htm', 'css', 'scss', 'sass', 'less',
        'js', 'mjs', 'cjs', 'jsx', 'ts', 'tsx', 'vue', 'svelte',
        'php', 'phtml', 'inc',
        'json', 'jsonc', 'xml', 'yml', 'yaml', 'toml', 'ini', 'env', 'conf', 'cfg',
        'sql', 'sh', 'bash', 'zsh',
        'py', 'rb', 'pl', 'lua', 'go', 'rs', 'java', 'kt', 'c', 'h', 'cpp', 'hpp', 'cs',
        'twig', 'blade', 'tpl', 'hbs', 'ejs',
        'gitignore', 'gitattributes', 'editorconfig', 'htaccess', 'lock'
    ];

    /** ชื่อไฟล์ที่ไม่มีนามสกุลแต่เป็นข้อความแน่นอน */
    private const EDITABLE_NAMES = [
        'dockerfile', 'makefile', 'procfile', 'readme', 'license', 'changelog',
        'composer.lock', 'package-lock.json', '.env', '.htaccess', '.gitignore'
    ];

    /** จับคู่นามสกุล -> ชนิดที่ใช้เลือกไอคอนและโหมดของตัวแก้ไข */
    private const KINDS = [
        'image' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'ico', 'bmp', 'avif'],
        'archive' => ['zip', 'tar', 'gz', 'tgz', 'bz2', 'xz', '7z', 'rar'],
        'document' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods'],
        'media' => ['mp3', 'mp4', 'wav', 'ogg', 'webm', 'avi', 'mkv', 'mov', 'flac'],
        'code' => ['php', 'js', 'mjs', 'ts', 'jsx', 'tsx', 'py', 'rb', 'go', 'rs', 'java', 'sh', 'sql', 'c', 'cpp'],
        'style' => ['css', 'scss', 'sass', 'less'],
        'markup' => ['html', 'htm', 'xml', 'vue', 'svelte', 'twig', 'blade'],
        'data' => ['json', 'yml', 'yaml', 'toml', 'ini', 'conf', 'env', 'csv', 'lock'],
        'text' => ['txt', 'md', 'markdown', 'log']
    ];

    /** นามสกุลของไฟล์ (ตัวพิมพ์เล็ก) — คืน '' เมื่อไม่มี */
    public static function extension(string $name): string
    {
        $at = strrpos($name, '.');
        if ($at === false || $at === 0) {
            return ''; // '.env' ถือว่าไม่มีนามสกุล ใช้ชื่อเต็มตัดสินแทน
        }

        return mb_strtolower(substr($name, $at + 1));
    }

    /** ไฟล์นี้เปิดในตัวแก้ไขข้อความได้หรือไม่ */
    public static function isEditable(string $name, int $size): bool
    {
        if ($size > self::MAX_EDIT_BYTES) {
            return false;
        }

        $lower = mb_strtolower($name);
        if (in_array($lower, self::EDITABLE_NAMES, true)) {
            return true;
        }

        $ext = self::extension($name);

        return $ext !== '' && in_array($ext, self::EDITABLE, true);
    }

    /** ชนิดของไฟล์สำหรับเลือกไอคอนบนหน้าจอ */
    public static function kind(string $name): string
    {
        $ext = self::extension($name);
        if ($ext === '') {
            return 'text';
        }

        foreach (self::KINDS as $kind => $extensions) {
            if (in_array($ext, $extensions, true)) {
                return $kind;
            }
        }

        return 'file';
    }

    /** โหมดไวยากรณ์ที่ส่งให้ตัวแก้ไขบนหน้าเว็บ */
    public static function syntax(string $name): string
    {
        return match (self::extension($name)) {
            'php', 'phtml', 'inc' => 'php',
            'js', 'mjs', 'cjs', 'jsx', 'ts', 'tsx' => 'javascript',
            'css', 'scss', 'sass', 'less' => 'css',
            'html', 'htm', 'xml', 'vue', 'svelte' => 'markup',
            'json', 'jsonc' => 'json',
            default => 'plain',
        };
    }

    /**
     * Content-Type ที่ปลอดภัยสำหรับการดาวน์โหลด
     *
     * คืน octet-stream เสมอโดยตั้งใจ: ไฟล์ในนี้เป็นของผู้ใช้ ถ้าส่ง text/html
     * ออกไปตรง ๆ เบราว์เซอร์จะรันสคริปต์ในโดเมนของแผงควบคุมทันที (stored XSS)
     */
    public static function downloadType(): string
    {
        return 'application/octet-stream';
    }

    /** สิทธิ์ตั้งต้นที่เสนอในกล่องเปลี่ยนสิทธิ์ */
    public static function suggestedModes(bool $isDir): array
    {
        return $isDir
            ? ['0755' => '0755 — อ่าน/เข้าถึงได้ทุกคน', '0750' => '0750 — เฉพาะเจ้าของและกลุ่ม', '0700' => '0700 — เฉพาะเจ้าของ']
            : ['0644' => '0644 — อ่านได้ทุกคน', '0640' => '0640 — เฉพาะเจ้าของและกลุ่ม', '0600' => '0600 — เฉพาะเจ้าของ'];
    }
}
