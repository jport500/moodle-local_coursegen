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

    /** @var string Reading content delivered as a single page. */
    public const CONTENT_PAGE = 'page';

    /** @var string Reading content delivered as a multi-chapter book. */
    public const CONTENT_BOOK = 'book';

    /** @var string Section assessed with a generated quiz. */
    public const ASSESS_QUIZ = 'quiz';

    /** @var string Section with no assessment. */
    public const ASSESS_NONE = 'none';

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
        $contenttype = ($section['contenttype'] ?? self::CONTENT_PAGE) === self::CONTENT_BOOK
            ? self::CONTENT_BOOK : self::CONTENT_PAGE;

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
}
