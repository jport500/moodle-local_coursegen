<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * The course blueprint: the editable, first-class generation plan (IR).
 *
 * @package    local_coursegen
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen\local;

/**
 * The intermediate representation produced at the blueprint stage and stored
 * in coursegen_blueprint (D8): a course title/description plus ordered
 * sections, each with objectives, content type, summary, an image flag + hint,
 * and an assessment spec (SPEC §3). (De)serializes to the JSON held in
 * coursegen_blueprint.content.
 */
class blueprint {
    /** @var int Serialization format version. */
    public const VERSION = 1;

    /**
     * @var string The only v1 content type: an inline "Text and media" area
     * (mod_label) rendered in the section itself. Book/mod_mubook is a
     * fast-follow (DECISIONS). The enum is intentionally single-valued so the
     * blueprint can only emit what the materializer builds.
     */
    public const CONTENT_INLINE = 'inline';

    /** @var string Section assessed with a generated quiz. */
    public const ASSESS_QUIZ = 'quiz';

    /** @var string Section with no assessment. */
    public const ASSESS_NONE = 'none';

    /** @var int Estimated generation units (≈tokens) of reading content per section. */
    public const EST_UNITS_PER_SECTION = 700;

    /** @var int Estimated generation units per flagged image. */
    public const EST_UNITS_PER_IMAGE = 1000;

    /** @var string Proposed course title. */
    private string $title = '';

    /** @var string Proposed course description. */
    private string $description = '';

    /** @var array[] Ordered, normalized section specs. */
    private array $sections = [];

    /**
     * Set the course title.
     *
     * @param string $title The title.
     * @return void
     */
    public function set_title(string $title): void {
        $this->title = trim($title);
    }

    /**
     * Set the course description.
     *
     * @param string $description The description.
     * @return void
     */
    public function set_description(string $description): void {
        $this->description = trim($description);
    }

    /**
     * Append a section, normalizing its shape and applying defaults.
     *
     * @param array $section Raw section data (e.g. decoded from model output).
     * @return void
     */
    public function add_section(array $section): void {
        // The v1 enum is single-valued; any input content type is ignored.
        $contenttype = self::CONTENT_INLINE;

        $objectives = [];
        foreach ((array) ($section['objectives'] ?? []) as $objective) {
            $objective = trim((string) $objective);
            if ($objective !== '') {
                $objectives[] = $objective;
            }
        }

        $image = (array) ($section['image'] ?? []);
        $assessment = (array) ($section['assessment'] ?? []);
        $assesstype = ($assessment['type'] ?? self::ASSESS_NONE) === self::ASSESS_QUIZ
            ? self::ASSESS_QUIZ : self::ASSESS_NONE;

        $this->sections[] = [
            'title' => trim((string) ($section['title'] ?? '')),
            'objectives' => $objectives,
            'contenttype' => $contenttype,
            'summary' => trim((string) ($section['summary'] ?? '')),
            'image' => [
                'generate' => !empty($image['generate']),
                'prompthint' => trim((string) ($image['prompthint'] ?? '')),
            ],
            'assessment' => [
                'type' => $assesstype,
                'questioncount' => max(0, (int) ($assessment['questioncount'] ?? 0)),
                'notes' => trim((string) ($assessment['notes'] ?? '')),
            ],
        ];
    }

    /**
     * The course title.
     *
     * @return string
     */
    public function get_title(): string {
        return $this->title;
    }

    /**
     * The course description.
     *
     * @return string
     */
    public function get_description(): string {
        return $this->description;
    }

    /**
     * The ordered sections.
     *
     * @return array[]
     */
    public function get_sections(): array {
        return $this->sections;
    }

    /**
     * Number of sections.
     *
     * @return int
     */
    public function section_count(): int {
        return count($this->sections);
    }

    /**
     * Number of sections flagged for image generation.
     *
     * @return int
     */
    public function image_count(): int {
        $count = 0;
        foreach ($this->sections as $section) {
            if (!empty($section['image']['generate'])) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Whether this is a usable blueprint (a title and at least one section).
     *
     * @return bool
     */
    public function is_valid(): bool {
        return $this->title !== '' && $this->sections !== [];
    }

    /**
     * The generation cost estimate in abstract generation units (≈tokens),
     * computed from the plan (SPEC §7). The single source of truth for the
     * estimate, used at generation and recomputed on edit.
     *
     * @return int
     */
    public function estimate_units(): int {
        return $this->section_count() * self::EST_UNITS_PER_SECTION
            + $this->image_count() * self::EST_UNITS_PER_IMAGE;
    }

    /**
     * Serialize to the JSON stored in coursegen_blueprint.content.
     *
     * @return string
     */
    public function to_json(): string {
        return json_encode([
            'version' => self::VERSION,
            'title' => $this->title,
            'description' => $this->description,
            'sections' => $this->sections,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Build a blueprint from a decoded associative array.
     *
     * @param array $data Decoded blueprint data with title/description/sections.
     * @return self
     */
    public static function from_array(array $data): self {
        $blueprint = new self();
        $blueprint->set_title((string) ($data['title'] ?? ''));
        $blueprint->set_description((string) ($data['description'] ?? ''));
        foreach ((array) ($data['sections'] ?? []) as $section) {
            if (is_array($section)) {
                $blueprint->add_section($section);
            }
        }
        return $blueprint;
    }

    /**
     * Rebuild a blueprint from stored JSON.
     *
     * @param string|null $json The stored JSON.
     * @return self
     */
    public static function from_json(?string $json): self {
        if ($json === null || trim($json) === '') {
            return new self();
        }
        $data = json_decode($json, true);
        return is_array($data) ? self::from_array($data) : new self();
    }

    /**
     * Decode a model's JSON output into an array, tolerating Markdown code
     * fences and surrounding prose by extracting the outermost object. Shared
     * by full-blueprint synthesis and per-section regeneration.
     *
     * @param string $content The raw model output.
     * @return array|null The decoded array, or null if no valid object found.
     */
    public static function decode_object(string $content): ?array {
        $content = trim($content);
        // Strip code fences without writing literal backticks in source.
        $fence = preg_quote(str_repeat(chr(96), 3), '/');
        $content = preg_replace('/^' . $fence . '(?:json)?\s*|\s*' . $fence . '$/i', '', $content);
        $decoded = json_decode($content, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        $start = strpos($content, '{');
        $end = strrpos($content, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $decoded = json_decode(substr($content, $start, $end - $start + 1), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return null;
    }

    /**
     * Build a blueprint from submitted edit-form data. Sections are ordered by
     * their per-section order field; blank-titled rows (removed) are dropped.
     *
     * @param \stdClass $data The moodleform data (repeat_elements arrays).
     * @return self
     */
    public static function from_form_data(\stdClass $data): self {
        $blueprint = new self();
        $blueprint->set_title((string) ($data->title ?? ''));
        $blueprint->set_description((string) ($data->description ?? ''));

        $titles = (array) ($data->sectiontitle ?? []);
        $orders = (array) ($data->sectionorder ?? []);
        $objectives = (array) ($data->sectionobjectives ?? []);
        $summaries = (array) ($data->sectionsummary ?? []);
        $images = (array) ($data->sectionimage ?? []);
        $imagehints = (array) ($data->sectionimagehint ?? []);
        $assesstypes = (array) ($data->sectionassesstype ?? []);
        $assesscounts = (array) ($data->sectionassesscount ?? []);

        $rows = [];
        foreach ($titles as $i => $title) {
            if (trim((string) $title) === '') {
                continue;
            }
            $rows[] = [
                'order' => (int) ($orders[$i] ?? ($i + 1)),
                'section' => [
                    'title' => (string) $title,
                    'objectives' => preg_split('/\R/', (string) ($objectives[$i] ?? '')),
                    'summary' => (string) ($summaries[$i] ?? ''),
                    'image' => [
                        'generate' => !empty($images[$i]),
                        'prompthint' => (string) ($imagehints[$i] ?? ''),
                    ],
                    'assessment' => [
                        'type' => (string) ($assesstypes[$i] ?? self::ASSESS_NONE),
                        'questioncount' => (int) ($assesscounts[$i] ?? 0),
                    ],
                ],
            ];
        }
        usort($rows, static fn(array $a, array $b): int => $a['order'] <=> $b['order']);
        foreach ($rows as $row) {
            $blueprint->add_section($row['section']);
        }
        return $blueprint;
    }
}
