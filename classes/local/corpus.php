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
 * Normalized source corpus: an ordered list of structure-aware blocks.
 *
 * @package    local_coursegen
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen\local;

/**
 * An ordered collection of text blocks (headings and paragraphs) carrying
 * lightweight structure metadata, produced by an extractor from one source
 * (SPEC §4). Serializes to/from the JSON stored in coursegen_source.corpus.
 */
class corpus {
    /** @var int Serialization format version. */
    public const VERSION = 1;

    /** @var string A heading block; carries a level (1–6). */
    public const TYPE_HEADING = 'heading';

    /** @var string A body/paragraph block. */
    public const TYPE_PARAGRAPH = 'paragraph';

    /** @var array[] Ordered list of blocks, each ['type'=>, 'level'=>?, 'text'=>]. */
    private array $blocks = [];

    /**
     * Append a heading block.
     *
     * @param string $text Heading text (trimmed; empty headings are ignored).
     * @param int $level Heading level, clamped to 1–6.
     * @return void
     */
    public function add_heading(string $text, int $level = 1): void {
        $text = trim($text);
        if ($text === '') {
            return;
        }
        $this->blocks[] = [
            'type' => self::TYPE_HEADING,
            'level' => max(1, min(6, $level)),
            'text' => $text,
        ];
    }

    /**
     * Append a paragraph block.
     *
     * @param string $text Paragraph text (trimmed; empty paragraphs are ignored).
     * @return void
     */
    public function add_paragraph(string $text): void {
        $text = trim($text);
        if ($text === '') {
            return;
        }
        $this->blocks[] = [
            'type' => self::TYPE_PARAGRAPH,
            'text' => $text,
        ];
    }

    /**
     * Return the ordered blocks.
     *
     * @return array[] Each block has 'type', optional 'level', and 'text'.
     */
    public function get_blocks(): array {
        return $this->blocks;
    }

    /**
     * Number of blocks.
     *
     * @return int
     */
    public function count(): int {
        return count($this->blocks);
    }

    /**
     * Whether the corpus has no blocks.
     *
     * @return bool
     */
    public function is_empty(): bool {
        return $this->blocks === [];
    }

    /**
     * Total character count across all block text.
     *
     * @return int
     */
    public function char_count(): int {
        $total = 0;
        foreach ($this->blocks as $block) {
            $total += \core_text::strlen($block['text']);
        }
        return $total;
    }

    /**
     * Rough token estimate (~4 characters per token) for cost/limit checks.
     * This is a heuristic for governance only, not a tokenizer.
     *
     * @return int
     */
    public function estimate_tokens(): int {
        return (int) ceil($this->char_count() / 4);
    }

    /**
     * Serialize to the JSON stored in coursegen_source.corpus.
     *
     * @return string
     */
    public function to_json(): string {
        return json_encode([
            'version' => self::VERSION,
            'blocks' => $this->blocks,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Rebuild a corpus from stored JSON.
     *
     * @param string|null $json The stored JSON, or null/empty for an empty corpus.
     * @return self
     */
    public static function from_json(?string $json): self {
        $corpus = new self();
        if ($json === null || trim($json) === '') {
            return $corpus;
        }
        $data = json_decode($json, true);
        if (!is_array($data) || empty($data['blocks']) || !is_array($data['blocks'])) {
            return $corpus;
        }
        foreach ($data['blocks'] as $block) {
            if (!isset($block['type'], $block['text'])) {
                continue;
            }
            if ($block['type'] === self::TYPE_HEADING) {
                $corpus->add_heading((string) $block['text'], (int) ($block['level'] ?? 1));
            } else {
                $corpus->add_paragraph((string) $block['text']);
            }
        }
        return $corpus;
    }
}
