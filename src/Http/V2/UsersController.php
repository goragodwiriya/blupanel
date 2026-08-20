<?php

declare (strict_types = 1);

namespace Phpcp\Http\V2;

use Phpcp\Domain\QuotaChecker;
use Phpcp\Domain\SettingsRepository;
use Phpcp\Domain\UserNotice;
use Phpcp\Domain\UserRepository;
use Phpcp\Driver\Db\MariaDbManager;
use Phpcp\Http\ApiController;
use Phpcp\Http\ApiProblem;
use Phpcp\Http\Resource\SiteResource;
use Phpcp\Http\Resource\UserResource;
use Phpcp\Kernel\Paths;
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
     * Stands in for the password in the email preview
     *
     * The real one is generated at the moment of sending, not while a preview
     * is being read — so an admin who opens the preview, thinks better of it
     * and closes the dialog has not left a live password sitting in a browser
     * tab, and has not silently locked the customer out of the account either.
     */
    private const PASSWORD_PLACEHOLDER = '••••••••••••';

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
                    // Installed only · the list now includes versions that
                    // could be installed, and offering one of those here would
                    // create an account whose first website fails to be created
                    if (($version['installed'] ?? false) !== true) {
                        continue;
                    }

                    $phpVersions[] = ['value' => $version['version'], 'text' => $version['version']];
                }
            }

            $body = [
                'data' => [
                    'id' => 0,
                    /*
                     * The create form opens with a strong password already in it
                     *
                     * The field used to start empty with "leave blank and one
                     * will be generated" underneath, which reads as a chore
                     * rather than an offer — so admins typed their own, and a
                     * password somebody thinks up under mild time pressure is
                     * the weakest one in the system · now the good answer is
                     * the default and typing is the deliberate act
                     *
                     * **One value, shown in both fields** — see `store()` for
                     * why the panel login and SFTP share it for a brand-new account
                     */
                    'suggested_password' => Password::random(PasswordsController::suggestedLength()),
                ],
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
     *
     * ## One request, the whole handover
     *
     * A usable hosting account is never just a row in `users`. It is an account,
     * a website, a certificate that is actually *switched on*, somewhere to put
     * the data, and a message telling the customer all of it. Split across five
     * screens, the steps after the first are the ones that get forgotten — and
     * every one of them is only noticed by the customer, days later: no site, a
     * browser saying "not secure", no database, no idea what the password was.
     *
     * So this one endpoint runs all of them, in the order they depend on each
     * other, each one optional and each one asked for on the same form:
     *
     *   1. the account (`customer.create`) — the only step that may fail the request
     *   2. the first website (`site.create`)
     *   3. its certificate (`ssl.issue`) **and `ssl.set_mode`** — see below
     *   4. its first database (`db.create`)
     *   5. the welcome email, built by reading 1–4 back out of the database
     *
     * **Steps 2–5 never fail the request.** The account exists by then and is
     * genuinely usable · answering with an error would read as "creation
     * failed" and send the admin round again into a username that now exists,
     * with the generated password lost in between. Each one reports itself as
     * a warning beside the success instead, saying what to do about it.
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

        /*
         * Everything the form can get wrong is checked **before** the account exists
         *
         * The optional steps below (website, certificate, database, welcome
         * email) all half-succeed on purpose — the account is real by then, so
         * failing the whole request would send the admin back into a username
         * that now exists with the generated password lost in between · but a
         * value that was already wrong when it arrived is not a half-success,
         * it is a typo, and a typo has to come back as a 422 while nothing has
         * been created yet and the form can still be corrected.
         */
        $sslMethod = $request->payloadString('ssl') ?: 'none';

        if (!in_array($sslMethod, ['none', 'letsencrypt', 'self-signed'], true)) {
            return $this->problem(ApiProblem::ValidationError, 'Invalid SSL choice', [
                'ssl' => 'Allowed: none, letsencrypt, self-signed'
            ]);
        }

        $databaseName = trim($request->payloadString('database'));

        if ($databaseName !== '') {
            try {
                // The one rule, read from the driver that enforces it — the
                // owner's prefix is added by `db.create` later, so what is
                // checked here is only the part the admin actually typed
                MariaDbManager::assertDatabaseName($databaseName);
            } catch (\Throwable $e) {
                return $this->problem(ApiProblem::ValidationError, 'Invalid database name', [
                    'database' => $e->getMessage()
                ]);
            }
        }

        $password = $request->payloadString('password');
        $wasRandom = $password === '';

        if ($wasRandom) {
            $password = Password::random(20);
        }

        /*
         * SFTP in the same breath as the account · the checkbox means "this
         * customer should upload over SFTP from the start", and the capability
         * still refuses when the package's quota says no
         *
         * **A brand-new account gets one password for both the panel and
         * SFTP.** They are the same thing to the customer — their own identity
         * on this host — and they are handed over together in one welcome
         * email that has to be read once and acted on. Two random strings in
         * that email is how one of them ends up written down wrong, and the
         * support ticket that follows costs more than the separation was worth
         * for a credential that gets replaced on first login anyway.
         *
         * **The sharing stops here.** A database password and a mailbox
         * password are still generated per resource, because those two get
         * copied into `wp-config.php` and into a phone, then sit untouched for
         * years — sharing the account password with them would mean one leaked
         * config file hands over the panel too.
         */
        $wantSftp = (bool) $request->payload('sftp');
        $sftpPassword = '';
        $sftpUsername = null;
        $sftpError = '';

        $domain = trim($request->payloadString('domain'));
        $createdSite = null;
        $siteError = '';

        $createdSsl = null;
        $sslError = '';
        $sslIssued = false;

        $createdDatabase = null;
        $databaseError = '';

        $welcomeSentTo = '';
        $welcomeError = '';

        $adminExtrasIgnored = '';

        if ($role === Permissions::WEBADMIN) {
            if ($wantSftp) {
                $sftpPassword = $password;
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
                        /*
                         * Not specified = the machine's own preferred version
                         *
                         * This used to be `ServiceCatalog::PHP_VERSIONS[0]`, a
                         * constant — so on a machine that did not happen to have
                         * the panel's newest known version installed, leaving the
                         * dropdown alone created the account and then failed to
                         * create its website, on a version that was never there
                         */
                        'php_version' => $request->payloadString('php_version') ?: $this->defaultPhpVersion($request),
                        'owner_user_id' => $id
                    ], $this->ctx->actor($request));

                    /*
                     * **`site_id`, not `id`** — `site.create` has always
                     * answered with `site_id` (SitesController::store reads it
                     * by that name), and this line read `id`, so the site's own
                     * id here was silently 0 from the day it was written.
                     *
                     * Nothing noticed while the value only travelled out in the
                     * response as a link nobody followed. The moment a step in
                     * this same request needed it — issuing the certificate —
                     * it surfaced as `site_id must be between 1 and …`, an
                     * error that names the argument and says nothing about
                     * where the 0 came from.
                     */
                    $createdSite = ['id' => (int) ($site['site_id'] ?? 0), 'domain' => $domain];
                } catch (\Throwable $e) {
                    $siteError = $e->getMessage();

                    $this->audit($request, 'site.create_failed', $domain, [
                        'user_id' => $id,
                        'reason' => $siteError
                    ]);
                }
            }

            /*
             * HTTPS — **two commands, not one**
             *
             * `ssl.issue` deliberately never switches HTTPS on by itself, and
             * that split is right at the capability layer: issuing can succeed
             * while the certificate misses a domain, and an admin may not be
             * ready to flip a live site over.
             *
             * What was wrong is that nothing ever ran the second command. The
             * panel had no screen anywhere that called `ssl.set_mode`, so
             * `sites.ssl_mode` stayed `off`, the vhost never grew a `:443`
             * block, and every certificate the panel had ever issued sat on
             * disk doing nothing while the browser kept saying "not secure".
             * The certificates page can do it now, and so can this form, in
             * the same request that created the account.
             *
             * Which mode each method lands on:
             *   - **letsencrypt → forced.** A publicly trusted certificate is
             *     the whole reason to redirect; leaving `http://` answering
             *     next to it means the customer's first visit is still plain text.
             *   - **self-signed → on.** Forcing every visitor through a
             *     certificate no browser trusts turns one warning page into
             *     the only page the site has · both schemes stay reachable so
             *     the site still works while a real certificate is arranged.
             *
             * Failure never fails the request, for the same reason the website
             * step doesn't — and doubly so here, since the usual cause is DNS
             * that hasn't been pointed at this machine yet, which is somebody
             * else's job and not a reason to refuse to create the account.
             */
            if ($createdSite !== null && $sslMethod !== 'none') {
                $sslMode = $sslMethod === 'letsencrypt' ? 'forced' : 'on';

                try {
                    $certificate = $this->agent()->data('ssl.issue', [
                        'site_id' => (int) $createdSite['id'],
                        'method' => $sslMethod,
                        // Left to the capability to resolve — it reaches for the
                        // owner's own email first, which is the account being
                        // created here, then the system's sender address
                        'email' => '',
                        'staging' => false,
                    ], $this->ctx->actor($request));

                    // Which of the two failed decides what the admin is told to
                    // do next, and the two answers are nothing alike: a
                    // certificate that was never issued is a DNS problem to
                    // chase, while one that was issued but not switched on is
                    // already sitting on disk and is one button away
                    $sslIssued = true;

                    $this->agent()->data('ssl.set_mode', [
                        'site_id' => (int) $createdSite['id'],
                        'mode' => $sslMode,
                    ], $this->ctx->actor($request));

                    $createdSsl = [
                        'method' => $sslMethod,
                        'mode' => $sslMode,
                        'expires_at' => $certificate['certificate']['expires_at'] ?? null,
                    ];
                } catch (\Throwable $e) {
                    $sslError = $e->getMessage();

                    $this->audit($request, $sslIssued ? 'ssl.set_mode_failed' : 'ssl.issue_failed', $domain, [
                        'user_id' => $id,
                        'method' => $sslMethod,
                        'reason' => $sslError
                    ]);
                }
            }

            /*
             * The first database, in the same request
             *
             * Bound to the website when there is one — `databases_.site_id` is
             * what puts a database into the automatic backup round and into
             * the welcome email's database section, so a database created
             * beside a site and left unbound would quietly be backed up by
             * nobody.
             *
             * The name is prefixed with the account's own (`db.create` does
             * it), so two customers can both ask for `shop`.
             */
            if ($databaseName !== '') {
                try {
                    $database = $this->agent()->data('db.create', [
                        'name' => $databaseName,
                        'owner_user_id' => $id,
                        'site_id' => $createdSite === null ? 0 : (int) $createdSite['id'],
                        // Generated by the capability and shown once, in the
                        // same dialog as the panel password · never the
                        // account's own password, because this one is copied
                        // into a config file and then sits there for years
                        'password' => '',
                        'host' => 'localhost',
                        'privileges' => 'readwrite',
                        'charset' => 'utf8mb4',
                    ], $this->ctx->actor($request));

                    $createdDatabase = [
                        'name' => (string) ($database['name'] ?? ''),
                        'username' => (string) ($database['username'] ?? ''),
                        'password' => (string) ($database['password'] ?? ''),
                    ];
                } catch (\Throwable $e) {
                    $databaseError = $e->getMessage();

                    $this->audit($request, 'db.create_failed', $databaseName, [
                        'user_id' => $id,
                        'reason' => $databaseError
                    ]);
                }
            }

            /*
             * The welcome email — **last**, on purpose
             *
             * `UserNotice` builds its body by reading the account back out of
             * the database: its websites, its databases, its SFTP details, its
             * quota. Sent any earlier in this method it would describe an
             * account that was still half-built, and the customer's one
             * handover message would be missing the very things this form just
             * created.
             *
             * The panel password goes in it only when the system generated
             * one — a password the admin typed is already in the admin's hands
             * and is usually being read out loud as they type it.
             */
            if ((bool) $request->payload('send_welcome')) {
                $created = $users->find($id);

                try {
                    if ($created === null || !UserNotice::appliesTo($created)) {
                        throw new \RuntimeException('A welcome email only applies to a hosting account');
                    }

                    $notice = (new UserNotice($this->app, $this->machineInfo($request)))
                        ->build(UserNotice::WELCOME, $created, $password);

                    $sent = $this->agent()->data('mail.user_notice', [
                        'user_id' => $id,
                        'subject' => $notice['subject'],
                        'body' => $notice['body'],
                    ], $this->ctx->actor($request));

                    $welcomeSentTo = (string) ($sent['to'] ?? '');

                    $this->audit($request, 'user.email_sent', $username, [
                        'kind' => UserNotice::WELCOME,
                        'to' => $welcomeSentTo
                    ]);
                } catch (\Throwable $e) {
                    $welcomeError = $e->getMessage();

                    $this->audit($request, 'user.email_failed', $username, [
                        'kind' => UserNotice::WELCOME,
                        'reason' => $welcomeError
                    ]);
                }
            }
        } else {
            $id = $users->create($username, $password, $role, mustChangePassword: $wasRandom);

            $this->audit($request, 'user.create', $username, ['user_id' => $id, 'role' => $role]);

            /*
             * An admin account has no website, no database and no welcome email
             * to send — every one of those belongs to a hosting package.
             *
             * The form does not hide those fields (the role is chosen on the
             * same screen, so hiding them would make the page jump around as
             * the dropdown changes), so a domain or a database name typed
             * before switching the role to sysadmin is simply dropped. Dropped
             * **silently** is the part that is wrong: the admin walks away
             * believing they created a website. Only the two fields that start
             * empty are reported — the ones with a default would fire this on
             * every single admin account and stop being read.
             */
            $ignored = array_values(array_filter([
                $domain === '' ? '' : $this->t('the website'),
                $databaseName === '' ? '' : $this->t('the database'),
            ]));

            if ($ignored !== []) {
                $adminExtrasIgnored = implode(', ', $ignored);
            }
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

        /*
         * The SFTP **login name** still belongs in the dialog — it is the one
         * value that isn't guessable from what the admin typed (the system
         * account name can differ from the panel username) · its password is
         * not repeated as a second row, because it is the same string sitting
         * right above it, and two rows holding the same secret reads as two
         * different secrets, which is how one of them gets copied into the wrong box
         */
        if ($sftpUsername !== null && $sftpPassword !== '' && $wasRandom) {
            $secrets['SFTP account'] = $sftpUsername;
        }

        /*
         * The database's password is revealed whether or not the account's was
         *
         * `db.create` always generates it — there is no field for it on this
         * form — and MariaDB keeps only a hash, so this response is the single
         * place it will ever be readable · it travels next to the database and
         * user names it belongs to, because the admin is about to paste all
         * three into a config file
         */
        if ($createdDatabase !== null && $createdDatabase['password'] !== '') {
            $secrets['Database'] = $createdDatabase['name'];
            $secrets['Database user'] = $createdDatabase['username'];
            $secrets['Database password'] = $createdDatabase['password'];
        }

        // Sentences under the revealed values — what else happened that the
        // admin has to know before closing the only screen that shows it
        $notes = [];

        if ($sftpUsername !== null && $sftpPassword !== '' && $wasRandom) {
            $notes[] = $this->t('SFTP uses this same password. The customer must set a new panel password the next time they sign in.');
        }

        if ($createdSsl !== null) {
            $notes[] = $createdSsl['mode'] === 'forced'
                ? $this->t('HTTPS is on and every visitor is redirected to it.')
                : $this->t('HTTPS is on, but the certificate is self-signed — browsers will warn until a trusted one replaces it.');
        }

        if ($welcomeSentTo !== '') {
            $notes[] = $this->t('The welcome email has been sent to {email}.', ['email' => $welcomeSentTo]);
        }

        return $this->revealed(
            $createdSite === null
                ? $this->t('Account {user} created', ['user' => $username])
                : $this->t('Account {user} created with website {domain}', ['user' => $username, 'domain' => (string) $createdSite['domain']]),
            'users',
            'Account created',
            $secrets,
            note: implode(' ', $notes),
            // The trip to the list waits for the Close button — navigating while
            // the dialog is open takes the dialog along with the page, and this
            // is the only place these passwords ever appear
            goAfterClose: '/users',
            extra: ['user_id' => $id, 'username' => $username, 'must_change_password' => $wasRandom]
             + ($wasRandom ? ['password' => $password] : [])
             + ($sftpUsername === null ? [] : ['sftp_username' => $sftpUsername, 'sftp_password' => $sftpPassword])
             + ($sftpError === '' ? [] : ['sftp_error' => $sftpError])
             + ($createdSite === null ? [] : ['site' => $createdSite])
             + ($siteError === '' ? [] : ['site_error' => $siteError])
             + ($createdSsl === null ? [] : ['ssl' => $createdSsl])
             + ($sslError === '' ? [] : ['ssl_error' => $sslError])
             + ($createdDatabase === null ? [] : ['database' => $createdDatabase])
             + ($databaseError === '' ? [] : ['database_error' => $databaseError])
             + ($welcomeSentTo === '' ? [] : ['welcome_sent_to' => $welcomeSentTo])
             + ($welcomeError === '' ? [] : ['welcome_error' => $welcomeError]),
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
                /*
                 * The usual cause is DNS that has not been pointed at this
                 * machine yet — so the message says what to do next rather
                 * than only what failed · the certificate can be issued from
                 * the certificates page whenever the domain does resolve here,
                 * and nothing about the account needs redoing in the meantime
                 */
                ['message' => $sslError === '' || $sslIssued ? '' : $this->t(
                    'Website {domain} was created, but the certificate could not be issued: {error} · issue it from the SSL certificates page once the domain points at this machine',
                    ['domain' => $domain, 'error' => $sslError],
                )],
                /*
                 * The certificate is genuinely there — saying "could not be
                 * issued" here would send the admin off to request a second
                 * one against Let's Encrypt's rate limit for a problem a
                 * single button already solves
                 */
                ['message' => $sslError === '' || !$sslIssued ? '' : $this->t(
                    'The certificate for {domain} was issued, but HTTPS could not be switched on: {error} · the website is still served over plain HTTP until it is enabled from the SSL certificates page',
                    ['domain' => $domain, 'error' => $sslError],
                )],
                ['message' => $databaseError === '' ? '' : $this->t(
                    'Account {user} created, but the database could not be created: {error}',
                    ['user' => $username, 'error' => $databaseError],
                )],
                ['message' => $welcomeError === '' ? '' : $this->t(
                    'Account {user} created, but the welcome email could not be sent: {error}',
                    ['user' => $username, 'error' => $welcomeError],
                )],
                ['message' => $adminExtrasIgnored === '' ? '' : $this->t(
                    'An administrator account has no hosting package, so {ignored} was not created — create a hosting account for that',
                    ['ignored' => $adminExtrasIgnored],
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
            [
                'form_action' => '/api/v2/users/' . $id . '/sftp',
                // Prefilled, like every other credential form in the panel · this
                // one used to be the only field in the system that demanded the
                // admin think a password up themselves
                'suggested_password' => Password::random(PasswordsController::suggestedLength()),
            ],
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

    /**
     * The "send this account an email" form — one row action, two things it can send
     *
     * ## Why this is a form and not two more buttons in the row
     *
     * The row already carries Manage, Reset password and Delete. A fourth and
     * fifth would push the useful ones off the edge on a laptop screen, and
     * both of these want something a row action cannot give them anyway: the
     * chance to read the message before it leaves. An email to a customer is
     * not undoable — the preview is the whole point.
     *
     * ## Both previews are rendered, not one that follows a dropdown
     *
     * A preview that re-fetches on every change of a radio button is a round
     * trip and a piece of JavaScript for something a `<details>` element does
     * natively. Both bodies are already composed by the time this answers.
     */
    public function emailForm(Request $request): Response
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

        $notice = new UserNotice($this->app, $this->machineInfo($request));
        $email = trim((string) ($user['email'] ?? ''));

        /*
         * The password in the preview is a placeholder, because the real one
         * does not exist yet — it is generated at the moment of sending, so
         * that a preview the admin looked at and closed never leaves a live
         * password lying in a browser tab
         */
        $welcome = $notice->build(UserNotice::WELCOME, $user);
        $reset = $notice->build(UserNotice::PASSWORD_RESET, $user, self::PASSWORD_PLACEHOLDER);

        return $this->ok(
            [
                'id' => $id,
                // The modal's template is a string of its own by the time it
                // opens, so nothing fills `{id}` into it — the action comes
                // ready-made from here, the same as the SFTP password form
                'form_action' => '/api/v2/users/' . $id . '/email',
                'username' => (string) $user['username'],
                'email' => $email,
                'has_email' => $email !== '',
                // A welcome email to an admin account would be a list of empty
                // sections — no websites, no quota, no SFTP · the screen hides
                // that choice rather than offering something that reads as broken
                'is_hosting' => UserNotice::appliesTo($user),
                'mail_ready' => trim((new SettingsRepository($this->app->db()))->get('mail.from')) !== '',
                'welcome_subject' => $welcome['subject'],
                'welcome_body' => $welcome['body'],
                'reset_subject' => $reset['subject'],
                'reset_body' => $reset['body'],
            ],
            [],
            [[
                'type' => 'modal',
                'action' => 'show',
                'template' => 'user-email-form.html',
                'title' => '{LNG_Send an email}',
                'titleClass' => 'icon-send',
            ]],
        );
    }

    /**
     * Send it
     *
     * `password_reset` resets the password **and then** mails it, in that
     * order — mailing a password that was never actually set is the one
     * failure mode worth designing against, since the customer would then try
     * it, fail, and conclude the whole account is broken.
     *
     * The generated password still appears in the reveal dialog as well. The
     * email can bounce, sit in a queue, or land in spam, and the admin on the
     * phone with the customer needs the value in front of them regardless.
     */
    public function sendEmail(Request $request): Response
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

        $kind = $request->payloadString('kind') ?: UserNotice::WELCOME;

        if (!UserNotice::isValidKind($kind)) {
            return $this->problem(ApiProblem::ValidationError, 'Unknown email type', [
                'kind' => 'Allowed: ' . implode(', ', array_keys(UserNotice::kinds())),
            ]);
        }

        if ($kind === UserNotice::WELCOME && !UserNotice::appliesTo($user)) {
            return $this->problem(
                ApiProblem::ValidationError,
                'A welcome email only applies to a hosting account',
            );
        }

        $password = '';
        $revoked = 0;

        if ($kind === UserNotice::PASSWORD_RESET) {
            [$password, $revoked] = $this->assignNewPassword($request, $users, $user);
        }

        $notice = (new UserNotice($this->app, $this->machineInfo($request)))
            ->build($kind, $user, $password);

        $result = $this->agent()->data('mail.user_notice', [
            'user_id' => $id,
            'subject' => $notice['subject'],
            'body' => $notice['body'],
        ], $this->ctx->actor($request));

        $this->audit($request, 'user.email_sent', (string) $user['username'], [
            'kind' => $kind,
            'to' => (string) ($result['to'] ?? ''),
        ]);

        $message = $this->t('Email sent to {user}', ['user' => (string) $user['username']]);

        /*
         * The dialog only opens when there is a password in play · a welcome
         * email holds nothing the admin cannot read again, so that case is an
         * ordinary save with the modal closed behind it
         */
        return $this->revealed(
            $message,
            'users',
            'New password',
            $password === '' ? [] : [
                'Account' => (string) $user['username'],
                'Password' => $password,
            ],
            note: $password === ''
                ? ''
                : $this->t(
                    '{count} open session(s) have been revoked, and this account must set a new password the next time it logs in',
                    ['count' => $revoked],
                ),
            extra: [
                'user_id' => $id,
                'kind' => $kind,
                'to' => (string) ($result['to'] ?? ''),
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

        [$password, $revoked, $wasRandom] = $this->assignNewPassword($request, $users, $user);

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

        /*
         * A hosting account is not just a row — it owns a Linux account, a home
         * full of files, and databases · `customer.delete` is what takes those
         * down, and what lets the admin say which of them should survive
         *
         * An admin account owns none of it (the system account is only ever
         * created for a hosting account), so it stays a plain row delete — no
         * agent, and nothing to ask about.
         */
        if (($user['role'] ?? '') === Permissions::WEBADMIN) {
            return $this->destroyHostingAccount($request, $user);
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
     * The dialog that asks what should happen to the files and the databases
     *
     * A row's `data-confirm` can only ever ask yes/no, and the question here is
     * not yes/no — it is "which of these two survives", with the account name
     * typed out to prove the right row was clicked. So it is a real form, the
     * same way SFTP's password and the customer email are.
     *
     * It arrives already knowing what there is to lose. "Delete the databases"
     * with no idea that there are four of them, named, is not a decision
     * anybody is really making.
     */
    public function deleteForm(Request $request): Response
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

        $databases = $this->app->db()->all(
            'SELECT db_name FROM databases_ WHERE owner_user_id = :u ORDER BY db_name',
            ['u' => $id],
        );

        $sites = $users->siteIds($id);

        return $this->ok(
            [
                'id' => $id,
                'form_action' => '/api/v2/users/' . $id,
                'username' => (string) $user['username'],
                'is_hosting' => ($user['role'] ?? '') === Permissions::WEBADMIN,
                // A website still standing is the one thing that blocks the
                // whole command — said here, on the screen that would otherwise
                // let the admin fill the form in and only then be refused
                'site_count' => count($sites),
                'has_sites' => $sites !== [],
                'databases' => array_map(
                    static fn (array $row): array => ['name' => (string) $row['db_name']],
                    $databases,
                ),
                'database_count' => count($databases),
                'has_databases' => $databases !== [],
                // Read from Paths, never written out as `/home/...` — a
                // portable install puts homes somewhere else entirely, and a
                // dialog naming a path that does not exist is worse than one
                // naming none
                'home' => Paths::usersDir() . '/' . (string) ($user['system_user'] ?? $user['username']),
            ],
            [],
            [[
                'type' => 'modal',
                'action' => 'show',
                'template' => 'user-delete-form.html',
                'title' => '{LNG_Delete account}',
                'titleClass' => 'icon-delete',
            ]],
        );
    }

    /**
     * Hands a hosting account's deletion to `customer.delete`
     *
     * Both switches are read as **explicitly sent or not sent**, never
     * defaulted to true here: a caller that forgets to mention the files gets
     * them kept, which is the outcome that can still be undone.
     *
     * @param array<string,mixed> $user
     */
    private function destroyHostingAccount(Request $request, array $user): Response
    {
        $id = (int) $user['id'];
        $username = (string) $user['username'];

        /*
         * The typed name arrives in the body from the dialog, but a `DELETE`
         * sent by hand may put it in the query string instead — the same
         * both-places read `destroy()` on a certificate does
         */
        $confirm = trim($request->payloadString('confirm_username')) ?: trim($request->get('confirm_username'));

        $result = $this->agent()->data('customer.delete', [
            'user_id' => $id,
            'confirm_username' => $confirm,
            'delete_files' => (bool) $request->payload('delete_files'),
            'delete_databases' => (bool) $request->payload('delete_databases'),
        ], $this->ctx->actor($request));

        $this->audit($request, 'user.delete', $username, [
            'role' => (string) $user['role'],
            'files_deleted' => (bool) ($result['files_deleted'] ?? false),
            'databases_deleted' => (bool) ($result['databases_deleted'] ?? false),
            'trash_path' => (string) ($result['trash_path'] ?? ''),
        ]);

        /*
         * What was **kept** is the part worth interrupting for · it is the half
         * somebody has to come back for, and the only moment they are certain
         * to read about it is right now, before the row leaves the table
         */
        $kept = [];

        if (($result['home_kept'] ?? '') !== '') {
            $kept[] = $this->t('The files are still at {path}, and the name stays taken until they are removed.', [
                'path' => (string) $result['home_kept'],
            ]);
        }

        if (($result['trash_path'] ?? '') !== '') {
            $kept[] = $this->t('The files were moved to {path} and can still be recovered from there.', [
                'path' => (string) $result['trash_path'],
            ]);
        }

        $keptDatabases = is_array($result['databases_kept'] ?? null) ? $result['databases_kept'] : [];

        if ($keptDatabases !== []) {
            $kept[] = $this->t('These databases were kept, with their own users and passwords: {names}', [
                'names' => implode(', ', $keptDatabases),
            ]);
        }

        $failed = is_array($result['databases_failed'] ?? null) ? $result['databases_failed'] : [];

        return $this->completed(
            (string) ($result['message'] ?? $this->t('Account {user} deleted', ['user' => $username])),
            'users',
            $result,
            notices: [
                ['level' => 'info', 'message' => implode(' ', $kept)],
                ['message' => $failed === [] ? '' : $this->t(
                    'The account is gone, but {count} database(s) could not be dropped: {names} — remove them from the databases page',
                    ['count' => count($failed), 'names' => implode(', ', array_column($failed, 'name'))],
                )],
            ],
        );
    }

    /**
     * Give this account a new password, revoke its sessions, write the audit entry
     *
     * Shared by the row's own Reset button and by the "send a new password"
     * email, because they are one operation with two ways of handing the result
     * over. Written twice, the two would drift — and the half that would drift
     * is "revoke every open session", which is the part that actually matters
     * when the reason for the reset is that somebody else knows the password.
     *
     * @param array<string,mixed> $user
     * @return array{0:string,1:int,2:bool} the password · sessions revoked · whether the system generated it
     */
    private function assignNewPassword(Request $request, UserRepository $users, array $user): array
    {
        $id = (int) $user['id'];
        $password = $request->payloadString('password');
        $wasRandom = $password === '';

        if ($wasRandom) {
            $password = Password::random(PasswordsController::suggestedLength());
        }

        $users->setPassword($id, $password, clearMustChange: false);

        // Only a password the system generated forces a change on next login —
        // one an admin typed was chosen deliberately, often while the customer
        // was on the phone, and forcing it to be replaced immediately would
        // undo the point of typing it
        if ($wasRandom) {
            $users->requirePasswordChange($id);
        }

        $revoked = (new SessionStore($this->app->db(), $this->app->config))->destroyAllFor($id);

        $this->audit($request, 'auth.password_reset', (string) $user['username'], [
            'by' => $this->ctx->username(),
            'sessions_revoked' => $revoked
        ]);

        return [$password, $revoked, $wasRandom];
    }

    /**
     * The version a website should get when nobody chose one
     *
     * Comes from the machine — the newest installed version still receiving
     * security fixes ({@see \Phpcp\Domain\PhpSupport::preferred()}) — never
     * from a constant, which can name a version this machine does not have.
     *
     * Empty when the agent cannot be reached; `site.create` then refuses with
     * a clear message rather than the account creation failing halfway.
     */
    private function defaultPhpVersion(Request $request): string
    {
        try {
            return (string) ($this->fetchPhpVersions($request)['default'] ?? '');
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * The handful of facts about this machine that only the machine can answer
     *
     * Host and port for the SFTP section of a welcome email, and the host the
     * panel's own address is built from. Fetched through `sftp.connection`,
     * which exists to hand out exactly these two values and nothing else about sshd.
     *
     * **Never fails the request.** A welcome email missing its SFTP section is
     * still worth sending; refusing to send anything because the agent happened
     * to be restarting would not be.
     *
     * @return array{host?:string,port?:int}
     */
    private function machineInfo(Request $request): array
    {
        try {
            $result = $this->agent()->data('sftp.connection', [], $this->ctx->actor($request));

            return [
                'host' => (string) ($result['host'] ?? ''),
                'port' => (int) ($result['port'] ?? 0),
            ];
        } catch (\Throwable) {
            return [];
        }
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
