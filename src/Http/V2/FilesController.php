<?php

declare (strict_types = 1);

namespace Phpcp\Http\V2;

use Phpcp\Agent\Actor;
use Phpcp\Domain\FileCatalog;
use Phpcp\Domain\FileRoots;
use Phpcp\Domain\FileScope;
use Phpcp\Http\ApiController;
use Phpcp\Http\ApiProblem;
use Phpcp\Kernel\Request;
use Phpcp\Kernel\Response;

/**
 * The file manager — `/api/v2/files` (PLAN-V2 §4.6, phase B's most complex resource)
 *
 * **No endpoint here touches a file's bytes directly** — everything goes through
 * the capability layer, which is where `PathGuard` checks twice (lexically, then
 * by realpath after privileges are dropped) and where file work actually runs
 * under the website owner's own privileges via `Executor::asUser()` per
 * ARCHITECTURE §4.4 · this layer's only job is to check whether the caller may
 * open this scope, then turn the HTTP request into arguments
 *
 * Every command always references `root` (the scope's key) + `path` (a path
 * relative to that scope) — no endpoint ever accepts an absolute machine path.
 * That's what makes traversal out of the scope impossible by the API's shape
 * itself, not just from value checking
 */
final class FilesController extends ApiController
{
    /**
     * The file scopes the caller may open
     *
     * The SPA must always call this first to know what's available to open — this
     * endpoint isn't in §4.6's route table because the old UI got this data
     * bundled with the HTML page, but the SPA has no HTML page to attach it to,
     * so it needs its own way to ask
     */
    public function roots(Request $request): Response
    {
        $scopes = [];

        foreach ($this->scopes() as $key => $scope) {
            $scopes[] = [
                'key' => $key,
                'label' => $this->t($scope->label),
                'kind' => $scope->kind,
                'site_id' => $scope->siteId,
                'writable' => $scope->writable
                // `root` (the real machine path) is never sent to the browser — the
                // frontend has nothing to do with it, and needlessly exposing the
                // machine's directory structure is a free gift to an attacker
            ];
        }

        return $this->ok($scopes, [
            'max_upload_bytes' => FileCatalog::MAX_TRANSFER_BYTES,
            'max_edit_bytes' => FileCatalog::MAX_EDIT_BYTES
        ]);
    }

    /** List the files in a folder */
    public function index(Request $request): Response
    {
        $root = $this->resolveRoot($request->get('root'));

        if (!$this->mayAccess($root)) {
            return $this->deniedScope();
        }

        $data = $this->agent()->data('file.list', [
            'root' => $root,
            'path' => $request->get('path'),
            'page' => $request->get('page'),
            'per_page' => $request->get('per_page')
        ], $this->ctx->actor($request));

        // The capability already paginated this — lift its computed values up to meta per §4.2's shape
        $entries = $data['entries'] ?? [];
        unset($data['entries']);

        return $this->ok($entries, $data);
    }

    /**
     * The folder tree for the left-hand navigation panel
     *
     * Uses `resolveRoot` just like `index` does, because the navigation panel is
     * drawn along with the screen, before the answer to `/files/roots` (a separate
     * request) arrives — so this first request never has a `root` attached yet
     */
    public function tree(Request $request): Response
    {
        $root = $this->resolveRoot($request->get('root'));

        if (!$this->mayAccess($root)) {
            return $this->deniedScope();
        }

        $data = $this->agent()->data('file.tree', [
            'root' => $root,
            'path' => $request->get('path'),
            'depth' => $request->get('depth')
        ], $this->ctx->actor($request));

        $nodes = $data['nodes'] ?? [];
        unset($data['nodes']);

        return $this->ok($nodes, $data);
    }

    /** Search for files by name under the open folder */
    public function search(Request $request): Response
    {
        $root = $this->resolveRoot($request->get('root'));

        if (!$this->mayAccess($root)) {
            return $this->deniedScope();
        }

        $data = $this->agent()->data('file.search', [
            'root' => $root,
            'path' => $request->get('path'),
            // `q` is §4.5's contract name · `searchTerm` also accepts the name Now.js's table sends
            'q' => $this->searchTerm($request)
        ], $this->ctx->actor($request));

        $entries = $data['entries'] ?? [];
        unset($data['entries']);

        return $this->ok($entries, $data);
    }

    /** A single file or folder's details — the properties box */
    public function info(Request $request): Response
    {
        $root = $request->get('root');

        if (!$this->mayAccess($root)) {
            return $this->deniedScope();
        }

        return $this->ok($this->agent()->data('file.info', [
            'root' => $root,
            'path' => $request->get('path')
        ], $this->ctx->actor($request)));
    }

    /** A text file's content, for editing */
    public function read(Request $request): Response
    {
        $root = $request->get('root');

        if (!$this->mayAccess($root)) {
            return $this->deniedScope();
        }

        return $this->ok($this->agent()->data('file.read', [
            'root' => $root,
            'path' => $request->get('path')
        ], $this->ctx->actor($request)));
    }

    /**
     * Save a file's content
     *
     * `create` is deliberately separate from overwriting — the default is "only
     * overwrite a file that already exists," so a mistyped filename gets an error
     * instead of quietly creating an empty file somewhere unintended
     */
    public function write(Request $request): Response
    {
        $root = $request->payloadString('root');

        if (!$this->mayAccess($root)) {
            return $this->deniedScope();
        }

        $result = $this->agent()->data('file.write', [
            'root' => $root,
            'path' => $request->payloadString('path'),
            'content' => (string) $request->payload('content', ''),
            'create' => $this->flag($request, 'create')
        ], $this->ctx->actor($request));

        return $this->refreshed(
            (string) ($result['message'] ?? 'File saved'),
            extra: is_array($result) ? $result : [],
        );
    }

    /**
     * Download a file
     *
     * **The one exception to "every endpoint answers JSON"** — a binary file
     * wrapped in base64 inside JSON grows by 33% and forces the client to decode
     * the whole file in memory before it can be saved, which doesn't work for
     * files tens of megabytes in size · this exception is documented explicitly
     * in `docs/openapi.yaml`
     *
     * Always sent as octet-stream + nosniff — letting the browser guess the type
     * itself would mean a user's .html file gets rendered on the panel's own
     * domain, an instant stored XSS that can steal an admin's session
     *
     * **No size ceiling anymore** — this used to reject anything larger than
     * 2.5 MB (the protocol's frame size) and suggest zipping it first, advice
     * that's useless for a backup file that's already compressed and always
     * larger than that · now it asks the agent for one chunk at a time and
     * streams each one straight through
     */
    public function download(Request $request): Response
    {
        $root = $request->get('root');

        if (!$this->mayAccess($root)) {
            return $this->deniedScope();
        }

        $path = $request->get('path');
        $actor = $this->ctx->actor($request);

        // The first chunk does two jobs at once: reports the file's total size,
        // and checks permission/existence before any headers are sent · a failure
        // here can still answer as JSON carrying an error code
        $first = $this->agent()->data('file.download', [
            'root' => $root,
            'path' => $path,
            'offset' => 0,
        ], $actor);

        if (base64_decode((string) ($first['content'] ?? ''), true) === false) {
            return $this->problem(ApiProblem::InternalError, 'The file could not be decoded');
        }

        $size = (int) ($first['size'] ?? 0);
        $agent = $this->agent();

        /*
         * Requests one chunk at a time until the file is exhausted, sending each
         * one out immediately
         *
         * **Never assemble the whole file before sending** — real website backups
         * run into the gigabytes, and holding the whole thing in memory is exactly
         * how to kill PHP with memory_limit on the one job users need it most for
         * · each chunk is 2.5 MB per the protocol frame's ceiling (see FileDownload)
         *
         * `Content-Length` can be sent because the size is known from the first
         * chunk — so the browser shows a real progress bar, not a number that
         * just climbs with no known destination
         */
        $stream = static function (callable $emit) use ($first, $agent, $root, $path, $actor): void {
            $chunk = $first;

            while (true) {
                $emit((string) base64_decode((string) ($chunk['content'] ?? ''), true));

                if (($chunk['eof'] ?? true) === true || (int) ($chunk['bytes'] ?? 0) <= 0) {
                    return;
                }

                $chunk = $agent->data('file.download', [
                    'root' => $root,
                    'path' => $path,
                    'offset' => (int) $chunk['offset'] + (int) $chunk['bytes'],
                ], $actor);
            }
        };

        return Response::stream($stream, FileCatalog::downloadType())
            ->withHeader('Content-Length', (string) $size)
            ->withHeader('Content-Disposition', self::disposition((string) ($first['name'] ?? 'download')))
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', 'private, no-store');
    }

    /**
     * Upload a file
     *
     * Accepts two shapes: multipart (`files[]`) from the browser, and JSON
     * carrying `name` + `content_base64` for scripts and curl · the second shape
     * is required because phase B's acceptance criteria is "every resource is
     * reachable by curl alone, with no browser ever opened"
     *
     * Sent to the agent one file at a time because the protocol has a per-command
     * size ceiling — bundling several files into one command would fail the whole
     * batch over a single small file
     */
    public function upload(Request $request): Response
    {
        $root = $request->payloadString('root');

        if (!$this->mayAccess($root)) {
            return $this->deniedScope();
        }

        $path = $request->payloadString('path');
        $overwrite = $this->flag($request, 'overwrite');
        $uploaded = [];

        foreach ($request->files('files') as $file) {
            $error = $this->uploadError($file);

            if ($error !== null) {
                return $this->problem(ApiProblem::ValidationError, $error, ['files' => $error]);
            }

            // is_uploaded_file stops a forged tmp_name from pointing at some other file on the machine
            if (!is_uploaded_file($file['tmp_name'])) {
                return $this->problem(ApiProblem::ValidationError, 'The uploaded file is not valid');
            }

            $content = file_get_contents($file['tmp_name']);

            if ($content === false) {
                return $this->problem(ApiProblem::ValidationError, 'The uploaded file could not be read');
            }

            $uploaded[] = $this->sendFile($request, $root, $path, (string) $file['name'], base64_encode($content), $overwrite);
        }

        // The JSON shape — one file per request
        $name = trim($request->payloadString('name'));
        if ($uploaded === [] && $name !== '') {
            $uploaded[] = $this->sendFile(
                $request,
                $root,
                $path,
                $name,
                (string) $request->payload('content_base64', ''),
                $overwrite,
            );
        }

        if ($uploaded === []) {
            return $this->problem(
                ApiProblem::ValidationError,
                'No uploaded file was received',
                ['files' => 'Send the file via multipart `files[]`, or send `name` + `content_base64` via JSON'],
            );
        }

        return $this->completed(
            $this->t('{count} files uploaded', ['count' => count($uploaded)]),
            'files',
            ['uploaded' => $uploaded, 'count' => count($uploaded)],
        );
    }

    /** Create a folder */
    public function makeDirectory(Request $request): Response
    {
        $root = $request->payloadString('root');

        if (!$this->mayAccess($root)) {
            return $this->deniedScope();
        }

        $result = $this->agent()->data('file.mkdir', [
            'root' => $root,
            'path' => $request->payloadString('path'),
            'name' => $request->payloadString('name')
        ], $this->ctx->actor($request));

        return $this->completed((string) ($result['message'] ?? 'Folder created'), 'files', $result);
    }

    /** Move or rename */
    public function move(Request $request): Response
    {
        return $this->transfer($request, copy: false);
    }

    /**
     * Copy
     *
     * Uses the same capability as move, with `copy` set — kept as a separate
     * endpoint because "copy" and "move" are different actions in the user's
     * eyes, and hiding the difference in a body flag makes reading the log later
     * unable to tell what actually happened
     */
    public function copy(Request $request): Response
    {
        return $this->transfer($request, copy: true);
    }

    /** Delete the selected files or folders */
    public function destroy(Request $request): Response
    {
        $root = $request->payloadString('root');

        if (!$this->mayAccess($root)) {
            return $this->deniedScope();
        }

        $items = $this->items($request);

        $this->agent()->data('file.delete', [
            'root' => $root,
            'items' => $items
        ], $this->ctx->actor($request));

        return $this->completed(
            $this->t('{count} items deleted', ['count' => count($items)]),
            'files',
            ['root' => $root, 'removed' => count($items)],
        );
    }

    /** Change file permissions */
    public function setPermissions(Request $request): Response
    {
        $root = $request->payloadString('root');

        if (!$this->mayAccess($root)) {
            return $this->deniedScope();
        }

        $result = $this->agent()->data('file.chmod', [
            'root' => $root,
            'path' => $request->payloadString('path'),
            'mode' => $request->payloadString('mode'),
            'recursive' => $this->flag($request, 'recursive')
        ], $this->ctx->actor($request));

        return $this->completed((string) ($result['message'] ?? 'Permissions changed'), 'files', is_array($result) ? $result : []);
    }

    /** Create an archive from the selected items */
    public function archive(Request $request): Response
    {
        $root = $request->payloadString('root');

        if (!$this->mayAccess($root)) {
            return $this->deniedScope();
        }

        $result = $this->agent()->data('file.zip', [
            'root' => $root,
            'path' => $request->payloadString('path'),
            'items' => $this->items($request),
            'archive' => $request->payloadString('archive')
        ], $this->ctx->actor($request));

        return $this->completed((string) ($result['message'] ?? 'Files compressed'), 'files', $result);
    }

    /** Extract an archive */
    public function extract(Request $request): Response
    {
        $root = $request->payloadString('root');

        if (!$this->mayAccess($root)) {
            return $this->deniedScope();
        }

        $result = $this->agent()->data('file.unzip', [
            'root' => $root,
            'path' => $request->payloadString('path'),
            'destination' => $request->payloadString('destination')
        ], $this->ctx->actor($request));

        return $this->completed((string) ($result['message'] ?? 'Archive extracted'), 'files', is_array($result) ? $result : []);
    }

    /** Move/copy — differ by only the one flag sent to the capability */
    private function transfer(Request $request, bool $copy): Response
    {
        $root = $request->payloadString('root');

        if (!$this->mayAccess($root)) {
            return $this->deniedScope();
        }

        $result = $this->agent()->data('file.move', [
            'root' => $root,
            'items' => $this->items($request),
            'destination' => $request->payloadString('destination'),
            'rename' => $request->payloadString('rename'),
            'copy' => $copy ? '1' : '',
            'overwrite' => $this->flag($request, 'overwrite')
        ], $this->ctx->actor($request));

        return $this->completed(
            (string) ($result['message'] ?? ($copy ? 'Copied' : 'Moved')),
            'files',
            is_array($result) ? $result : [],
        );
    }

    /** Send one file to the agent — returns the name it was actually saved under */
    private function sendFile(
        Request $request,
        string $root,
        string $path,
        string $name,
        string $base64,
        string $overwrite,
    ): string {
        $result = $this->agent()->data('file.upload', [
            'root' => $root,
            'path' => $path,
            'name' => $name,
            'content' => $base64,
            'overwrite' => $overwrite
        ], $this->ctx->actor($request));

        return (string) ($result['name'] ?? $name);
    }

    /**
     * The list of selected files — accepts either an array (JSON) or newline-separated text (a form)
     *
     * @return list<string>
     */
    private function items(Request $request): array
    {
        $items = $request->payload('items', []);

        if (is_string($items)) {
            $items = preg_split('/\R/', $items) ?: [];
        }

        if (!is_array($items)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn(mixed $item): string => is_scalar($item) ? trim((string) $item) : '',
            $items,
        ), static fn(string $item): bool => $item !== ''));
    }

    /**
     * A boolean flag the capability expects as a string
     *
     * The file capabilities accept '' = no · anything non-empty = yes (this comes
     * from HTML forms) · the SPA sends true/false as JSON, so it has to be
     * converted here, not by changing the capability, which is a layer the plan
     * says must not be touched
     */
    private function flag(Request $request, string $key): string
    {
        $value = $request->payload($key, false);

        return in_array($value, [true, 1, '1', 'true', 'on'], true) ? '1' : '';
    }

    /** @return array<string,FileScope> */
    private function scopes(): array
    {
        return FileRoots::forActor(
            new Actor($this->ctx->userId(), $this->ctx->username(), $this->ctx->role()),
            $this->app->db(),
        );
    }

    /**
     * Whether the caller may actually open this scope
     *
     * Checked here to produce an understandable message · the agent always
     * checks again on its own, because it must stand on its own if the web layer
     * is ever breached (ARCHITECTURE §4.2)
     */
    /**
     * The scope to actually use — unspecified = the first scope the caller may open
     *
     * Same reasoning as `GET /logs` leaving `source` unspecified: a list endpoint
     * called with no arguments should get a usable default, not an error · the
     * SPA's screen loads the table before the scope choices (which come from
     * `/files/roots`, a separate request) arrive, so this first request never has
     * a `root` attached
     *
     * Naming a scope the caller can't access is still a 403 as usual — "nothing
     * chosen yet" and "chose something out of reach" are different things
     */
    private function resolveRoot(string $rootKey): string
    {
        if ($rootKey !== '') {
            return $rootKey;
        }

        $scopes = $this->scopes();

        return $scopes === [] ? '' : (string) array_key_first($scopes);
    }

    /**
     * @param string $rootKey
     * @return mixed
     */
    private function mayAccess(string $rootKey): bool
    {
        return $rootKey !== '' && isset($this->scopes()[$rootKey]);
    }

    /**
     * @return mixed
     */
    private function deniedScope(): Response
    {
        // 403, not 404 — a scope key isn't a secret (it's site-<id>, already guessable)
        // and stating plainly "not permitted" tells the admin to fix permissions, not the URL
        return $this->problem(ApiProblem::Forbidden, 'You may not access this file area');
    }

    /** @param array{name:string,error:int,size:int} $file */
    private function uploadError(array $file): ?string
    {
        return match ($file['error']) {
            UPLOAD_ERR_OK => null,
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => $this->t('The file is larger than the server accepts'),
            UPLOAD_ERR_PARTIAL => $this->t('The upload was incomplete'),
            UPLOAD_ERR_NO_FILE => $this->t('No uploaded file was received'),
            default => $this->t('Upload failed (code {code})', ['code' => $file['error']]),
        };
    }

    /**
     * A Content-Disposition header that's safe with non-ASCII filenames
     *
     * A user-chosen filename can contain quotes or newlines, which can be used to
     * inject other headers — the ASCII value is sent sanitized as the primary
     * value, with the real name in filename* per RFC 5987
     */
    private static function disposition(string $name): string
    {
        $fallback = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?: 'download';

        return sprintf('attachment; filename="%s"; filename*=UTF-8\'\'%s', $fallback, rawurlencode($name));
    }
}
