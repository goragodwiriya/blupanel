<?php

declare (strict_types = 1);

namespace Phpcp\Domain;

use Phpcp\Security\Permissions;

/**
 * The file manager's rules: what can be edited, how large it can be, and how it's displayed
 *
 * Split out from the capability because both the web side (to tell the user in
 * advance) and the agent side (to actually enforce it) must use the exact same
 * rules — two separate copies would drift apart the moment only one gets edited.
 */
final class FileCatalog
{
    /** Ceiling for a file opened in the text editor — SECURITY §2.7 */
    public const MAX_EDIT_BYTES = 5_242_880; // 5 MB

    /**
     * Ceiling for a file uploaded/downloaded through the agent in a single transfer
     *
     * Protocol::MAX_FRAME is 4 MB, and base64 expands data by 4/3 · leaving room
     * for the frame's own header, this is set to 2.5 MB.
     */
    public const MAX_TRANSFER_BYTES = 2_621_440; // 2.5 MB

    /** Extensions that can be opened in the text editor — an allowlist, not a blocklist */
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

    /** Filenames with no extension that are still definitely text */
    private const EDITABLE_NAMES = [
        'dockerfile', 'makefile', 'procfile', 'readme', 'license', 'changelog',
        'composer.lock', 'package-lock.json', '.env', '.htaccess', '.gitignore'
    ];

    /** Maps extension -> kind, used to pick an icon and the editor's mode */
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

    /** A file's extension (lowercase) — returns '' when there isn't one */
    public static function extension(string $name): string
    {
        $at = strrpos($name, '.');
        if ($at === false || $at === 0) {
            return ''; // '.env' counts as having no extension — judged by the full name instead
        }

        return mb_strtolower(substr($name, $at + 1));
    }

    /** Whether this file can be opened in the text editor */
    public static function isEditable(string $name, int $size, ?string $role = null): bool
    {
        if ($size > self::MAX_EDIT_BYTES) {
            return false;
        }

        if ($role !== null && in_array($role, [Permissions::SUPERADMIN, Permissions::SYSADMIN], true)) {
            return true;
        }

        $lower = mb_strtolower($name);
        if (in_array($lower, self::EDITABLE_NAMES, true)) {
            return true;
        }

        $ext = self::extension($name);

        return $ext !== '' && in_array($ext, self::EDITABLE, true);
    }

    /** A file's kind, used to pick an icon on screen */
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

    /** The syntax mode sent to the editor on the web page */
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
     * A safe Content-Type for downloads
     *
     * Deliberately always returns octet-stream: files in here belong to the user
     * — sending text/html directly would make the browser run a script under the
     * control panel's own domain immediately (stored XSS).
     */
    public static function downloadType(): string
    {
        return 'application/octet-stream';
    }

    /** The default permissions offered in the chmod dialog */
    public static function suggestedModes(bool $isDir): array
    {
        return $isDir
            ? ['0755' => '0755 — everyone can read/access', '0750' => '0750 — owner and group only', '0700' => '0700 — owner only']
            : ['0644' => '0644 — everyone can read', '0640' => '0640 — owner and group only', '0600' => '0600 — owner only'];
    }
}
