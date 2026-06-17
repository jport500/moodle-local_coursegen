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
 * Tests for the blueprint IR value object.
 *
 * @package    local_coursegen
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen;

use local_coursegen\local\blueprint;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests building, validating and (de)serializing the blueprint.
 */
#[CoversClass(\local_coursegen\local\blueprint::class)]
final class blueprint_test extends \advanced_testcase {
    /**
     * from_array populates fields and counts.
     *
     * @return void
     */
    public function test_from_array_and_counts(): void {
        $blueprint = blueprint::from_array([
            'title' => 'Widgets',
            'description' => 'About widgets.',
            'sections' => [
                ['title' => 'A', 'image' => ['generate' => true, 'prompthint' => 'x']],
                ['title' => 'B', 'image' => ['generate' => false]],
                ['title' => 'C', 'image' => ['generate' => true]],
            ],
        ]);
        $this->assertSame('Widgets', $blueprint->get_title());
        $this->assertSame('About widgets.', $blueprint->get_description());
        $this->assertSame(3, $blueprint->section_count());
        $this->assertSame(2, $blueprint->image_count());
        $this->assertTrue($blueprint->is_valid());
    }

    /**
     * Sections get safe defaults for missing fields.
     *
     * @return void
     */
    public function test_section_defaults(): void {
        $blueprint = blueprint::from_array([
            'title' => 'T',
            'sections' => [['title' => 'Only title']],
        ]);
        $section = $blueprint->get_sections()[0];
        $this->assertSame(blueprint::CONTENT_INLINE, $section['contenttype']);
        $this->assertSame(blueprint::ASSESS_NONE, $section['assessment']['type']);
        $this->assertSame([], $section['objectives']);
        $this->assertFalse($section['image']['generate']);
    }

    /**
     * to_json/from_json round-trips losslessly.
     *
     * @return void
     */
    public function test_json_roundtrip(): void {
        $original = blueprint::from_array([
            'title' => 'T',
            'description' => 'D',
            'sections' => [
                ['title' => 'S', 'objectives' => ['o1', 'o2'], 'contenttype' => 'book',
                 'summary' => 'sum', 'assessment' => ['type' => 'knowledgecheck', 'questioncount' => 5]],
            ],
        ]);
        $restored = blueprint::from_json($original->to_json());
        $this->assertSame($original->get_title(), $restored->get_title());
        $this->assertEquals($original->get_sections(), $restored->get_sections());
    }

    /**
     * An empty or title-less blueprint is invalid.
     *
     * @return void
     */
    public function test_invalid_when_incomplete(): void {
        $this->assertFalse((new blueprint())->is_valid());
        $this->assertFalse(blueprint::from_array(['title' => 'No sections'])->is_valid());
        $this->assertFalse(blueprint::from_array(['sections' => [['title' => 'x']]])->is_valid());
    }
}
