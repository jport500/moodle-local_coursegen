# Changes — local_coursegen

All notable changes to this plugin are recorded here, newest first. One
entry per phase / release, per the LMS Light working process.

## v0.11.0 — 2026-06-17 (Phase 14: assessment-model coherence)

Two corrections, no new build targets (a real graded quiz is P15). See DECISIONS D21.

- **One completion-tracked activity per section.** An assessed section carried two
  tracked activities (the manual "Mark as done" reading label + the auto-on-submit
  knowledge check), so learners who passed every check still sat short of 100% until
  they also clicked each reading area. The reading label is now set to
  `COMPLETION_TRACKING_NONE` when a knowledge check was built (the check is the
  signal) and kept at `COMPLETION_TRACKING_MANUAL` only on reading-only sections. The
  choice is keyed on whether a check was *actually* built (`build_knowledgecheck()`
  now returns `?string`, null = not built), so a generation-skipped section keeps its
  reading label as the one signal — every section has exactly one tracked activity,
  never zero or two. `format_pathway` progress now reads coherently per section.
- **Honest knowledge-check naming.** The assessment type called "quiz" always built a
  knowledge check. Renamed everywhere to `knowledgecheck` (enum
  `ASSESS_QUIZ`→`ASSESS_KNOWLEDGECHECK`, value `'quiz'`→`'knowledgecheck'`): blueprint
  normalizer, edit dropdown + lang ("Knowledge check"), `view.php` meta, materializer
  dispatch, and the AI prompt vocabulary (now `{none, knowledgecheck}` — the AI never
  emits a real quiz).
- **Migration.** A one-time `db/upgrade.php` step rewrites stored `coursegen_blueprint`
  JSON `'quiz'`→`'knowledgecheck'` (mandatory: the normalizer would otherwise coerce
  unmigrated `'quiz'` to `none` and drop the assessment). No legacy `'quiz'` remains,
  reserving the value for P15's real quiz.

## v0.10.1 — 2026-06-17 (Phase 13: pre-pilot wayfinding polish)

Two fixes on the P12 nav work; no new features (pipeline, guards, caps, wrap
unchanged).

- **Reviewer access (Fix A).** The hub, the job page and the category nav link
  guarded on `:generate` alone, so a reviewgate-only approver was bounced before
  reaching the review action `edit.php` already allows them. New testable helpers
  `job_manager::can_access()` (generate OR reviewgate) and `can_create()`
  (generate) now gate navigation/hub/job-page on access and the Create
  action/button on create. A reviewer can navigate and approve but isn't offered
  Create. (Admins bypass capabilities, so this is covered by unit tests with a
  reviewgate-only role.)
- **Refused-rebuild notice (Fix B).** A D18/D20 refusal leaves the job COMPLETE
  with the reason only in the audit log, so an operator who edited a populated
  course and re-approved landed on "course built, open it" with no hint the
  rebuild was declined. The job page's complete view now shows the refusal. The
  trap — a normally-built course also carries benign `outcome=failure` rows from
  in-build skips (a skipped knowledge check/image) — is handled by marking the
  refusal log row with a distinct stage (`STAGE_REBUILD_REFUSED`) at the source
  and a `job_manager::current_refusal()` that returns it only while it is the
  job's latest log row (cleared once a later rebuild supersedes it). A clean
  complete job, and one carrying only skip failures, show nothing extra.

## v0.10.0 — 2026-06-17 (Phase 12: navigation & wayfinding)

Connects the three pages so an operator is never stranded typing URLs or running
tasks blind. No new generation features — the pipeline, guards (D18/D20), caps and
wrap are unchanged.

- **Category hub.** `index.php?contextid=N` is now a hub that lists the category's
  generation jobs (status + last-updated, each row links to its job page) with a
  **Create a generation job** action. The create form moved to
  `index.php?contextid=N&action=create` and, on submit, **redirects to the job
  page** instead of a flash notification on a dead page. The P11 category link is
  unchanged (it now lands on the hub) and relabelled "Course builder".
- **Status-aware job page.** `view.php?jobid=N` dispatches on the job's status
  (via a new `job_manager::classify_status()` phase map): *processing* shows a
  progress view with a light Moodle meta-refresh and a "advances as scheduled
  tasks run" note; *awaiting_review* shows a prominent **Review & approve** button
  to `edit.php`; *complete* links to the built (hidden) course with an unhide
  nudge; *failed* shows the reason from the audit log. Handles both modes
  (automatic runs processing→complete; outline-first stops at review). The
  blueprint rendering is reused, not rebuilt.
- **Breadcrumbs** (category → Course builder → Job → Review) on the hub, job page,
  and edit page, so back-navigation works.
- New testable logic: `job_manager::classify_status()`, `jobs_in_context()`, and
  `failure_reason()`.

## v0.9.2 — 2026-06-16 (Phase 11: entry-point navigation)

- **A real entry point.** Until now the generation page was reachable only by
  typing its URL — nothing wired it into Moodle's UI. New `lib.php` adds
  `local_coursegen_extend_navigation_category_settings()`, which puts a
  "Create a generation job" link in a course category's settings navigation. The
  link carries the category context id `index.php` requires and is shown only to
  users who hold `local/coursegen:generate` there — so it never appears where it
  would not work. No new capability, string, or schema.

## v0.9.1 — 2026-06-16 (Phase 10: guard the course's own learner state)

Extends the D18 re-materialize guard to protect the course itself, not only the
cert-chain wrap — the gap P9's reopen-from-COMPLETE made reachable.

- **Symmetric refusal (DECISIONS D20).** A re-materialize is delete_course +
  rebuild, which destroys the course's enrolments, completion and grades. D18
  refused only on wrap allocations/assignments, so a learner enrolled by any
  other route (manual/self/cohort, once an admin unhid the course) was invisible
  and could be silently deleted by a trivial edit + re-approve. `materialize()`
  now also refuses when the job's course has live learner state.
- **Course predicate** (`materializer::course_learner_state_reason`): fires on ≥1
  user enrolled via a non-`muprog` instance, or any real completion
  (`course_modules_completion.completionstate <> 0`, or `course_completions`
  with `timecompleted` set). Muprog enrolments are excluded because they map 1:1
  to program allocations already counted by the wrap check — no double-reporting,
  no gap. Verified that a freshly-built course has a zero baseline (no enrolments,
  no completion), so the authoring-retry loop is never false-refused.
- **Composition.** Two predicates feed one refusal: `cert_wrap::populated_block_reason`
  (now returns just its clause) and the course predicate, joined into one
  combined, actionable reason. Still runs before any cleanup, so a refusal leaves
  the course, program and certification intact and the job COMPLETE; the retry
  path is P9's reopen-from-COMPLETE (clear the learner state, then edit +
  re-approve to rebuild).

## v0.9.0 — 2026-06-16 (Phase 9: pre-pilot cleanup)

Final cleanup before the pilot; no new features.

- **Refusal leaves the job COMPLETE, not FAILED (DECISIONS D18 amended).** When a
  re-materialize is refused to protect a populated wrap (P8), the previously-built
  course is still live and serving learners — so the job is genuinely complete.
  `materializer::refuse()` now sets COMPLETE and surfaces the reason only through
  the §10.2 audit log (outcome `failure`) and the caller's `false` return. Marking
  it FAILED misrepresented a healthy course and stranded the admin, because nothing
  re-drives a FAILED job.
- **Real retry path (D18).** The reopen was broadened so the refusal's promised
  retry actually exists: `review_gate::reopen_for_reedit` (renamed from
  `reopen_if_approved`) now reopens from COMPLETE as well as APPROVED, so editing
  and re-approving a built course re-drives materialize. Clearing the allocations,
  then editing + re-approving, rebuilds with an empty wrap. This also fixes a
  latent bug: an edit to a completed job previously saved a blueprint version that
  never reached the live course.
- **Pre-pilot release posture (D19).** `MATURITY_BETA`, release `v0.9.0`, and
  `$plugin->requires` bumped to the verified Moodle 5.2 floor (`2026042000`,
  PHP 8.3) instead of the never-tested 5.1 / PHP 8.2 claim.

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
