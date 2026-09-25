<?php

namespace App\Http\Controllers\API;

use App\Enums\VaultPurpose;
use App\Http\Controllers\Controller;
use App\Models\File;
use App\Models\Vault;
use App\Models\VaultLink;
use App\Models\VaultWrite;
use App\Services\AiVaultWriter;
use App\Services\GalleryVaultWriter;
use App\Services\Processing\VisionImagePreparer;
use App\Services\VaultAskService;
use App\Services\VaultIngest;
use App\Services\VaultLinkService;
use App\Services\VaultOperationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The human namespace — /v/{orgSlug}/{vaultSlug}[/{resourceSlug}[/{fileSlug}]]
 * (VAULT_SYSTEM.md §4) — and the shared operation grammar (§5). Resolves the
 * same (vault, resource, file?) triple as the hash form and dispatches to the
 * same VaultOperationService.
 */
class VaultNamespaceController extends Controller
{
    public function __construct(
        protected VaultLinkService $links,
        protected VaultOperationService $ops,
    ) {}

    // -------------------------------------------------------------------------
    // Vault level
    // -------------------------------------------------------------------------

    public function vaultIndex(string $orgSlug, string $vaultSlug, Request $request): JsonResponse
    {
        $vault = $this->resolveVault($orgSlug, $vaultSlug, $request);

        if (! $vault) {
            return $this->notFound();
        }

        return $this->vaultJson($this->ops->vaultIndex(
            $vault,
            page: max(1, (int) $request->query('page', '1')),
            perPage: min(100, max(1, (int) $request->query('per_page', '20'))),
            tag: $request->query('tag'),
            category: $request->query('category'),
        ));
    }

    public function vaultOperation(string $orgSlug, string $vaultSlug, string $op, Request $request): JsonResponse
    {
        $vault = $this->resolveVault($orgSlug, $vaultSlug, $request);

        if (! $vault) {
            return $this->notFound();
        }

        return $this->dispatchVaultOperation($vault, $op, $request);
    }

    public function dispatchVaultOperation(Vault $vault, string $op, Request $request): JsonResponse
    {
        return match ($op) {
            'meta' => $this->vaultJson($this->ops->vaultMeta($vault)),
            'tags' => $this->vaultJson($this->ops->vaultTags($vault)),
            'resources' => $this->vaultJson($this->ops->vaultIndex(
                $vault,
                page: max(1, (int) $request->query('page', '1')),
                perPage: min(100, max(1, (int) $request->query('per_page', '20'))),
                tag: $request->query('tag'),
                category: $request->query('category'),
            )),
            'search' => $this->vaultSearch($vault, $request),
            'embed' => $this->vaultEmbed($request),
            'graph' => $this->vaultJson($this->ops->vaultGraph(
                $vault,
                nodeCap: min(1000, max(1, (int) $request->query('nodes', '300'))),
            )),
            default => $this->notFound(),
        };
    }

    /** POST /v/{org}/{vault}/ask — the boundary form of the vault ask head. */
    public function vaultAsk(string $orgSlug, string $vaultSlug, Request $request, VaultAskService $ask): SymfonyResponse
    {
        $vault = $this->resolveVault($orgSlug, $vaultSlug, $request);

        if (! $vault) {
            return $this->notFound();
        }

        return $this->dispatchAsk($vault, $request, $ask);
    }

    /** POST /h/{vaultHash}/ask — same op, machine address. */
    public function hashVaultAsk(string $vaultHash, Request $request, VaultAskService $ask): SymfonyResponse
    {
        $vault = $this->links->resolveVaultByHash($vaultHash, $request->ip(), $this->vaultKey($request), $this->grant($request));

        if (! $vault) {
            return $this->notFound();
        }

        return $this->dispatchAsk($vault, $request, $ask);
    }

    /**
     * Reasoning at the boundary: gated by the vault's ask policy (AI-facing
     * purposes only, unless exposure_policy opts in) on top of the usual
     * published/key resolution. Retrieval inside the ask head already goes through
     * this same grammar, so answers can only draw on what the vault projects.
     *
     * Content-negotiated: `Accept: text/event-stream` streams the answer as
     * SSE (`token` frames, then a `done` frame carrying sources/used); any
     * other Accept gets the single JSON payload. One route, one gate.
     */
    private function dispatchAsk(Vault $vault, Request $request, VaultAskService $ask): SymfonyResponse
    {
        if (! $vault->allowsAsk()) {
            return $this->denied();
        }

        $validated = $request->validate([
            'question' => ['required', 'string', 'min:3', 'max:2000'],
            'k' => ['sometimes', 'integer', 'min:1', 'max:20'],
        ]);
        $question = $validated['question'];
        $k = (int) ($validated['k'] ?? 5);

        $wantsStream = str_contains((string) $request->header('Accept', ''), 'text/event-stream');

        if (! $wantsStream) {
            try {
                return $this->vaultJson($ask->ask($vault, $question, $k));
            } catch (\Throwable $e) {
                Log::error("Vault boundary ask failed for {$vault->id}: ".$e->getMessage());

                return $this->vaultJson(['error' => 'Reasoning service unavailable'], 503);
            }
        }

        return $this->streamAsk($vault, $question, $k, $ask);
    }

    /**
     * SSE emitter: one `token` frame per answer fragment, a final `done`
     * frame with the full result (sources, counts), or an `error` frame.
     * Payloads are JSON-encoded so newlines never break the SSE framing.
     */
    private function streamAsk(Vault $vault, string $question, int $k, VaultAskService $ask): StreamedResponse
    {
        $emit = function (string $event, array $payload): void {
            echo "event: {$event}\n";
            echo 'data: '.json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n\n";
            if (function_exists('ob_get_level') && ob_get_level() > 0) {
                @ob_flush();
            }
            flush();
        };

        return response()->stream(function () use ($emit, $ask, $vault, $question, $k) {
            try {
                $result = $ask->askStreamed($vault, $question, $k, function (string $delta) use ($emit) {
                    $emit('token', ['text' => $delta]);
                });
                $emit('done', $result);
            } catch (\Throwable $e) {
                Log::error("Vault boundary ask (stream) failed for {$vault->id}: ".$e->getMessage());
                $emit('error', ['error' => 'Reasoning service unavailable']);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no', // ask nginx not to buffer the stream
        ]);
    }

    private function vaultSearch(Vault $vault, Request $request): JsonResponse
    {
        $q = (string) $request->query('q', '');
        $mode = $request->query('mode') === 'semantic' ? 'semantic' : 'keyword';

        if ($request->query('scope') === 'chunks') {
            $payload = $this->ops->vaultSearchChunks(
                $vault,
                $q,
                limit: min(100, max(1, (int) $request->query('limit', '20'))),
                mode: $mode,
            );

            return $payload === null ? $this->denied() : $this->vaultJson($payload);
        }

        return $this->vaultJson($this->ops->vaultSearch(
            $vault,
            q: $q,
            page: max(1, (int) $request->query('page', '1')),
            mode: $mode,
            facets: $this->facetFilters($request),
        ));
    }

    /**
     * `facet[field]=value` (or `facet[field][]=a&facet[field][]=b`) →
     * field ⇒ list of accepted values. Unknown fields are dropped later
     * against the vault's aggregating slots.
     *
     * @return array<string, list<string>>
     */
    private function facetFilters(Request $request): array
    {
        $raw = $request->query('facet', []);

        if (! is_array($raw)) {
            return [];
        }

        $facets = [];
        foreach ($raw as $field => $values) {
            $values = collect(is_array($values) ? $values : [$values])
                ->filter(fn ($v) => is_string($v) && $v !== '')
                ->values()
                ->all();

            if (is_string($field) && $values !== []) {
                $facets[$field] = $values;
            }
        }

        return $facets;
    }

    /**
     * `/embed` — query-embedding compute (Epic 4.1). Gated only by vault
     * resolution (published/key), like every Tier 0 operation; exposes no
     * vault data, so no further tier check.
     */
    private function vaultEmbed(Request $request): JsonResponse
    {
        $q = (string) $request->query('q', '');

        if ($q === '') {
            return $this->vaultJson(['error' => 'Missing query parameter q'], 422);
        }

        $payload = $this->ops->vaultEmbedQuery($q);

        return $payload === null
            ? $this->vaultJson(['error' => 'Embedding service unavailable'], 503)
            : $this->vaultJson($payload);
    }

    // -------------------------------------------------------------------------
    // Resource level
    // -------------------------------------------------------------------------

    public function resourceEntry(string $orgSlug, string $vaultSlug, string $resourceSlug, Request $request): StreamedResponse|JsonResponse
    {
        [$vault, $link] = $this->resolveResource($orgSlug, $vaultSlug, $resourceSlug, $request);

        if (! $link) {
            return $this->notFound();
        }

        $entry = $this->ops->resourceEntry($vault, $link);

        return $entry instanceof File ? $this->stream($entry) : $this->vaultJson($entry);
    }

    public function resourceOperation(string $orgSlug, string $vaultSlug, string $resourceSlug, string $op, Request $request): StreamedResponse|JsonResponse
    {
        [$vault, $link] = $this->resolveResource($orgSlug, $vaultSlug, $resourceSlug, $request);

        if (! $link) {
            return $this->notFound();
        }

        return $this->dispatchResourceOperation($vault, $link, $op, $request);
    }

    // -------------------------------------------------------------------------
    // File level
    // -------------------------------------------------------------------------

    public function fileEntry(string $orgSlug, string $vaultSlug, string $resourceSlug, string $fileSlug, Request $request): StreamedResponse|JsonResponse
    {
        [$vault, $link] = $this->resolveFile($orgSlug, $vaultSlug, $resourceSlug, $fileSlug, $request);

        if (! $link) {
            return $this->notFound();
        }

        $file = $this->ops->fileEntry($vault, $link);

        return $file ? $this->stream($file) : $this->denied();
    }

    public function fileOperation(string $orgSlug, string $vaultSlug, string $resourceSlug, string $fileSlug, string $op, Request $request): StreamedResponse|JsonResponse
    {
        [$vault, $link] = $this->resolveFile($orgSlug, $vaultSlug, $resourceSlug, $fileSlug, $request);

        if (! $link) {
            return $this->notFound();
        }

        return $this->dispatchFileOperation($vault, $link, $op, $request);
    }

    // -------------------------------------------------------------------------
    // Hash form — /h/{vaultHash}[/{linkHash}] resolves to the same entities
    // and dispatches into the same grammar (§4: one grammar, two address forms)
    // -------------------------------------------------------------------------

    public function hashVaultIndex(string $vaultHash, Request $request): JsonResponse
    {
        $vault = $this->links->resolveVaultByHash($vaultHash, $request->ip(), $this->vaultKey($request), $this->grant($request));

        if (! $vault) {
            return $this->notFound();
        }

        return $this->vaultJson($this->ops->vaultIndex(
            $vault,
            page: max(1, (int) $request->query('page', '1')),
            perPage: min(100, max(1, (int) $request->query('per_page', '20'))),
            tag: $request->query('tag'),
            category: $request->query('category'),
        ));
    }

    public function hashVaultOperation(string $vaultHash, string $op, Request $request): JsonResponse
    {
        $vault = $this->links->resolveVaultByHash($vaultHash, $request->ip(), $this->vaultKey($request), $this->grant($request));

        if (! $vault) {
            return $this->notFound();
        }

        return $this->dispatchVaultOperation($vault, $op, $request);
    }

    /**
     * Write at the boundary (VAULT_WRITE_METHODS.md §5) — the inbound
     * counterpart to the read grammar. Gated by a write-capable vault key
     * (not the read policy: writes must reach a private vault). The purpose
     * lists the verbs: every purpose that takes content shares `ingest` /
     * `update` / `withdraw` (VaultIngest), and `gallery` adds its projection
     * ops `activate` / `open` / `close`. Every accepted call is audited on the
     * vault — the audit is also the provenance `update`/`withdraw` check.
     */
    public function hashVaultWrite(string $vaultHash, string $method, Request $request, GalleryVaultWriter $writer, AiVaultWriter $aiWriter, VaultIngest $ingest): JsonResponse
    {
        return $this->dispatchWrite(Vault::findByHashCached($vaultHash), $method, $request, $writer, $aiWriter, $ingest);
    }

    /** Human-form write — same gate and grammar as the hash form (spec §4). */
    public function vaultWrite(string $orgSlug, string $vaultSlug, string $method, Request $request, GalleryVaultWriter $writer, AiVaultWriter $aiWriter, VaultIngest $ingest): JsonResponse
    {
        return $this->dispatchWrite(Vault::findBySlugsCached($orgSlug, $vaultSlug), $method, $request, $writer, $aiWriter, $ingest);
    }

    /**
     * Non-destructive write-auth probe (VAULT_WRITE_METHODS.md §5): the read
     * (GET) counterpart to the write boundary. Reports the write methods the
     * presented key may invoke here, without invoking any — so a consumer's
     * config UI can confirm a write key is bound before an opening depends on
     * it. When the key may `update` or `withdraw`, it also lists the resources
     * those ops can act on (`ingested`), so the consumer offers them on the
     * right items. Same gate, same 404 opacity as the write itself.
     */
    public function hashVaultWriteInfo(string $vaultHash, Request $request, VaultIngest $ingest): JsonResponse
    {
        return $this->writeInfo(Vault::findByHashCached($vaultHash), $request, $ingest);
    }

    /** Human-form write probe — same gate as the hash form. */
    public function vaultWriteInfo(string $orgSlug, string $vaultSlug, Request $request, VaultIngest $ingest): JsonResponse
    {
        return $this->writeInfo(Vault::findBySlugsCached($orgSlug, $vaultSlug), $request, $ingest);
    }

    private function writeInfo(?Vault $vault, Request $request, VaultIngest $ingest): JsonResponse
    {
        $probe = $this->links->writeCapabilities($vault, $request->ip(), $this->vaultKey($request));

        if ($probe['status'] !== 200 || ! $vault) {
            return $this->vaultJson(
                ['ok' => false, 'error' => $probe['error'] ?? 'Not found or expired'],
                $probe['status'] === 200 ? 404 : $probe['status'],
            );
        }

        $body = ['ok' => true, 'methods' => $probe['methods']];
        if (array_intersect(['update', 'withdraw'], $probe['methods']) !== []) {
            $body['ingested'] = $ingest->ingested($vault);
        }

        return $this->vaultJson($body);
    }

    private function dispatchWrite(?Vault $vault, string $method, Request $request, GalleryVaultWriter $writer, AiVaultWriter $aiWriter, VaultIngest $ingest): JsonResponse
    {
        $auth = $this->links->authorizeWrite($vault, $method, $request->ip(), $this->vaultKey($request));

        if ($auth['status'] !== 200) {
            return $this->vaultJson(
                ['ok' => false, 'error' => $auth['error'] ?? 'Not found or expired'],
                $auth['status'],
            );
        }

        $vault = $auth['vault'];
        $key = $auth['key'];

        try {
            $summary = match ($method) {
                'activate' => $writer->activate($vault, $this->activatePayload($request)),
                'open' => $writer->open($vault),
                'close' => $writer->close($vault),
                'ingest' => $this->dispatchIngest($vault, $request, $writer, $aiWriter, $ingest),
                'update' => $ingest->update($vault, $this->resourcePayload($request), $request->input('metadata')),
                'withdraw' => $ingest->withdraw($vault, $this->resourcePayload($request)),
                // allowsWriteMethod vouched for the method but no handler maps
                // it — only reachable if a purpose lists a verb without an
                // implementation (config drift), never from client input.
                default => null,
            };
        } catch (ValidationException $e) {
            return $this->vaultJson(['ok' => false, 'error' => $e->validator->errors()->first()], 400);
        }

        if ($summary === null) {
            return $this->vaultJson(['ok' => false, 'error' => 'Unsupported write method'], 400);
        }

        VaultWrite::create([
            'vault_id' => $vault->id,
            'vault_key_id' => $key->id,
            'method' => $method,
            'summary' => $summary,
            'client_ip' => $request->ip(),
            'created_at' => now(),
        ]);

        return $this->vaultJson(['ok' => true, 'result' => $summary]);
    }

    /**
     * The `activate` body: a non-empty list of vault link hashes to project.
     *
     * @return list<string>
     */
    private function activatePayload(Request $request): array
    {
        $validated = $request->validate([
            'resources' => ['required', 'array', 'min:1'],
            'resources.*' => ['string'],
        ]);

        return array_values($validated['resources']);
    }

    /** The `update`/`withdraw` target: the link hash `ingest` returned. */
    private function resourcePayload(Request $request): string
    {
        return $request->validate([
            'resource' => ['required', 'string', 'max:64'],
        ])['resource'];
    }

    /**
     * An `ingest` (multipart): an `image` file plus the `metadata` document
     * (a JSON object — `name`/`description` become the resource's columns,
     * the rest its metadata). The purpose decides what else it carries and
     * how the files are stored: `gallery` stores the photograph; `ai` also
     * needs a `descriptor` JSON document (VAULT_WRITE_METHODS.md §3/§7).
     *
     * @return array<string, mixed>
     */
    private function dispatchIngest(Vault $vault, Request $request, GalleryVaultWriter $writer, AiVaultWriter $aiWriter, VaultIngest $ingest): array
    {
        $request->validate([
            'image' => ['required', 'file', 'image', 'max:512000'],
            'metadata' => ['sometimes', 'nullable'],
        ]);
        $image = $request->file('image');
        if (! $image instanceof UploadedFile) {
            throw ValidationException::withMessages(['image' => 'An image file is required.']);
        }

        $document = $ingest->document($request->input('metadata'));

        if ($vault->purpose === VaultPurpose::AI) {
            $descriptor = json_decode((string) $request->validate([
                'descriptor' => ['required', 'string'],
            ])['descriptor'], true);
            if (! is_array($descriptor)) {
                throw ValidationException::withMessages([
                    'descriptor' => 'The descriptor must be a JSON object.',
                ]);
            }

            return $aiWriter->ingest($vault, $document, $descriptor, $image);
        }

        return $writer->ingest($vault, $document, $image);
    }

    public function hashEntry(string $vaultHash, string $linkHash, Request $request): StreamedResponse|JsonResponse
    {
        $link = $this->links->resolveVaultLink($vaultHash, $linkHash, $request->ip(), $this->vaultKey($request), $this->grant($request));

        if (! $link) {
            return $this->notFound();
        }

        if ($link->file_id !== null) {
            $file = $this->ops->fileEntry($link->vault, $link);

            return $file ? $this->stream($file) : $this->denied();
        }

        $entry = $this->ops->resourceEntry($link->vault, $link);

        return $entry instanceof File ? $this->stream($entry) : $this->vaultJson($entry);
    }

    public function hashOperation(string $vaultHash, string $linkHash, string $op, Request $request): StreamedResponse|JsonResponse
    {
        $link = $this->links->resolveVaultLink($vaultHash, $linkHash, $request->ip(), $this->vaultKey($request), $this->grant($request));

        if (! $link) {
            return $this->notFound();
        }

        return $link->file_id !== null
            ? $this->dispatchFileOperation($link->vault, $link, $op, $request)
            : $this->dispatchResourceOperation($link->vault, $link, $op, $request);
    }

    // -------------------------------------------------------------------------
    // Shared operation dispatch — one grammar for both address forms
    // -------------------------------------------------------------------------

    public function dispatchResourceOperation(Vault $vault, VaultLink $link, string $op, Request $request): StreamedResponse|JsonResponse
    {
        switch ($op) {
            case 'download':
                if (! $vault->allowsBinary() || ! $vault->is_downloadable) {
                    return $this->denied();
                }
                $file = $this->ops->firstExposedFile($vault, $link);

                return $file
                    ? Storage::disk($file->disk)->download($file->path, $file->filename)
                    : $this->notFound();
            case 'meta':
                return $this->vaultJson($this->ops->resourceMeta($vault, $link));
            case 'tags':
                return $this->vaultJson($this->ops->resourceTags($vault, $link));
            case 'files':
                return $this->vaultJson($this->ops->resourceFiles($vault, $link));
            case 'chunks':
                $payload = $this->ops->resourceChunks(
                    $vault,
                    $link,
                    from: $request->filled('from') ? (int) $request->query('from') : null,
                    to: $request->filled('to') ? (int) $request->query('to') : null,
                );

                return $payload === null ? $this->denied() : $this->vaultJson($payload);
            case 'links':
                $payload = $this->ops->resourceLinks($vault, $link);

                return $payload === null ? $this->denied() : $this->vaultJson($payload);
            case 'related':
                return $this->vaultJson($this->ops->resourceRelated($vault, $link));
            case 'preview':
                // The resource's face — served inline (unlike download, not
                // gated by is_downloadable: viewing ≠ taking a copy).
                $rendition = $request->query('rendition');
                if ($rendition !== null && ! is_string($rendition)) {
                    return $this->notFound();
                }
                $preview = $this->ops->resourcePreview($vault, $link, $rendition === 'ai-prepared' ? 'original' : $rendition);

                if ($preview === null) {
                    return $vault->allowsBinary() ? $this->notFound() : $this->denied();
                }

                $validation = Validator::make($request->query(), [
                    'max_bytes' => $rendition === 'ai-prepared'
                        ? ['sometimes', 'integer', 'between:'.VisionImagePreparer::MIN_REQUEST_BYTES.','.VisionImagePreparer::MAX_REQUEST_BYTES]
                        : ['prohibited'],
                ]);
                if ($validation->fails()) {
                    return response()->json(['error' => $validation->errors()->first()], 422);
                }
                $disk = Storage::disk($preview['disk']);
                if (! $disk->exists($preview['path'])) {
                    return $this->notFound();
                }
                if ($rendition === 'ai-prepared') {
                    $data = $disk->get($preview['path']);
                    $info = is_string($data) ? @getimagesizefromstring($data) : false;
                    if (! $info || ! in_array($info['mime'], VisionImagePreparer::SUPPORTED_MIME_TYPES, true)) {
                        return response()->json(['error' => 'This preview cannot be prepared as a vision image.'], 422);
                    }
                    $maxBytes = (int) $request->query('max_bytes', VisionImagePreparer::MAX_BYTES);
                    try {
                        $prepared = app(VisionImagePreparer::class)->prepare($data, $info['mime'], $maxBytes);
                    } catch (\RuntimeException) {
                        return response()->json(['error' => 'The image could not be prepared within the requested byte limit.'], 422);
                    }
                    $info = getimagesizefromstring($prepared['data']);

                    return response()->stream(static function () use ($prepared) {
                        echo $prepared['data'];
                    }, 200, [
                        'Content-Type' => $prepared['mime_type'],
                        'Content-Length' => (string) strlen($prepared['data']),
                        'Content-Disposition' => 'inline',
                        'Cache-Control' => 'private, no-store',
                        'X-Tydal-Image-Rendition' => 'ai-prepared',
                        'X-Tydal-Image-Width' => (string) $info[0],
                        'X-Tydal-Image-Height' => (string) $info[1],
                        'X-Tydal-Image-Max-Bytes' => (string) $maxBytes,
                    ]);
                }
                // Read only the header, not an entire large original, for optional dimensions.
                $stream = $disk->readStream($preview['path']);
                $info = false;
                if (is_resource($stream)) {
                    try {
                        $header = stream_get_contents($stream, 256 * 1024);
                        $info = is_string($header) ? @getimagesizefromstring($header) : false;
                    } finally {
                        fclose($stream);
                    }
                }

                return $disk->response(
                    $preview['path'],
                    $preview['filename'],
                    array_merge([
                        'Content-Type' => $preview['mime_type'],
                        'X-Tydal-Image-Rendition' => $rendition ?: 'original',
                    ], $info ? [
                        'X-Tydal-Image-Width' => (string) $info[0],
                        'X-Tydal-Image-Height' => (string) $info[1],
                    ] : [])
                );
            default:
                return $this->notFound();
        }
    }

    public function dispatchFileOperation(Vault $vault, VaultLink $link, string $op, Request $request): StreamedResponse|JsonResponse
    {
        switch ($op) {
            case 'meta':
                return $this->vaultJson($this->ops->fileMeta($vault, $link));
            case 'chunks':
                $payload = $this->ops->fileChunks(
                    $vault,
                    $link,
                    from: $request->filled('from') ? (int) $request->query('from') : null,
                    to: $request->filled('to') ? (int) $request->query('to') : null,
                );

                return $payload === null ? $this->denied() : $this->vaultJson($payload);
            case 'download':
                // ?rendition=<name> downloads a generated conversion instead
                // of the original — the vault-scoped form of media URLs.
                $rendition = $request->query('rendition');
                if (is_string($rendition) && $rendition !== '') {
                    $payload = $this->ops->fileRendition($vault, $link, $rendition);

                    return $payload
                        ? Storage::disk($payload['disk'])->download($payload['path'], $payload['filename'], ['Content-Type' => $payload['mime_type']])
                        : $this->notFound();
                }

                $file = $this->ops->fileDownload($vault, $link);

                return $file
                    ? Storage::disk($file->disk)->download($file->path, $file->filename)
                    : $this->denied();
            case 'renditions':
                $payload = $this->ops->fileRenditions($vault, $link);

                return $payload === null ? $this->denied() : $this->vaultJson($payload);
            default:
                return $this->notFound();
        }
    }

    // -------------------------------------------------------------------------

    /** @return array{0: Vault, 1: VaultLink}|array{0: null, 1: null} */
    private function resolveResource(string $orgSlug, string $vaultSlug, string $resourceSlug, Request $request): array
    {
        $vault = $this->resolveVault($orgSlug, $vaultSlug, $request);

        if (! $vault) {
            return [null, null];
        }

        $link = $this->links->resolveResourceLinkBySlug($vault, $resourceSlug);

        return $link ? [$vault, $link] : [null, null];
    }

    /** @return array{0: Vault, 1: VaultLink}|array{0: null, 1: null} */
    private function resolveFile(string $orgSlug, string $vaultSlug, string $resourceSlug, string $fileSlug, Request $request): array
    {
        $vault = $this->resolveVault($orgSlug, $vaultSlug, $request);

        if (! $vault) {
            return [null, null];
        }

        $link = $this->links->resolveFileLinkBySlug($vault, $resourceSlug, $fileSlug);

        return $link ? [$vault, $link] : [null, null];
    }

    private function resolveVault(string $orgSlug, string $vaultSlug, Request $request): ?Vault
    {
        return $this->links->resolveVaultBySlugs($orgSlug, $vaultSlug, $request->ip(), $this->vaultKey($request), $this->grant($request));
    }

    /**
     * Presented VaultKey, if any — header preferred, query fallback (spec §7).
     */
    private function vaultKey(Request $request): ?string
    {
        $key = $request->header('X-Vault-Key') ?? $request->query('vault_key');

        return is_string($key) && $key !== '' ? $key : null;
    }

    /**
     * Signed-grant credentials (`?sig=&exp=`, Epic 5.4) — the time-limited
     * publish form; verified against the vault salt in the link service.
     *
     * @return array{sig: string, exp: int}|null
     */
    private function grant(Request $request): ?array
    {
        $sig = $request->query('sig');
        $exp = $request->query('exp');

        return is_string($sig) && $sig !== '' && is_numeric($exp)
            ? ['sig' => $sig, 'exp' => (int) $exp]
            : null;
    }

    private function stream(File $file): StreamedResponse
    {
        return Storage::disk($file->disk)->response(
            $file->path,
            $file->filename,
            ['Content-Type' => $file->mime_type]
        );
    }

    /**
     * JSON for the public vault surface. Slashes and unicode are left
     * unescaped: the payload is URL-heavy (every card carries /h and /v
     * addresses) and description text is human-facing, so `\/` and `’`
     * are pure noise — valid JSON either way, but cleaner for agents and curl.
     */
    private function vaultJson(mixed $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function notFound(): JsonResponse
    {
        return $this->vaultJson(['error' => 'Not found or expired'], 404);
    }

    private function denied(): JsonResponse
    {
        return $this->vaultJson(['error' => 'This vault does not expose that tier'], 403);
    }
}
