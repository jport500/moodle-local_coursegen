# local_coursegen — Design Spec (as-built, v0.16.2)

AI course-builder for LMS Light. Generates real Moodle courses from
uploaded materials or a topic prompt, composing the existing plugin stack.
This spec has been reconciled to the shipped plugin (through P20); the
decisions and their rationale — including the changes that superseded the
original v1.0 design — live in `docs/DECISIONS.md`, and the per-phase history
is in `CHANGES.md`. Read
`github.com/jport500/lms-light-docs/blob/main/CONTEXT.md` first.

Status: **as-built.** Verified against Moodle 5.2 (the exercised floor,
`requires = 2026042000`; see DECISIONS D19). Where this spec and DECISIONS
disagree, DECISIONS is authoritative.

---

## 1. Purpose and scope

Let an instructor produce a structured, ICP-appropriate Moodle course from
their own source material (or a topic) in minutes, reviewing or skipping a
human checkpoint, with the output landing as a native, hidden/draft course
that composes with format_pathway, quizgenpro, and the gradebook.

### In scope (built)
- Source ingestion: PDF, DOCX, PPTX, pasted text/markdown, topic-only prompt
- Blueprint generation (editable, persisted) → review gate → materialization
- Output content: learning objectives, structured reading as inline "Text and
  media" areas (D12), AI-generated images (diagrams/illustrations) with alt text
- Operator-controlled course depth: audience level (beginner / intermediate /
  advanced) and length/depth (brief / standard / comprehensive) at create time
  (D26)
- Course structure: intro + wrap-up bookends and a generated course thumbnail
  (D25)
- Assessments via local_quizgenpro's public API — inline formative knowledge
  checks (mod_knowledgecheck) and summative graded quizzes (mod_quiz) (D15, D23)
- Two generation modes (outline-first / automatic), draft-by-default
- Per-tenant provider mapping (capability tiers) via AI Providers
- Per-tenant cost estimate, quota/cap, and audit log
- Course-completion criteria wired from the tracked activities (D22)

### Out of scope — fast-follows
- Flashcards (deferred — D12); audio/podcast (TTS), video, comics, music,
  games, real-time AI tutor
- Credentialing: wrapping the course in tool_muprog / tool_mucertify — removed
  as out of scope for a course builder (D24)
- Vector RAG / per-tenant embeddings store
- Audio/video transcription, URL scraping as sources
- Distribution via enrol_lti (parked; see DECISIONS D9)
- Usage-based billing (audit log is the substrate)

---

## 2. Architecture

A pipeline of discrete, resumable stages, each a unit of work suitable for
Moodle's adhoc task framework:

```
ingest → extract → blueprint → [REVIEW GATE] → materialize → assess → finalize(draft)
```

1. **Ingest** — accept uploads (Moodle File API) or topic text. Store as a
   generation job tied to a course-to-be.
2. **Extract** — async: pull text + structure from each source into a
   normalized "source corpus" (text, headings, order). Topic-only jobs skip
   this.
3. **Blueprint** — `reasoning` tier: produce the editable plan (see §3).
   Map-reduce over the corpus when it exceeds context.
4. **Review gate** — outline-first: instructor edits/approves the blueprint.
   Automatic: skip. Cost estimate shown here either way.
5. **Materialize** — create the hidden course in format_pathway; put the intro
   overview in section 0 and a wrap-up bookend last (D25); for each content
   section, `drafting` tier generates reading content (pitched to the audience
   level, D26) and `image` capability generates flagged visuals; assessments are
   placed after the reading (D27). A course thumbnail is generated when images
   are opted in.
6. **Assess** — via local_quizgenpro's API, generate questions for the section's
   assessment: an inline knowledge check (D15) or a graded quiz (D23).
7. **Finalize** — configure course-completion criteria from the tracked
   activities (D22); leave the course hidden/draft for instructor publish.

All AI calls route through the AI Providers subsystem (§5). Long-running
stages run as adhoc tasks with progress surfaced to the instructor; no
generation blocks a web request.

---

## 3. Data model

Plugin-owned tables (names indicative):

- **`coursegen_job`** — one per generation run: requesting user, target
  course id (once created), mode, audience level + length/depth (D26), status
  (`extracting`/`blueprinted`/`awaiting_review`/`materializing`/`assessing`/
  `complete`/`failed`), timestamps, estimated and actual spend.
- **`coursegen_source`** — uploaded source references (File API item ids)
  and extracted-corpus metadata per job.
- **`coursegen_blueprint`** — the IR: serialized plan for a job (course
  title, description, ordered sections; per section: title, objectives,
  content type, summary, image flag + prompt hint, assessment spec).
  Editable in outline-first mode; versioned on edit.
- **`coursegen_log`** — per-stage / per-call audit: job id, stage,
  capability tier, provider/model used *(verify how AI Providers exposes
  the resolved provider)*, token/image counts, estimated cost, outcome.

Config stored via Moodle's standard plugin config (`set_config`) for
automatic per-tenant isolation: capability-tier → provider mappings (or
deferral to AI Providers defaults), generation caps and thresholds,
default mode + lock, image opt-in default, and default audience level +
length/depth (D26).

---

## 4. Source ingestion and chunking

- **Accepted types:** PDF, DOCX, PPTX, text/markdown, topic prompt.
- **Extraction:** server-side PHP libraries in an adhoc task. *(verify what
  Moodle core already bundles — e.g. for PPTX/DOCX/PDF — to avoid duplicate
  vendored deps; prefer reuse.)* Candidate libs if not bundled:
  `smalot/pdfparser`, `phpoffice/phpword`, `phpoffice/phppresentation`.
- **Normalization:** produce ordered text blocks with heading/structure
  metadata → the source corpus.
- **Chunking:** split on document structure; fall back to a token-based
  sliding window with overlap where structure is absent.
- **Large corpora:** map-reduce summarization to build the blueprint;
  section-relevant chunks selected (heading match + lexical relevance) for
  per-section content generation. No vector store in v1.
- **Limits:** per-job source size/token cap (per-tenant configurable) for
  cost and quality; surfaced clearly to the instructor.

---

## 5. AI integration

- All calls go through Moodle's AI Providers subsystem (`core_ai`); the
  resolved provider/model is read back from the subsystem and logged (§10.2).
- **Capability tiers** declared by the plugin, mapped to providers per
  tenant:
  - `reasoning` — blueprint generation (low volume, high leverage). The
    operator's audience/length targets are woven into this prompt (D26).
  - `drafting` — bulk per-section reading content (higher volume,
    cost-sensitive). The audience level pitches this prose (D26).
  - `image` — diagram/illustration generation.
- **Defaults:** ship sensible tier→provider defaults; admins override per
  tenant for cost or data residency. The plugin never hardcodes a vendor.
- **Quizzes:** delegated to `local_quizgenpro`'s public API; quizgenpro uses
  its own provider config. No quiz logic duplicated here.
- **Images:** generated via the image capability, stored through the File
  API (per-tenant moodledata partitioning), embedded into the section's inline
  label HTML; alt text generated alongside each image for accessibility.

---

## 6. Generation modes and the review gate

- **Outline-first:** pipeline pauses at `awaiting_review`. Instructor edits
  the blueprint (reorder/rename sections, change content types, toggle
  images, adjust objectives, edit assessment spec), sees the cost estimate,
  approves. Per-section regeneration available.
- **Automatic:** proceeds through the gate without pause; cost estimate
  still recorded; respects caps.
- **Draft-by-default:** the materialized course is always created hidden /
  not visible to learners, in both modes. Publishing is an explicit
  instructor action.
- Mode is a per-tenant default with per-run override; admins can lock it.
- **Course depth (D26):** two create-time controls — audience level and
  length/depth — set on the create form (pre-filled from the per-tenant
  defaults). They shape the initial blueprint and any per-section regeneration;
  changing them after generation is out of scope for v1.

---

## 7. Cost, quota, and audit

- **Estimate** computed from the blueprint (section count × content size +
  flagged images) and shown at the gate; large jobs require explicit
  confirmation.
- **Quota:** per-tenant period cap with a soft-warning threshold; hard stop
  at cap; admins can raise.
- **Image sub-cap:** separate, lower cap given higher unit cost; image
  generation is opt-in per section.
- **Audit:** every stage/call logged (§3 `coursegen_log`). Credential
  values are never logged; see CONTEXT.md credential-handling rule.

---

## 8. Capabilities, roles, multi-tenancy, privacy

- **Capabilities:** `local/coursegen:generate` (instructor/manager),
  `local/coursegen:configure` (manager/admin), `local/coursegen:reviewgate`
  (approve materialization). Map to standard archetypes.
- **Multi-tenancy:** config in standard mechanisms (per-tenant DB → per-
  tenant config); File API for all storage (per-tenant moodledata); no
  cross-tenant references; tenant-context aware where it touches
  tool_mutenancy. *(verify any tenant-scoping needed for adhoc tasks.)*
- **Privacy provider:** required — the plugin stores who generated what,
  source references, and the audit log. Implement a full GDPR privacy
  provider (export + delete) from v1.

---

## 9. Composition with the stack

- **format_pathway** — default course format for generated courses (D10); the
  intro overview lives in its native section 0 (D25, amended). The
  self-contained linear layout also embeds cleanly if D9 is ever pursued.
- **local_quizgenpro** — question generation for both assessment types (§5).
- **mod_knowledgecheck / filter_knowledgecheck** — inline formative checks
  (D15); **mod_quiz** — summative graded quizzes (D23). **mod_label** — the
  inline reading "Text and media" areas (D12).
- **Credentialing (tool_muprog / tool_mucertify)** — out of scope; the wrap was
  removed in P18 (D24). The plugin builds a completable course and a wrap-up
  section as a home for an operator-added certificate, but creates no
  certificate and takes no dependency on those tools.

---

## 10. Verification (resolved during implementation)

The original spec-verification checklist has been resolved against the
Moodle 5.2 codebase over P1–P20. Notable outcomes recorded in DECISIONS:

1. Course/section/module creation APIs verified per phase (e.g. the
   `add_moduleinfo` field matrix, `course_create_section`, and — D27 — the 5.2
   `core_courseformat\local\cmactions` replacing the deprecated `moveto_module`).
2. `core_ai` actions and the resolved-provider/model readback are used for the
   §10.2 audit log; the image action is exposed and used for visuals/thumbnail.
3. `local_quizgenpro`'s public API drives both knowledge checks and quizzes.
4. Source extraction uses vendored libraries (`smalot/pdfparser`, PHPOffice) in
   `vendor/`, excluded from the phpcs gate.
5. format_pathway specifics verified, including the section-0 "Overview"
   behaviour and the `pathwayshowsection0` option (D25, amended).
6. Adhoc-task progress is surfaced on the job page (self-refreshing).
7. (For D9, later) enrol_lti LTI 1.3 maturity — still parked.

---

## 11. Phasing (as-built)

Each phase landed in its own commit with phase-boundary quality gates
(phpcs moodle-clean, PHPUnit green, real-transport verification where
applicable), per the LMS Light working process. The original P0–P7 proposal
was delivered and then extended well beyond it (through P20). `CHANGES.md` is
the authoritative per-phase history; in brief:

- **P0–P7** — skeleton, ingestion/extraction, blueprint generation, review
  gate + UI, materialization, assessments, finalize/governance, hardening.
- **P13–P16** — wayfinding polish; assessment-model coherence; the
  completion→certificate wiring and cert-wrap allocation source.
- **P17** — real graded quiz (summative, pass-to-complete; D23).
- **P18** — removed the cert/program wrap; credentialing is out of scope (D24).
- **P19** — course-structure enrichment: intro + wrap-up bookends and a course
  thumbnail (D25; the intro was later corrected to live in section 0).
- **P20** — operator-controlled course depth (audience level + length/depth;
  D26), plus the read-then-assess section ordering (D27).
