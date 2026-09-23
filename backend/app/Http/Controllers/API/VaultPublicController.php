<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\File;
use App\Models\VaultLink;
use App\Services\VaultLinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VaultPublicController extends Controller
{
    public function __construct(protected VaultLinkService $vaultLinkService) {}

    // -------------------------------------------------------------------------
    // Flat form — /vault/{hash} (delivery links)
    // -------------------------------------------------------------------------

    /**
     * Serve the file inline in the browser.
     * Main Vault URL — no auth required, no is_downloadable check.
     * For resource-level links, serves the first active file.
     */
    public function serve(string $hash, Request $request): StreamedResponse|JsonResponse
    {
        $link = $this->vaultLinkService->resolveHash(
            $hash, $request->ip(), $this->vaultKey($request), $this->grant($request)
        );

        return $link ? $this->serveLink($link) : $this->notFound();
    }

    /**
     * Return JSON metadata for a Vault link.
     */
    public function info(string $hash, Request $request): JsonResponse
    {
        $link = $this->vaultLinkService->resolveHash(
            $hash, $request->ip(), $this->vaultKey($request), $this->grant($request)
        );

        return $link ? $this->infoPayload($link) : $this->notFound();
    }

    /**
     * Force-download the file. Requires is_downloadable = true on the Vault.
     */
    public function download(string $hash, Request $request): StreamedResponse|JsonResponse
    {
        $link = $this->vaultLinkService->resolveHash(
            $hash, $request->ip(), $this->vaultKey($request), $this->grant($request)
        );

        return $link ? $this->downloadLink($link) : $this->notFound();
    }

    // -------------------------------------------------------------------------
    // Machine form — /h/{vaultHash}/{linkHash}/info (legacy-style metadata
    // alias; the default GET and the operation grammar live in
    // VaultNamespaceController)
    // -------------------------------------------------------------------------

    public function infoByVaultHash(string $vaultHash, string $linkHash, Request $request): JsonResponse
    {
        $link = $this->vaultLinkService->resolveVaultLink($vaultHash, $linkHash, $request->ip());

        return $link ? $this->infoPayload($link) : $this->notFound();
    }

    // -------------------------------------------------------------------------
    // Shared serving logic
    // -------------------------------------------------------------------------

    /**
     * Standing credential for a private vault — same channel as the /h/ and
     * /v/ forms, so a key that opens the grammar also opens the flat address.
     */
    private function vaultKey(Request $request): ?string
    {
        $key = $request->header('X-Vault-Key') ?? $request->query('vault_key');

        return is_string($key) && $key !== '' ? $key : null;
    }

    /**
     * Time-limited signed grant (`?sig=&exp=`, Epic 5.4).
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

    private function serveLink(VaultLink $link): StreamedResponse|JsonResponse
    {
        $file = $this->resolveFile($link);

        if (! $file) {
            return $this->vaultJson(['error' => 'No file found'], 404);
        }

        return Storage::disk($file->disk)->response(
            $file->path,
            $file->filename,
            ['Content-Type' => $file->mime_type]
        );
    }

    /**
     * Boundary-safe link metadata. Ids are vault-scoped (link hashes) and file
     * URLs are vault addresses — never the internal resource/file UUID or the
     * raw storage URL, both of which would cross the vault boundary (the raw
     * URL also bypasses the hash/expiry/policy gate). Predates the tier-gated
     * grammar; `/…/meta` is the grammar-native equivalent.
     */
    private function infoPayload(VaultLink $link): JsonResponse
    {
        $vault = $link->vault;

        if ($link->file_id) {
            $file = $link->file()->first();

            return $this->vaultJson([
                'type' => 'file',
                'id' => $link->hash,
                'filename' => $file->filename,
                'mime_type' => $file->mime_type,
                'size' => $file->size,
                'url' => $this->vaultLinkService->buildUrl($link),
                'expires_at' => $link->expires_at?->toIso8601String(),
            ]);
        }

        $resource = $link->resource()->with('files')->first();

        return $this->vaultJson([
            'type' => 'resource',
            'id' => $link->hash,
            'name' => $resource->name,
            'metadata' => $resource->metadata,
            'files' => $resource->files->map(function (File $f) use ($vault, $link) {
                $fileLink = $this->vaultLinkService->getOrCreateLink($vault, $link->workspace, $link->resource_id, $f->id);

                return [
                    'id' => $fileLink->hash,
                    'filename' => $f->filename,
                    'mime_type' => $f->mime_type,
                    'size' => $f->size,
                    'url' => $this->vaultLinkService->buildUrl($fileLink),
                ];
            }),
            'expires_at' => $link->expires_at?->toIso8601String(),
        ]);
    }

    private function downloadLink(VaultLink $link): StreamedResponse|JsonResponse
    {
        if (! $link->vault->is_downloadable) {
            return $this->vaultJson(['error' => 'Downloads are not enabled for this Vault'], 403);
        }

        $file = $this->resolveFile($link);

        if (! $file) {
            return $this->vaultJson(['error' => 'No downloadable file found'], 404);
        }

        return Storage::disk($file->disk)->download(
            $file->path,
            $file->filename
        );
    }

    private function resolveFile(VaultLink $link): ?File
    {
        if ($link->file_id) {
            return $link->file()->first();
        }

        // Links FK-cascade with their resource, so the resource always exists.
        $resource = $link->resource()->with('snapshotFile')->first();

        return $resource->snapshotFile
            ?? $resource->files()->where('is_active', true)->first();
    }

    private function notFound(): JsonResponse
    {
        return $this->vaultJson(['error' => 'Not found or expired'], 404);
    }

    /**
     * JSON for the public vault surface — slashes and unicode left unescaped
     * (URL-heavy, human-facing payloads), matching VaultNamespaceController.
     */
    private function vaultJson(mixed $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
