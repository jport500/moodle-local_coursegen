# local_coursegen — Architectural Decisions

Decision records for the LMS Light AI course-builder plugin. Each entry
states the decision, why, what was rejected, and the conditions that would
make us reconsider. This is a point-in-time record from v1.0 design and
should be appended to (not rewritten) as the plugin evolves.

Read `github.com/jport500/lms-light-docs/blob/main/CONTEXT.md` first for
the product, deployment model, and conventions these decisions assume.

---

## D1 — Build as a native Moodle plugin, not an external app behind LTI

**Decision.** Course generation is a native Moodle `local` plugin that
builds real Moodle courses inside the tenant. It is not a standalone
application that hosts content and serves it into Moodle via LTI.

**Why.** LMS Light's moat is a managed, isolated, per-tenant Moodle where
the content, the learner experience, and the data stay in-tenant, and
where training composes with the native stack (format_pathway, muprog,
mucertify, gradebook, completion). An external app would move the
experience and data out of the tenant onto a central multi-tenant service,
reintroducing the cross-tenant isolation burden the one-instance-per-tenant
model deliberately avoids, forcing a rebuild of program/cert/progress
features that already exist in MuTMS, and standing up a second product to
operate, secure, and certify. The native plugin rides infrastructure we
already run and reinforces the differentiator Honen structurally cannot
match.

**Rejected.** Standalone app + LTI 1.3 tool (the Honen architecture).
Genuinely better only if the goal is to be an LMS-agnostic course-building
*vendor* selling to customers on their own LMS — a different company than
the managed-Moodle service.

**Revisit if.** The strategy shifts from "feature of LMS Light" to
"horizontal course-building product sold to non-Moodle customers." Even
then, see D9 — Moodle-as-LTI-provider may serve that need without an
external app.

---

## D2 — Its own plugin (`local_coursegen`), separate from the planned AI agent plugin

**Decision.** Course building ships as its own plugin with a clean public
API, not folded into the planned in-Moodle AI agent plugin.

**Why.** Different lifecycle (authoring-time vs runtime), different audience
(instructor/admin vs learner-inclusive), and different capability surface
(course-creation rights vs conversational assistance). The agent is still
unscoped; anchoring a shippable plugin to an undesigned one imports its
uncertainty. One-repo-per-plugin and clean per-phase history favour
separation. The compelling "conversational course building" UX is achieved
later by the agent calling `local_coursegen`'s public API at runtime — the
welcomeemail → `auth_magiclink\api` pattern — which gives the UX without
the coupling.

**Rejected.** Folding into the AI agent plugin. Shared AI plumbing is a
candidate for a thin extracted library *if* duplication emerges once the
agent is built — not a reason to fuse two features now.

**Revisit if.** Real, substantial code duplication appears between this and
the agent plugin; extract a shared library at that point rather than
merging the plugins.

---

## D3 — Configurable generation modes, with draft-by-default as a safety rail

**Decision.** Support both outline-first (instructor reviews/edits the
generated blueprint, then approves materialization) and fully automatic
(straight through). Mode is a per-tenant default with a per-run override;
admins can lock it. Regardless of mode, generation always lands in a
**hidden/draft** course.

**Why.** Both modes have real demand; the pipeline supports them with one
reviewable checkpoint between *plan* and *materialize*. Draft-by-default
ensures unreviewed AI content never reaches learners silently — a rail, not
a gate — consistent with LMS Light's supervised ethos and severity
calibration.

**Rejected.** Outline-first only (too rigid for power users) and automatic
only (ships unreviewed content into live courses).

**Revisit if.** Operators report the draft step is friction with no payoff
in a trusted high-volume workflow; could offer an admin-gated "publish on
generate" for specific roles.

---

## D4 — v1 output is text + image; other formats deferred

**Decision.** v1 generates learning objectives, structured reading (inline
"Text and media" areas — see D12), quizzes (via quizgenpro), and AI-generated
images (diagrams/illustrations). Flashcards and book-style reading are deferred
to fast-follows (D12). Audio/podcasts, video, comics, music, games, and a
real-time AI tutor are out of scope for v1.

*(P4 amendment: flashcards moved to fast-follow; "page/book" reading narrowed
to a single inline type — see D12.)*

**Why.** Moodle's AI subsystem is provider-pluggable, so format choice is a
product/cost/quality call, not a constraint. Text and image are mature,
cheap, fast, and directly valuable for B2B SaaS training and cert/CE.
Audio/video are heavier and pricier; comics/music/games are
consumer/K-12-flavoured and off-ICP. Scope discipline beats feature parity
with Honen.

**Rejected.** Chasing all ten Honen formats. Now technically possible, but
cost, latency, generation quality, and ICP fit argue against most of them.

**Revisit if.** A target customer needs audio narration for compliance/CE
or accessibility; TTS is the most likely first addition.

---

## D5 — All AI via Moodle's AI Providers subsystem, bound to capability tiers

**Decision.** Every AI call routes through Moodle's native AI Providers
subsystem. The plugin declares the *capability tier* each step needs —
`reasoning` (blueprint), `drafting` (bulk section content), `image`
(visuals) — and the tenant maps each tier to an actual provider/model.
Quiz/question generation is delegated to local_quizgenpro's API and uses
its own provider config.

**Why.** Honours the LMS Light AI integration standard (no direct LLM
calls), keeps the plugin provider-agnostic and portable, and enables
per-tenant provider/region selection — a data-residency selling point.
Tiering puts the expensive strong model only where leverage is highest
(the blueprint) and a cheaper model on high-volume drafting. Delegating
quizzes avoids duplicating quizgenpro and keeps one source of truth.

**Rejected.** Direct LLM integration (violates the standard, loses
per-tenant config); a single model for everything (wastes spend on bulk
drafting or underpowers the blueprint); reimplementing quiz generation.

**Revisit if.** AI Providers in the target Moodle version lacks an action
we need (e.g., image generation not exposed); fall back to the nearest
supported action and document the gap, do not bypass the subsystem.

---

## D6 — Source ingestion: documents + topic prompt; structure-aware chunking; no vector store in v1

**Decision.** v1 accepts PDF, DOCX, PPTX, pasted text/markdown, and a bare
topic prompt. Text extraction runs in an async task using established PHP
libraries. Chunking is structure-aware (split on headings/sections) with a
map-reduce pass to build the outline when the corpus exceeds context, and
section-relevant chunks passed to content generation. A normalized "source
corpus" (extracted text + structure metadata) is the input to generation.

**Why.** These formats cover the overwhelming majority of corporate/cert
source material. Structure-aware chunking preserves pedagogy; map-reduce
handles large inputs without a vector store. Keeping v1 free of a vector
dependency reduces operational surface.

**Rejected.** Vector RAG over a per-tenant store (deferred — adds infra;
revisit when corpora regularly exceed what map-reduce handles well);
audio/video transcription and URL scraping (deferred — extra provider
capability / quality and legal questions).

**Revisit if.** Source corpora routinely get large enough that map-reduce
quality degrades, or customers want to point at a media library; add
retrieval/RAG and transcription as a v1.x capability.

---

## D7 — Per-tenant generation cost/quota governance with audit logging

**Decision.** The plugin tracks generation spend per tenant (distinct from
tool_mutrain learner credits): a pre-generation estimate surfaced at the
blueprint stage, a soft-warning threshold, a hard cap admins can raise, and
a per-generation audit log (who, what, provider/model, estimated cost).
Image generation is opt-in per section with its own sub-cap. All stored in
standard config/tables for automatic per-tenant isolation.

**Why.** Generative spend is real and uneven (image >> text). Estimating at
the blueprint stage pairs naturally with the human gate. Audit logging fits
the credential/audit discipline and becomes the substrate for usage-based
billing later.

**Rejected.** No governance (uncontrolled spend); reusing tool_mutrain
(that's learner-facing credits, wrong layer).

**Revisit if.** LMS Light wants to bill customers for generation — the log
is the substrate, but billing itself is a separate future build.

---

## D8 — The course blueprint is a first-class, persisted artifact

**Decision.** The generated plan (sections, objectives, per-section content
types, assessment spec, per-section image flags, status) is a stored,
editable artifact, not a throwaway intermediate.

**Why.** It is the IR that makes both generation modes possible, enables
cost estimation before materialization, supports per-section regeneration
and editing, and gives the instructor something concrete to review and
approve.

**Rejected.** Generating straight from source to course with no inspectable
intermediate (kills the review gate, estimation, and partial regeneration).

**Revisit if.** Never expected; this is foundational.

---

## D9 — Distribution via Moodle-as-LTI-provider (`enrol_lti`) is parked, not built

**Decision.** Serving generated courses out to remote LMSes via Moodle's
LTI provider capability (`enrol_lti`, "Publish as LTI tool") is documented
as a future option and is not in v1 scope. It is orthogonal to
local_coursegen and requires no change to it.

**Why.** It gives LMS-agnostic reach while keeping content, experience, and
data in the tenant — the opposite trade-off from an external app, and a
viable second go-to-market mode. But it acts on courses after they exist;
coursegen builds them. Build and publish are cleanly separable.

**Rejected.** Designing v1 around it (premature; unverified provider-side
maturity and multi-tenancy behaviour).

**Revisit if.** LMS Light pursues a "serve our courses into your existing
LMS" motion. First verify LTI 1.3 provider maturity in the target Moodle
version, enrol_lti behaviour inside a tool_mutenancy tenant, shadow-account
provisioning implications, and the grade record-of-truth question.

---

## D10 — Compose with the existing stack

**Decision.** Generated courses default to the format_pathway course
format; assessments go through local_quizgenpro's API; wrapping a generated
course into a tool_muprog program and/or tool_mucertify certification is an
optional toggle, not core v1.

**Why.** format_pathway is the ICP-aligned delivery target and embeds
cleanly (relevant to D9). quizgenpro already does assessment generation.
Program/cert wrapping is valuable for the niche-operator ICP but adds scope;
making it optional keeps v1 focused while leaving the path open.

**Rejected.** Building bespoke delivery/assessment/program features inside
coursegen (duplicates the stack, against CONTEXT.md).

**Revisit if.** Program/cert wrapping proves to be a default expectation for
the cert/CE ICP; promote it from optional toggle to a first-class step.

---

## D11 — Capability tiers collapse to internal prompt labels (P4)

**Decision.** The plugin no longer exposes per-tier provider settings
(`provider_reasoning`, `provider_drafting` removed). core_ai's
`process_action()` routes by configured provider *order*, not per call, so
those settings routed nowhere. "Tier" survives only as an internal *prompt*
label (a reasoning prompt vs a drafting prompt) issued against core_ai's single
configured text provider. The image path stays separate because `generate_image`
is its own core_ai action that routes to image-capable providers independently
of text; `provider_image` is retained as the marker for that separate path.

**Why.** Honest configuration: a setting that cannot change behaviour is worse
than no setting. Tiering still has value as prompt-shaping (different system
prompts for outline reasoning vs bulk drafting) even when both hit the same
provider. §10.2 logging records the actually-resolved provider/model regardless.

**Rejected.** Keeping the tier→provider dropdowns (mislead operators into
thinking they route); reflection/core patches to force a provider per call
(fragile, out of scope).

**Revisit if.** core_ai adds public per-action / per-call provider selection —
then reintroduce real tier→provider mapping and route the reasoning, drafting,
and image tiers to distinct configured providers.

---

## D12 — Reading content is an inline "Text and media" area; book + flashcards deferred (P4)

**Decision.** Generated reading content is materialized as an inline
`mod_label` ("Text and media") area rendered within the format_pathway section,
not `mod_page`. The blueprint content-type enum is single-valued (`inline`) so
the plan can only emit what the materializer builds. Book-style reading
(`mod_mubook`) and flashcards are deferred to fast-follows.

**Why.** format_pathway shows one section at a time; an inline area displays the
reading directly in the section, matching that pagination without an extra
click into a page/book. Each label is created with manual completion and the
course has completion enabled, so format_pathway's progress (which counts
completion-tracked activities) works and the later muprog/mucertify cert/CE
chain has per-activity completion to build on. A single content type keeps the
blueprint, the edit form, and the materializer in lock-step.

**Rejected.** mod_page (extra navigation, breaks the one-section flow);
mod_mubook now (heavier, audit-pending — deferred); flashcards in v1 (scope).

**Revisit if.** A customer needs long multi-chapter sections (promote
mod_mubook) or spaced-recall study aids (add flashcards).

---

## D13 — Quiz-generation cost is out of coursegen's cap; quizgenpro governs its own (P5)

**Decision.** Assessment generation is delegated to local_quizgenpro, which uses
its own AI provider config and exposes no token/cost to the caller. quiz-gen
spend is therefore NOT counted in coursegen's §10.2 token log, estimate, or
materialize-time spend cap; it is governed by quizgenpro's own provider limits.
coursegen logs the quiz step (provider `quizgenpro`, no tokens) for audit, and
calls quizgenpro's public API (generator + exporter) — it never reimplements
question generation (D5/D10). Because quizgenpro only generates and banks
questions (no quiz placement), coursegen creates the mod_quiz and attaches the
questions; that assembly is materialization, not generation.

**Why.** quizgenpro returns no usage data, so including its cost would require
estimating or patching it — both out of scope for v1. Keeping the boundary
explicit avoids a misleadingly precise coursegen estimate.

**Rejected.** Estimating quiz cost from question counts (guesswork); patching
quizgenpro to surface tokens (cross-plugin change, out of scope).

**Revisit if.** quizgenpro exposes per-call token/cost; then fold quiz spend
into the §10.2 log and the cap.

---

## D14 — Quiz failure skips the assessment but still completes the course (P5)

**Decision.** If quizgenpro errors or returns no usable questions for a section,
the quiz is skipped (logged as a failure for audit), the section keeps its
reading content, and materialization still completes. A quiz-generation failure
never sinks the whole course.

**Why.** Consistent with the P4 image-subcap "skip and build" precedent: a
missing assessment in one section is a degraded result, not a fatal one, and the
course is hidden/draft for instructor review anyway.

**Rejected.** Failing the whole job on a single section's quiz failure (loses the
reading content and every other section's work).

**Revisit if.** Operators want assessments to be mandatory for certain course
types; add a per-job "assessments required" toggle that hard-fails instead.

---

## D15 — Assessed sections use formative mod_knowledgecheck; graded mod_quiz deferred (P5)

**Decision.** A type=quiz blueprint section is fulfilled by an inline
mod_knowledgecheck (the house formative-check plugin): best-attempt, auto-
complete on a finished attempt, rendered in place via filter_knowledgecheck's
`{knowledgecheck id=<uuid>}` token embedded in the section's reading label.
Questions are still generated and banked by local_quizgenpro (D5/D10/D13);
coursegen banks them in the course's default question bank (mod_qbank), creates a
stealth knowledge check, pins the banked entries
(`\mod_knowledgecheck\local\questions::add`), and embeds the token. A graded
mod_quiz for genuine certification assessment is a documented fast-follow, not
built in v1. Adds dependencies `mod_knowledgecheck` and `filter_knowledgecheck`.

**Why.** A pathway section is a learning step; the assessment there is formative
(check understanding, retry freely), not a summative graded exam. mod_quiz with
complete-on-attempt was the summative tool doing a formative job as a
click-through — and required hand-assembling ~40 brittle quiz fields.
knowledgecheck is purpose-built, renders inline in the reading flow, and owns its
own completion. Stealth + completion-tracked still feeds format_pathway progress
and the cert/CE chain.

**Rejected.** mod_quiz inline (wrong tool, brittle creation); building both
vehicles now (scope).

**Disabled-filter handling.** If filter_knowledgecheck is not enabled at
materialize time, the check is created NON-stealth (visible on the course page)
with no embedded token and a logged warning — so it is reachable as a normal
activity rather than a silently-invisible inline check. Enabling the filter
restores inline rendering for future builds.

**Revisit if.** A course type needs a graded, summative, certification-grade exam
— add a mod_quiz vehicle as a per-section assessment option alongside the
knowledge check.

---

## D16 — Spend cap is a rolling window enforced at every AI call; cert-chain wrap deferred to P7 (P6)

**Decision.** The per-tenant spend cap (`cap_period_spend`) and image sub-cap
(`cap_image_count`) are accounted over a rolling window (`period_days`, default
30) from the §10.2 audit log, via a single `local\spend_governor`. The governor
gates **every** AI call site — blueprint synthesis, section regeneration, and
materialization — not just materialization. A cap of `0` means unlimited for both
the spend cap and the image sub-cap. The original P0 default of `50` is bumped to
the current default `1000000` by a `db/upgrade` step, but only where the stored
value is still exactly `'50'` (an untouched default), never a value an admin set.

The optional muprog/mucertify "wrap" (the `wrap_muprog` / `wrap_mucertify`
toggles from D10/P0) is **deferred to P7**. The toggles remain in settings,
labelled "not yet active".

**Why.** A "per period" cap that summed all-time spend never reset — it was a
lifetime budget that silently hard-stopped a tenant forever. A rolling window
makes the period real. Gating only materialization left blueprint/regen reasoning
calls uncapped, so "enforced at every AI call site" was untrue. The cap=50 bump
is conditional so it self-heals untouched installs without overriding deliberate
admin policy. The cert-chain wrap is a genuine two-plugin integration (create a
muprog program, add the course to its content **tree** via
`local\content\{top,set,course,item}`, then a mucertify certification linking the
program through `programid1`), plus cross-plugin re-entrancy (a retry must not
mint a duplicate program *or* certification). That is more than a finalize phase
should carry, and the wrap is optional/off-by-default, so v1 is releasable without
it.

**Rejected.** Keeping the lifetime sum and renaming the setting (a never-resetting
"period" cap is a footgun); gating only materialization (leaves reasoning spend
uncapped); unconditionally bumping cap=50 (would clobber an admin who genuinely
set 50); building the cert wrap in P6 (scope/risk in a finalize phase).

**Revisit if.** Per-call estimates become available before the blueprint exists
(then blueprint/regen could pre-check an estimate rather than only refusing an
already-over-cap tenant); or the cert/CE ICP makes the wrap a default expectation
(promote it from a P7 optional step per D10).

---

## D17 — Cert-chain wrap is built: in-band, best-effort, runtime soft-check (P7)

> **SUPERSEDED (P18, see D24).** The cert/program wrap was removed: credentialing via
> muprog/mucertify is out of scope for a course-building tool. `cert_wrap`, the
> `wrap_muprog`/`wrap_mucertify` toggles, and the muprog/mucertify integration are gone.
> The amendments below (P16 allocation source, etc.) are historical.

**Decision.** The `wrap_muprog` / `wrap_mucertify` toggles (D10/D16) are now wired
via `local\cert_wrap`. At the end of `materialize()`, if `wrap_muprog` is on the
generated course is placed in a `tool_muprog` program (`program::create` +
`load_content`/`top->append_course`); if `wrap_mucertify` is also on, a single-
period `tool_mucertify` certification is created linked to that program
(`certification::create` with `programid1`). The program/certification are named
after the course and keyed by a per-job `idnumber` of `coursegen-job-{jobid}`
(both tables enforce a UNIQUE idnumber). The wrap creates only the container — it
does **not** allocate or assign learners; an admin configures allocation sources
afterwards. The wrap is pure orchestration (no AI), so it is outside the spend
governor.

- **Default cap posture (front-matter fix).** The fresh-install default and the
  `db/upgrade` heal target for `cap_period_spend` are both set to **500000**
  generation units / 30-day period (≈ 30–100 courses, warns at 80%) — a deliberate
  bounded default rather than the arbitrary 1000000 left over from P4. settings.php
  and the upgrade target agree.
- **Re-entrancy: in-band, cleanup removes prior.** `cleanup_partial_course()` (the
  retry's delete-and-rebuild hook) deletes any program/certification with the job's
  idnumber — certification first, then program (the cert FK points at the program)
  — so each attempt rebuilds course + program + certification fresh. `cert_wrap`
  also find-or-creates defensively, so a leftover from an interrupted cleanup is
  reused, never duplicated.
- **Failure mode: best-effort.** A wrap failure is logged (§10.2 warning + mtrace)
  and the job still reaches COMPLETE — the course is the primary artifact (consistent
  with the quiz/image skip-and-build precedent, D14).
- **Toggle dependency.** `wrap_mucertify` is hidden in settings unless `wrap_muprog`
  is on (`hide_if`), and `cert_wrap` skips the certification at runtime if it ever
  runs without a program — never silently creating a program the admin didn't enable.
- **Dependency posture.** `tool_muprog`/`tool_mucertify` are deliberately NOT hard
  `requires` in version.php (forcing the cert stack onto every tenant for an optional
  off-by-default feature would be wrong). `cert_wrap` does a runtime soft-check
  (`class_exists` + a version floor of the verified `2026041950`); a toggle that is
  on with the plugin/API absent warns and skips.

**Why.** The wrap is a real two-plugin integration but the API is clean: program
and certification each take `{contextid, fullname, idnumber}` (the cert also takes
`programid1`), both `create()` calls self-fill all other NOT NULL fields, and the
UNIQUE idnumber gives a DB-enforced idempotency/cleanup handle. In-band wrap reuses
the existing course rebuild path exactly, so a retry provably cannot strand or
duplicate a program/certification. Best-effort keeps an optional finalize step from
destroying a fully-built course.

**Rejected.** Hard `requires` on the cert stack (forces it on every tenant);
find-or-create-and-re-point on retry (must fix the orphaned old-course item when the
course is rebuilt — more moving parts than delete-and-rebuild); hard-fail on wrap
error (loses a built course over an optional step); auto-enabling the program when
only the certification toggle is set (creates a program the admin didn't request);
shipping the arbitrary 1000000 cap default.

**Revisit if.** Recertification is wanted (set `programid2`/`recertify` on the
certification); or the wrap should move out of materialize into an explicit,
separately-triggered finalize action.

**Allocation source amended (P16).** As first built, the wrap created the program and
the certification (linked by `programid1`) but never enabled the muprog `mucertify`
allocation **source** on the program. `sync_certifications` only allocates members
through that source, so a learner assigned to a wrapped certification got an assignment
and a period but **no program allocation and no course enrolment** — the certification
was structurally inert ("pending" forever) while looking correct. The wrap now enables
the source (`\tool_muprog\local\source\mucertify::update_source`, idempotent) on the
program immediately after the certification is created. Because an inert certification
is worse than none, the cert chain is **atomic**: if the source cannot be enabled the
certification is rolled back (deleted) and the failure audited loudly — never ship a
certification that can't certify. The program is kept (it is independently allocatable,
not inert), and the wrap stays best-effort for the job overall. The earlier "configure
the program's allocation sources afterwards" note referred to *who* gets allocated
(cohort/manual/self) — not this cert→program plumbing, which is mandatory for the
certification to function. Tested at the seam (assign → allocate → enrol), not just the
source row's existence.

---

## D18 — Re-materialize refuses rather than destroy a populated wrap (P8)

> **REMOVED (P18, see D24).** This decision was wrap-specific (refusing when a muprog
> program had allocations or a mucertify certification had assignments). With the wrap
> gone, the populated-wrap refusal is removed. The COURSE-protective refusal — refusing a
> destructive re-materialize when the course itself has real enrolments/completion — was
> always a separate decision (D20) and stays.

**Decision.** The D17 "cleanup removes prior, rebuild fresh" path is destructive:
`tool_muprog\local\program::delete` hard-cascades — it deletes every
`tool_muprog_allocation` row and tears down the muprog enrolment instances — and
`tool_mucertify\local\certification::delete` deletes every
`tool_mucertify_assignment` row; neither refuses or requires archiving first. Since
re-materialize is a supported flow (a post-approval section edit reopens the job to
`awaiting_review`, and re-approving re-runs materialize), an unguarded rebuild would
silently wipe an admin's allocation configuration and the cohort's access. So:
before any destructive cleanup, `materialize()` calls
`cert_wrap::populated_block_reason($job)`; if the job's program has any allocations
or its certification has any assignments, the job is **refused** via a new
non-destructive `materializer::refuse()` (audit + the caller's false return, **no
cleanup**), with an actionable reason naming the program/certification and the counts.
An absent or empty wrap returns null, so the authoring-retry case keeps the D17
delete-and-rebuild unchanged. v1 is refuse-only; it does NOT build reuse-and-re-point.

**Refusal leaves the job COMPLETE, not FAILED (amended P9).** The previously-built
course is live and serving the allocated cohort, so the job is genuinely complete —
marking it FAILED would misrepresent a healthy course and, worse, strand the admin:
`approve()` requires `awaiting_review` and the reopen requires `approved`/`complete`,
so nothing re-drives a FAILED job. `refuse()` therefore sets `COMPLETE` and surfaces
the reason only through the §10.2 audit log (outcome `failure`) and the false return.
To make the promised retry real, the reopen was broadened (P9): `review_gate::
reopen_for_reedit` (renamed from `reopen_if_approved`) now reopens from `complete` as
well as `approved`, so editing/re-approving a built course re-drives materialize —
clearing the allocations then editing + re-approving rebuilds with an empty wrap.

- **Guard placement.** Predicate in `cert_wrap` (it owns the idnumber and the
  program/certification lookup); enforcement in `materializer::materialize()` BEFORE
  `cleanup_partial_course()`, so a refusal leaves course + program + certification
  fully intact — no half-torn-down state. The existing `fail()` is unusable here
  because it calls `cleanup_partial_course()` (which would destroy the wrap); hence
  the separate `refuse()`.
- **Predicate.** No public muprog "has allocations" helper exists; the canonical
  signal is the very table `delete()` wipes — `count_records('tool_muprog_allocation',
  ['programid' => …]) > 0` and `count_records('tool_mucertify_assignment',
  ['certificationid' => …]) > 0`. All rows count (including archived — still data the
  delete would lose).

**Why.** Destroy-and-rebuild was correct for an empty, disposable wrap but wrong once
an admin has invested configuration into it — and the wrap ICP is exactly who will.
Refusing is a safe, reversible v1: the admin clears allocations or detaches the
program, then retries. Reuse-and-re-point (keep the populated program, swap its course
content to the rebuilt course) is more surface than this data-safety fix warrants now.

**Rejected.** Leaving D17 as-is (silent data loss); reuse-and-re-point in v1 (scope);
gating on configured sources without learners too (broader than the stated
allocations/assignments predicate — a possible later tightening); putting the refusal
through `fail()` (would run cleanup and destroy the wrap it is meant to protect).

**Revisit if.** Re-materializing a populated wrap becomes a common need — then build
reuse-and-re-point (swap the program's course item to the rebuilt course in place,
preserving allocations) rather than refusing; or gate on source configuration too.

**Surfaced in the UI (P13).** The refusal was previously only in the audit log. The
job page now shows it on the complete view, but must not be confused with the benign
`outcome=failure` rows a normally-built course carries (a skipped knowledge check or
image). So `refuse()` marks its log row with a distinct stage
(`job_manager::STAGE_REBUILD_REFUSED`) and `job_manager::current_refusal()` returns it
only while it is the job's latest log row — i.e. no later rebuild has written build
rows after it. A clean complete job, and one carrying only skip failures, show nothing.

---

## D19 — Pre-pilot release posture: beta, declared floor matches what is verified (P9)

**Decision.** For the pilot the plugin is marked `MATURITY_BETA`, release `v0.9.0`
(GA would be `v1.0.0`), and `$plugin->requires` is bumped from the unverified Moodle
5.1 floor (`2025092600`) to **`2026042000` (Moodle 5.2)** — the only version ever run.
PHP 8.3 is the verified runtime. The code uses no 5.2-only APIs, so 5.1/PHP 8.2 may
well work, but the declared floor reflects what has actually been tested rather than an
unverified compatibility promise.

**Why.** Feature-complete and entering a pilot is the definition of beta. A plugin
should not advertise a Moodle/PHP floor it has never been exercised against; the pilot
runs on 5.2.1/PHP 8.3, so the honest, low-risk choice is to declare that and widen the
floor later only if a real 5.1/PHP 8.2 test pass substantiates it.

**Rejected.** Staying `MATURITY_ALPHA` (understates readiness for a pilot); keeping the
5.1 floor untested (an unverified support claim); testing on a real 5.1/PHP 8.2 box now
(no such environment, and not worth blocking the pilot for).

**Revisit if.** A 5.1/PHP 8.2 environment is stood up and the suite passes there (lower
the floor back); or the pilot succeeds and the plugin goes GA (`v1.0.0`,
`MATURITY_STABLE`).

---

## D20 — The re-materialize guard protects the course's own learner state, not just the wrap (P10)

**Decision.** D18 refused a destructive rebuild only when the muprog program had
allocations or the certification had assignments. But a re-materialize is
`delete_course` + rebuild, which destroys the course's enrolments, completion and
grades wholesale — and P9's reopen-from-COMPLETE made that reachable from a trivial
edit. A learner who reached the (now-unhidden) course by any non-wrap route — manual,
self, cohort enrolment — was invisible to the wrap-only guard. P10 makes the refusal
symmetric: it now also refuses when the job's existing course holds live learner state,
however it arose.

- **Baseline (verified empirically).** A freshly-materialized course has **zero**
  enrolled users, zero role assignments and zero completion records; `create_course`
  does not auto-enrol the creator (`creatornewroleid` applies only in the web edit
  flow, and the admin has manage caps). The default manual/guest/self instances — and,
  once wrapped, a `muprog` instance — carry zero `user_enrolments`. So "populated" needs
  no baseline subtraction: any enrolled learner or any real completion is genuine.
- **Course predicate** (`materializer::course_learner_state_reason`): the job's course
  is populated if it has ≥1 distinct user enrolled via a **non-`muprog`** instance, or
  any real completion (`course_modules_completion.completionstate <> 0`, or
  `course_completions.timecompleted` set).
- **Why exclude `muprog` enrolments.** A muprog enrolment exists iff the user is
  allocated to our program, which the wrap predicate already counts — so excluding them
  reports the wrap case once (as "N allocations") instead of double-counting the same
  people as "N enrolled learners". No gap: allocation ⟺ muprog enrolment ⟺ caught by the
  wrap predicate. (Real completion is counted regardless of enrolment source, so a
  learner who actually progressed is caught either way.)
- **Composition.** Two predicates feeding one refusal: `cert_wrap::populated_block_reason`
  (now returns just its clause) for the program/cert, and the course predicate in the
  materializer; `materialize()` joins the non-null clauses into a single `refuse()` with
  a combined, actionable reason. Still runs BEFORE `cleanup_partial_course`, so a refused
  rebuild leaves the course, program and certification fully intact, and the job stays
  COMPLETE (D18 as amended in P9). The retry path is P9's reopen-from-COMPLETE — clearing
  the learner state, then editing + re-approving, rebuilds.

**Why.** A guard that protects the wrap but not the course it wraps is asymmetric and
unsafe: the worst loss (a learner's completion/grades) was exactly the unguarded case.
Anchoring on the verified zero baseline lets the guard fire on genuine added learners
without false-positiving the authoring-retry loop (build → edit → rebuild with nobody
enrolled), which would otherwise be broken.

**Rejected.** One combined check counting all enrolments incl. muprog with internal
dedup (moves program/cert knowledge out of cert_wrap; needs explicit dedup); counting
any completion-tracking row incl. `completionstate = 0` (a muprog enrolment can create a
`course_completions` tracking row, overlapping the wrap clause); subtracting a non-zero
baseline (there is none).

**Revisit if.** A generated course is legitimately added to a second, foreign muprog
program (its learners would be muprog-enrolled but outside our program, so caught only
if they have real completion) — then widen the course predicate to count muprog
enrolments not attributable to the job's own program.

---

## D21 — Assessment-model coherence: one criterion per section, honest knowledge-check naming (P14)

**Decision.** Two corrections to the assessment model, no new build targets (a real
graded quiz is P15).

- **One completion-tracked activity per section.** An assessed section previously
  carried two tracked activities — the reading label (manual "Mark as done") and the
  knowledge check (auto-on-submit) — so passing the checks left a learner short of 100%
  until they also clicked every reading area. Now the label's completion rides the same
  branch that decides whether a check was built: a section with a knowledge check sets
  the label to `COMPLETION_TRACKING_NONE` (the check is the signal); a reading-only
  section keeps the label at `COMPLETION_TRACKING_MANUAL` (its only signal). The signal
  is keyed on whether a check was *actually built*, not on section type:
  `build_knowledgecheck()` now returns `?string` (null = not built, so the label stays
  the manual signal), guaranteeing exactly one tracked activity per section — never zero
  (uncompletable) nor two. `format_pathway` progress counts tracked CMs (it skips
  `COMPLETION_TRACKING_NONE`), so per-section progress now reads coherently. The
  materializer still configures no course-completion *criteria* (it never did); that
  `course_completions`/cert-chain question is separate and out of scope here.
- **Honest knowledge-check naming.** The assessment type historically called `quiz`
  always built a knowledge check (the P5 swap, D15). Renamed everywhere to
  `knowledgecheck` (`ASSESS_QUIZ`→`ASSESS_KNOWLEDGECHECK`, value `'quiz'`→
  `'knowledgecheck'`): blueprint enum/normalizer, edit dropdown + lang, `view.php` meta,
  materializer dispatch, and the AI prompt vocabulary (now `{none, knowledgecheck}` — the
  AI never emits a real quiz; that is a human-only choice in P15).

**The data hazard.** Stored blueprints carried `"type":"quiz"` meaning knowledge check,
and the normalizer coerces any non-`'knowledgecheck'` type to `none` after the rename —
so unmigrated rows would silently lose their assessment on read. A one-time
`db/upgrade.php` migration rewrites stored `coursegen_blueprint` JSON `'quiz'`→
`'knowledgecheck'` (per-row decode → rewrite → encode), leaving **no** legacy `'quiz'`.

**`'quiz'` reserved for P15.** Because the migration clears every legacy `'quiz'`, the
value is free for P15 to reintroduce meaning a real `mod_quiz` — no ambiguity between an
old knowledge check and a new graded quiz.

**Why.** Two tracked activities per section made completion (and the cert chain that
keys off it) misrepresent learner progress, and the "Quiz" label actively confused a
real operator about what the tool builds. Migrating rather than aliasing keeps the enum
honest and reclaims the natural word for the real thing.

**Rejected.** A read-time `'quiz'`→knowledgecheck alias (makes `'quiz'` permanently mean
KC, blocking P15's reuse); keying the label's completion on section type rather than
whether a check was built (would leave a generation-skipped section with zero tracked
activities); wiring course-completion criteria here (separate concern, added scope).

**Revisit if.** P15 lands the real graded quiz (reintroduces `ASSESS_QUIZ='quiz'` as a
distinct, human-chosen, graded vehicle alongside the knowledge check).

---

## D22 — Generated courses configure course-completion criteria (all tracked activities, ALL); the completion→cert chain (P15)

**Decision.** The materializer now configures course-completion criteria on every
generated course: one `completion_criteria_activity` per completion-tracked module,
with overall and activity `completion_aggregation` set to `COMPLETION_AGGREGATION_ALL`
(mirrors core's `course/completion.php`). Since P14 (D21) leaves exactly one tracked
activity per section, "complete all tracked activities" equals "completed every
section". It runs at the end of the build (after all activities exist, so their cmids
are known), before the cert wrap, on the same guarded path; a clean re-materialize
deletes the prior course and its criteria and rebuilds them fresh, so no criterion ever
points at a stale cmid. The configurator is a shared static method
(`materializer::configure_course_completion`) the completion walkthrough test also calls,
so the test asserts against real production wiring.

**Why.** This was the one missing link in the value chain. The front half (build →
learner completes activities → format_pathway progress) worked, but the generated course
had **no** completion criteria, so `course_completions` could never fire — and everything
downstream keys off it. With criteria configured, completing the activities drives course
completion, which emits `\core\event\course_completed`; the existing P7 wrap propagates
the rest with no new code: muprog's observer (`event_observer::course_completed` →
`allocation::fix_user_enrolments`) copies course completion into program-item completion
and fires `\tool_muprog\event\allocation_completed`, and mucertify's observer
(`event_observer::allocation_completed`) issues the certificate. Verified by reading the
observers; proven end to end by the runtime walkthrough (allocate → enrol → complete →
cron → course_completions fires, allocation completes, certificate issues).

**Rejected.** Leaving criteria unconfigured and relying on operators to set them by hand
(the back half would silently never work — the status quo this fixes); a single
course-level or grade-based criterion (the per-activity ALL model maps cleanly onto the
one-tracked-activity-per-section design and needs no grade setup); patching muprog/
mucertify to watch something other than course completion (they already watch it
correctly — the gap was purely the missing criteria).

**Revisit if.** A course type wants partial/weighted completion (e.g. complete N of M
sections) — switch the aggregation to ANY or a points model; or completion should also
require a passing grade once the real graded quiz (P16) lands.

---

## D23 — Real graded quiz: summative, pass-to-complete, human-selected (P17)

**Decision.** A third assessment type, `ASSESS_QUIZ = 'quiz'` (reclaimed in P14/D21),
builds an actual graded `mod_quiz` — summative, distinct from the formative inline
knowledge check (D15). It is a **separate, visible click-through activity** (a graded
exam has no inline render), generated and banked through the same quizgenpro seam the
knowledge check uses. Completion is **pass-based**: `completionpassgrade` + a
grade-to-pass of 50/100, so passing yields `COMPLETION_COMPLETE_PASS` (which the P15
course criteria count) and a graded-but-failed attempt yields `COMPLETION_COMPLETE_FAIL`
(which they do not) — the exam genuinely gates course completion (and, if wrapped, the
certificate). Defaults: unlimited attempts (a learner can retake to pass, since failing
blocks completion), highest grade, no time limit, review after the attempt and once
closed — all tunable post-build. Selection is **human-only**: the AI vocabulary stays
`{none, knowledgecheck}` and never emits a quiz; "Quiz" is a deliberate review-UI choice
(promote a section — usually the last — to a graded exam). Completes D15 (which deferred
the graded quiz when it swapped the formative vehicle to the knowledge check).

The P14 one-tracked-activity-per-section rule generalizes: if a KC **or** a quiz was
built the reading label is `COMPLETION_TRACKING_NONE` and the assessment is the tracked
activity; on a generation/banking skip (D14) the label reverts to `MANUAL` so the
section is never uncompletable. P15's `configure_course_completion` already requires
every tracked CM, so the quiz is automatically part of course completion. Re-entrancy is
unchanged (the quiz lives in the course; `delete_course` removes it on rebuild), and
quiz-generation cost stays outside the coursegen spend cap (D13).

**Why.** The knowledge check is a formative check-for-understanding; certification and
real assessment need a summative, graded, pass-or-fail exam. Building a real `mod_quiz`
(rather than grading the KC) reuses Moodle's full quiz/grade/review machinery and lets
operators tune it. Pass-to-complete makes the exam the gate, which is the point of a
graded assessment in a cert chain.

**Rejected.** Grading the knowledge check instead of a real quiz (the KC is formative by
design, D15; conflating the two repeats the P5 mistake); complete-on-submit rather than
pass (loses the summative gate); AI emitting quiz sections (a graded exam is a
deliberate human decision, not a generation default); a single attempt (a learner who
fails once would be permanently blocked from completing).

**Revisit if.** Operators want per-section pass marks or attempt limits surfaced in the
review UI; or a quiz should also support non-gating "graded but optional" placement.

---

## D24 — Remove the certification/program wrap; credentialing is out of scope (P18)

**Decision.** The optional cert/program wrap (D10/D16/D17, with the P16 allocation-source
fix and the D18 populated-wrap refusal) is removed. `classes/local/cert_wrap.php`, the
`wrap_muprog`/`wrap_mucertify` settings, the muprog/mucertify soft-dependency wiring, and
the D18 refusal branch are deleted. `local_coursegen` is a course-building tool;
credentialing via tool_muprog/tool_mucertify is out of scope — an operator can wrap a
generated course into a program/certification themselves, and P19 adds a
mod_coursecertificate activity slot for the common "certificate of completion" case.

**Course-protective machinery stays.** The removal is surgical: it takes out only the
wrap. The guards that protect the COURSE itself are untouched —
`materializer::course_learner_state_reason` (D20, refuse a destructive re-materialize when
the course has real enrolments/completion), the rebuild-refusal surfacing (P13: `refuse()`,
`STAGE_REBUILD_REFUSED`, `current_refusal()`, the complete-view notice), and the
course-completion criteria (D22). The refuse machinery simply loses its D18 (wrap) trigger
and keeps firing on the D20 (course-learner-state) one. With muprog gone, D20's guard no
longer needs to exclude muprog enrolments, so it simplifies to "any real enrolment or
completion".

**Why.** Owning a credentialing integration inside a course builder was scope creep: it
coupled the plugin to two external plugins, carried a fragile multi-plugin allocation/
certification flow (the P16 inert-certification bug is evidence of the surface area), and
duplicated what the stack already does. A course builder should build courses well and
leave credentialing to the credentialing tools (or a simple in-course certificate
activity).

**Rejected.** Keeping the wrap behind its off-by-default toggles (still carries the
coupling and the maintenance surface for a feature outside the tool's job); keeping muprog
as a soft dependency "just in case" (dead code path).

**Revisit if.** Credentialing becomes a core requirement of the builder itself (rather
than an operator/stack concern) — re-introduce it as a deliberately-scoped integration,
not an in-band materialize step.

---

## D25 — Course-structure enrichment: intro + wrap-up bookends and a course thumbnail (P19)

**Decision.** The materializer brackets the generated content with two **untracked**
bookend sections and sets a generated course image. All three live in the materializer
— no blueprint, AI-prompt, or review-form change.

- **Introduction** — **section 0**, format_pathway's native "Overview" that a learner
  lands on first (NOT a numbered section — see the amendment below). It is named via
  `course_update_section` and pinned in the sidebar by setting the `pathwayshowsection0='1'`
  course-format option explicitly, so it renders the same on any tenant regardless of that
  tenant's default. Its content is *derived* from the editable course description plus a
  "what you'll cover" list of the content section titles — no extra AI call; re-materialize
  picks up an edited description.
- **Wrap-up** — the last section, a short boilerplate (lang-string) closing note. It gives
  the final content section a `<Next>` target (so its completion display refreshes on
  navigation) and an obvious home for an operator-added certificate. **The plugin builds
  the section only — it never creates a mod_coursecertificate and takes no dependency on
  it.**
- **Thumbnail** — a decorative cover generated via the existing image_client and set as
  the course's "Course image" (the `overviewfiles` area). Gated by the same image opt-in
  the sections use (≥1 image-flagged section) AND the image sub-cap; skipped — not failed
  — when off or exhausted, and counted as one image against the budget. No alt text.

**The shared correctness point.** The bookend labels are `COMPLETION_TRACKING_NONE`: a
deliberate EXCEPTION to P14's one-tracked-activity-per-section rule (which applies to
CONTENT sections so they're never uncompletable). They are orientation and closure, not
learning units, so they must not become completion criteria — otherwise P15's "all
tracked activities" would require "completing" the intro and the wrap-up, changing what
finishing the course means. `configure_course_completion` selects `completion <> NONE`,
so the criteria equal exactly the content/assessment tracked activities; the bookends
contribute nothing. The intro occupies section 0, so the content sections are 1…N (no
shift) and the wrap-up is the last numbered section; the KC/quiz placement keys on the
real `sectionnum` returned by `add_named_section`, so it stays correct by construction.

**Why.** A generated course that opens cold on the first content unit and ends abruptly
reads as a dump, not a course. An overview, a closing section, and a cover image make it
feel finished — at no extra AI cost for the bookends and one optional image for the
cover. The wrap-up also resolves a real format_pathway lag: section progress is rendered
server-side, so completing the last unit only reflects after a navigation — the `<Next>`
into the wrap-up provides it.

**Rejected.** A dedicated AI-written overview (extra call, another thing to keep in sync —
deriving from the description is free and stays editable); auto-adding a
mod_coursecertificate (credentialing is the operator's/stack's job, D24 — the plugin only
provides the home); generating the thumbnail regardless of the image opt-in (would force a
cover even when images are off).

**Amended (P19 follow-up).** The original P19 build put the intro in a *numbered*
"Introduction" section on the belief that "format_pathway hides section 0 by default."
That was wrong: format_pathway treats section 0 as the native "Overview" a learner lands
on first (`format.php` sets the section to 0 for non-editors), so the build produced TWO
"Introduction" sections visible to learners. Fixed by putting the intro into section 0
(named, with `pathwayshowsection0='1'` set explicitly so it's pinned in the sidebar on any
tenant) and no longer creating the extra section. `pathwayshowsection0` does not gate
whether section 0 renders — it only controls sidebar-pinned vs. shown-above-content — so
the intro renders for any tenant either way; setting it explicitly just guarantees the
pinned nav placement. Content sections are now 1…N (no front-shift); completion model and
criteria are unchanged (the section-0 intro and the wrap-up stay untracked).

**Revisit if.** Operators want the bookends configurable/optional, or a course-card image
even when section images are off (a separate "course image" opt-in distinct from section
images).

## D26 — Operator-controlled course depth: audience level + length/depth (P20)

**Decision.** Two named, independent create-time controls steer the generated course,
filling a vacuum (the prompt previously placed no constraint on length or pitch — ~5
sections was pure model habit):
- **Audience level** — beginner | intermediate | advanced.
- **Length/depth** — brief | standard | comprehensive.

They are stored as two `char(20)` columns on `coursegen_job` (`audiencelevel`, `depth`;
defaults intermediate/standard), seeded from two admin settings
(`default_audience_level`, `default_depth`) mirroring `default_mode`, and chosen per job
on the create form. They are **create-time only** (D26-b): changing them after generation
is out of scope for v1 — the operator edits sections at the review gate as today. The
chosen pair is surfaced read-only on the job page (D26-c). All tuning lives in one seam,
`local\course_depth` (ranges + prompt fragments + reading pitch).

Target table (D26-a, confirmed): Brief ~3-4 / Standard ~5-7 / Comprehensive ~8-12
sections (non-overlapping ranges, expressed as "approximately N-M", never a hard count);
Beginner=Remember/Understand, Intermediate=Apply/Analyze, Advanced=Analyze/Evaluate.

**Each axis acts where it actually bites.** The first cut wired both axes as guidance in
the blueprint prompt; a real-transport smoke proved that inert against the reasoning model
(gpt-4o) — and, more sharply, exposed that the smoke had been comparing two jobs whose
depth/level never persisted (the live DB lacked the columns until upgraded), so both ran
at the defaults. With the columns present the two axes land where they belong:
- **Length** is steered by a prompt range AND enforced best-effort: after the blueprint
  parses, if the section count misses the depth range, `blueprint_generator` re-prompts
  **once** (audited, counted against the spend cap, skipped if the cap is reached) citing
  the observed miss; a valid retry is used, else the original is kept. The job is never
  failed over the count — a thin source that can't support Comprehensive lands at the
  nearest feasible end.
- **Pitch** is a prose instruction threaded into the materializer's per-section reading
  generation (`course_depth::reading_pitch`) — vocabulary, assumed knowledge, how
  concepts are explained — which is a highly compliant instruction, not the objective-verb
  framing the model treated as boilerplate. Objective-level (Bloom) framing stays in the
  blueprint prompt but is no longer relied on as the pitch lever.

Smoke proof (same rich source, opposite ends, materialized): Brief+Beginner = 4 sections
with plain, term-defining prose; Comprehensive+Advanced = 8 sections with concise,
technical prose surfacing tradeoffs. Both axes visibly move.

**Scope.** The blueprint IR schema does NOT change — no new JSON fields, no
blueprint-validation or edit-form-schema changes. Section count stays a RANGE enforced by
re-prompt (not a stored count); pitch is a prose instruction. The only guard relaxed from
the original brief is "guidance-only": one bounded re-prompt plus the materializer
reading-pitch are in scope. `DEFAULT_QUESTION_COUNT` is untouched — the audience axis
influences question difficulty via Bloom's framing, not a new question-count knob.

**Rejected.** A 1-5 slider (a bare number conflates the two axes and is uninterpretable —
named selects instead); a hard section count (a range lets the model adapt to the
material); post-generation depth changes (v1 edits sections at the gate); enforcing pitch
by a second objective-rewrite pass (the reading-prose instruction is sufficient and
cheaper); relying on objective-verb framing as the pitch lever (proven inert).

**Revisit if.** Operators want to change depth/level after generation (would need a
re-generate-from-stored-params path), or the reading-pitch proves insufficient on a
future provider (would justify the objective-rewrite pass), or length enforcement should
escalate beyond one retry.

## D27 — Assessment placed last within its section: read, then assess (P20 follow-up)

**Decision.** Within a content section the reading comes first and the assessment activity
(knowledge check or graded quiz) is **last**. The materializer still *builds* the
assessment before the reading label — its completion outcome and any inline filter token
must be resolved first (D21/D23) — and then moves the reading ahead of it via
`core_courseformat\local\cmactions::move_before` (the Moodle 5.2 replacement for the
deprecated `moveto_module`). A stealth (inline) knowledge check is off the course page and
renders inline at the end of the reading regardless, so the reorder only changes what a
learner sees for a **visible** assessment — a non-stealth knowledge check (filter disabled)
or a graded quiz — which previously appeared at the *top* of the section.

**Why.** "Read, then assess" is the expected flow. The build-assessment-first order (kept
for the completion/token reasons above) was placing the visible assessment ahead of the
reading.

**Scope.** Reorder only — the completion model is unchanged (still exactly one tracked
activity per section; the reading label or the assessment carries it as before), and the
IR/blueprint/prompt are untouched. Each content section holds at most the reading label
plus one assessment, so moving the reading to the front is sufficient.

**Rejected.** Swapping the build order (creating the label first) — breaks the completion
decision (which depends on whether the assessment built) and the stealth inline token
(needs the knowledge check's UUID); both require building the assessment first.

**Revisit if.** A section ever holds more than one assessment, or quizgenpro's
per-second question-category idnumber collides when two assessments in one course bank
within the same wall-clock second (latent; real generation spaces calls out by AI latency,
but a fast/batch path could hit it).

## D28 — The job topic is a labeled steering directive, separate from source material

**Decision.** When a job has a topic, it is injected into the synthesis prompt as a
labeled **COURSE FOCUS** directive — ahead of and separate from SOURCE MATERIAL —
rather than riding into the corpus as an undifferentiated trailing source block (its
prior behaviour, traced from the `type='topic'` source through `gather_corpus`). The
topic is read out of its `type='topic'` source separately from the document sources and
injected at **final synthesis**, so it survives intact regardless of corpus size and is
**never folded into the map-reduced document corpus**. It is not duplicated in SOURCE
MATERIAL. No schema change — the source `type` already distinguishes it.

**Fidelity-safe wording.** The directive steers **scope, emphasis, and framing**, but
instructs that substantive content be **drawn from the source material, not invented
beyond it**: the topic decides *what the course is about*; the documents stay the
authority for *what it says*. This distinction is load-bearing for compliance/SOP courses.

**Placement (bracket, not adjacent).** COURSE FOCUS leads, ahead of SOURCE MATERIAL;
COURSE DESIGN TARGETS (depth/level, D26) stays **after** SOURCE MATERIAL for the recency
D26's real-transport smoke established. The two operator-intent directives *bracket* the
source rather than sitting literally adjacent — moving the depth targets back above the
source would risk regressing D26.

**Topic-only is preserved.** With no documents, COURSE FOCUS carries the build, SOURCE
MATERIAL renders "(No source documents were provided.)", and the model develops content
from the focus plus general knowledge; the opening line no longer presumes a corpus.

**Why.** The trace showed topic+documents steering was weak: the topic was one trailing
block competing with the whole corpus, and in the over-budget map-reduce path only a
*summary* of it survived. For the primary "documents + intent" workflow the topic should
be an actual lever.

**Evidence.** Real-transport smoke — the same water-cycle source produced *"Understanding
the Water Cycle"* (broad) with no topic, but *"Evaporation Fundamentals for Facilities
Staff"* (narrowed to evaporation, content drawn from the source) with the focus topic;
topic-only still built a full course.

**Rejected.** Leaving the topic in SOURCE MATERIAL (weak, buried, summary-only on large
sources); a schema column for the topic (the `type='topic'` source already distinguishes
it); moving COURSE DESIGN TARGETS above the source to sit adjacent (regresses D26 recency).

**Revisit if.** The model over-weights the focus and starts inventing beyond the source
(tighten the fidelity rule), or operators want multiple focus directives / a separate
"must cover" list.

## D29 — Review-gate section ordering: blank = end, normalize to 1..N, later-row wins ties

**Decision.** In `blueprint::from_form_data`, the per-section **Order** field now behaves
predictably: a blank, non-numeric, or non-positive value means **"add at the end"**; a
positive value **places** the section at that position. Sorting is an **explicit, total
comparator** — positioned rows (order >= 1) first, ascending by order; "end" rows after,
in form order — so it does **not** rely on `usort` stability. Positions are contiguous
**1..N** (sections persist positionally; the Order values are sort keys only, not stored;
`edit.php` re-prefills `1..N` on reopen, so a reopened blueprint shows no `0`s, gaps, or
duplicate-order ambiguity).

**Why.** A blank Order cleaned to `0` (empty `PARAM_INT`), sorting newly added sections to
the **top** — the reported bug — and the `?? ($i+1)` default was dead for present-but-empty
fields. Collisions previously resolved by incidental `usort` stability.

**Collision rule (explicit, documented).** On a tie for a position, the **later form row
wins the slot** and the earlier one shifts down. New sections are appended at the highest
form indices, so "add a section and type position N" places it at N and pushes the
existing section there to N+1 — *without needing to distinguish new-vs-existing rows*,
which the form data can't do cleanly (sections have no stable per-section id). For the
rarer "edit an existing section's number into a collision" case, the lower-listed section
yields — one consistent rule.

**Scope.** Edit handler / `from_form_data` only — no IR/schema change (sections already
persist positionally). A help string on the Order field documents the behaviour.

**Rejected.** Detecting new-vs-existing rows via form index vs. original section count
(fragile — couples the converter to the prefill convention); storing an explicit order
field (unnecessary — positional); relying on `usort` stability for ties (incidental, not
guaranteed semantics).

**Revisit if.** Drag-and-drop reordering replaces the numeric field, or operators want a
different collision rule.

## D30 — Steer generated images to clean, text-free illustrations

**Decision.** Section images are steered toward clean, text-free illustrations rather than
labeled infographics, fixing reports of garbled (mis-rendered) text and truncated
(content clipped) images. Two prompt-layer levers, no parameter or display change:

- **Image-prompt wrap (materializer).** The per-section image prompt no longer sends the
  hint bare. It wraps it: *"A clean, professional illustration of {hint}. Illustrative or
  photographic style, depicting the subject. No text, no words, no letters, no labels, no
  captions, and no charts, diagrams, or infographics."* (The course thumbnail path already
  wrapped its prompt; the section path was the bare one.)
- **Hint contract (blueprint synthesis AND section regeneration).** The image field's
  guidance changed from `"diagram idea or empty"` to *"an illustrative subject, not a chart
  or infographic, or empty"*. The word "diagram" — which actively pulled the reasoning
  model toward labeled-infographic hints like "PPE categories overview diagram" — is
  removed from both prompts.

**Why.** The image model garbles rendered text and overflows dense multi-column layouts.
All three levers pointed at infographics: the contract literally said "diagram", the
materializer sent that hint bare with no counter-framing, and there was nothing asking for
a text-free illustration. Display was already innocent — `img-fluid` (no crop) and a
`square` request — so the truncation was the model overflowing its own canvas, i.e. a
prompt problem, not a sizing one.

**`style` left as-is ('vivid').** Verified that the configured OpenAI image provider's
`create_request_object` never reads the action's `style` parameter (grep-confirmed across
`ai/provider/openai`), so `'vivid' -> 'natural'` would be a no-op for this deployment.
Changing it would imply an effect that doesn't exist; the prompt is the only effective
lever, so all steering lives there. `aspectratio: 'square'` and the `img-fluid` display
are unchanged (your trace cleared both).

**Evidence.** Real-transport: the worst-case hint "PPE categories comparison" — which
previously produced a garbled, truncated infographic — returned a clean, text-free
photographic illustration (1024x1024, in-frame, no labels) on gpt-image-1.5.

**Rejected.** Flipping `style` to `'natural'` (inert for the active provider — theater);
changing the aspect ratio or adding a display crop (not the cause); post-processing to
strip text (the model shouldn't be asked to render text at all).

**Revisit if.** A provider that *does* honor `style` becomes the image tier (then revisit
'natural'), or operators legitimately need labeled diagrams (would be a separate,
diagram-capable path, not the illustration path).

## D31 — Job lifecycle: archive (soft-delete), opt-in course delete, orphan flag

**Decision.** The hub gets a job lifecycle. "Deleting" a job from the operator's point of
view is a reversible **archive** (soft-delete), and deleting the generated *course* is a
separate, explicit, off-by-default option.

- **Archive = soft-delete.** A nullable `coursegen_job.timearchived` (null = active); a
  separate column, NOT an overloaded `status` (which would destroy the real pipeline
  state). The hub shows active jobs by default (`timearchived IS NULL`) with a
  Show/Hide-archived toggle and an inline Restore. Archiving never touches the course and
  preserves the `coursegen_log` spend/provenance. Reversible via unarchive.
- **Opt-in course delete.** The archive confirm offers an OFF-by-default "also delete the
  generated course". When chosen it re-checks `moodle/course:delete` on the target course
  (defence in depth beyond `:manage`) and applies the D20 learner-state check as a
  **WARN-not-block**: if the course has enrolments or completion, the manager sees a clear
  warning and must tick an explicit override — the requested delete is never silently
  dropped, but it is the manager's call (not a hard block). Deletion routes through the
  shared teardown.
- **Shared teardown mechanism.** `materializer::teardown_generated_course(int $courseid)`
  — `delete_course()` only. The quizgenpro question categories banked for a course live in
  a qbank MODULE context inside the course, so they (and their entries/versions/questions)
  cascade with the course context; there is NO separate category to sweep, and an idnumber
  (`quizgen-*`) sweep would risk other courses' categories. Verified by a test that asserts
  no `quizgen-*` category or entries survive teardown (verified cascade, not blind trust).
  Mechanism only — no capability, confirm, or learner gate inside it, so all three callers
  (rebuild cleanup, operator delete, privacy erase) share it.
- **Orphan flag, don't archive.** A nullable `timecoursedeleted`. A `course_deleted` event
  observer (new `db/events.php`) flags EVERY job whose `courseid` matches the deleted
  course (a courseid can match several across rebuilds): sets `timecoursedeleted`, nulls
  `courseid`. The job stays visible and un-archived — the operator should still see it and
  its cost. The hub row and job page render the "course deleted" state explicitly instead
  of a bare "complete". Because the observer fires for the internal rebuild cleanup too, a
  successful re-materialize clears `timecoursedeleted` as its deliberate LAST step (after
  the cleanup's event fired and after courseid was re-set), so a rebuild is never
  mis-flagged.
- **Privacy leak fixed via the same teardown.** The GDPR erase path
  (`delete_jobs_and_children`) previously removed the coursegen_* rows but left the
  generated course (and its quizgenpro categories) behind. It now routes each job's course
  through the teardown — a MANDATORY, un-gated hard delete: unlike the operator path it
  does NOT apply the learner-state warning, because erasure cannot be gated on learner
  state.
- **New capability `local/coursegen:manage`** — CONTEXT_COURSECAT, RISK_DATALOSS,
  manager-only (NOT editingteacher, since it can trigger course deletion). Tenancy is the
  established pattern: actions re-derive the context from `$job->contextid` and re-check
  `:manage`, so an operator only ever acts on jobs in their own category context.

**Why.** Operators had no way to remove a job (we reset demo2 by hand-rolled SQL). Archive
gives a safe, reversible default that preserves cost/provenance; course deletion is the
rare, explicit, gated action; and a deleted course should leave a legible record, not a
broken "complete".

**Rejected.** Overloading `status='archived'` (loses pipeline state, breaks
classify_status and the status strings); hard-deleting jobs as the default verb (hard
purge is a separate later action; the privacy path already hard-deletes for GDPR);
hard-blocking course deletion on learner state (manager's call — warn + override); an
idnumber sweep for quizgenpro categories (risks other courses); flagging-by-archiving an
orphaned job (hides the record the operator needs).

**Revisit if.** A hard-purge-from-archive operator action is wanted (the mechanism — the
teardown + child-row delete — already exists in the privacy path and can be surfaced), or
bulk archive/restore from the hub is needed.

## D32 — Reconcile declared dependency floors to reality (pre-pilot)

**Decision.** The plugin's declared dependency versions in version.php are reconciled to
honest floors — each is either a **real minimum** (the earliest version with the API
surface coursegen actually calls) or a **verified floor** (the demo2-exercised version,
where a true historical minimum could not be established) — mirroring the core `requires`
honesty of D19. No functional code change; metadata + docs.

| Dependency | Was | Now | Basis |
|---|---|---|---|
| format_pathway | 2025021586 | **2026052000** | Real minimum — `pathwayshowsection0` (D25) is present in 1.0.1 (2026052000), the earliest exercised release; verified end-to-end on 1.0.2 |
| local_quizgenpro | 2026012301 | **2026051300** | Verified floor — the historical minimum for the 3-arg `exporter::export_to_question_bank` / `generator` surface is not establishable; pinned to the tested v3.1.0 |
| mod_knowledgecheck | 2026051800 | 2026051800 | Verified floor — already matched the tested 1.0.2 (`questions::add`, the `uuid` field, the `{knowledgecheck id=<uuid>}` token all present) |
| filter_knowledgecheck | 2026051800 | 2026051800 | Verified floor — already matched the tested 1.0.0 (renders the inline token) |
| Moodle core (`requires`) | 2026042000 | 2026042000 | Verified floor (D19), unchanged |

**Why.** A wrong floor is install-time poison: too high blocks install on a real tenant;
too low (or fictional) lets it install against a dependency missing an API we call — the
silent, worse failure. The prior `format_pathway => 2025021586` was a **stale fictional
number**: it sits in the 2025-02 era, but format_pathway's real releases are 2026052000
(1.0.1) and 2026060501 (1.0.2), a year later — no such release ever existed. The prior
`local_quizgenpro => 2026012301` was an **unverified low guess** below the tested
2026051300, with no confirmation the 3-arg exporter existed that early. Neither blocked
install on demo2 (both were below what is installed), but both were dishonest floors.

**Verified-floor caveat.** "Verified floor" means *tested*, not *proven minimal* — the
dependency may well work at an earlier version, but we pin to what has actually been
exercised rather than invent a lower number. This is the same discipline as D19 and the
same class of bug as the early CONTEXT.md version error.

**Rejected.** Pinning format_pathway to the current 2026060501 (1.0.1 demonstrably has the
API, so 2026052000 is the honest minimum); leaving the stale 2025021586/2026012301 numbers
(dishonest — could permit an API-missing install); inventing lower numbers for the
verified-floor cases (the thing this decision exists to stop).

**Revisit if.** A dependency's API surface coursegen relies on changes (re-establish the
minimum), or an earlier dependency version is deliberately tested (lower the floor to that
proven point).

## D33 — Regenerate one section's image in place, reusing the stored hint

**Decision.** A built course gets a per-section **"Regenerate image"** action on the job
page that reruns ONLY image generation against the section's existing stored hint —
leaving the reading prose and the assessment untouched. It fixes the expensive-only
recovery for a bad image (previously: regenerate the whole section, a fresh text call that
discards good reading to fix a picture). The hint is REUSED; no hint-editing in v1.

**The path (forced by a verified fact).** The generated reading prose is NOT recoverable
anywhere except inside the materialized label: `draft_reading`'s output goes only into the
section's `mod_label` intro HTML (beside the image and any `{knowledgecheck}` token), and
the blueprint stores only the *inputs* (summary, objectives), not the prose. So a "rebuild
the label from its parts" approach (Path B) is impossible — the parts aren't separately
stored. The honest path is in-place replacement (Path A).

**Zero-surgery in-place swap.** The image is a file in the reading label's `mod_label/intro`
filearea, referenced by `@@PLUGINFILE@@/<filename>` in `label.intro`. Regeneration writes
the new image under the SAME filename, so the label HTML is **never touched** and the
reading prose + the `{knowledgecheck}` token survive **byte-for-byte by construction** —
not by careful regex. This is strictly safer than editing the `<img>` tag.

- **Mechanism (`image_regenerator`, mirrors `section_regenerator`):** resolve job → course;
  blueprint section i → course section i+1 (intro at section 0, D25) → the reading label →
  its single intro image file; regenerate via the shared `materializer::section_image_prompt`
  wrapper (D30, extracted so the wording can't drift); replace the file in place; log one
  image against the sub-cap.
- **Trigger:** a POST `action=regenimage` on the job page (`view.php`, `PHASE_COMPLETE`
  only — images exist only after materialize), sesskey + the same capability as section
  regenerate (`:generate` | `:reviewgate`). A per-section button appears only where an
  image file is actually present.
- **Guardrails:** the reading and the token are never touched (structural); the assessment,
  completion, and section structure are untouched; image sub-cap respected (refuse cleanly
  if exhausted); on generation failure the existing image and label are left exactly as-is.

**Scope (v1).** Targets the common case — replacing an existing image. A section flagged
for an image whose generation FAILED at materialize (no file) would need inserting an
`<img>` into `label.intro` (HTML surgery); deferred — that section simply offers no button.
Alt text stays as originally generated (the hint is reused, so it still fits); the
pluginfile URL is unchanged, so a hard refresh may be needed to see the new image.

**Rejected.** Whole-section regenerate as the only recovery (expensive, discards good
reading); rebuilding the label from recovered parts (Path B — impossible, prose isn't
stored separately); editing the `<img>` tag in `label.intro` (riskier than reusing the
filename); hint-editing in v1 (out of scope — reuse the stored hint).

**Revisit if.** Operators want to edit the hint before regenerating, or to add an image to
a section that has none (the deferred flagged-but-failed / opt-in-after-the-fact case).

## D34 — Regenerate-image cache-bust: new filename + single-reference edit (D33 follow-up)

**Decision.** The D33 regenerate-image swap is changed from "overwrite the file under the
SAME filename" to "write a NEW unique filename and repoint the one image reference in
`label.intro`". The original same-filename overwrite worked server-side but left the
rendered `pluginfile` URL identical — and `mod_label` intro images are served with a
6-hour browser `max-age` and no revisionable URL component — so the browser kept serving
the cached old image for hours (the pilot symptom). A new filename means a new URL, so the
browser fetches the new image on a normal reload. No server-side revision lever exists, so
this is the only real fix (investigation confirmed).

**Means vs. end (the reframe).** D33's guarantee was "the reading prose and the
`{knowledgecheck}` token survive intact." The byte-identical-`label.intro` property was the
*means*, not the *end*. This swaps the means — a precise single-substring edit instead of an
in-place file overwrite — while keeping the end: the ONLY change to `label.intro` is the one
old→new filename substring inside the single image reference; the reading prose and the
token are byte-for-byte unchanged. The test asserts exactly that
(`str_replace(new, old, after) === before`).

**Mechanism (`image_regenerator`).** On a successful generation: read `label.intro`; build
the exact anchor `@@PLUGINFILE@@/<oldname>`; pick a fresh unique filename (same extension);
`str_replace(..., $count)` and **require `$count === 1`** — if the reference isn't found
exactly once, **abort** (log failure, return false, change nothing). Then create the new
file, repoint `label.intro` (+ bump `label.timemodified`), delete the old file, and
`rebuild_course_cache(..., true)`. Order is deliberate: the new file exists before the label
points to it and the old file is deleted only after, so `label.intro` always references a
file that exists — a mid-step fault leaves the old image showing, never corruption.

**Preserved.** Failure semantics (generation failure OR anchor-not-exactly-once → image +
label untouched, failure logged); capability gating; image sub-cap accounting/logging; alt
text (same `<img>`, only the `src` filename changes); the no-image no-op; the
cap-exhausted refuse.

**Rejected.** A server-side URL-revision bump (no such lever exists for mod_label intro —
the URL is `/pluginfile.php/<ctx>/mod_label/intro/<filename>` with no rev, 6h max-age); the
hard-refresh hint (the 6h cache makes a normal reload show the stale image — unreliable);
keeping the same filename (the direct cause of the symptom).

**Revisit if.** Moodle gains a revisionable pluginfile URL for module intro files (a
cleaner bump than a filename change), or the regenerate moves to a background task (a
separate concern — the synchronous inline call is still a slow-request UX issue, deferred).

## D35 — Two better entry points to the course builder (additive navigation)

**Decision.** Add two doorways into the builder without changing the category-context model
underneath (every build still happens in a category). Both are *additional doors into
already-gated rooms* — neither bypasses the existing per-category access checks; the pages
they link to (`index.php`, `view.php`) re-resolve their context and call `require_access`.

**Surface 2 — a "Course builder" item in a course's More (secondary) navigation.**
`local_coursegen_extend_navigation_course` (the **legacy** `extend_navigation_course`
callback — still dispatched in 5.2 at `settings_navigation.php:602`, and consistent with
the plugin's existing `extend_navigation_category_settings`). The item shows ONLY when both
(a) a job exists for this `courseid`, and (b) the user can build in the **job's category
context**. Critical: the builder capability is `CONTEXT_COURSECAT`, so it is re-checked
against `job->contextid`, NOT the `context_course` the hook hands us — a teacher with
course-edit rights but no builder access in the category must not see it. The item deep-links
to the generating job: `view.php?jobid=<latest job for this course>`.
- **Latest-by-id determinism:** `coursegen_job.courseid` is a non-unique FK (rebuilds can
  produce several jobs; the `course_deleted` observer already loops), so the link resolves
  to the most recent job by id (`job_manager::latest_job_for_course`). In normal flow it's
  1:1; this just makes the multi-job case well-defined.
- **Lifecycle:** an archived job still shows the item (its record is *more* relevant when
  archived; `view.php` renders the archived state). A deleted course nulls `courseid` (the
  observer) and has no course page, so it never matches — hidden by construction.

**Surface 1 — a Site administration > Courses link → a category-picker landing page.**
A new `landing.php` (the hub's `index.php` requires a `contextid` and can't serve this).
It lists the categories the operator can build in via `job_manager::buildable_categories`
(the SAME per-category `can_access` the hub uses — one definition of "can build here"),
each linking to `index.php?contextid=…`; an operator with builder rights in zero categories
sees a clear empty state, not an error. Registered as an `admin_externalpage` under the
existing `courses` admin category.
- **Coarse-cap-plus-landing-gate split:** `admin_externalpage::check_access()` checks a
  static capability in a fixed (system) context, so it CANNOT gate on the per-category
  builder cap. The menu item is gated coarsely on **`moodle/course:create`** (system) — the
  standard "creates courses" capability, broad enough that plausible builders see the door,
  inventing no new cap. The **landing page is the real gate** (per-category `can_access`).
  - **Known trade-off:** a *category-only* course creator (who has `moodle/course:create`
    only inside a category, not at system) won't see this top-level admin link — but they
    still have the per-course More item (Surface 2) and the category-settings entry point.
    The coarse cap intentionally errs toward the admin audience for an admin-area menu.

**The invariant (both surfaces).** Entry points may multiply; access control does not move.
Every door lands on a page that gates itself in the correct context — the category for the
hub/landing, the job's category for the More item — never the course context for the
builder cap, never a system check standing in for the per-category gate.

**Rejected.** The newer `\core\hook\navigation\secondary_extend` for Surface 2 (works, but
inconsistent with the existing category callback); checking the course context for the More
item's capability (the wrong-context bug the design exists to avoid); gating Surface 1 on
`moodle/site:config` (full admins only — excludes managers who build) or a new system cap
(over-engineered for a coarse pre-filter when the landing page is the true gate); a custom
`admin_externalpage` subclass looping all categories in `check_access` (cost, and it
duplicates the landing page's filtering).

**Revisit if.** Moodle deprecates the legacy `extend_navigation_course` callback (move to
`secondary_extend`), or operators want the top-level link visible to category-only creators
(would need a per-context-aware admin item or a different surface).

## D36 — Optional AI intro header banner on format_pathway's section-0 image

**Decision.** An opt-in (default off) feature: at materialize time, generate one wide title
banner for the course and set it as **format_pathway's section-0 (Introduction) header
image** — distinct from the per-section reading-label images (D30, mod_label intro). The
banner is regenerable, since AI title-text rendering is hit-or-miss.

**The seam (investigated as the gate).** format_pathway stores a section header image in a
**conventional, backup/restore-participating filearea**: course context, component
`format_pathway`, filearea `sectionimage`, itemid = the section's DB id. We write it
directly (like our mod_label image writes) — there is no public format-API setter (the only
writer is the section-edit form's draft-driven path). Display gates on the course-level
`pathwayshowimages` option (default `'1'`) AND file presence; it does not consult the
per-section flag. **Coupling: medium** — a sibling plugin's filearea written by name, but
the convention is locked by their own backup/restore, so it's stable enough; the coupling
is centralized in two materializer statics (`write_section0_banner`, `section0_has_banner`)
so it's the only place to fix if format_pathway ever changes.

**Aspect ratio — 3:2, cropped into the header (no 4:1).** The provider's `calculate_size`
accepts only `square` / `landscape` (~3:2, 1536×1024 on gpt-image) / `portrait` and throws
otherwise. So the banner is requested in **`landscape`** and format_pathway crops it into
its wide header band; a true 4:1 isn't achievable from the provider. The image client gained
an optional `aspectratio` parameter (default `square`, clamped to the three valid values),
leaving every existing caller unchanged.

**Separate text-wanting prompt.** `materializer::banner_prompt($title)` is the OPPOSITE of
the D30 `section_image_prompt` ("no text"): it WANTS the course title rendered as clean
legible text. Title from `$blueprint->get_title()`. Not a tweak to the section wrapper.

**Opt-in, cap, explicit option, best-effort.** A `coursegen_job.headerbanner` column (course
-level, mirroring audiencelevel/depth), set by a create-form checkbox (default off). Counts
one image against the existing sub-cap and is logged like the thumbnail. `pathwayshowimages`
is set on explicitly (the D25 lesson — a tenant default of `'0'` must not silently hide it).
**Best-effort:** a generation, cross-plugin file-write, or format-option failure is logged
and the build continues — the course never fails to build for the banner.

**Regenerate (simpler than D33/D34).** The banner is a standalone file in the filearea with
no label.intro and no embedded reference — so regeneration has NO HTML edit, no anchor, no
single-occurrence guard. `banner_regenerator` just generates, writes under a fresh filename
(a new pluginfile URL busts the browser cache, the D34 lesson), and rebuilds the course
cache. On failure the existing banner is left intact.

**Rejected.** A 4:1 request (the provider can't); reusing `section_image_prompt` (it forbids
text — the opposite of a title banner); a public format setter (none exists — direct
filearea write); failing the build on a banner error (best-effort, like the thumbnail);
D34's HTML-surgery machinery for regenerate (no HTML here — overkill).

**Footgun fixed during build.** The upgrade step for the `headerbanner` column was first
guarded `< 2026062400`, but the prior phase D35 already used 2026062400, so the add never
fired on an existing site (PHPUnit passed because it installs fresh from install.xml; the
live demo2 DB silently lacked the column and insert_record dropped the value). Fixed by
guarding `< 2026062402` (clearing both the D35 collision and the dead 2026062401 version)
with `field_exists` keeping it idempotent. A reminder: a new upgrade savepoint must exceed
every prior phase's version, and a real-instance smoke catches what fresh-install PHPUnit
cannot.

**Revisit if.** A provider offers a wider aspect ratio (revisit the crop), or format_pathway
exposes a public section-image setter (use it instead of the direct filearea write).
