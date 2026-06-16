# Changes — local_coursegen

All notable changes to this plugin are recorded here, newest first. One
entry per phase / release, per the LMS Light working process.

## v0.4.0 — 2026-06-16 (Phase 3: review gate, editing, regeneration)

Puts the human gate on top of the blueprint. No course materialization, content,
images, or assessments yet (P4+).

- Mode branch (`review_gate`): after a blueprint exists, outline-first holds the
  job at `awaiting_review`; automatic auto-approves to `approved`
  (materialize-ready). Honors the per-tenant default, the per-run mode, and the
  admin lock (D3). The `generate_blueprint` task applies the gate and is
  structured so `blueprinted` is a clean pass-through (a retry still advances it).
- Editing UI (`edit.php` + `edit_blueprint_form`): a native `repeat_elements`
  form to edit course title/description and reorder/rename/add/remove sections
  and their objectives, content type, image flag + hint, and assessment spec.
  The estimate is recomputed and re-stored on save.
- Per-section regeneration (`section_regenerator`): re-calls the reasoning tier
  for one section through the P2 `text_client` seam — capability-gated,
  §10.2-logged, same JSON tolerance/failure handling.
- Versioning (`blueprint_store`): each edit/regeneration inserts a new current
  `coursegen_blueprint` row (version + 1, `iscurrent`); prior versions are
  retained. No schema change.
- Approval: `local/coursegen:reviewgate` gates approve (enforced in
  `review_gate::approve`). Editing/regeneration need `:generate` OR `:reviewgate`
  so authors aren't locked out of their own draft. **Editing or regenerating an
  already-approved job reopens it to `awaiting_review`** — no approved job points
  at an unreviewed version.
- DRY: estimate and JSON decoding moved onto `blueprint`; `blueprint_generator`
  reuses them and `blueprint_store`.
- `cli/gate.php` for an inspect/regenerate/approve smoke. Privacy unchanged —
  versions are more `coursegen_blueprint` rows, already covered.

## v0.3.0 — 2026-06-16 (Phase 2: blueprint generation)

Generates the editable course blueprint (IR) from a job's corpus via the
`reasoning` capability tier. No course content, assessments, images, or
review-gate UI yet (P3+).

- `blueprint_generator` turns the corpus into the IR — course title/
  description and ordered sections (objectives, content type, summary, image
  flag + prompt hint, assessment spec) — through Moodle's AI Providers
  subsystem (mirrors the local_quizgenpro call pattern).
- Map-reduce: when the corpus exceeds a configured `reasoning_budget_tokens`,
  chunks are summarized then synthesised. Copes with flat, structureless
  corpora (e.g. PDF).
- Blueprint persisted as the first-class, versioned IR in
  `coursegen_blueprint` (JSON in `content`; no schema change — P0 fit).
- Cost estimate (in generation units ≈ tokens) computed from the blueprint and
  stored on `coursegen_job.estimatedspend` (SPEC §7).
- Resolved provider/model/token counts logged to `coursegen_log` — exercising
  the §10.2 columns added in P0.
- Job advances `extracted → blueprinted`; `extract_corpus` queues the new
  `generate_blueprint` adhoc task (carrying the user id for tenant context).
- AI access is wrapped in a `text_client` seam (real `core_ai_text_client` +
  `stub_text_client`), so all unit tests run with no live model call.
- Read-only `view.php` of the blueprint; `cli/blueprint.php` runner (also the
  real-transport smoke).
- Tests: blueprint IR, single-call + map-reduce generation, estimate, status
  transition, §10.2 logging, JSON-parse and provider-failure handling, and the
  task wiring. Privacy unchanged (no new personal data).

## v0.2.0 — 2026-06-16 (Phase 1: ingestion & extraction)

Source ingestion and asynchronous text/structure extraction. No AI calls or
blueprint generation yet (P2+).

- Accept PDF, DOCX, PPTX, text/markdown uploads and a topic-only prompt;
  create a `coursegen_job` and attach sources via the File API.
- Extract a normalized, structure-aware "source corpus" (ordered heading/
  paragraph blocks) per source, persisted in a new `coursegen_source.corpus`
  column (added via `db/upgrade.php`).
  - PDF via the vendored `smalot/pdfparser` (see `thirdpartylibs.xml`);
    DOCX/PPTX via `ZipArchive` (no library); text/markdown natively;
    topic-only jobs skip extraction and use the prompt as a trivial corpus.
- Extraction runs in the `extract_corpus` adhoc task, carrying the requesting
  user's id so it runs in the correct tenant/user context (SPEC §10.6).
- Per-job source byte cap and corpus token cap (per-tenant settings).
- Minimal upload/topic form (`index.php`) and a `cli/extract.php` test runner.
- Privacy provider, metadata and tests updated for the corpus field.
- PHPUnit coverage for each extractor, job creation, limits, the task, and
  privacy; one fixture per supported type.

## v0.1.0 — 2026-06-16 (Phase 0: scaffolding)

Initial scaffolding. No generation pipeline yet.

- Plugin skeleton: `version.php` (requires Moodle 5.1, depends on
  `format_pathway` and `local_quizgenpro`).
- Capabilities: `local/coursegen:generate`, `:reviewgate` (category
  context), `:configure` (site context).
- Admin settings: capability-tier → provider mappings, generation caps and
  warning thresholds, image sub-cap, default mode + lock, per-section image
  opt-in default, and optional `tool_muprog` / `tool_mucertify` wrap toggles.
- Database schema: `coursegen_job`, `coursegen_source`,
  `coursegen_blueprint`, `coursegen_log` (SPEC §3).
- A full GDPR privacy provider (metadata + export + delete) for the
  user-linked data stored from day one.
- `README.md` and this changelog.
