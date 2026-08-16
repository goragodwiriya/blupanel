<?php

declare(strict_types=1);

namespace Phpcp\Http\V2;

use Phpcp\Http\ApiController;
use Phpcp\Kernel\Request;
use Phpcp\Kernel\Response;

/**
 * Machine information and the panel's own health — `/api/v2/system`
 *
 * `GET /system/health` **deliberately never calls the agent** — it exists to
 * answer "can the agent be reached at all?" if it depended on the agent
 * itself, it would fail right alongside it and have nothing to say at exactly
 * the moment an answer is needed most · the SPA calls this to raise a warning bar when the agent is down
 */
final class SystemController extends ApiController
{
    /** Machine information: distro, kernel, CPU, RAM, uptime */
    public function info(Request $request): Response
    {
        return $this->ok($this->agent()->data('system.info', [], $this->ctx->actor($request)));
    }

    /**
     * Set the machine's hostname
     *
     * PUT, not PATCH, because it sends the whole value (a single name) · the
     * side effects the system can't handle itself — rDNS, certificates,
     * restarting Postfix — are sent back in `follow_up` so the screen can
     * pass them along, instead of doing it silently and mail breaking with nobody knowing why
     */
    public function setHostname(Request $request): Response
    {
        $result = $this->agent()->data(
            'system.hostname_set',
            ['hostname' => (string) ($request->payload('hostname') ?? '')],
            $this->ctx->actor($request),
        );

        return $this->done(
            (string) ($result['message'] ?? 'Hostname saved'),
            [[
                'type' => 'notification',
                // The hostname affects mail and certificates — the admin needs to read what still needs following up
                'level' => ($result['follow_up'] ?? []) === [] ? 'success' : 'warning',
                'message' => (string) ($result['message'] ?? ''),
            ]],
            is_array($result) ? $result : [],
        );
    }

    /** The panel's own status — answers even when the agent is down */
    public function health(Request $request): Response
    {
        $agent = $this->agent();

        return $this->ok([
            'agent_available' => $agent->isAvailable(),
            'socket' => $agent->socketPath(),
            'mode' => $this->app->config->mode->value,
            'version' => PHPCP_VERSION,
            'time' => time(),
        ]);
    }
}
