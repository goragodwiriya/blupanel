<?php

declare(strict_types=1);

namespace Phpcp\Domain;

use Phpcp\Agent\ValidationError;
use Phpcp\Support\Validator;

/**
 * The email address of a mailbox on this machine — PLAN-MAIL Phase M1
 *
 * **A value that passes here becomes both a directory name and a line in
 * Postfix's own table**, so it has to be far narrower than what the RFC actually
 * allows · RFC 5321 permits a local part to contain characters like ! # $ % & ' * =
 * ? ^, and even spaces if quoted, none of which can be used to name a folder and
 * which would make Postfix's map file read the wrong line.
 *
 * What's accepted: letters, digits, dot, hyphen, underscore, plus — enough for
 * every address people actually use in practice, and none of it carries any
 * special meaning to the shell, a path, or the map file's format.
 */
final class MailAddress
{
    /** The local part's maximum length per RFC 5321 */
    private const MAX_LOCAL = 64;

    /**
     * Names the system reserves — can never become a customer mailbox
     *
     * `postmaster` and `abuse` must always reach the machine's own admin per RFC
     * 2142 · letting a customer claim those two names on their own domain would
     * still be acceptable, but a name that collides with a system account cannot be allowed.
     */
    private const RESERVED = ['root', 'daemon', 'mail', 'vmail', 'nobody'];

    public function __construct(
        public readonly string $localPart,
        public readonly string $domain,
    ) {
    }

    /**
     * Split and validate a full `name@domain` address
     */
    public static function parse(string $value): self
    {
        $value = strtolower(trim($value));
        $at = strrpos($value, '@');

        if ($at === false) {
            throw new ValidationError('Email address must have an @ separating the name from the domain');
        }

        return new self(
            self::assertLocalPart(substr($value, 0, $at)),
            Validator::domain(substr($value, $at + 1)),
        );
    }

    /**
     * Validate only the part before @ — used when the domain already came from elsewhere (e.g. chosen from a list)
     */
    public static function assertLocalPart(string $value): string
    {
        $value = strtolower(trim($value));

        if ($value === '') {
            throw new ValidationError('A mailbox name must be specified');
        }

        if (strlen($value) > self::MAX_LOCAL) {
            throw new ValidationError('Mailbox name is longer than ' . self::MAX_LOCAL . ' characters');
        }

        // A leading/trailing dot, or two dots in a row, would produce a directory name like `.` or `..`
        if (str_starts_with($value, '.') || str_ends_with($value, '.') || str_contains($value, '..')) {
            throw new ValidationError('Mailbox name must not start or end with a dot, or contain two dots in a row');
        }

        if (preg_match('/^[a-z0-9._+-]+$/', $value) !== 1) {
            throw new ValidationError('Mailbox name may only use a-z 0-9 . _ + and -');
        }

        if (in_array($value, self::RESERVED, true)) {
            throw new ValidationError('The name ' . $value . ' is reserved by the system');
        }

        return $value;
    }

    public function full(): string
    {
        return $this->localPart . '@' . $this->domain;
    }

    /**
     * This mailbox's Maildir path — always ends with `/`
     *
     * The trailing slash is what tells Postfix this is a **Maildir** (a folder
     * with one file per message), not mbox (a single file with every message
     * appended together) · forget the trailing slash, and every message gets
     * appended to that same single file until IMAP can no longer read any of it.
     */
    public function maildir(string $root): string
    {
        return rtrim($root, '/') . '/' . $this->domain . '/' . $this->localPart . '/';
    }
}
