<?php

declare (strict_types = 1);

namespace Phpcp\Http\V2;

use Phpcp\Domain\QuotaChecker;
use Phpcp\Domain\ServiceCatalog;
use Phpcp\Domain\UserRepository;
use Phpcp\Http\ApiController;
use Phpcp\Http\ApiProblem;
use Phpcp\Http\Resource\SiteResource;
use Phpcp\Http\Resource\UserResource;
use Phpcp\Kernel\Request;
use Phpcp\Kernel\Response;
use Phpcp\Security\Password;
use Phpcp\Security\Permissions;
use Phpcp\Security\SessionStore;

/**
 * Every user account — `/api/v2/users`
 *
 * Since migration 0005, this resource covers both admins and hosting customers,
 * since they're now the same table · the old `/api/v2/customers` route was folded
 * into this one entirely. A customer is a user with `role=webadmin` and their own
 * quota/expiry columns.
 *
 * **Permission scope — the most important thing in this file**
 * Folding two resources into one means the route sysadmin used to manage customers
 * with can now also reach superadmin accounts — left as-is, that's an immediate
 * privilege escalation. So it's enforced in two layers:
 *   1. the route requires `customer.manage` (both superadmin and sysadmin have it)
 *   2. `assertMayManage()` here additionally requires `user.manage` whenever the
 *      target isn't webadmin, or a role is being set/changed to something other
 *      than webadmin
 * and the agent layer guards against it once more, independently, in
 * `CustomerCapability::loadHostingAccount()`.
 *
 * **Why this resource doesn't go through the agent for everything**
 * A user account is the panel's own internal state — it never touches the OS, never
 * creates a system user (Linux account creation happens when the first site is
 * created, in Phase M3, not when the user is created). The parts that already have
 * a capability (creating a hosting account, editing quotas, assigning sites) still
 * always go through the agent, because quota rules and audit logging must live in
 * exactly one place — a test watches for the web tier calling the repository directly.
 *
 * The price paid for this is that **every method that changes data must write its
 * own audit entry**, since there's no Dispatcher here to write one automatically.
 */
final class UsersController extends ApiController
{
    /**
     * @param Request $request
     * @return mixed
     */
    public function index(Request $request): Response
    {
        $users = new UserRepository($this->app->db());
        $quota = new QuotaChecker($users);
        $rows = $users->all();

        $role = $request->get('role');
        if (Permissions::isValidRole($role)) {
            $rows = array_filter($rows, static fn(array $u): bool => $u['role'] === $role);
        }

        $status = $request->get('status');
        if (in_array($status, ['active', 'disabled'], true)) {
            $rows = array_filter($rows, static fn(array $u): bool => $u['status'] === $status);
        }

        $service = $request->get('service_status');
        if (in_array($service, ['active', 'suspended', 'expired'], true)) {
            $rows = array_filter($rows, static fn(array $u): bool => $u['service_status'] === $service);
        }

        $query = $this->searchTerm($request);
        if ($query !== '') {
            $needle = mb_strtolower($query);
            $rows = array_filter($rows, static fn(array $u): bool => str_contains(mb_strtolower((string) $u['username']), $needle)
                || str_contains(mb_strtolower((string) $u['email']), $needle));
        }

        $rows = array_values($rows);
        $page = $this->pagination($request);

        return $this->paginate(
            array_map(
                fn(array $row): array=> $this->present($row, $users, $quota),
                array_slice($rows, $page['offset'], $page['per_page']),
            ),
            count($rows),
            $page['page'],
            $page['per_page'],
        );
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function show(Request $request): Response
    {
        $id = $request->paramInt('id');

        if ($id === 0) {
            // Must always answer 200 even if the agent is down — this page is
            // only a form's default values, not a command that genuinely
            // depends on the agent (see SystemController::health). The PHP
            // version just can't be chosen during that window, not the whole
            // page being unusable.
            $phpVersions = [];

            if ($this->agent()->isAvailable()) {
                foreach ($this->fetchPhpVersions($request)['versions'] as $version) {
                    $phpVersions[] = ['value' => $version['version'], 'text' => $version['version']];
                }
            }

            $body = [
                'data' => ['id' => 0],
                'options' => [
                    'php_version' => $phpVersions
                ]
            ];
        } else {

            $users = new UserRepository($this->app->db());
            $user = $users->find($id);

            if ($user === null) {
                return $this->problem(ApiProblem::NotFound, 'User not found');
            }

            // present() already fills in `sites` for a hosting account
            $body = $this->present($user, $users, new QuotaChecker($users));
        }

        return $this->ok($body);
    }

    /**
     * Create a new user
     *
     * A hosting account (webadmin) goes through the `customer.create` capability,
     * since it has quota rules and needs an audit entry written by the Dispatcher ·
     * an admin account is created directly at this layer, since it has no quota and
     * requires `user.manage`, a higher privilege.
     *
     * A password is always randomly generated if none was sent, and a change is
     * forced on first login — the admin never has to think one up themselves
     * (which usually produces a weak one), and a password that passed through a
     * middleman's eyes gets the shortest possible lifespan.
     */
    public function store(Request $request): Response
    {
        $users = new UserRepository($this->app->db());

        $username = trim($request->payloadString('username'));
        $role = $request->payloadString('role') ?: Permissions::WEBADMIN;

        if (!Permissions::isValidRole($role)) {
            return $this->problem(ApiProblem::ValidationError, 'Invalid role', [
                'role' => 'Allowed: '.implode(', ', array_keys(Permissions::roleLabels()))
            ]);
        }

        if (($denied = $this->assertMayManageRole($role)) !== null) {
            return $denied;
        }

        try {
            UserRepository::assertUsername($username);
        } catch (\InvalidArgumentException $e) {
            return $this->problem(ApiProblem::ValidationError, 'Invalid username', ['username' => $e->getMessage()]);
        }

        if ($users->findByUsername($username) !== null) {
            return $this->problem(ApiProblem::Conflict, 'That username is already taken');
        }

        $password = $request->payloadString('password');
        $wasRandom = $password === '';

        if ($wasRandom) {
            $password = Password::random(20);
        }

        /*
         * SFTP in the same breath as the account — always a password the
         * system generates, never one an admin types, so it has the same
         * one-response-only lifetime as a generated panel password · the
         * checkbox means "this customer should upload over SFTP from the
         * start"; the capability still refuses when the package's quota
         * says no
         */
        $wantSftp = (bool) $request->payload('sftp');
        $sftpPassword = '';
        $sftpUsername = null;
        $sftpError = '';

        $createdSite = null;
        $siteError = '';

        if ($role === Permissions::WEBADMIN) {
            if ($wantSftp) {
                $sftpPassword = Password::random(20);
            }

            $result = $this->agent()->data('customer.create', [
                'username' => $username,
                'password' => $password,
                'email' => trim($request->payloadString('email')),
                'quota_domains' => (int) $request->payload('quota_domains', 10),
                'quota_subdomains' => (int) $request->payload('quota_subdomains', 20),
                'quota_aliases' => (int) $request->payload('quota_aliases', 50),
                'quota_emails' => (int) $request->payload('quota_emails', 100),
                'quota_databases' => (int) $request->payload('quota_databases', 10),
                'quota_ftp_users' => (int) $request->payload('quota_ftp_users', 5),
                'disk_quota_mb' => (int) $request->payload('disk_quota_mb', 10240),
                'expiry_at' => $this->expiryFrom($request),
                'must_change_password' => $wasRandom,
                'sftp_password' => $sftpPassword
            ], $this->ctx->actor($request));

            $id = (int) $result['id'];
            $sftpUsername = $result['sftp_username'] ?? null;
            $sftpError = (string) ($result['sftp_error'] ?? '');

            // Create the first site right away if a domain was given too
            //
            // A hosting account with not a single site yet can't do anything at
            // all — forcing the admin to go create one on a separate page is a
            // step that's easy to forget, and if it's forgotten, the customer
            // logs in to an empty page · the quota is already checked by the
            // `site.create` capability itself.
            $domain = trim($request->payloadString('domain'));

            if ($domain !== '') {
                // **A failure at this step must not fail the whole request**
                //
                // The account has already been created and is genuinely usable ·
                // throwing an error here would make the admin read it as
                // "creation failed" and try again, which would collide with the
                // username that already exists, with nothing left to say what
                // the first randomly generated password even was.
                //
                // So this returns 201 with a clear statement of what succeeded
                // and what didn't — the admin can go create the site themselves
                // from the sites page without touching the account again.
                try {
                    $site = $this->agent()->data('site.create', [
                        'domain' => $domain,
                        // Not specified = use the newest version the system supports · `site.create` validates it again anyway
                        'php_version' => $request->payloadString('php_version') ?: ServiceCatalog::PHP_VERSIONS[0],
                        'owner_user_id' => $id
                    ], $this->ctx->actor($request));

                    $createdSite = ['id' => (int) ($site['id'] ?? 0), 'domain' => $domain];
                } catch (\Throwable $e) {
                    $siteError = $e->getMessage();

                    $this->audit($request, 'site.create_failed', $domain, [
                        'user_id' => $id,
                        'reason' => $siteError
                    ]);
                }
            }
        } else {
            $id = $users->create($username, $password, $role, mustChangePassword: $wasRandom);

            $this->audit($request, 'user.create', $username, ['user_id' => $id, 'role' => $role]);
        }

        // A system-generated password lives in this one response only — the
        // panel never stores it anywhere (it used to be passed through a query
        // string during a redirect, which then ended up in the access log and
        // the browser's history). The response for a **command** — no `data`
        // key · values the caller needs sit at the top level (a generated
        // password has nowhere else to be looked up later, so it must live in
        // this response and in a dialog the user has to close themselves).
        /*
         * Only what the admin can't get anywhere else — a password they typed
         * in themselves is already in their hands, and showing it back just
         * teaches them to dismiss this dialog without reading it · each
         * password travels next to the account name it belongs to, since this
         * one dialog can be carrying two of them
         */
        $secrets = [];

        if ($wasRandom) {
            $secrets['Account'] = $username;
            $secrets['Password'] = $password;
        }

        if ($sftpUsername !== null && $sftpPassword !== '') {
            $secrets['SFTP account'] = $sftpUsername;
            $secrets['SFTP password'] = $sftpPassword;
        }

        return $this->revealed(
            $createdSite === null
                ? $this->t('Account {user} created', ['user' => $username])
                : $this->t('Account {user} created with website {domain}', ['user' => $username, 'domain' => (string) $createdSite['domain']]),
            'users',
            'Account created',
            $secrets,
            // The trip to the list waits for the Close button — navigating while
            // the dialog is open takes the dialog along with the page, and this
            // is the only place these passwords ever appear
            goAfterClose: '/users',
            extra: ['user_id' => $id, 'username' => $username, 'must_change_password' => $wasRandom]
             + ($wasRandom ? ['password' => $password] : [])
             + ($sftpUsername === null ? [] : ['sftp_username' => $sftpUsername, 'sftp_password' => $sftpPassword])
             + ($sftpError === '' ? [] : ['sftp_error' => $sftpError])
             + ($createdSite === null ? [] : ['site' => $createdSite])
             + ($siteError === '' ? [] : ['site_error' => $siteError]),
            status: 201,
            notices: [
                /*
                 * A step that failed **after** the account was already created
                 * — reported beside the success, never as an error · answering
                 * with an error would read as "creation failed" and send the
                 * admin round again into a username that now exists, with the
                 * generated password lost in between
                 */
                ['message' => $siteError === '' ? '' : $this->t(
                    'Account {user} created, but the website could not be created: {error}',
                    ['user' => $username, 'error' => $siteError],
                )],
                ['message' => $sftpError === '' ? '' : $this->t(
                    'Account {user} created, but SFTP could not be enabled: {error}',
                    ['user' => $username, 'error' => $sftpError],
                )],
            ],
        )->withHeader('Location', '/api/v2/users/'.$id);
    }

    /**
     * Edit the profile, role, login status, service status, or expiry — send only what needs to change
     *
     * Rules that must never be violated, always checked before touching the database:
     *   - can't change your own role/suspend yourself — prevents locking yourself out with a misclick
     *   - at least one working admin account must always remain
     */
    public function update(Request $request): Response
    {
        $users = new UserRepository($this->app->db());
        $id = $request->paramInt('id');
        $user = $users->find($id);

        if ($user === null) {
            return $this->problem(ApiProblem::NotFound, 'User not found');
        }

        if (($denied = $this->assertMayManage($user)) !== null) {
            return $denied;
        }

        $role = $request->payloadString('role');
        $status = $request->payloadString('status');
        $service = $request->payloadString('service_status');
        $email = $request->payloadString('email');
        $hasExpiry = $request->payload('expiry_at') !== null || $request->get('expiry_at') !== '';

        if ($role === '' && $status === '' && $service === '' && $email === '' && !$hasExpiry) {
            return $this->problem(ApiProblem::ValidationError, 'Send at least one value to change');
        }

        // Changing your own role and login status is a shortcut to locking
        // yourself out of the system — editing your own name/email isn't
        // dangerous, so it isn't blocked
        if ($id === $this->ctx->userId() && ($role !== '' || $status !== '')) {
            return $this->problem(
                ApiProblem::Forbidden,
                'You cannot change your own role or status — ask another administrator to do it',
            );
        }

        $changes = [];

        if ($role !== '') {
            if (!Permissions::isValidRole($role)) {
                return $this->problem(ApiProblem::ValidationError, 'Invalid role', [
                    'role' => 'Allowed: '.implode(', ', array_keys(Permissions::roleLabels()))
                ]);
            }

            // A role can only be raised to admin by someone who genuinely has permission to manage users
            if (($denied = $this->assertMayManageRole($role)) !== null) {
                return $denied;
            }

            if ($role !== Permissions::SUPERADMIN && $users->wouldRemoveLastSuperadmin($id)) {
                return $this->problem(ApiProblem::Conflict, 'At least one working administrator account must remain');
            }

            $users->setRole($id, $role);
            $changes['role'] = ['from' => $user['role'], 'to' => $role];
        }

        if ($status !== '') {
            if (!in_array($status, ['active', 'disabled'], true)) {
                return $this->problem(ApiProblem::ValidationError, 'Invalid status', [
                    'status' => 'Allowed: active, disabled'
                ]);
            }

            if ($status !== 'active' && $users->wouldRemoveLastSuperadmin($id)) {
                return $this->problem(ApiProblem::Conflict, 'At least one working administrator account must remain');
            }

            $users->setStatus($id, $status);
            $changes['status'] = ['from' => $user['status'], 'to' => $status];
        }

        if ($service !== '') {
            if (!in_array($service, ['active', 'suspended', 'expired'], true)) {
                return $this->problem(ApiProblem::ValidationError, 'Invalid service status', [
                    'service_status' => 'Allowed: active, suspended, expired'
                ]);
            }

            $users->setServiceStatus($id, $service);
            $changes['service_status'] = ['from' => $user['service_status'], 'to' => $service];
        }

        if ($email !== '') {
            try {
                $users->updateProfile($id, $email);
            } catch (\InvalidArgumentException $e) {
                return $this->problem(ApiProblem::ValidationError, $e->getMessage(), ['email' => $e->getMessage()]);
            }

            $changes['profile'] = true;
        }

        if ($hasExpiry) {
            $expiry = $this->expiryFrom($request);

            if ($expiry !== null && $expiry < time()) {
                return $this->problem(ApiProblem::ValidationError, 'The expiry date must be in the future', [
                    'expiry_at' => 'Must be a time in the future'
                ]);
            }

            $users->updateExpiry($id, $expiry);
            $changes['expiry_at'] = $expiry;
        }

        // Permission or status changed, but the existing session still holds
        // the old one — force a fresh login (editing just the name or email
        // doesn't need sessions cut)
        $revoked = 0;
        if (isset($changes['role']) || isset($changes['status']) || isset($changes['service_status'])) {
            $revoked = (new SessionStore($this->app->db(), $this->app->config))->destroyAllFor($id);
        }

        $this->audit($request, 'user.update', (string) $user['username'], $changes + ['sessions_revoked' => $revoked]);

        /*
         * Respond with **the entire account after editing**, not just what changed
         *
         * The screen binds the whole page's data to this endpoint · a save's
         * response therefore overwrites the bound data · this used to respond
         * with only `{user_id, sessions_revoked, ...whatever changed}`, which
         * had no `sftp_enabled` at all — the moment the admin saved just the
         * name or email, **the entire SFTP section disappeared immediately**,
         * then came back once the page was reloaded, looking like the system
         * was unstable (found on a real server, 2026-08-14).
         *
         * Fixed at the response, not the template, because a PATCH's contract
         * is "this is the resource after the edit" — another caller (curl, a
         * script) should get the complete thing too, not have to call again.
         */
        $fresh = $users->find($id);
        $result = ($fresh === null ? [] : $this->present($fresh, $users, new QuotaChecker($users)))
            + ['sessions_revoked' => $revoked, 'changed' => $changes];

        return $this->completed('Account saved', 'users', $result);
    }

    /**
     * Change the account's file layout — **actually moves files**, not just a saved value
     *
     * Kept as a separate endpoint from `PATCH /users/{id}` because the side effects
     * are on an entirely different level: that one edits a name and email, this one
     * moves every site's directory in the account and rewrites their vhosts · someone
     * saving a username edit should never accidentally move a customer's files.
     */
    public function setLayout(Request $request): Response
    {
        $result = $this->agent()->data(
            'customer.layout_set',
            [
                'user_id' => $request->paramInt('id'),
                'layout' => (string) ($request->payload('layout') ?? ''),
            ],
            $this->ctx->actor($request),
        );

        $message = (string) ($result['message'] ?? 'Layout saved');

        return $this->done(
            $message,
            [[
                'type' => 'notification',
                // Moving files is something that should catch the eye, not a green bar that gets glossed over
                'level' => ((int) ($result['moved'] ?? 0)) > 0 ? 'warning' : 'success',
                'message' => $message,
            ]],
            is_array($result) ? $result : [],
        );
    }

    /** Update quotas — always goes through the capability (the rule for -1 = unlimited lives there alone) */
    public function setQuota(Request $request): Response
    {
        $quotas = [];

        foreach (['quota_domains', 'quota_subdomains', 'quota_aliases',
            'quota_emails', 'quota_databases', 'quota_ftp_users', 'disk_quota_mb'] as $field) {
            $value = $request->payload($field);

            if ($value !== null && $value !== '') {
                $quotas[$field] = (int) $value;
            }
        }

        $result = $this->agent()->data(
            'customer.quota_update',
            ['user_id' => $request->paramInt('id')] + $quotas,
            $this->ctx->actor($request),
        );

        // Uses the message from the capability, not a fixed one — the
        // capability is the one that knows what side effects actually
        // happened, such as revoking SFTP per the package, which also cuts
        // off an access that was left enabled (this used to be overwritten
        // with a plain "quota saved," so the admin never knew a customer's
        // access had just been cut).
        $message = (string) ($result['message'] ?? 'Quota saved');
        $revoked = (bool) ($result['sftp_revoked'] ?? false);

        return $this->done(
            $message,
            [[
                'type' => 'notification',
                // Cutting off a customer's access is a side effect that should catch the eye, not an ordinary green bar
                'level' => $revoked ? 'warning' : 'success',
                'message' => $message,
            ]],
            is_array($result) ? $result : [],
        );
    }

    /**
     * Enable SFTP with a password — safe to call again to change the password (PLAN-V2 Phase E4)
     *
     * The password comes from the admin only, never generated randomly — unlike a
     * panel account's password, this one gets typed into the user's own FTP client
     * program, and can't be changed on first login the same way.
     */
    /**
     * The SFTP password form — opens in a Modal
     *
     * A single input field shouldn't permanently take up space on the page · one
     * form handles both the first-time enable and a password change, since they're
     * technically the same action (a PUT that sets the whole value) — what differs
     * is the title, which the server picks from the real state, not something the
     * screen has to guess at.
     */
    public function sftpForm(Request $request): Response
    {
        $id = $request->paramInt('id');
        $user = (new UserRepository($this->app->db()))->find($id);

        if ($user === null) {
            return $this->problem(ApiProblem::NotFound, 'User not found');
        }

        $enabled = (bool) ($user['sftp_enabled'] ?? false);

        return $this->ok(
            ['form_action' => '/api/v2/users/' . $id . '/sftp'],
            [],
            [[
                'type' => 'modal',
                'action' => 'show',
                'template' => 'sftp-password-form.html',
                'title' => $enabled ? '{LNG_Change password}' : '{LNG_Enable SFTP}',
                'titleClass' => 'icon-lock',
            ]],
        );
    }

    public function enableSftp(Request $request): Response
    {
        $result = $this->agent()->data('sftp.enable', [
            'user_id' => $request->paramInt('id'),
            'password' => $request->payloadString('password'),
        ], $this->ctx->actor($request));

        // saved(), not completed() — this form opens in a Modal, so it must
        // close it on success, otherwise the Modal stays open with the
        // password field still filled in, looking like the save didn't go through
        //
        // **The table has to be named**, even though this endpoint is reached
        // from two different screens: the SFTP page (where `sftpAccounts` is)
        // and a user's own page (where it isn't) · the row's state and its
        // "opened since" date both change here, and with no refresh the row
        // kept saying Disabled until someone reloaded by hand · naming a table
        // that isn't on the current page is safe now that the refresh action
        // skips what it can't find instead of reloading the whole browser page
        return $this->saved(
            (string) ($result['message'] ?? 'SFTP enabled'),
            'sftpAccounts',
            is_array($result) ? $result : [],
        );
    }

    public function disableSftp(Request $request): Response
    {
        $result = $this->agent()->data(
            'sftp.disable',
            ['user_id' => $request->paramInt('id')],
            $this->ctx->actor($request),
        );

        return $this->completed(
            (string) ($result['message'] ?? 'SFTP disabled'),
            'sftpAccounts',
            is_array($result) ? $result : [],
        );
    }

    /** Assign an unowned site to a hosting account — the capability checks the quota too */
    public function attachSites(Request $request): Response
    {
        $siteIds = $request->payload('site_ids', []);

        if (!is_array($siteIds)) {
            $siteIds = [$siteIds];
        }

        $siteIds = array_values(array_unique(array_map('intval', $siteIds)));

        if ($siteIds === []) {
            return $this->problem(ApiProblem::ValidationError, 'Choose at least one website to attach', [
                'site_ids' => 'Send a list of website ids'
            ]);
        }

        $result = $this->agent()->data('customer.site_attach', [
            'user_id' => $request->paramInt('id'),
            'site_ids' => $siteIds
        ], $this->ctx->actor($request));

        // Not a single site attached = this request did not succeed, even
        // though the agent didn't throw an error (per-site reasons live in
        // results — e.g. over quota, or already has an owner)
        return (int) $result['attached_count'] === 0
            ? $this->problem(ApiProblem::Conflict, (string) $result['message'])
            : $this->completed((string) $result['message'], 'userSites', $result);
    }

    /**
     * Detach a site from a customer account — the owner becomes the admin who
     * issued the command, never "unowned"
     *
     * Since migration 0005, every site must always have an owner (enforced by a
     * trigger), because an unowned site is a site that's still running on the
     * machine with nobody responsible for it and not counted against anyone's quota.
     */
    public function detachSite(Request $request): Response
    {
        $users = new UserRepository($this->app->db());
        $id = $request->paramInt('id');
        $user = $users->find($id);

        if ($user === null) {
            return $this->problem(ApiProblem::NotFound, 'User not found');
        }

        $siteId = $request->paramInt('site_id');
        $site = $this->app->db()->first('SELECT id, owner_user_id FROM sites WHERE id = :id', ['id' => $siteId]);

        if ($site === null || (int) $site['owner_user_id'] !== $id) {
            return $this->problem(ApiProblem::NotFound, 'That website is not in this account');
        }

        $newOwner = $this->ctx->userId();

        $this->app->db()->update(
            'sites',
            ['owner_user_id' => $newOwner, 'updated_at' => time()],
            ['id' => $siteId],
        );

        $this->audit($request, 'customer.site_detach', (string) $user['username'], [
            'site_id' => $siteId,
            'new_owner_user_id' => $newOwner
        ]);

        return $this->completed(
            $this->t('Websites moved away from {user} — the new owner is {owner}', ['user' => (string) $user['username'], 'owner' => $this->ctx->username()]),
            'userSites',
            [
                'site_id' => $siteId,
                'previous_owner_user_id' => $id,
                'new_owner_user_id' => $newOwner
            ],
        );
    }

    /** Set a new password for another user — used when a password is forgotten */
    public function resetPassword(Request $request): Response
    {
        $users = new UserRepository($this->app->db());
        $id = $request->paramInt('id');
        $user = $users->find($id);

        if ($user === null) {
            return $this->problem(ApiProblem::NotFound, 'User not found');
        }

        if (($denied = $this->assertMayManage($user)) !== null) {
            return $denied;
        }

        $password = $request->payloadString('password');
        $wasRandom = $password === '';

        if ($wasRandom) {
            $password = Password::random(20);
        }

        $users->setPassword($id, $password, clearMustChange: false);

        if ($wasRandom) {
            $users->requirePasswordChange($id);
        }

        $revoked = (new SessionStore($this->app->db(), $this->app->config))->destroyAllFor($id);

        $this->audit($request, 'auth.password_reset', (string) $user['username'], [
            'by' => $this->ctx->username(),
            'sessions_revoked' => $revoked
        ]);

        /*
         * A system-generated password has **no other place it can be looked up
         * later** — if the admin misses it, the whole reset has to happen again
         * · so it goes into a dialog the user closes themselves, not a toast
         * that vanishes on its own · a password the admin typed in is already
         * in their hands, so that case is an ordinary "saved" with no dialog
         */
        return $this->revealed(
            $this->t('New password set for {user}', ['user' => (string) $user['username']]),
            'users',
            'New password',
            $wasRandom ? [
                'Account' => (string) $user['username'],
                'Password' => $password,
            ] : [],
            note: $this->t(
                '{count} open session(s) have been revoked, and this account must set a new password the next time it logs in',
                ['count' => $revoked],
            ),
            extra: [
                'user_id' => $id,
                'username' => (string) $user['username'],
                'password' => $password,
                'must_change_password' => $wasRandom,
                'sessions_revoked' => $revoked
            ],
        );
    }

    /**
     * Disable another user's 2FA — used when an authentication device is lost
     *
     * A `DELETE` on the `two-factor` resource per §4.1 · re-enabling it must be
     * done by the account's own owner only — there's no endpoint for an admin to
     * enable it on someone else's behalf (otherwise an admin could hold another
     * person's secret, which defeats the entire point of 2FA).
     */
    public function disableTwoFactor(Request $request): Response
    {
        $users = new UserRepository($this->app->db());
        $id = $request->paramInt('id');
        $user = $users->find($id);

        if ($user === null) {
            return $this->problem(ApiProblem::NotFound, 'User not found');
        }

        if (($denied = $this->assertMayManage($user)) !== null) {
            return $denied;
        }

        $users->disableTotp($id);

        $this->audit($request, 'user.disable_2fa', (string) $user['username'], []);

        return $this->completed(
            $this->t('Two-factor disabled for {user}', ['user' => (string) $user['username']]),
            'users',
            ['user_id' => $id],
        );
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function destroy(Request $request): Response
    {
        $users = new UserRepository($this->app->db());
        $id = $request->paramInt('id');
        $user = $users->find($id);

        if ($user === null) {
            return $this->problem(ApiProblem::NotFound, 'User not found');
        }

        if (($denied = $this->assertMayManage($user)) !== null) {
            return $denied;
        }

        if ($id === $this->ctx->userId()) {
            return $this->problem(ApiProblem::Forbidden, 'You cannot delete your own account');
        }

        if ($users->wouldRemoveLastSuperadmin($id)) {
            return $this->problem(ApiProblem::Conflict, 'At least one working administrator account must remain');
        }

        // The database also guards against this with a trigger, but this is
        // answered here first, to give a message that explains what to do
        // next, instead of a raw constraint error straight from SQLite
        if ($users->siteIds($id) !== []) {
            return $this->problem(
                ApiProblem::Conflict,
                'A user who still owns websites cannot be deleted — delete the websites or move them to another owner first',
            );
        }

        $users->delete($id);

        $this->audit($request, 'user.delete', (string) $user['username'], ['role' => $user['role']]);

        // Responds 200 with `actions` instead of 204
        //
        // 204 has no response body, so the screen has nothing left to act on
        // — the now-deleted row would stay sitting in the table until the
        // user reloads the page themselves, which reads as "clicked delete
        // and nothing happened" · instead, the server tells it to show a
        // notification and reload the table right away, the same pattern
        // used for creation and password reset.
        return $this->completed(
            $this->t('Account {user} deleted', ['user' => (string) $user['username']]),
            'users',
            ['user_id' => $id, 'username' => (string) $user['username']],
        );
    }

    /**
     * One user in the shape exported by the API — quotas attached only for hosting accounts
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function present(array $row, UserRepository $users, QuotaChecker $quota): array
    {
        $id = (int) ($row['id'] ?? 0);

        $presented = ($row['role'] ?? '') !== Permissions::WEBADMIN
            ? UserResource::one($row)
            : UserResource::withHosting($row, $quota->summary($id) ?? [], count($users->siteIds($id)));
        $presented['role_label'] = $this->t($presented['role_label']);

        /*
         * An account's sites are always in the same shape — this used to be
         * something only `show()` filled in afterward.
         *
         * The screen uses `sites` as the condition for showing the sections
         * that only exist for hosting accounts (SFTP, attached sites) · a
         * `PATCH` response with no such key would therefore make those
         * sections disappear after saving, even though nothing actually
         * changed — a resource's shape must never depend on which method
         * was used to call it.
         */
        if (($row['role'] ?? '') === Permissions::WEBADMIN) {
            $presented['sites'] = SiteResource::collection($users->sites($id));
        }

        // The row knows for itself whether it's the caller's own account —
        // the screen can write conditions that hide the delete button and
        // the reset-own-password button without needing any JS-side logic.
        //
        // This is purely UX · the API independently refuses self-deletion at its own layer regardless.
        $presented['is_self'] = $id === $this->ctx->userId();

        return $presented;
    }

    /**
     * Whether the caller has permission to manage this target account
     *
     * `customer.manage` only allows managing hosting accounts · touching an
     * admin account requires `user.manage` — without this guard, a sysadmin
     * could reset a superadmin's password through this same route immediately.
     *
     * @param array<string,mixed> $target
     */
    private function assertMayManage(array $target): ?Response
    {
        if (($target['role'] ?? '') === Permissions::WEBADMIN) {
            return null;
        }

        return $this->ctx->can('user.manage')
            ? null
            : $this->problem(ApiProblem::Forbidden, 'Managing system users is required to edit an administrator account');
    }

    /** Setting a role other than webadmin requires permission to manage system users */
    private function assertMayManageRole(string $role): ?Response
    {
        if ($role === Permissions::WEBADMIN) {
            return null;
        }

        return $this->ctx->can('user.manage')
            ? null
            : $this->problem(ApiProblem::Forbidden, 'Managing system users is required to assign an administrator role');
    }

    /** Accepts an expiry date as either a unix timestamp or a date string */
    private function expiryFrom(Request $request): ?int
    {
        $value = $request->payload('expiry_at', $request->get('expiry_at'));

        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value) || ctype_digit((string) $value)) {
            return (int) $value;
        }

        $parsed = strtotime((string) $value);

        return $parsed === false ? null : $parsed;
    }

    /**
     * Write an audit entry — must be called from every method in this file that changes data
     *
     * This resource doesn't go through the Dispatcher, so nothing writes an audit
     * entry automatically — this is the price paid for not expanding the agent
     * layer's own surface area.
     *
     * @param array<string,mixed> $detail
     */
    private function audit(Request $request, string $action, string $target, array $detail): void
    {
        $this->app->audit()->write($this->ctx->actor($request), $action, $target, 'ok', $detail);
    }
}
