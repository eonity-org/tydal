# 🗺️ TYDAL — System Diagrams

Visual companion to [`ARCHITECTURE_AND_ROADMAP.md`](ARCHITECTURE_AND_ROADMAP.md).
Diagrams are [Mermaid](https://mermaid.js.org/) and render directly on GitHub.

Status legend: ✅ exists · 🟡 partial · 🔜 planned
(updated 2026-07-03 — Milestones 1 & 2 closed)

---

## 1. Layered architecture — Data → Knowledge → Intelligence

```mermaid
flowchart TB
    subgraph COG["🟡 Cognitive Layer — Intelligence"]
        AITY["AITY agent 🔜<br/>(vault-aware reasoning)"]
        VMCP["vault-mcp ✅ vault-scoped, read-only by default<br/>get_vault / list_* / search_* /<br/>read_chunks / resolve / link_* /<br/>ingest (write key only)"]
        IMCP["@tydal/org-mcp ✅ org-wide, write-capable<br/>workspaces / uploads / tags"]
        AITY --> VMCP
    end

    subgraph CTX["🟢 Context Layer — Vault System ✅ CORE"]
        VAULT["Vault ✅ (promoted CDN)<br/>purpose · publish · tiers · exposure policy<br/>slug map · vault keys · /v + /h grammar"]
        OVERLAY["VaultSchemaOverlay 🔜 M3<br/>gallery / obsidian / ai roles"]
        VAULT --- OVERLAY
    end

    subgraph SEM["🔵 Semantic Layer — Resources"]
        RES["Resource ✅<br/>(canonical / component / supporting)<br/>+ mean embedding ✅"]
        CAT["Categories ✅"]
        TAG["Semantic tags ✅"]
        SCHEMA["Collection schemes ✅<br/>(schema = source of truth,<br/>schema:validate ✅)"]
        RES --- CAT
        RES --- TAG
        RES --- SCHEMA
    end

    subgraph PHY["🟣 Physical Layer — Storage & Media"]
        FILES["Files ✅ (roles + position)"]
        MEDIA["Media (Spatie) ✅"]
        CHUNKS["File chunks ✅ (embedded)"]
        CDN["Vault tables ✅<br/>vaults · vault_links ·<br/>workspace_vault · vault_keys"]
    end

    VMCP --> VAULT
    IMCP --> RES
    VAULT --> RES
    RES --> FILES
    RES --> MEDIA
    VAULT ==>|backed by| CDN

    classDef cog fill:#214F61,color:#fff,stroke:#143540;
    classDef ctx fill:#2e7d4f,color:#fff,stroke:#1b5e20;
    classDef sem fill:#1f6f8b,color:#fff,stroke:#114b5f;
    classDef phy fill:#911A2C,color:#fff,stroke:#5e0f1c;
    class AITY,VMCP,IMCP cog;
    class VAULT,OVERLAY ctx;
    class RES,CAT,TAG,SCHEMA sem;
    class FILES,MEDIA,CHUNKS,CDN phy;
```

---

## 2. Data model evolution (current → v2)

```mermaid
flowchart LR
    subgraph NOW["Pre-v2 (historical)"]
        direction TB
        O1["Organization"] --> W1["Workspace"]
        W1 --> C1["Collection"]
        C1 --> R1["Resource"]
        R1 --> F1["File(s)"]
        C1 -. search_indexes .-> S1["Elasticsearch"]
    end

    subgraph V2["TYDAL v2 🟡 (M1+M2 ✅ · M3 🔜)"]
        direction TB
        O2["Organization"] --> W2["Workspace"]
        W2 --> C2["Collection"]
        C2 --> R2["Resource ✅<br/>+ mean embedding"]
        R2 --> F2["File(s) ✅<br/>role: canonical/component/supporting<br/>+ position"]
        VV["Vault ✅ org-scoped<br/>purpose · publish · keys · tiers"]
        VV -. "M2M scope ✅<br/>(workspace_vault)" .-> W2
        VV -. "projects ✅ (vault_links:<br/>hash + slug)" .-> R2
        VV -. per-vault overlay 🔜 M3 .-> S2["Per-vault ES index 🔜"]
    end

    NOW ==>|"CDN → Vault promotion<br/>✅ done 2026-07-03"| V2
```

Key shift: file-centric → **resource-centric** ✅; global → **vault-scoped**
addressing ✅ (vault-scoped *semantic search* lands with M3); static →
**schema-driven** indexing (mappings schema-derived ✅, per-vault indexes 🔜);
embedding per file → **per resource** ✅ (mean aggregation);
CDN delivery → **Vault projection layer** ✅ (M2M over workspaces, not a
hierarchy node).

---

## 3. Ingestion & AI data flow

```mermaid
sequenceDiagram
    participant U as User / UI
    participant CORE as TYDAL Core (assets)
    participant EV as resource_events
    participant SEM as Semantic layer
    participant AI as AI agent (via vault-mcp)

    U->>CORE: Upload asset
    CORE->>CORE: Create Resource + files (roles)
    CORE-->>EV: events (timeline)
    CORE->>SEM: extract → chunk → embed (queued)
    Note over SEM: promoted metadata ✅<br/>chunk vectors ✅<br/>resource mean embedding ✅

    U->>AI: Query within a Vault
    AI->>SEM: vault-scoped tools only ✅<br/>(search_* → read_chunks → link_*)
    SEM-->>AI: identity cards + chunk evidence
    AI->>AI: reasoning / ranking (AITY 🔜)
    AI-->>U: answer + minted links (Tier 2, if allowed)
```

> **Invariant (enforced ✅):** external AI never touches the raw DB — it
> reasons **only** through the vault-scoped MCP tools, and the vault's tier
> policy decides what each verb may return.

---

## 4. Resource model and consuming experiences

```mermaid
flowchart LR
    SC["Schemas: structure, validation, indexing"] --> COLL["Collections"]
    COLL --> RE["Resources: files, metadata, relationships"]
    RE -->|curated into| WS["Workspaces"]
    WS -->|selected through| VA["Vaults: context, access rules, addressing"]
    VA --> GALLERY["Gallery applications"]
    VA --> KNOWLEDGE["Knowledge views"]
    VA --> AI["AI clients and agents"]

    classDef core fill:#3b2e58,color:#fff;
    classDef experience fill:#2e7d4f,color:#fff;
    class SC,COLL,RE,WS,VA core;
    class GALLERY,KNOWLEDGE,AI experience;
```

This diagram shows relationships, not a file-copy pipeline. The same resource
can participate in several workspace selections and Vault contexts. Collections
organize resources; galleries and knowledge views are consuming applications.
See [product terminology](ARCHITECTURE_AND_ROADMAP.md).
