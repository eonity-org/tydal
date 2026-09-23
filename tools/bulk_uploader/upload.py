#!/usr/bin/env python3
"""
TYDAL Bulk Folder Uploader

Four modes via --phase:

  full    (default) Complete pipeline: upload every file, wait for AITY, auto-accept
                    name/description, then run cross-resource tag analysis for the whole
                    batch. Accepts --dedup, --max-tags, and all --tag-* thresholds.

  upload  Upload all files as fast as possible and stop. Each file is recorded
          as 'uploaded' in the state file. AITY runs in the background on the
          server — run --phase accept later to collect the results.

  accept  Read the state file, poll AITY for every 'uploaded' entry (returning
          instantly if it already finished), and apply name + description.

  tags    Cross-resource confidence analysis: collect all AI tag suggestions,
          compute how many resources share each tag, then auto-accept tags that
          meet the configured confidence/frequency thresholds. Add --dedup to
          have the backend LLM normalize labels before writing (merges synonyms,
          abbreviations, spelling variants). Safe to re-run. Use --dry-run first.

State is saved in .tydal_upload_state.json so every phase is re-runnable safely.
"""

import argparse
import json
import mimetypes
import signal
import sys
import time
from datetime import datetime, timezone
from pathlib import Path

try:
    import requests
except ImportError:
    print("Error: 'requests' is required. Install with: pip install requests")
    sys.exit(1)


# ─── Constants ────────────────────────────────────────────────────────────────

POLL_INTERVAL_S = 5
DEFAULT_POLL_TIMEOUT_S = 600  # 10 minutes


# ─── Graceful interrupt ───────────────────────────────────────────────────────

_interrupt_requested = False

def _sigint_handler(sig, frame):
    global _interrupt_requested
    if _interrupt_requested:
        print("\n  Force quitting — state may be incomplete for the current file.", flush=True)
        sys.exit(1)
    _interrupt_requested = True
    print("\n  Ctrl-C received — finishing current file then stopping safely.", flush=True)
    print("  Press Ctrl-C again to force quit (current file state will be lost).", flush=True)

signal.signal(signal.SIGINT, _sigint_handler)

def _check_interrupt():
    """Call at safe checkpoints (between files). Raises SystemExit if interrupted."""
    if _interrupt_requested:
        _log("Stopped cleanly after completing last file.")
        sys.exit(0)


# ─── API Client ───────────────────────────────────────────────────────────────

class TydalClient:
    """Thin wrapper around TYDAL's REST API."""

    def __init__(self, base_url: str):
        self.base_url = base_url.rstrip("/")
        self._session = requests.Session()
        self._session.headers["Accept"] = "application/json"

    def _url(self, path: str) -> str:
        return f"{self.base_url}/{path.lstrip('/')}"

    def _raise(self, resp: requests.Response):
        try:
            body = resp.json()
            msg = body.get("message") or json.dumps(body)
        except Exception:
            msg = resp.text or f"HTTP {resp.status_code}"
        resp.reason = msg
        resp.raise_for_status()

    def login(self, email: str, password: str) -> str:
        resp = self._session.post(self._url("/login"), json={"email": email, "password": password})
        self._raise(resp)
        token = resp.json().get("data", {}).get("token")
        if not token:
            raise RuntimeError(f"Login succeeded but no token returned: {resp.text}")
        self._session.headers["Authorization"] = f"Bearer {token}"
        return token

    def switch_organization(self, org_id: str):
        resp = self._session.post(self._url(f"/organizations/{org_id}/switch"))
        self._raise(resp)

    def create_resource(
        self,
        name: str,
        collection_id: int,
        resource_type: str = "document",
        visibility: str = "organization",
    ) -> dict:
        resp = self._session.post(
            self._url("/resources"),
            json={"name": name, "type": resource_type, "collection_id": collection_id, "visibility": visibility},
        )
        self._raise(resp)
        return resp.json()["data"]["resource"]

    def upload_file(self, resource_id: str, file_path: Path, role: str = "canonical") -> dict:
        mime, _ = mimetypes.guess_type(str(file_path))
        with open(file_path, "rb") as fh:
            resp = self._session.post(
                self._url(f"/resources/{resource_id}/files"),
                files={"File": (file_path.name, fh, mime or "application/octet-stream")},
                data={"role": role},
            )
        self._raise(resp)
        return resp.json()["data"]["file"]

    def get_aity_status(self, resource_id: str, file_id: str) -> dict:
        resp = self._session.get(self._url(f"/resources/{resource_id}/files/{file_id}/aity-status"))
        self._raise(resp)
        return resp.json()["data"]

    def update_resource(self, resource_id: str, **fields) -> dict:
        resp = self._session.put(self._url(f"/resources/{resource_id}"), json=fields)
        self._raise(resp)
        return resp.json()["data"]["resource"]

    def clear_file_ai_suggestions(self, resource_id: str, file_id: str):
        """Best-effort — marks suggestions as processed so the UI badge clears."""
        self._session.delete(self._url(f"/resources/{resource_id}/files/{file_id}/ai-suggestions"))

    def create_or_find_tag(self, label: str, entity_type: str, description: str | None = None) -> dict:
        """Find or create a semantic tag in the current organisation (idempotent)."""
        resp = self._session.post(
            self._url("/semantic-tags"),
            json={"label": label, "entity_type": entity_type, "description": description, "source": "ai_bulk"},
        )
        self._raise(resp)
        return resp.json()["data"]

    def get_resource_tag_ids(self, resource_id: str) -> list[int]:
        """Return IDs of semantic tags already applied to a resource."""
        resp = self._session.get(self._url(f"/resources/{resource_id}"))
        self._raise(resp)
        resource = resp.json()["data"]["resource"]
        tags = resource.get("semantic_tags") or resource.get("semanticTags") or []
        return [t["id"] for t in tags]

    def sync_resource_tags(self, resource_id: str, tag_ids: list[int]):
        """Replace the full tag set on a resource (full sync — merging is done by the caller)."""
        resp = self._session.put(
            self._url(f"/resources/{resource_id}/semantic-tags"),
            json={"tag_ids": tag_ids},
        )
        self._raise(resp)

    def llm_chat(self, messages: list[dict]) -> str:
        """Proxy a chat request through the backend's configured LLM driver via POST /aity/chat."""
        resp = self._session.post(self._url("/aity/chat"), json={"messages": messages})
        self._raise(resp)
        return resp.json()["data"]["response"]


# ─── Upload State ─────────────────────────────────────────────────────────────

class UploadState:
    """
    Tracks per-file progress across phases.

    Statuses:
      uploaded  File is in TYDAL, AITY is running, suggestions not yet applied.
      done      Suggestions applied (or AITY returned not_applicable/failed).
      failed    An unrecoverable error occurred; inspect and retry manually.
    """

    def __init__(self, state_file: Path):
        self.path = state_file
        self._data: dict = {}
        if state_file.exists():
            with open(state_file) as fh:
                self._data = json.load(fh)

    def _save(self):
        with open(self.path, "w") as fh:
            json.dump(self._data, fh, indent=2)

    def status(self, filename: str) -> str | None:
        return self._data.get(filename, {}).get("status")

    def is_done(self, filename: str) -> bool:
        return self.status(filename) == "done"

    def is_uploaded(self, filename: str) -> bool:
        return self.status(filename) == "uploaded"

    def pending_accept(self) -> list[tuple[str, dict]]:
        """All entries that are uploaded but not yet accepted."""
        return [(fn, entry) for fn, entry in self._data.items() if entry.get("status") == "uploaded"]

    def mark_uploaded(self, filename: str, resource_id: str, file_id: str, initial_name: str):
        self._data[filename] = {
            "status": "uploaded",
            "resource_id": resource_id,
            "file_id": file_id,
            "initial_name": initial_name,
            "uploaded_at": _now(),
        }
        self._save()

    def mark_done(self, filename: str, resource_id: str, name: str, description: str | None):
        entry = self._data.get(filename, {})
        entry.update({
            "status": "done",
            "resource_id": resource_id,
            "accepted_name": name,
            "accepted_description": description,
            "done_at": _now(),
        })
        # Keep file_id — needed for --phase tags to re-poll AITY suggestions.
        entry.pop("initial_name", None)
        self._data[filename] = entry
        self._save()

    def mark_failed(self, filename: str, error: str):
        self._data[filename] = {"status": "failed", "error": error, "failed_at": _now()}
        self._save()

    def all_processable(self) -> list[tuple[str, dict]]:
        """Entries with a file_id available — status done or uploaded, not failed."""
        return [
            (fn, entry)
            for fn, entry in self._data.items()
            if entry.get("status") in ("done", "uploaded") and entry.get("file_id")
        ]

    def is_tags_done(self, filename: str) -> bool:
        return bool(self._data.get(filename, {}).get("tags_done"))

    def mark_tags_done(self, filename: str, accepted: list[dict], skipped: list[dict]):
        entry = self._data.get(filename, {})
        entry["tags_done"] = True
        entry["accepted_tags"] = accepted
        entry["skipped_tags"] = skipped
        entry["tags_done_at"] = _now()
        self._data[filename] = entry
        self._save()


# ─── Helpers ──────────────────────────────────────────────────────────────────

def _now() -> str:
    return datetime.now(timezone.utc).isoformat().replace("+00:00", "Z")

def _log(msg: str):
    print(msg, flush=True)

def _print_summary(counts: dict, failed: list[str], skipped: list[str]):
    _log(f"\n{'═' * 50}")
    parts = [f"{counts.get('done') or counts.get('uploaded', 0)} {'done' if 'done' in counts else 'uploaded'}"]
    if counts.get("skipped"):
        parts.append(f"{counts['skipped']} skipped")
    if counts.get("failed"):
        parts.append(f"{counts['failed']} failed")
    _log("Results: " + ", ".join(parts))
    if failed:
        _log("\nFailed files:")
        for fn in failed:
            _log(f"  ✗ {fn}")
        _log(
            "\nTo retry failed files: open the state file, change their \"status\" "
            "from \"failed\" to \"uploaded\", then re-run --phase accept."
        )
    if skipped:
        _log("\nSkipped files (already processed):")
        for fn in skipped:
            _log(f"  – {fn}")

def infer_resource_type(file_path: Path) -> str:
    mime, _ = mimetypes.guess_type(str(file_path))
    if not mime:
        return "document"
    if mime.startswith("image/"):
        return "image"
    if mime.startswith("video/"):
        return "video"
    if mime.startswith("audio/"):
        return "audio"
    return "document"

def humanise_name(stem: str) -> str:
    return stem.replace("_", " ").replace("-", " ").strip()

def _parse_llm_json(text: str) -> list:
    """Extract a JSON array from an LLM response, tolerating markdown fences."""
    text = text.strip()
    if text.startswith("```"):
        lines = text.splitlines()
        # Drop the opening fence line and any closing fence
        inner = lines[1:]
        if inner and inner[-1].strip() == "```":
            inner = inner[:-1]
        text = "\n".join(inner).strip()
    return json.loads(text)

def poll_until_done(client: TydalClient, resource_id: str, file_id: str, timeout_s: int) -> dict:
    deadline = time.time() + timeout_s
    while time.time() < deadline:
        status = client.get_aity_status(resource_id, file_id)
        stage = status.get("stage")
        _log(f"  AITY stage: {stage or 'unknown'}")
        if stage in ("done", "failed", "not_applicable"):
            return status
        time.sleep(POLL_INTERVAL_S)
    raise TimeoutError(f"AITY did not finish within {timeout_s}s")

def apply_suggestions(
    client: TydalClient,
    resource_id: str,
    file_id: str,
    status: dict,
    initial_name: str,
    keep_suggestions: bool = False,
) -> str:
    """
    Applies AI-suggested name + description to the resource.
    Tags are always skipped (left for manual review in the UI).

    keep_suggestions=False (default): clears all suggestion system files so the UI
      badge disappears. Tags are gone from the UI too.
    keep_suggestions=True: skips the clear call so all suggestion system files stay
      active — the AITY badge remains lit and tag suggestions stay visible in
      the UI for manual review.
    """
    stage = status.get("stage")
    suggestions = status.get("suggestions") or {}
    update: dict = {}

    if stage == "done" and suggestions:
        if suggestions.get("name"):
            update["name"] = suggestions["name"]
            _log(f"  Accepted name:        {suggestions['name']!r}")
        if suggestions.get("description"):
            update["description"] = suggestions["description"]
            preview = suggestions["description"][:100].replace("\n", " ")
            _log(f"  Accepted description: {preview!r}...")
        tag_count = len(suggestions.get("tags") or [])
        if tag_count:
            _log(f"  {tag_count} tag suggestion(s) pending manual review in UI.")
    elif stage == "not_applicable":
        _log("  AITY: file type not supported for enrichment.")
    elif stage == "failed":
        _log(f"  AITY failed: {status.get('error') or 'unknown error'}")
    else:
        _log(f"  AITY ended with unexpected stage: {stage!r}")

    if update:
        client.update_resource(resource_id, **update)
        if keep_suggestions:
            _log("  Resource updated. Suggestions kept active for tag review in UI.")
        else:
            client.clear_file_ai_suggestions(resource_id, file_id)
            _log("  Resource updated with AI suggestions.")

    return update.get("name", initial_name)


# ─── Dedup prompt ────────────────────────────────────────────────────────────

_DEDUP_SYSTEM = """\
You are a semantic data analyst helping to clean up a tag taxonomy for a digital asset management system.

You will receive a JSON list of candidate tags. Each tag has:
  label    — tag text
  type     — entity type: person | organization | place | thing | tag
             ('tag' means the system could not identify the entity type)
  freq     — how many documents in the batch suggested this tag
  avg_conf — average AI confidence (0.0–1.0)
  in       — up to 5 document names this tag appears in

Your tasks:

1. RECLASSIFY — For any tag with type "tag", suggest the correct entity type if you can identify it.
   Valid types: person | organization | place | thing | tag (keep "tag" only if truly unclassifiable).

2. MERGE — Identify groups of 2+ tags that refer to the same concept and should be merged.
   This includes abbreviations (ML vs Machine Learning), spelling variants, synonyms, near-duplicates.
   Merging rules:
   - Only merge tags of the SAME entity type (apply reclassifications first when evaluating).
   - Choose the best canonical label (prefer full names, proper casing, most descriptive).
   - List the OTHER labels (not the canonical) as duplicates.
   - Be conservative: only group tags you are confident refer to the same concept.
   - Do NOT merge tags that are related but distinct (e.g. "Finance" and "Financial Report").

Return ONLY a valid JSON object — no explanation, no markdown fences:
{
  "reclassify": [
    {"label": "Python", "type": "thing"}
  ],
  "merges": [
    {"canonical": "Machine Learning", "type": "thing", "duplicates": ["ML", "AI"], "reason": "..."}
  ]
}
Use empty arrays if nothing applies."""

_TOP_TAGS_SYSTEM = """\
You are a tag curator for a digital asset management system.
You will receive a resource (name and description) and a list of candidate tags with their confidence scores.
Your task: select the most relevant and specific tags for this resource, up to the limit in max_tags.

Rules:
- Prefer specific over generic tags.
- Prefer tags clearly relevant to the resource's content, not tangentially related.
- Respect max_tags. If fewer candidates are clearly relevant, return fewer.
- Return ONLY a valid JSON array of selected label strings — no explanation, no markdown.

Example: ["Machine Learning", "Python", "Tutorial"]"""


# ─── Phase: upload ────────────────────────────────────────────────────────────

def phase_upload(
    client: TydalClient,
    folder: Path,
    collection_id: int,
    state: UploadState,
    extensions: list[str] | None,
    visibility: str,
):
    candidates = sorted(f for f in folder.iterdir() if f.is_file() and not f.name.startswith("."))
    if extensions:
        candidates = [f for f in candidates if f.suffix.lower().lstrip(".") in extensions]

    total = len(candidates)
    _log(f"\nFound {total} file(s) to upload.\n{'─' * 50}")
    counts = {"uploaded": 0, "skipped": 0, "failed": 0}
    failed_files: list[str] = []
    skipped_files: list[str] = []

    for idx, file_path in enumerate(candidates, 1):
        _check_interrupt()
        filename = file_path.name
        _log(f"\n[{idx}/{total}] {filename}")

        if state.is_done(filename):
            _log("  Already done — skipping.")
            counts["skipped"] += 1
            skipped_files.append(filename)
            continue

        if state.is_uploaded(filename):
            _log("  Already uploaded (pending accept) — skipping.")
            counts["skipped"] += 1
            skipped_files.append(filename)
            continue

        try:
            rtype = infer_resource_type(file_path)
            initial_name = humanise_name(file_path.stem)
            _log(f"  Creating resource (type={rtype})...")
            resource = client.create_resource(initial_name, collection_id, rtype, visibility)
            resource_id = resource["id"]
            _log(f"  Resource created → {resource_id}")

            size_kb = file_path.stat().st_size / 1024
            _log(f"  Uploading {size_kb:.1f} KB...")
            file_record = client.upload_file(resource_id, file_path)
            file_id = file_record["id"]
            _log(f"  Uploaded → file {file_id}  (AITY running in background)")

            state.mark_uploaded(filename, resource_id, file_id, initial_name)
            counts["uploaded"] += 1

        except Exception as exc:
            _log(f"  ERROR: {exc}")
            state.mark_failed(filename, str(exc))
            counts["failed"] += 1
            failed_files.append(filename)

    _print_summary(counts, failed_files, skipped_files)
    _log("Run --phase accept when ready to apply AI suggestions.")


# ─── Phase: accept ────────────────────────────────────────────────────────────

def phase_accept(client: TydalClient, state: UploadState, poll_timeout: int, keep_suggestions: bool = False):
    pending = state.pending_accept()

    if not pending:
        _log("Nothing to accept — no files in 'uploaded' state.")
        return

    total = len(pending)
    _log(f"\n{total} file(s) pending suggestion acceptance.\n{'─' * 50}")
    counts = {"done": 0, "failed": 0}
    failed_files: list[str] = []

    for idx, (filename, entry) in enumerate(pending, 1):
        _check_interrupt()
        resource_id = entry["resource_id"]
        file_id = entry["file_id"]
        initial_name = entry.get("initial_name", filename)
        _log(f"\n[{idx}/{total}] {filename}  (resource {resource_id})")

        try:
            _log(f"  Polling AITY status (timeout {poll_timeout}s)...")
            status = poll_until_done(client, resource_id, file_id, poll_timeout)
            accepted_name = apply_suggestions(client, resource_id, file_id, status, initial_name, keep_suggestions)
            state.mark_done(filename, resource_id, accepted_name, None)
            counts["done"] += 1
            _log("  Done.")

        except Exception as exc:
            _log(f"  ERROR: {exc}")
            state.mark_failed(filename, str(exc))
            counts["failed"] += 1
            failed_files.append(filename)

    _print_summary(counts, failed_files, [])


# ─── Phase: tags ─────────────────────────────────────────────────────────────

def phase_tags(
    client: TydalClient,
    state: UploadState,
    poll_timeout: int,
    confidence: float,
    mid_confidence: float,
    min_frequency: int,
    dedup: bool,
    max_tags: int | None,
    dry_run: bool,
    clear_suggestions: bool = False,
):
    """
    Collect AI tag suggestions across all processed resources, optionally run
    AITY dedup to normalise labels, then auto-accept tags that clear either:

      - Individual:  tag.confidence >= confidence        (default 0.80)
      - Collective:  tag.confidence >= mid_confidence    (default 0.65)
                     AND tag appeared in >= min_frequency resources (default 3)

    With --dedup labels are normalised before decisions (synonyms merged).
    With --max-tags the LLM selects the most relevant N tags per resource.
    clear_suggestions=True clears AITY suggestion files after tagging (used by full mode).
    """
    processable = state.all_processable()

    if not processable:
        _log("No processable entries in state file (need status=done or uploaded with file_id).")
        return

    total = len(processable)
    _log(f"\nCollecting tag suggestions from {total} resource(s)...")
    _log(f"Thresholds:  individual >= {confidence:.0%}   or   >= {mid_confidence:.0%} + frequency >= {min_frequency}")
    if dedup:
        _log("Dedup:  enabled — AITY will normalise labels before decisions.")
    if max_tags:
        _log(f"Max tags:    {max_tags} per resource (LLM selects most relevant).")
    if dry_run:
        _log("DRY RUN — no changes will be written.")
    _log(f"{'─' * 50}")

    # ── Step 1: gather suggestions ─────────────────────────────────────────────
    resource_tags: dict[str, list[dict]] = {}
    resource_ids: dict[str, str] = {}
    name_map: dict[str, str] = {}   # filename → display name for LLM context
    desc_map: dict[str, str] = {}   # filename → description for LLM context

    for filename, entry in processable:
        resource_id = entry["resource_id"]
        file_id = entry["file_id"]
        resource_ids[filename] = resource_id
        name_map[filename] = entry.get("accepted_name") or entry.get("initial_name") or filename
        desc_map[filename] = entry.get("accepted_description") or ""

        if state.is_tags_done(filename):
            _log(f"  {filename}: tags already processed — skipping.")
            continue

        try:
            status = poll_until_done(client, resource_id, file_id, poll_timeout)
            tags = (status.get("suggestions") or {}).get("tags") or []
            resource_tags[filename] = [t for t in tags if t.get("label")]
            _log(f"  {filename}: {len(resource_tags[filename])} tag suggestion(s)")
        except Exception as exc:
            _log(f"  {filename}: ERROR — {exc}")

    if not resource_tags:
        _log("\nNo tag suggestions to process.")
        return

    # ── Step 2: cross-resource frequency map ──────────────────────────────────
    # key = (label_lower, entity_type) → { count, total_confidence, label }
    freq_map: dict[tuple, dict] = {}
    tag_resource_names: dict[tuple, list[str]] = {}  # tag key → resource display names

    for filename, tags in resource_tags.items():
        res_name = name_map.get(filename, filename)
        for tag in tags:
            label = tag["label"].strip()
            entity_type = tag.get("type") or "tag"
            key = (label.lower(), entity_type)
            if key not in freq_map:
                freq_map[key] = {"count": 0, "total_confidence": 0.0, "label": label}
            freq_map[key]["count"] += 1
            freq_map[key]["total_confidence"] += float(tag.get("confidence") or 0.0)
            tag_resource_names.setdefault(key, [])
            if res_name not in tag_resource_names[key]:
                tag_resource_names[key].append(res_name)

    # ── Step 2.5: optional AITY dedup — normalise labels before decisions ──────
    normalize_map: dict[tuple, str] = {}  # (label_lower, entity_type) → canonical_label

    if dedup:
        _log(f"\nSending {len(freq_map)} unique tag(s) to AITY for deduplication...")
        tag_list = sorted(
            [
                {
                    "label": info["label"],
                    "type": entity_type,
                    "freq": info["count"],
                    "avg_conf": round(info["total_confidence"] / info["count"], 2),
                    "in": tag_resource_names.get((info["label"].lower(), entity_type), [])[:5],
                }
                for (_, entity_type), info in freq_map.items()
            ],
            key=lambda x: x["label"].lower(),
        )
        try:
            raw = client.llm_chat([
                {"role": "system", "content": _DEDUP_SYSTEM},
                {"role": "user",   "content": json.dumps(tag_list, indent=2, ensure_ascii=False)},
            ])
            result = _parse_llm_json(raw)
            # Support new object format {"reclassify": [...], "merges": [...]}
            # and fall back gracefully to a legacy plain array (merges only).
            if isinstance(result, dict):
                reclassify_list = result.get("reclassify") or []
                groups = result.get("merges") or []
            else:
                reclassify_list = []
                groups = result if isinstance(result, list) else []

            # ── Reclassify types ───────────────────────────────────────────────
            reclassify_map: dict[str, str] = {}  # label_lower → corrected entity_type
            _VALID_TYPES = {"person", "organization", "place", "thing", "tag"}
            if reclassify_list:
                _log(f"  AITY reclassified {len(reclassify_list)} tag type(s):")
                for r in reclassify_list:
                    lbl = (r.get("label") or "").strip()
                    new_type = (r.get("type") or "").strip()
                    if lbl and new_type in _VALID_TYPES:
                        reclassify_map[lbl.lower()] = new_type
                        _log(f"    {lbl!r}  tag → {new_type}")

                if reclassify_map:
                    # Patch resource_tags in-place so downstream steps see corrected types.
                    for tags2 in resource_tags.values():
                        for tag in tags2:
                            lk = tag["label"].strip().lower()
                            if lk in reclassify_map and tag.get("type") == "tag":
                                tag["type"] = reclassify_map[lk]
                    # Rebuild freq_map so its keys reflect the corrected types.
                    rebuilt: dict[tuple, dict] = {}
                    for tags2 in resource_tags.values():
                        for tag in tags2:
                            lbl = tag["label"].strip()
                            et = tag.get("type") or "tag"
                            k = (lbl.lower(), et)
                            if k not in rebuilt:
                                rebuilt[k] = {"count": 0, "total_confidence": 0.0, "label": lbl}
                            rebuilt[k]["count"] += 1
                            rebuilt[k]["total_confidence"] += float(tag.get("confidence") or 0.0)
                    freq_map = rebuilt
            else:
                _log("  AITY found no type reclassifications.")

            # ── Merge groups ───────────────────────────────────────────────────
            if groups:
                _log(f"  AITY proposed {len(groups)} merge group(s):")
                for g in groups:
                    canonical = (g.get("canonical") or "").strip()
                    entity_type = g.get("type", "tag")
                    dups = [d.strip() for d in (g.get("duplicates") or []) if d.strip()]
                    if not canonical or not dups:
                        continue
                    _log(f"    KEEP {canonical!r}  <-  {dups}")
                    if g.get("reason"):
                        _log(f"         ({g['reason']})")
                    for dup in dups:
                        k = (dup.lower(), entity_type)
                        if k in freq_map:
                            normalize_map[k] = canonical
                if normalize_map:
                    # Rebuild freq_map with canonical labels so pooled frequencies
                    # are used in the accept/skip decisions that follow.
                    merged_freq: dict[tuple, dict] = {}
                    for filename2, tags2 in resource_tags.items():
                        for tag in tags2:
                            label = tag["label"].strip()
                            entity_type = tag.get("type") or "tag"
                            canonical = normalize_map.get((label.lower(), entity_type), label)
                            key = (canonical.lower(), entity_type)
                            if key not in merged_freq:
                                merged_freq[key] = {"count": 0, "total_confidence": 0.0, "label": canonical}
                            merged_freq[key]["count"] += 1
                            merged_freq[key]["total_confidence"] += float(tag.get("confidence") or 0.0)
                    freq_map = merged_freq
            else:
                _log("  AITY found no duplicate groups.")
        except Exception as exc:
            _log(f"  WARNING: dedup call failed ({exc}). Continuing without normalisation.")

    # ── Step 3: per-resource accept/skip decisions ─────────────────────────────
    decisions: dict[str, dict] = {}
    for filename, tags in resource_tags.items():
        accept, skip = [], []
        for tag in tags:
            label = tag["label"].strip()
            entity_type = tag.get("type") or "tag"
            conf = float(tag.get("confidence") or 0.0)
            canonical = normalize_map.get((label.lower(), entity_type), label)
            key = (canonical.lower(), entity_type)
            freq = freq_map.get(key, {}).get("count", 1)

            if conf >= confidence:
                accept.append({**tag, "_canonical": canonical, "_reason": "high confidence"})
            elif conf >= mid_confidence and freq >= min_frequency:
                accept.append({**tag, "_canonical": canonical, "_reason": f"freq boost x{freq}"})
            else:
                skip.append({**tag, "_canonical": canonical, "_reason": f"conf={conf:.0%} freq={freq}"})

        decisions[filename] = {"accept": accept, "skip": skip}

    # ── Step 3.5: optional LLM top-N pruning per resource ─────────────────────
    if max_tags:
        needs_pruning = {fn: d for fn, d in decisions.items() if len(d["accept"]) > max_tags}
        if needs_pruning:
            _log(f"\nPruning to top {max_tags} tag(s) per resource for {len(needs_pruning)} resource(s)...")
            for filename, d in needs_pruning.items():
                payload = {
                    "resource": {
                        "name": name_map.get(filename, filename),
                        "description": desc_map.get(filename, ""),
                    },
                    "max_tags": max_tags,
                    "candidates": [
                        {
                            "label": t["_canonical"],
                            "type": t.get("type") or "tag",
                            "confidence": round(float(t.get("confidence") or 0.0), 2),
                            "reason": t["_reason"],
                        }
                        for t in d["accept"]
                    ],
                }
                try:
                    raw = client.llm_chat([
                        {"role": "system", "content": _TOP_TAGS_SYSTEM},
                        {"role": "user",   "content": json.dumps(payload, indent=2, ensure_ascii=False)},
                    ])
                    selected_labels = _parse_llm_json(raw)
                    if isinstance(selected_labels, list) and selected_labels:
                        selected_set = {s.lower() for s in selected_labels if isinstance(s, str)}
                        kept   = [t for t in d["accept"] if t["_canonical"].lower() in selected_set]
                        pruned = [t for t in d["accept"] if t["_canonical"].lower() not in selected_set]
                        if kept:
                            d["accept"] = kept
                            for t in pruned:
                                t["_reason"] = f"pruned by LLM (top-{max_tags})"
                                d["skip"].append(t)
                            _log(f"  {filename}: kept {len(kept)}, pruned {len(pruned)}")
                        else:
                            _log(f"  {filename}: LLM returned no valid labels — keeping all {len(d['accept'])}")
                    else:
                        _log(f"  {filename}: LLM returned empty list — keeping all {len(d['accept'])}")
                except Exception as exc:
                    _log(f"  {filename}: WARNING — pruning call failed ({exc}). Keeping all candidates.")

    # ── Step 4: cross-resource summary ────────────────────────────────────────
    _log(f"\nCross-resource tag breakdown  ({len(freq_map)} unique tag(s), {len(resource_tags)} resources):")
    _log(f"{'─' * 50}")
    for (_, entity_type), info in sorted(
        freq_map.items(), key=lambda x: (-x[1]["count"], -x[1]["total_confidence"])
    ):
        avg_conf = info["total_confidence"] / info["count"]
        accepted = avg_conf >= confidence or (avg_conf >= mid_confidence and info["count"] >= min_frequency)
        verdict = "ACCEPT" if accepted else "SKIP  "
        if avg_conf >= confidence:
            reason = "high confidence"
        elif accepted:
            reason = f"freq boost x{info['count']}"
        else:
            reason = f"conf={avg_conf:.0%} freq={info['count']}"
        _log(
            f"  [{verdict}] {info['label']!r:40s} ({entity_type:12s})"
            f"  avg={avg_conf:.0%}  freq={info['count']:3d}  {reason}"
        )

    total_accepted = sum(len(d["accept"]) for d in decisions.values())
    total_skipped  = sum(len(d["skip"])  for d in decisions.values())
    _log(f"\n  -> {total_accepted} tag assignment(s) to accept, {total_skipped} to skip")

    if dry_run:
        _log("\n[DRY RUN] No changes written. Remove --dry-run to apply.")
        return

    # ── Step 5: apply ─────────────────────────────────────────────────────────
    _log(f"\nApplying tags...")
    _log(f"{'─' * 50}")
    applied = 0
    failed_files: list[str] = []

    for filename, d in decisions.items():
        _check_interrupt()
        resource_id = resource_ids[filename]

        if not d["accept"]:
            state.mark_tags_done(
                filename,
                accepted=[],
                skipped=[
                    {"label": t["label"], "confidence": t.get("confidence"), "reason": t["_reason"]}
                    for t in d["skip"]
                ],
            )
            continue

        _log(f"\n  {filename}  (resource {resource_id})")
        try:
            new_tag_ids = []
            accepted_records = []

            for tag in d["accept"]:
                canonical = tag["_canonical"]
                entity_type = tag.get("type") or "tag"
                conf = float(tag.get("confidence") or 0.0)
                freq = freq_map.get((canonical.lower(), entity_type), {}).get("count", 1)

                created = client.create_or_find_tag(canonical, entity_type, tag.get("description"))
                new_tag_ids.append(created["id"])
                accepted_records.append({
                    "id": created["id"],
                    "label": created["label"],
                    "entity_type": entity_type,
                    "confidence": conf,
                    "frequency": freq,
                    "reason": tag["_reason"],
                })
                merge_note = f"  (merged from {tag['label']!r})" if canonical != tag["label"] else ""
                _log(f"    + {canonical!r} ({entity_type}){merge_note}  conf={conf:.0%}  [{tag['_reason']}]")

            existing_ids = client.get_resource_tag_ids(resource_id)
            merged_ids = list(set(existing_ids + new_tag_ids))
            client.sync_resource_tags(resource_id, merged_ids)

            if clear_suggestions:
                file_id = state._data[filename].get("file_id")
                if file_id:
                    client.clear_file_ai_suggestions(resource_id, file_id)

            state.mark_tags_done(
                filename,
                accepted=accepted_records,
                skipped=[
                    {"label": t["label"], "confidence": t.get("confidence"), "reason": t["_reason"]}
                    for t in d["skip"]
                ],
            )
            applied += 1
            _log(f"    Synced {len(merged_ids)} tag(s) total ({len(new_tag_ids)} new, {len(existing_ids)} existing).")

        except Exception as exc:
            _log(f"    ERROR: {exc}")
            failed_files.append(filename)

    _log(f"\n{'=' * 50}")
    _log(f"Results: {applied} resource(s) tagged" + (f", {len(failed_files)} failed" if failed_files else ""))
    if failed_files:
        _log("\nFailed:")
        for fn in failed_files:
            _log(f"  x {fn}")


# ─── Phase: full (original behaviour) ────────────────────────────────────────

def phase_full(
    client: TydalClient,
    folder: Path,
    collection_id: int,
    state: UploadState,
    extensions: list[str] | None,
    visibility: str,
    poll_timeout: int,
    keep_suggestions: bool = False,
):
    candidates = sorted(f for f in folder.iterdir() if f.is_file() and not f.name.startswith("."))
    if extensions:
        candidates = [f for f in candidates if f.suffix.lower().lstrip(".") in extensions]

    total = len(candidates)
    _log(f"\nFound {total} file(s) to process.\n{'─' * 50}")
    counts = {"done": 0, "skipped": 0, "failed": 0}
    failed_files: list[str] = []
    skipped_files: list[str] = []

    for idx, file_path in enumerate(candidates, 1):
        _check_interrupt()
        filename = file_path.name
        _log(f"\n[{idx}/{total}] {filename}")

        if state.is_done(filename):
            _log("  Already done — skipping.")
            counts["skipped"] += 1
            skipped_files.append(filename)
            continue

        # Resume from 'uploaded' if a previous bulk run left it mid-way
        if state.is_uploaded(filename):
            _log("  Already uploaded — jumping to accept step.")
            entry = state._data[filename]
            resource_id = entry["resource_id"]
            file_id = entry["file_id"]
            initial_name = entry.get("initial_name", filename)
        else:
            try:
                rtype = infer_resource_type(file_path)
                initial_name = humanise_name(file_path.stem)
                _log(f"  Creating resource (type={rtype})...")
                resource = client.create_resource(initial_name, collection_id, rtype, visibility)
                resource_id = resource["id"]
                _log(f"  Resource created → {resource_id}")

                size_kb = file_path.stat().st_size / 1024
                _log(f"  Uploading {size_kb:.1f} KB...")
                file_record = client.upload_file(resource_id, file_path)
                file_id = file_record["id"]
                _log(f"  File uploaded → {file_id}")

                state.mark_uploaded(filename, resource_id, file_id, initial_name)

            except Exception as exc:
                _log(f"  ERROR during upload: {exc}")
                state.mark_failed(filename, str(exc))
                counts["failed"] += 1
                failed_files.append(filename)
                continue

        try:
            _log(f"  Waiting for AI analysis (timeout {poll_timeout}s)...")
            status = poll_until_done(client, resource_id, file_id, poll_timeout)
            accepted_name = apply_suggestions(client, resource_id, file_id, status, initial_name, keep_suggestions)
            state.mark_done(filename, resource_id, accepted_name, None)
            counts["done"] += 1
            _log("  Done.")

        except Exception as exc:
            _log(f"  ERROR during accept: {exc}")
            state.mark_failed(filename, str(exc))
            counts["failed"] += 1
            failed_files.append(filename)

    _print_summary(counts, failed_files, skipped_files)
    _log(f"State saved to: {state.path}")


# ─── Entry point ──────────────────────────────────────────────────────────────

def main():
    parser = argparse.ArgumentParser(
        description="Upload a folder of files to TYDAL with optional multi-phase bulk mode.",
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog="""
Phases:
  full    (default) Complete pipeline: upload + accept name/description + auto-tag.
          Accepts --dedup, --max-tags, and all --tag-* thresholds.
  upload  Upload all files immediately; AITY runs in background on the server.
  accept  Collect AITY results and apply name/description to all pending files.
  tags    Cross-resource tag analysis only (run after upload/accept).
          Dedup (type reclassification + label normalisation) is on by default;
          use --no-dedup to skip it. --max-tags caps tags per resource.
          Use --dry-run to preview without writing.

Examples:
  # Full pipeline — upload + name/description + tags in one shot
  python upload.py /files --email admin@example.com --password secret \\
    --org-id <uuid> --collection-id 3

  # Full pipeline without deduplication
  python upload.py /files --no-dedup --email ... --org-id ... --collection-id 3

  # Two-phase: upload fast, accept name/description later, then tag
  python upload.py /files --phase upload --email ... --org-id ... --collection-id 3
  python upload.py /files --phase accept --email ... --org-id ...
  python upload.py /files --phase tags --email ... --org-id ...

  # Preview tag decisions without writing
  python upload.py /files --phase tags --dry-run --email ... --org-id ...

  # Re-run any phase safely — completed files are always skipped
""",
    )
    parser.add_argument("folder", help="Folder containing files to upload")
    parser.add_argument(
        "--phase",
        choices=["full", "upload", "accept", "tags"],
        default="full",
        help="full (default): complete pipeline — upload + accept + auto-tag. upload: upload only. accept: apply name/description. tags: tag analysis only.",
    )
    parser.add_argument("--api-url", default="http://localhost:8000/api/v1", help="TYDAL API base URL")
    parser.add_argument("--email", required=True, help="TYDAL user email")
    parser.add_argument("--password", required=True, help="TYDAL user password")
    parser.add_argument("--org-id", required=True, help="Organization UUID")
    parser.add_argument("--collection-id", type=int, help="Target collection ID (required for upload/full phases)")
    parser.add_argument(
        "--visibility",
        default="organization",
        choices=["private", "organization", "workspace", "public", "draft"],
    )
    parser.add_argument("--extensions", help="Comma-separated extensions to include, e.g. pdf,jpg,png")
    parser.add_argument(
        "--poll-timeout",
        type=int,
        default=DEFAULT_POLL_TIMEOUT_S,
        help=f"Max seconds to wait for AITY per file (default: {DEFAULT_POLL_TIMEOUT_S})",
    )
    parser.add_argument("--state-file", help="Path to state file (default: <folder>/.tydal_upload_state.json)")
    parser.add_argument(
        "--keep-suggestions",
        action="store_true",
        default=False,
        help=(
            "After accepting name/description, do NOT clear the AITY suggestion system files. "
            "The AITY badge stays lit in the UI and tag suggestions remain visible for manual review. "
            "Without this flag, all suggestions (including tags) are cleared once name/description are applied."
        ),
    )

    # ── --phase tags options ───────────────────────────────────────────────────
    parser.add_argument(
        "--tag-confidence",
        type=float,
        default=0.80,
        metavar="FLOAT",
        help="Min confidence (0.0–1.0) to auto-accept a tag regardless of frequency (default: 0.80).",
    )
    parser.add_argument(
        "--tag-mid-confidence",
        type=float,
        default=0.65,
        metavar="FLOAT",
        help="Lower confidence threshold used together with --tag-min-frequency (default: 0.65).",
    )
    parser.add_argument(
        "--tag-min-frequency",
        type=int,
        default=3,
        metavar="N",
        help="A tag at mid-confidence is accepted if it appears in at least N resources (default: 3).",
    )
    parser.add_argument(
        "--dry-run",
        action="store_true",
        default=False,
        help="Preview tag decisions without writing. Applies to --phase tags (no effect on full/upload/accept).",
    )
    parser.add_argument(
        "--max-tags",
        type=int,
        default=4,
        metavar="N",
        help="Max tags per resource; LLM selects the most relevant N when over the limit (default: 4). Set to 0 to disable.",
    )
    parser.add_argument(
        "--no-dedup",
        dest="dedup",
        action="store_false",
        default=True,
        help="Disable LLM type reclassification and label normalisation (dedup is on by default).",
    )

    args = parser.parse_args()

    folder = Path(args.folder)
    if not folder.is_dir():
        _log(f"Error: '{folder}' is not a directory.")
        sys.exit(1)

    if args.phase in ("full", "upload") and not args.collection_id:
        _log("Error: --collection-id is required for --phase upload and --phase full.")
        sys.exit(1)

    extensions = (
        [e.strip().lower().lstrip(".") for e in args.extensions.split(",")]
        if args.extensions else None
    )
    state_path = Path(args.state_file) if args.state_file else folder / ".tydal_upload_state.json"
    state = UploadState(state_path)
    client = TydalClient(args.api_url)

    _log(f"Authenticating as {args.email}...")
    client.login(args.email, args.password)
    _log("Authenticated.")

    _log(f"Switching to organization {args.org_id}...")
    client.switch_organization(args.org_id)
    _log("Organization context set.")

    if args.phase == "upload":
        phase_upload(client, folder, args.collection_id, state, extensions, args.visibility)
    elif args.phase == "accept":
        phase_accept(client, state, args.poll_timeout, keep_suggestions=args.keep_suggestions)
    elif args.phase == "tags":
        phase_tags(
            client,
            state,
            poll_timeout=args.poll_timeout,
            confidence=args.tag_confidence,
            mid_confidence=args.tag_mid_confidence,
            min_frequency=args.tag_min_frequency,
            dedup=args.dedup,
            max_tags=args.max_tags or None,
            dry_run=args.dry_run,
        )
    else:
        # Force keep_suggestions=True so tag suggestions survive for phase_tags below.
        phase_full(client, folder, args.collection_id, state, extensions, args.visibility, args.poll_timeout, keep_suggestions=True)
        phase_tags(
            client,
            state,
            poll_timeout=args.poll_timeout,
            confidence=args.tag_confidence,
            mid_confidence=args.tag_mid_confidence,
            min_frequency=args.tag_min_frequency,
            dedup=args.dedup,
            max_tags=args.max_tags or None,
            dry_run=args.dry_run,
            clear_suggestions=not args.keep_suggestions,
        )


if __name__ == "__main__":
    main()
