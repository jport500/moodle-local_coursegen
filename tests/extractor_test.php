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
 * Tests for the per-format source extractors.
 *
 * @package    local_coursegen
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen;

use local_coursegen\local\corpus;
use local_coursegen\local\extractor\factory;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the per-format source extractors and the factory.
 */
#[CoversClass(\local_coursegen\local\extractor\text_extractor::class)]
#[CoversClass(\local_coursegen\local\extractor\docx_extractor::class)]
#[CoversClass(\local_coursegen\local\extractor\pptx_extractor::class)]
#[CoversClass(\local_coursegen\local\extractor\pdf_extractor::class)]
#[CoversClass(\local_coursegen\local\extractor\factory::class)]
final class extractor_test extends \advanced_testcase {
    /**
     * Markdown headings and paragraphs are recognised.
     *
     * @return void
     */
    public function test_markdown_structure(): void {
        $this->resetAfterTest();
        $corpus = factory::make(factory::TYPE_TEXT)->extract($this->stored_file('sample.md'));
        $blocks = $corpus->get_blocks();

        $this->assertSame(corpus::TYPE_HEADING, $blocks[0]['type']);
        $this->assertSame(1, $blocks[0]['level']);
        $this->assertSame('Widgets 101', $blocks[0]['text']);
        // Two level-2 headings (History, Usage).
        $h2 = array_filter($blocks, static fn($b) => $b['type'] === corpus::TYPE_HEADING && $b['level'] === 2);
        $this->assertCount(2, $h2);
    }

    /**
     * Plain text splits into paragraphs on blank lines.
     *
     * @return void
     */
    public function test_plain_text_paragraphs(): void {
        $this->resetAfterTest();
        $corpus = factory::make(factory::TYPE_TEXT)->extract($this->stored_file('sample.txt'));
        $this->assertSame(3, $corpus->count());
        $this->assertStringContainsString('Course Introduction', $corpus->get_blocks()[0]['text']);
    }

    /**
     * DOCX paragraphs are read and Heading styles become headings.
     *
     * @return void
     */
    public function test_docx_heading_and_paragraphs(): void {
        $this->resetAfterTest();
        $corpus = factory::make(factory::TYPE_DOCX)->extract($this->stored_file('sample.docx'));
        $blocks = $corpus->get_blocks();

        $this->assertCount(3, $blocks);
        $this->assertSame(corpus::TYPE_HEADING, $blocks[0]['type']);
        $this->assertSame('Getting Started', $blocks[0]['text']);
        $this->assertSame(corpus::TYPE_PARAGRAPH, $blocks[1]['type']);
    }

    /**
     * PPTX title placeholders become headings; body text becomes paragraphs.
     *
     * @return void
     */
    public function test_pptx_title_and_body(): void {
        $this->resetAfterTest();
        $corpus = factory::make(factory::TYPE_PPTX)->extract($this->stored_file('sample.pptx'));
        $blocks = $corpus->get_blocks();

        $this->assertSame(corpus::TYPE_HEADING, $blocks[0]['type']);
        $this->assertSame('Slide One Title', $blocks[0]['text']);
        $bodies = array_filter($blocks, static fn($b) => $b['type'] === corpus::TYPE_PARAGRAPH);
        $this->assertCount(2, $bodies);
    }

    /**
     * PDF text is extracted into paragraph blocks.
     *
     * @return void
     */
    public function test_pdf_text(): void {
        $this->resetAfterTest();
        $corpus = factory::make(factory::TYPE_PDF)->extract($this->stored_file('sample.pdf'));
        $this->assertGreaterThan(0, $corpus->count());

        $alltext = '';
        foreach ($corpus->get_blocks() as $block) {
            $alltext .= ' ' . $block['text'];
        }
        $this->assertStringContainsString('Course Introduction', $alltext);
        $this->assertStringContainsString('second PDF paragraph', $alltext);
    }

    /**
     * The factory maps file extensions to source types.
     *
     * @return void
     */
    public function test_factory_type_detection(): void {
        $this->assertSame(factory::TYPE_PDF, factory::type_for_filename('notes.PDF'));
        $this->assertSame(factory::TYPE_TEXT, factory::type_for_filename('readme.md'));
        $this->assertSame(factory::TYPE_DOCX, factory::type_for_filename('a.docx'));
        $this->assertNull(factory::type_for_filename('archive.zip'));
    }

    /**
     * Load a fixture file into a stored_file.
     *
     * @param string $name Fixture filename under tests/fixtures.
     * @return \stored_file
     */
    private function stored_file(string $name): \stored_file {
        $fs = get_file_storage();
        $record = (object) [
            'contextid' => \context_system::instance()->id,
            'component' => 'local_coursegen',
            'filearea' => 'unittest',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => $name,
        ];
        return $fs->create_file_from_pathname($record, __DIR__ . '/fixtures/' . $name);
    }
}
