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
 * Tests for blueprint editing: form mapping, versioning, estimate, capability.
 *
 * @package    local_coursegen
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen;

use local_coursegen\local\blueprint;
use local_coursegen\local\blueprint_store;
use local_coursegen\local\job_manager;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests the data→IR mapping, version retention and the edit capability rule.
 */
#[CoversClass(\local_coursegen\local\blueprint::class)]
#[CoversClass(\local_coursegen\local\blueprint_store::class)]
final class blueprint_edit_test extends \advanced_testcase {
    /**
     * from_form_data orders by the per-section field, drops blank rows, and
     * maps every per-section field (objectives split on newlines).
     *
     * @return void
     */
    public function test_from_form_data_mapping(): void {
        $data = (object) [
            'title' => 'My Course',
            'description' => 'Desc',
            'sectiontitle' => ['Beta', '', 'Alpha'],
            'sectionorder' => [2, 5, 1],
            'sectionobjectives' => ["o1\no2", 'x', 'oa'],
            'sectioncontenttype' => ['book', 'page', 'page'],
            'sectionsummary' => ['sb', '', 'sa'],
            'sectionimage' => [1, 0, 0],
            'sectionimagehint' => ['hint', '', ''],
            'sectionassesstype' => ['knowledgecheck', 'none', 'none'],
            'sectionassesscount' => [3, 0, 0],
        ];

        $blueprint = blueprint::from_form_data($data);

        $this->assertSame('My Course', $blueprint->get_title());
        $this->assertSame(2, $blueprint->section_count()); // Blank-titled row dropped.
        $sections = $blueprint->get_sections();
        // Ordered by the order field: Alpha (1) before Beta (2).
        $this->assertSame('Alpha', $sections[0]['title']);
        $this->assertSame('Beta', $sections[1]['title']);
        $this->assertSame(['o1', 'o2'], $sections[1]['objectives']);
        $this->assertSame(blueprint::CONTENT_INLINE, $sections[1]['contenttype']);
        $this->assertTrue($sections[1]['image']['generate']);
        $this->assertSame(1, $blueprint->image_count());
    }

    /**
     * The estimate is derived from the plan (sections + flagged images).
     *
     * @return void
     */
    public function test_estimate_units(): void {
        $blueprint = blueprint::from_array([
            'title' => 'C',
            'sections' => [
                ['title' => 'A', 'image' => ['generate' => true]],
                ['title' => 'B', 'image' => ['generate' => false]],
            ],
        ]);
        $expected = 2 * blueprint::EST_UNITS_PER_SECTION + 1 * blueprint::EST_UNITS_PER_IMAGE;
        $this->assertSame($expected, $blueprint->estimate_units());
    }

    /**
     * Saving a new version retains the prior one and moves iscurrent; the
     * recomputed estimate reflects the edited plan.
     *
     * @return void
     */
    public function test_versioning_and_estimate_recompute(): void {
        global $DB;
        $this->resetAfterTest();
        $job = $this->job();

        $v1 = blueprint::from_array([
            'title' => 'C',
            'sections' => [
                ['title' => 'A', 'image' => ['generate' => true]],
                ['title' => 'B'],
            ],
        ]);
        blueprint_store::save_new_version($job, $v1, 2);
        $DB->set_field('coursegen_job', 'estimatedspend', $v1->estimate_units(), ['id' => $job->id]);
        $this->assertEquals(2 * 700 + 1000, (int) $DB->get_field('coursegen_job', 'estimatedspend', ['id' => $job->id]));

        // Edit: three sections, no images.
        $v2 = blueprint::from_array([
            'title' => 'C',
            'sections' => [['title' => 'A'], ['title' => 'B'], ['title' => 'D']],
        ]);
        blueprint_store::save_new_version($job, $v2, 2);
        $DB->set_field('coursegen_job', 'estimatedspend', $v2->estimate_units(), ['id' => $job->id]);

        $this->assertEquals(2, $DB->count_records('coursegen_blueprint', ['jobid' => $job->id]));
        $this->assertEquals(1, $DB->count_records('coursegen_blueprint', ['jobid' => $job->id, 'iscurrent' => 1]));
        $current = blueprint_store::current_record($job->id);
        $this->assertEquals(2, $current->version);
        $this->assertEquals(3 * 700, (int) $DB->get_field('coursegen_job', 'estimatedspend', ['id' => $job->id]));
        // Prior version retained.
        $this->assertTrue($DB->record_exists(
            'coursegen_blueprint',
            ['jobid' => $job->id, 'version' => 1, 'iscurrent' => 0]
        ));
    }

    /**
     * Editing is open to :generate OR :reviewgate; an author with only
     * :generate is not locked out of their own draft.
     *
     * @return void
     */
    public function test_edit_capability_rule(): void {
        $this->resetAfterTest();
        $job = $this->job();
        $context = \context::instance_by_id($job->contextid);

        $author = $this->user_with($context, ['local/coursegen:generate']);
        $reviewer = $this->user_with($context, ['local/coursegen:reviewgate']);
        $outsider = $this->getDataGenerator()->create_user();

        $canedit = static fn($u): bool =>
            has_capability('local/coursegen:generate', $context, $u)
            || has_capability('local/coursegen:reviewgate', $context, $u);

        $this->assertTrue($canedit($author));
        $this->assertTrue($canedit($reviewer));
        $this->assertFalse($canedit($outsider));
    }

    /**
     * A job in a fresh category context.
     *
     * @return \stdClass
     */
    private function job(): \stdClass {
        global $DB;
        $category = $this->getDataGenerator()->create_category();
        $context = \context_coursecat::instance($category->id);
        $now = time();
        $id = $DB->insert_record('coursegen_job', (object) [
            'userid' => 2,
            'contextid' => $context->id,
            'courseid' => null,
            'mode' => 'outlinefirst',
            'status' => job_manager::STATUS_AWAITING_REVIEW,
            'timecreated' => $now,
            'timemodified' => $now,
            'usermodified' => 2,
        ]);
        return $DB->get_record('coursegen_job', ['id' => $id], '*', MUST_EXIST);
    }

    /**
     * Create a user granted the given capabilities at a context.
     *
     * @param \context $context The context.
     * @param string[] $capabilities Capabilities to allow.
     * @return \stdClass The user.
     */
    private function user_with(\context $context, array $capabilities): \stdClass {
        $user = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        foreach ($capabilities as $capability) {
            assign_capability($capability, CAP_ALLOW, $roleid, $context->id, true);
        }
        role_assign($roleid, $user->id, $context->id);
        return $user;
    }
}
