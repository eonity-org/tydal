<?php

namespace App\Enums;

/**
 * What a vault projects and how its links behave (VAULT_SYSTEM.md §1).
 * `delivery` is the classic CDN — the default every existing row keeps.
 */
enum VaultPurpose: string
{
    case DELIVERY = 'delivery';
    case GALLERY = 'gallery';
    case OBSIDIAN = 'obsidian';
    case AI = 'ai';
    case MIXED = 'mixed';

    public function label(): string
    {
        return match ($this) {
            self::DELIVERY => 'Delivery (CDN)',
            self::GALLERY => 'Gallery',
            self::OBSIDIAN => 'Obsidian',
            self::AI => 'AI',
            self::MIXED => 'Mixed',
        };
    }

    /**
     * The write methods this purpose exposes at the boundary
     * (VAULT_WRITE_METHODS.md §3) — the symmetric counterpart to the read
     * tiers (allowsChunks/allowsBinary/allowsAsk). A write is accepted only
     * when its method is in this list AND the presented key carries the
     * matching `w:{method}` ability. Empty = no writes cross this boundary.
     *
     * @return list<string>
     */
    public function writeMethods(): array
    {
        return match ($this) {
            // The inbound ops are purpose-agnostic (VaultIngest): `ingest` adds
            // a resource to the vault's ingest target, `update` corrects one it
            // ingested, `withdraw` removes one. Each purpose's writer decides
            // only which files an `ingest` carries (VAULT_WRITE_METHODS.md §3).
            self::GALLERY => ['activate', 'open', 'close', 'ingest', 'update', 'withdraw'],
            self::AI => ['ingest', 'update', 'withdraw'],
            default => [],
        };
    }
}
