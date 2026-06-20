# local_coursegen — AI course builder

LMS Light's AI course builder. Generates native Moodle courses from uploaded
materials or a topic prompt, composing the existing stack
(`format_pathway`, `local_quizgenpro`, and `mod_knowledgecheck` /
`filter_knowledgecheck`). Every AI call routes through Moodle's AI Providers
subsystem — the plugin never talks to an LLM vendor directly.

The design and the reasoning behind it live in [`docs/SPEC.md`](docs/SPEC.md)
and [`docs/DECISIONS.md`](docs/DECISIONS.md). Read
[CONTEXT.md](https://github.com/jport500/lms-light-docs/blob/main/CONTEXT.md)
first for the product and deployment model.

## Status

**Beta (v0.16.2).** The full pipeline is implemented: source ingestion and
extraction, blueprint generation, the review gate with per-section
regeneration, materialization into a hidden format_pathway course (intro +
wrap-up bookends, per-section reading, optional images, a course thumbnail),
assessments (inline knowledge checks and graded quizzes), and
completion-to-criteria wiring. Operators steer each run with audience-level and
length/depth controls. See [CHANGES.md](CHANGES.md) for the per-phase history.

## Requirements

- Moodle 5.2 or later (`requires = 2026042000`; verified floor — see
  DECISIONS D19).
- `format_pathway`, `local_quizgenpro`, and
  `mod_knowledgecheck` + `filter_knowledgecheck` installed.
- At least one provider configured in the AI Providers subsystem (a text
  provider is required; an image provider is needed for section images).

## Installation

1. Place this plugin at `public/local/coursegen/` in your Moodle tree.
2. Visit *Site administration → Notifications* to run the installation.
3. Configure it at *Site administration → Plugins → Local plugins →
   AI course builder*.

## Configuration

All settings are stored via standard plugin config for automatic per-tenant
isolation:

- **Capability-tier providers** — map the `reasoning`, `drafting`, and
  `image` tiers to configured AI providers, or defer to the AI Providers
  default.
- **Generation caps** — spend cap, soft-warning threshold, and a separate
  image sub-cap.
- **Mode** — default generation mode (outline-first / automatic), an
  optional lock, and the per-section image opt-in default.
- **Course depth** — default audience level (beginner / intermediate /
  advanced) and default length/depth (brief / standard / comprehensive); the
  create form pre-selects these and the operator can change them per run
  (DECISIONS D26).

## Capabilities

- `local/coursegen:generate` — start a generation run (category context).
- `local/coursegen:reviewgate` — approve a blueprint at the review gate.
- `local/coursegen:configure` — configure the plugin (site context).

## Privacy

The plugin stores who generated what, the source material supplied, the
editable blueprint, and a per-stage audit log. A full GDPR privacy provider
(export + delete) is implemented. Credential values are never stored.

## License

GNU GPL v3 or later. © 2026 LMS Light.
