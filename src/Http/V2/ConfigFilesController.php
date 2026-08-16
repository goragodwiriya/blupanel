<?php

declare(strict_types=1);

namespace Phpcp\Http\V2;

use Phpcp\Kernel\Request;
use Phpcp\Kernel\Response;

/**
 * The system's configuration files — `/api/v2/config-files` (a shared surface every screen can call)
 *
 * Broken out as its own resource instead of hidden under `/sites/{id}`,
 * because it's the same problem whether the file belongs to a website, DNS,
 * or mail · the screen calls it the same way, opens the same modal, and the
 * same security rule automatically applies to every file
 *
 * **The screen always sends a "key," never a path** — the real path is
 * assembled inside `ConfigFileCatalog` from data that's already been
 * validated, so a caller can never request a file outside the registry at all
 */
final class ConfigFilesController extends HostingController
{
    /**
     * Read a file within the requested scope — one website, or the whole machine's mail system
     *
     * The scope comes from the `scope` parameter, not the file path · adding a
     * new scope (DNS, etc.) happens in this one place, and every screen can
     * immediately use the same pattern
     *
     * @return array{0:string,1:array<string,mixed>}|Response  [capability name, args] or an error response
     */
    private function scopeArgs(Request $request, string $key = ''): array|Response
    {
        $scope = $request->get('scope');

        if ($scope === 'mail') {
            return ['mail.config_read', $key === '' ? [] : ['key' => $key]];
        }

        if ($scope === 'dns') {
            return ['dns.config_read', $key === '' ? [] : ['key' => $key]];
        }

        $siteId = (int) $request->get('site_id');

        if ($this->findSite($siteId) === null) {
            return $this->siteNotFound();
        }

        return ['config.file_read', ['site_id' => $siteId] + ($key === '' ? [] : ['key' => $key])];
    }

    /** The file list for one scope — currently only the website scope exists */
    public function index(Request $request): Response
    {
        $resolved = $this->scopeArgs($request);

        if ($resolved instanceof Response) {
            return $resolved;
        }

        [$capability, $args] = $resolved;
        $siteId = (int) ($args['site_id'] ?? 0);
        $scope = $request->get('scope');

        $result = $this->agent()->data($capability, $args, $this->ctx->actor($request));

        $rows = array_map(
            fn (array $file): array => [
                'label' => $this->t((string) ($file['label'] ?? '')),
            ] + $file + [
                'row_id' => $file['key'],
                // The row's button assembles its URL from values in the row —
                // both values must be attached, or a variable becomes empty and the file can't be opened
                'site_id' => $siteId,
                'scope' => $scope,
                'writable' => $file['kind'] === 'writable',
                // A label stating plainly whether it's editable or read-only —
                // an answer from the server, not the screen guessing from the filename
                'kind_label' => $file['kind'] === 'writable'
                    ? $this->t('You can edit this')
                    : $this->t('Read-only — the panel rewrites it'),
                'kind_tone' => $file['kind'] === 'writable' ? 'ok' : 'muted',
                'state' => $file['exists'] ? $this->t('In use') : $this->t('Not created yet'),
            ],
            (array) ($result['files'] ?? []),
        );

        return $this->ok($rows);
    }

    /**
     * Open a single file in a modal
     *
     * An editable file opens as a form, a non-editable one opens as read-only
     * text — decided by the server from the registry, not the screen · one
     * template supports both shapes
     */
    public function show(Request $request): Response
    {
        $resolved = $this->scopeArgs($request, (string) $request->param('key'));

        if ($resolved instanceof Response) {
            return $resolved;
        }

        [$capability, $args] = $resolved;
        $siteId = (int) ($args['site_id'] ?? 0);

        $result = $this->agent()->data($capability, $args, $this->ctx->actor($request));

        $writable = ($result['kind'] ?? '') === 'writable';

        return $this->ok(
            [
                'key' => (string) ($result['key'] ?? ''),
                'site_id' => $siteId,
                'scope' => (string) $request->get('scope'),
                'service' => (string) ($result['service'] ?? ''),
                'path' => (string) ($result['path'] ?? ''),
                'content' => (string) ($result['content'] ?? ''),
                'writable' => $writable,
                'readonly' => !$writable,
            ],
            [],
            [[
                'type' => 'modal',
                'action' => 'show',
                'template' => 'config-file.html',
                'title' => (string) ($result['path'] ?? ''),
                'titleClass' => $writable ? 'icon-edit' : 'icon-lock',
            ]],
        );
    }

    /**
     * Write an editable file
     *
     * **The writer never trusts a submitted key's claim about write
     * permission** — the capability decides for itself, from the registry,
     * whether this file is genuinely editable · a screen that submits a
     * read-only file's key is rejected at the lowest layer, not just by the
     * button never appearing on screen
     */
    public function update(Request $request): Response
    {
        /*
         * **The writer never trusts a submitted key's claim about write
         * permission** — the capability decides for itself, from the registry,
         * whether this file is genuinely editable · a screen that submits a
         * read-only file's key is rejected at the lowest layer, not just by the
         * button never appearing on screen
         *
         * The key comes in the request body, not the path — the form lives in
         * a modal, and nothing fills a path variable in for it
         * (`RouterManager` only fills those in for a page's own template)
         */
        $key = $request->payloadString('key');

        if ($request->payloadString('scope') === 'dns') {
            $result = $this->agent()->data('dns.custom_config', [
                'key' => $key,
                'content' => $request->payloadString('content'),
                'window' => (int) $request->payload('window', 0),
            ], $this->ctx->actor($request));

            return $this->saved(
                (string) ($result['message'] ?? 'Configuration saved'),
                'configFiles',
                is_array($result) ? $result : [],
            );
        }

        if ($request->payloadString('scope') === 'mail') {
            $result = $this->agent()->data('mail.custom_config', [
                'service' => $request->payloadString('service'),
                'key' => $key,
                'content' => $request->payloadString('content'),
                'window' => (int) $request->payload('window', 0),
            ], $this->ctx->actor($request));

            return $this->saved(
                (string) ($result['message'] ?? 'Configuration saved'),
                'configFiles',
                is_array($result) ? $result : [],
            );
        }

        $siteId = (int) $request->payload('site_id', 0);

        if ($this->findSite($siteId) === null) {
            return $this->siteNotFound();
        }

        $result = $this->agent()->data('site.custom_config', [
            'site_id' => $siteId,
            'key' => $key,
            'content' => $request->payloadString('content'),
            'window' => (int) $request->payload('window', 0),
        ], $this->ctx->actor($request));

        return $this->saved(
            (string) ($result['message'] ?? 'Configuration saved'),
            'configFiles',
            is_array($result) ? $result : [],
        );
    }
}
