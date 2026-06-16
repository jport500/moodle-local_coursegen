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

**Revisit if.** The wrap should also allocate learners (add an allocation source at
wrap time); or recertification is wanted (set `programid2`/`recertify` on the
certification); or the wrap should move out of materialize into an explicit,
separately-triggered finalize action.
