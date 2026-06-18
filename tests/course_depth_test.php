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
 * Tests for the operator-controlled course-depth mapping seam (D26).
 *
 * @package    local_coursegen
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen;

use local_coursegen\local\course_depth;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

/**
 * The single tuning seam: section ranges are monotonic across depths and the
 * cognitive framing differs across audience levels (DECISIONS D26-a).
 */
#[CoversClass(\local_coursegen\local\course_depth::class)]
final class course_depth_test extends \advanced_testcase {
    /**
     * Section ranges grow strictly and do not overlap: Brief < Standard <
     * Comprehensive, so the control demonstrably moves the output.
     *
     * @return void
     */
    public function test_section_ranges_are_monotonic_and_non_overlapping(): void {
        $brief = course_depth::profile(course_depth::LEVEL_INTERMEDIATE, course_depth::DEPTH_BRIEF);
        $standard = course_depth::profile(course_depth::LEVEL_INTERMEDIATE, course_depth::DEPTH_STANDARD);
        $comprehensive = course_depth::profile(course_depth::LEVEL_INTERMEDIATE, course_depth::DEPTH_COMPREHENSIVE);

        // Each range is well-formed (min <= max).
        foreach ([$brief, $standard, $comprehensive] as $p) {
            $this->assertLessThanOrEqual($p['sectionmax'], $p['sectionmin']);
        }

        // Strictly increasing and non-overlapping at the boundaries.
        $this->assertGreaterThan($brief['sectionmin'], $standard['sectionmin']);
        $this->assertGreaterThan($standard['sectionmin'], $comprehensive['sectionmin']);
        $this->assertGreaterThan($brief['sectionmax'], $standard['sectionmax']);
        $this->assertGreaterThan($standard['sectionmax'], $comprehensive['sectionmax']);
        $this->assertLessThan($standard['sectionmin'], $brief['sectionmax']);
        $this->assertLessThan($comprehensive['sectionmin'], $standard['sectionmax']);
    }

    /**
     * The fragment carries the resolved section range as an approximate range,
     * never a hard count (DECISIONS D26 scope guard).
     *
     * @return void
     */
    public function test_fragment_states_an_approximate_section_range(): void {
        $p = course_depth::profile(course_depth::LEVEL_BEGINNER, course_depth::DEPTH_COMPREHENSIVE);
        $this->assertStringContainsString('approximately', $p['fragment']);
        $this->assertStringContainsString("{$p['sectionmin']}-{$p['sectionmax']} sections", $p['fragment']);
    }

    /**
     * The audience axis changes the cognitive framing: Beginner and Advanced
     * produce different fragments pitched at different Bloom's levels.
     *
     * @return void
     */
    public function test_audience_level_changes_cognitive_framing(): void {
        $depth = course_depth::DEPTH_STANDARD;
        $beginner = course_depth::prompt_fragment(course_depth::LEVEL_BEGINNER, $depth);
        $intermediate = course_depth::prompt_fragment(course_depth::LEVEL_INTERMEDIATE, $depth);
        $advanced = course_depth::prompt_fragment(course_depth::LEVEL_ADVANCED, $depth);

        // All three differ from one another at the same depth.
        $this->assertNotSame($beginner, $intermediate);
        $this->assertNotSame($intermediate, $advanced);
        $this->assertNotSame($beginner, $advanced);

        // The Bloom's framing matches the audience.
        $this->assertStringContainsStringIgnoringCase('no prior knowledge', $beginner);
        $this->assertStringContainsString('Remember', $beginner);
        $this->assertStringContainsString('Evaluate', $advanced);
        $this->assertStringContainsStringIgnoringCase('expertise', $advanced);
        // Beginner does not borrow the Advanced framing.
        $this->assertStringNotContainsString('Evaluate', $beginner);
    }

    /**
     * Depth changes the length guidance independently of the audience axis: the
     * two controls compose (Advanced+Brief differs from Advanced+Comprehensive).
     *
     * @return void
     */
    public function test_depth_changes_length_guidance_independently(): void {
        $brief = course_depth::prompt_fragment(course_depth::LEVEL_ADVANCED, course_depth::DEPTH_BRIEF);
        $comprehensive = course_depth::prompt_fragment(course_depth::LEVEL_ADVANCED, course_depth::DEPTH_COMPREHENSIVE);
        $this->assertNotSame($brief, $comprehensive);
        $this->assertStringContainsString('3-4 sections', $brief);
        $this->assertStringContainsString('8-12 sections', $comprehensive);
        // The shared audience framing is present in both.
        $this->assertStringContainsString('Evaluate', $brief);
        $this->assertStringContainsString('Evaluate', $comprehensive);
    }

    /**
     * Unknown or null inputs clamp to the house defaults rather than erroring.
     *
     * @return void
     */
    public function test_unknown_values_clamp_to_defaults(): void {
        $this->assertSame(course_depth::DEFAULT_LEVEL, course_depth::normalize_level('wizard'));
        $this->assertSame(course_depth::DEFAULT_LEVEL, course_depth::normalize_level(null));
        $this->assertSame(course_depth::DEFAULT_DEPTH, course_depth::normalize_depth('epic'));
        $this->assertSame(course_depth::DEFAULT_DEPTH, course_depth::normalize_depth(null));

        // A valid value is preserved.
        $this->assertSame(course_depth::LEVEL_ADVANCED, course_depth::normalize_level('advanced'));
        $this->assertSame(course_depth::DEPTH_BRIEF, course_depth::normalize_depth('brief'));

        // A junk pair resolves to the defaults' targets.
        $defaults = course_depth::profile(course_depth::DEFAULT_LEVEL, course_depth::DEFAULT_DEPTH);
        $clamped = course_depth::profile('wizard', 'epic');
        $this->assertSame($defaults, $clamped);
    }

    /**
     * The option maps used by the form and settings are complete and localized.
     *
     * @return void
     */
    public function test_option_maps_cover_every_value(): void {
        $this->assertSame(
            [course_depth::LEVEL_BEGINNER, course_depth::LEVEL_INTERMEDIATE, course_depth::LEVEL_ADVANCED],
            array_keys(course_depth::levels())
        );
        $this->assertSame(
            [course_depth::DEPTH_BRIEF, course_depth::DEPTH_STANDARD, course_depth::DEPTH_COMPREHENSIVE],
            array_keys(course_depth::depths())
        );
    }
}
