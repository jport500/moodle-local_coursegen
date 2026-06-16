# Changes — local_coursegen

All notable changes to this plugin are recorded here, newest first. One
entry per phase / release, per the LMS Light working process.

## v0.8.1 — 2026-06-16 (Phase 8: protect a populated wrap)

Closes a data-safety hole in the P7 wrap: a re-materialize is a supported flow
(a post-approval section edit reopens the job and re-approving re-runs
materialize), and P7's "cleanup removes prior, rebuild fresh" would silently
delete a program/certification an admin had since populated.

- **Refuse rather than destroy (DECISIONS D18).** `tool_muprog`/`tool_mucertify`
  `delete()` hard-cascades — it removes all learner allocations/assignments and
  tears down enrolments — with no refuse and no archive-first. So before any
  destructive cleanup, `materialize()` now calls
  `cert_wrap::populated_block_reason()`; if the job's program has any allocations
  or its certification has any assignments, the job is **refused** (FAILED, audited
  with an actionable reason naming the program/certification and the counts) via a
  new non-destructive `materializer::refuse()` — no cleanup, so the existing
  course, program, certification and allocations are left fully intact.
- An absent or empty wrap is unaffected: the authoring-retry case keeps the P7
  delete-and-rebuild behaviour.
- This is refuse-only for v1; reuse-and-re-point (rebuild while preserving
  allocations) is a documented later option.
- **Test gap closed.** Adds the best-effort partial-wrap test the P7 smoke didn't
  cover: when the certification step throws after the program is created, the wrap
  keeps the program, logs the partial, and does not propagate — so the job still
  completes.

## v0.8.0 — 2026-06-16 (Phase 7: cert-chain wrap)

Wires the optional muprog/mucertify wrap deferred from P6, and fixes the default
spend-cap posture carried over from the P6 review.

- **Cert-chain wrap (DECISIONS D17).** New `local\cert_wrap`. At the end of
  materialization, when `wrap_muprog` is on the generated course is placed in a
  `tool_muprog` program (`program::create` + `top->append_course`); when
  `wrap_mucertify` is also on, a single-period `tool_mucertify` certification is
  created linked to that program (`certification::create` with `programid1`).
  The wrap builds the container only — learners are not allocated (configure the
  program's allocation sources afterwards). No AI, so it is outside the spend
  governor.
- **Re-entrancy.** Program/certification are keyed by a per-job idnumber
  (`coursegen-job-{jobid}`, UNIQUE in both tables). `cleanup_partial_course` (the
  retry's delete-and-rebuild hook) deletes any prior certification then program,
  so a retry rebuilds course + program + certification fresh and provably never
  strands or duplicates one; `cert_wrap` also find-or-creates defensively.
- **Best-effort.** A wrap failure is logged (§10.2 warning + mtrace) and the job
  still completes — the course is the primary artifact (consistent with the
  quiz/image skip-and-build precedent, D14).
- **Toggle dependency.** `wrap_mucertify` is hidden in settings unless
  `wrap_muprog` is on (`hide_if`), with a runtime guard that skips the
  certification (and warns) rather than silently creating a program.
- **Soft dependency.** `tool_muprog`/`tool_mucertify` are NOT hard `requires` in
  version.php; `cert_wrap` runtime-checks for them (class + version floor
  2026041950) and skips with a warning when a toggle is on but the plugin is
  absent. The toggles are relabelled from "not yet active" to active.
- **Default spend-cap posture (P6 carry-over).** The fresh-install default and the
  `db/upgrade` heal target for `cap_period_spend` are now both **500000**
  generation units / 30-day period (≈ 30–100 courses, warns at 80%) — a deliberate
  bounded default instead of the arbitrary 1000000 left from P4.

Verified on demo2 (5.2.1): phpcs moodle-clean, PHPUnit green, real-transport smoke
(materialize with both toggles on → a real program containing the course and a
linked certification; a retry minted no duplicate program or certification).

## v0.7.0 — 2026-06-16 (Phase 6: finalize & governance)

Makes the plugin releasable: completion verified end-to-end, the spend cap made
correct and complete, dependency floors pinned, and an operator smoke script.
No new generation features.

- **Completion gate (verified).** A new `completion_walkthrough_test` proves the
  learner round-trip: submitting an inline knowledge check (`api::submit_attempt`,
  the path the inline webservice drives) completes the activity, advances
  format_pathway progress to 100%, and — with an activity criterion — drives
  course completion. mod_knowledgecheck calls `completion->update_state()` on a
  finished attempt, so an inline submit propagates completion exactly like an
  activity view.
- **Spend cap completeness (D16).** New `local\spend_governor` is the single
  source of truth for the spend and image caps. Spend/images are totalled over a
  **rolling window** (`period_days`, default 30) so the "per period" cap actually
  resets, instead of summing all-time. The cap now gates **every** AI call site —
  blueprint synthesis and section regeneration refuse before any call when the
  tenant is already over cap, not just materialization. A cap of `0` means
  unlimited (spend and images), now explicit in code and settings.
- **cap=50 self-heal.** A `db/upgrade` step bumps the original P0 default of 50
  generation units to the current default (1000000) **only** where the stored
  value is still exactly `50` — never overwriting a value an admin set.
- **Dependency floors** for `mod_knowledgecheck` / `filter_knowledgecheck` are
  pinned to the build verified to contain the API surface we call.
- **`MANUAL_SMOKE.md`** — an end-to-end operator smoke script plus governance
  footguns (don't disable the filter on a tenant with generated courses; the
  cap=50 gotcha; 0=unlimited; rolling window; provider-order routing).
- **Cert-chain wrap deferred to P7 (D16).** The muprog/mucertify wrap is a real
  two-plugin integration (program content-tree + certification link + cross-plugin
  re-entrancy); it is out of scope for a finalize phase and optional/off-by-default,
  so v1 ships without it. The `wrap_muprog`/`wrap_mucertify` toggles remain,
  relabelled "not yet active".

## v0.6.0 — 2026-06-16 (Phase 5: assessments)

Fulfils the blueprint's assessment spec by placing inline formative knowledge
checks in the materialized course. No program/cert wrap (P6), publishing, or
governance UI.

- For each `type=quiz` section, the materialize pass generates questions from
  the section's reading content via local_quizgenpro (delegated, D5/D10), banks
  them in the course's default question bank (mod_qbank), creates a **stealth
  `mod_knowledgecheck`** in that pathway section, pins the banked entries
  (`\mod_knowledgecheck\local\questions::add`), and embeds the check's
  `{knowledgecheck id=<uuid>}` filter token in the section's reading label so it
  renders inline. Formative by design — best-attempt, retry freely (D15).
  A graded mod_quiz is a documented fast-follow; adds dependencies
  `mod_knowledgecheck` + `filter_knowledgecheck`.
- The knowledge check owns its completion (auto-complete on a finished attempt),
  so assessed sections are natively completion-tracked — feeding format_pathway
  progress and the cert/CE chain (the thing reading labels can't). `type=none`
  sections get none. "complete" now means reading + assessments built.
- **Disabled-filter guard:** if `filter_knowledgecheck` is off, the check is
  created non-stealth (shown on the course page) with no token and a logged
  warning — never a silently-invisible assessment (D15).
- `quiz_client` seam (real `quizgenpro_quiz_client` + `stub_quiz_client`),
  mirroring the text/image seams — the AI generation is unit-testable offline;
  the banking (quizgenpro exporter) and pinning run for real in tests. A drift
  guard round-trips a generator-shaped question through the remap and the real
  exporter, so a change to quizgenpro's `question`/`text` field breaks a test.
- Cost: quiz-gen spend is out of coursegen's cap — quizgenpro governs its own and
  exposes no tokens (D13). A no-questions (or banking) failure skips the check
  and still completes the course (D14).

P4 carry-overs resolved:
- Partial-failure / re-entrancy: a failed pass deletes its half-built hidden
  course (no orphans), and the materialize task accepts approved OR materializing
  and deletes any prior partial course before rebuilding — so a retry never mints
  a second course or strands the job at "materializing".
- The §10.2 audit log is now written through a single `audit_log::record()` whose
  `outcome` is a required argument (no success default), used by every stage —
  closing the "failure logged as success" bug class seen in P3/P4.

## v0.5.0 — 2026-06-16 (Phase 4: materialization)

Turns an approved blueprint into a real, hidden Moodle course. No quizzes
(P5), program/cert wrapping (P6), flashcards/book, or publishing.

- `materializer` consumes an approved job's current blueprint and creates a
  course in format_pathway, **hidden** (draft-by-default, D3), with completion
  enabled. Records the `courseid` on the job and advances
  approved → materializing → complete/failed.
- Each blueprint section becomes a pathway section holding one inline "Text and
  media" area (`mod_label`, D12) with drafting-tier reading content and, where
  flagged, an AI-generated image embedded inline (`@@PLUGINFILE@@`) with
  generated alt text. Labels use manual completion so pathway progress and the
  later cert/CE chain have completion-tracked activities.
- `image_client` seam (real `core_ai_image_client` via the `generate_image`
  action + `stub_image_client`), mirroring the P2 text seam — image generation
  is unit-testable with no live call.
- Materialize-time cap enforcement (SPEC §7): hard-stop if the job estimate
  exceeds the tenant's remaining spend cap, soft-warn at the threshold, accrue
  actual spend from the §10.2 token logs (with a mid-run hard-stop), and a
  separate image sub-cap that skips excess images while still building.
- §10.2 logging for every text and image call (provider/model/tokens/images).
- `materialize_course` adhoc task, queued by `review_gate` when a job is
  approved (auto in automatic mode, or on manual approval). `cli/materialize.php`.
- Cleanup (D11/D12): removed the `provider_reasoning`/`provider_drafting`
  settings (route nowhere); kept the separate image provider; collapsed the
  blueprint content-type enum to a single inline type across the IR, edit form,
  and prompts; reinterpreted the spend cap as generation units. Privacy
  unchanged (`courseid` already covered).

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
