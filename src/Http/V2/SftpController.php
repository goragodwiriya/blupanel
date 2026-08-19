<?php

declare(strict_types=1);

namespace Phpcp\Http\V2;

use Phpcp\Domain\UserRepository;
use Phpcp\Http\ApiController;
use Phpcp\Http\ApiProblem;
use Phpcp\Kernel\Request;
use Phpcp\Kernel\Response;
use Phpcp\Security\Permissions;

/**
 * The SFTP screen — one page shaped by role (PLAN-V2 phase E4)
 *
 * A website admin gets their own account's state and a password form; a server
 * admin gets the same thing **plus** every account in one table — the same
 * "one resource, shaped by permission" idea the users page has had since phase
 * M, so there is exactly one menu entry and nobody has to remember which page
 * hides behind which role.
 *
 * The admin write side stays on the users resource (`/api/v2/users/{id}/sftp`),
 * where the audit trail for admin actions already lives — the only command
 * issued here is the owner's own password change, whose user id comes from the
 * session, never from the request.
 */
final class SftpController extends ApiController
{
    /**
     * The page's own data — the viewer's account, which only exists for a
     * hosting account (admins have no SFTP login of their own)
     */
    public function index(Request $request): Response
    {
        $users = new UserRepository($this->app->db());
        $viewer = $users->find($this->ctx->userId());

        if ($viewer === null) {
            return $this->problem(ApiProblem::NotFound, 'User not found');
        }

        return $this->ok([
            'own' => $viewer['role'] === Permissions::WEBADMIN
                ? $this->ownAccount($viewer, $users)
                : null,
            // The screen asks `can[...]` from this response — never guessed from the role
            'can' => $this->can([
                'view_accounts' => 'customer.view',
            ]),
        ]);
    }

    /** Every hosting account's SFTP state, for the admin table */
    public function accounts(Request $request): Response
    {
        if (!$this->ctx->can('customer.view')) {
            return $this->problem(ApiProblem::Forbidden, 'You do not have access to other accounts');
        }

        $users = new UserRepository($this->app->db());
        $canManage = $this->ctx->can('customer.manage');

        $rows = array_map(
            static function (array $row) use ($users, $canManage): array {
                $enabled = (int) ($row['sftp_enabled'] ?? 0) === 1;
                // The same two-field contract as UserResource: "off" and
                // "excluded by package" are different facts, and the screen
                // must be able to state both
                $available = (int) ($row['quota_ftp_users'] ?? 0) !== 0;

                return [
                    'id' => (int) $row['id'],
                    'username' => (string) $row['username'],
                    'sftp_enabled' => $enabled,
                    'sftp_available' => $available,
                    // Ready-composed, never the raw state — same reason
                    // ScheduledJobsController gives the pill a label+tone
                    'status_label' => $enabled ? 'Enabled' : ($available ? 'Disabled' : 'Not included'),
                    'status_tone' => $enabled ? 'ok' : ($available ? 'danger' : 'muted'),
                    'sftp_enabled_at' => $row['sftp_enabled_at'] === null ? null : (int) $row['sftp_enabled_at'],
                    'site_count' => count($users->siteIds((int) $row['id'])),
                    // Hides the buttons for viewers who can't use them — the
                    // route checks the permission again regardless
                    'can_manage' => $canManage,
                ];
            },
            $users->hostingAccounts(),
        );

        return $this->ok($rows);
    }

    /** The owner changes their own SFTP password — the id always comes from the session */
    public function changeOwnPassword(Request $request): Response
    {
        $password = $request->payloadString('password');

        if (mb_strlen($password) < 12) {
            return $this->problem(ApiProblem::ValidationError, 'SFTP password must be at least 12 characters long', [
                'password' => 'At least 12 characters',
            ]);
        }

        $result = $this->agent()->data('sftp.own_password', [
            'password' => $password,
        ], $this->ctx->actor($request));

        return $this->saved((string) ($result['message'] ?? 'SFTP password changed'), '', $result);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function ownAccount(array $row, UserRepository $users): array
    {
        return [
            'username' => (string) $row['username'],
            'sftp_enabled' => (int) ($row['sftp_enabled'] ?? 0) === 1,
            'sftp_available' => (int) ($row['quota_ftp_users'] ?? 0) !== 0,
            // The system account exists from the first website on — the honest
            // reason to show when it doesn't, instead of a button that errors
            'has_system_account' => count($users->siteIds((int) $row['id'])) > 0,
        ];
    }
}
