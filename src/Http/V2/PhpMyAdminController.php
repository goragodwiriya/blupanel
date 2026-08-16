<?php

declare(strict_types=1);

namespace Phpcp\Http\V2;

use Phpcp\Agent\AgentException;
use Phpcp\Http\ApiController;
use Phpcp\Http\ApiProblem;
use Phpcp\Kernel\Request;
use Phpcp\Kernel\Response;

/**
 * A password-free bridge into phpMyAdmin — PLAN-V2 Phase M5
 *
 * phpMyAdmin supports `$cfg['Servers'][$i]['auth_type'] = 'signon'`, which reads a
 * username and password from a PHP session another app has prepared for it · so we
 * request the user's own dedicated MariaDB account from the agent (the only place
 * that can decrypt it), and stuff it into that session.
 *
 * **Why this is still POST even after moving to REST**
 * This is "logging in," not reading data — if it were GET, any website the user has
 * open could embed `<img src="https://panel:8443/api/v2/phpmyadmin/session">` and
 * silently make the victim's browser create a phpMyAdmin session · POST forces it
 * through the CSRF guard.
 *
 * **The one difference from the original: answers with JSON instead of redirecting**
 * The SPA fires this request with `fetch`, so it can't usefully follow a 302 itself
 * · the signon cookie still gets set from this response normally, since it's the
 * same origin — the page just navigates to `data.url` afterward · **the password
 * itself never once reaches the web page** — it travels agent → phpMyAdmin's own
 * session only, never through HTML, never through a query string, and never
 * through JSON that JavaScript can read.
 */
final class PhpMyAdminController extends ApiController
{
    /**
     * The session name phpMyAdmin reads from — must match `SignonSession` in config.inc.php
     *
     * Named so it never collides with the panel's own session, since both live on the same domain.
     */
    private const SIGNON_SESSION = 'phpcp_pma_signon';

    public function create(Request $request): Response
    {
        if (!$this->app->config->paths->hasPhpMyAdmin()) {
            return $this->problem(ApiProblem::NotFound, 'phpMyAdmin is not installed on this machine');
        }

        try {
            $credentials = $this->agent()->data('db.account_credentials', [], $this->ctx->actor($request));
        } catch (AgentException $e) {
            /*
             * **Never send the raw message back to the caller**
             *
             * This route is open to anyone with `db.view`, which includes customers ·
             * the message on an `ExecutionFailed` wrapped up from the layer below is
             * MariaDB's own stderr, verbatim — it names the socket path, the server
             * version, and sometimes even the system username — great initial
             * reconnaissance for choosing an attack, in exchange for nothing the
             * customer could actually do with it anyway.
             *
             * The detail goes to the machine's own log instead, which is where the
             * admin already looks when someone reports they can't open phpMyAdmin.
             */
            $this->app->logger()->error('Failed to prepare the database account for phpMyAdmin', [
                'user' => $this->ctx->username(),
                'error' => $e->getMessage(),
                'request_id' => $request->requestId,
            ]);

            return $this->problem(
                ApiProblem::InternalError,
                $this->t('The database account could not be prepared')
                . ' (' . $request->requestId . ')',
            );
        }

        if (!$this->writeSignonSession((string) $credentials['user'], (string) $credentials['password'])) {
            return $this->problem(
                ApiProblem::InternalError,
                $this->t('The phpMyAdmin session could not be opened — check that session.save_path for this pool points to a folder inside open_basedir and is writable'),
            );
        }

        // Go to the structure page of the given database, or the front page if none was given
        $database = $request->payloadString('db');
        $url = '/phpmyadmin/';

        if ($database !== '' && preg_match('/^[A-Za-z0-9_]{1,64}$/', $database) === 1) {
            $url .= 'index.php?route=/database/structure&db='.rawurlencode($database);
        }

        /*
         * `actions` is here **specifically so a table row action can call this
         * endpoint declaratively** — a `data-row-actions` entry with
         * `"method": "post"` and `"target": "_blank"` goes through
         * `ResponseHandler`'s `redirect` handler, which already supports opening
         * a URL in a new tab. The header button's own bespoke JS
         * (`openPhpMyAdmin` in ui.js) still reads `data.url` directly and never
         * looks at `actions`, so it keeps working unchanged either way.
         *
         * This is deliberately never a bare GET-able redirect at the HTTP level
         * — the URL only ever reaches the browser inside a POST response body,
         * after the CSRF-guarded signon session above has already been created.
         */
        return $this->ok(
            ['url' => $url],
            [],
            [['type' => 'redirect', 'url' => $url, 'target' => '_blank', 'delay' => 0]],
        );
    }

    /**
     * Write the username and password into the session phpMyAdmin will read from
     *
     * Must close the session immediately after writing — leaving it open would
     * lock the same user's next request (which is opening phpMyAdmin itself) until
     * it times out.
     *
     * The panel's own session must be closed before switching names, otherwise the two get mixed together.
     */
    private function writeSignonSession(string $user, string $password): bool
    {
        $previousName = session_name();

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        session_name(self::SIGNON_SESSION);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);

        // A PHP session can't be written when session.save_path sits outside
        // open_basedir, which is the default on Debian (/var/lib/php/sessions) ·
        // this has to be caught explicitly and reported clearly, instead of
        // turning into a 500 nobody can guess the cause of — found through
        // testing on a real machine.
        if (!@session_start()) {
            session_name($previousName);

            return false;
        }

        // Rotate the id every time a new ticket is issued — the same session-fixation guard used at panel login
        session_regenerate_id(true);

        $_SESSION['PMA_single_signon_user'] = $user;
        $_SESSION['PMA_single_signon_password'] = $password;
        $_SESSION['PMA_single_signon_host'] = 'localhost';

        session_write_close();
        session_name($previousName);

        return true;
    }
}
