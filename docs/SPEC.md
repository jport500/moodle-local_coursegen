# local_coursegen — Design Spec (v1.0, for review)

AI course-builder for LMS Light. Generates real Moodle courses from
uploaded materials or a topic prompt, composing the existing plugin stack.
This is a point-in-time design document; decisions and their rationale live
in `docs/DECISIONS.md`. Read
`github.com/jport500/lms-light-docs/blob/main/CONTEXT.md` first.

Status: **draft for review.** Items marked *(verify)* must be checked
against the actual Moodle 5.1 codebase before implementation, per the
spec-verification rule.

---

## 1. Purpose and scope

Let an instructor produce a structured, ICP-appropriate Moodle course from
their own source material (or a topic) in minutes, reviewing or skipping a
human checkpoint, with the output landing as a native, hidden/draft course
that composes with format_pathway, quizgenpro, and the gradebook.

### In scope (v1)
- Source ingestion: PDF, DOCX, PPTX, pasted text/markdown, topic-only prompt
- Blueprint generation (editable, persisted) → review gate → materialization
- Output content: learning objectives, structured reading (page/book),
  flashcards, AI-generated images (diagrams/illustrations) with alt text
- Assessments via local_quizgenpro's public API
- Two generation modes (outline-first / automatic), draft-by-default
- Per-tenant provider mapping (capability tiers) via AI Providers
- Per-tenant cost estimate, quota/cap, and audit log
- Optional toggle: wrap result in tool_muprog / tool_mucertify

### Out of scope (v1) — fast-follows
- Audio/podcast (TTS), video, comics, music, games, real-time AI tutor
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
5. **Materialize** — create the hidden course in format_pathway; for each
   section, `drafting` tier generates reading content; `image` capability
   generates flagged visuals; flashcards generated.
6. **Assess** — call local_quizgenpro's API to generate quizzes/questions
   per the blueprint's assessment spec.
7. **Finalize** — assemble completion settings; optionally wrap in
   muprog/mucertify; leave the course hidden/draft for instructor publish.

All AI calls route through the AI Providers subsystem (§5). Long-running
stages run as adhoc tasks with progress surfaced to the instructor; no
generation blocks a web request.

---

## 3. Data model

Plugin-owned tables (names indicative):

- **`coursegen_job`** — one per generation run: requesting user, target
  course id (once created), mode, status (`extracting`/`blueprinted`/
  `awaiting_review`/`materializing`/`assessing`/`complete`/`failed`),
  timestamps, estimated and actual spend.
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
default mode + lock, image opt-in defaults, muprog/mucertify toggle.

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

- All calls go through Moodle's AI Providers subsystem. *(verify the exact
  `core_ai` action names and the availability of an image-generation action
  in 5.1.)*
- **Capability tiers** declared by the plugin, mapped to providers per
  tenant:
  - `reasoning` — blueprint generation (low volume, high leverage).
  - `drafting` — bulk per-section reading content (higher volume,
    cost-sensitive).
  - `image` — diagram/illustration generation.
- **Defaults:** ship sensible tier→provider defaults; admins override per
  tenant for cost or data residency. The plugin never hardcodes a vendor.
- **Quizzes:** delegated to `local_quizgenpro`'s public API *(verify the
  current API surface and version)*; quizgenpro uses its own provider
  config. No quiz logic duplicated here.
- **Images:** generated via the image capability, stored through the File
  API (per-tenant moodledata partitioning), embedded into page/book HTML;
  alt text generated alongside each image for accessibility.

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

- **format_pathway** — default course format for generated courses; the
  self-contained linear layout also embeds cleanly if D9 is ever pursued.
- **local_quizgenpro** — assessments (§5).
- **tool_muprog / tool_mucertify** — optional finalize step to wrap the
  generated course into a program / certification; off by default.
- **mod_page / mod_book (or mod_mubook)** — reading content targets
  *(verify which book module is preferred in the deployment)*.

---

## 10. To verify before build (spec-verification)

1. Moodle 5.1 course/section/module creation APIs and signatures — do not
   assume; grep the codebase.
2. `core_ai` action names and whether an image-generation action is exposed
   in 5.1; how the resolved provider/model is reported for logging.
3. `local_quizgenpro` current public API and version compatibility.
4. Whether Moodle core bundles PDF/DOCX/PPTX text extraction usable here,
   to avoid duplicate dependencies.
5. format_pathway course-creation hooks and any required course settings.
6. Adhoc task behaviour and progress reporting inside a tool_mutenancy
   tenant context.
7. (For D9, later) LTI 1.3 provider maturity of enrol_lti in 5.1.

---

## 11. Proposed phasing

Each phase lands in its own commit with phase-boundary quality gates
(phpcs moodle-clean, PHPUnit green, real-transport verification where
applicable), per the LMS Light working process.

- **P0 — Skeleton & scaffolding.** Plugin structure, capabilities,
  settings (tier mappings, caps, mode), DB schema, privacy provider stub.
- **P1 — Ingestion & extraction.** Uploads, source corpus, async extract,
  limits. CLI/test fixtures for each file type.
- **P2 — Blueprint generation.** `reasoning`-tier blueprint, map-reduce,
  persisted editable IR, cost estimate.
- **P3 — Review gate & UI.** Outline-first editing, approval,
  per-section regeneration; mode handling.
- **P4 — Materialization.** Draft format_pathway course, `drafting`-tier
  reading content, flashcards, image generation + alt text + File API.
- **P5 — Assessments.** quizgenpro integration per the assessment spec.
- **P6 — Finalize & governance.** Quota enforcement, audit completeness,
  optional muprog/mucertify wrap, MANUAL_SMOKE.md.
- **P7 — Hardening.** Real-transport verification of provider calls,
  failure/resume paths, docs (README, CHANGES), release tag.
