# local_coursegen — AI course builder

LMS Light's AI course builder. Generates native Moodle courses from uploaded
materials or a topic prompt, composing the existing stack
(`format_pathway`, `local_quizgenpro`, and optionally `tool_muprog` /
`tool_mucertify`). Every AI call routes through Moodle's AI Providers
subsystem — the plugin never talks to an LLM vendor directly.

The design and the reasoning behind it live in [`docs/SPEC.md`](docs/SPEC.md)
and [`docs/DECISIONS.md`](docs/DECISIONS.md). Read
[CONTEXT.md](https://github.com/jport500/lms-light-docs/blob/main/CONTEXT.md)
first for the product and deployment model.

## Status

**Phase 0 — scaffolding.** Plugin skeleton, capabilities, admin settings,
database schema, and privacy provider are in place. Ingestion, generation,
and the pipeline are not yet implemented (P1 onward — see SPEC §11).

## Requirements

- Moodle 5.1 or later (`requires = 2025092600`).
- `format_pathway` and `local_quizgenpro` installed.
- At least one provider configured in the AI Providers subsystem.
- Optional: `tool_muprog` / `tool_mucertify` to enable the wrap toggles.

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
- **Composition** — optional toggles to wrap generated courses in a
  `tool_muprog` program or `tool_mucertify` certification.

## Capabilities

- `local/coursegen:generate` — start a generation run (category context).
- `local/coursegen:reviewgate` — approve a blueprint at the review gate.
- `local/coursegen:configure` — configure the plugin (site context).

## Privacy

The plugin stores who generated what, the source material supplied, the
editable blueprint, and a per-stage audit log. A full GDPR privacy provider
(export + delete) ships from v1. Credential values are never stored.

## License

GNU GPL v3 or later. © 2026 LMS Light.
