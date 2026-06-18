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
 * Tests for the P14 (D21) legacy assessment-type migration rewrite.
 *
 * @package    local_coursegen
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen;

use local_coursegen\local\blueprint;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/upgradelib.php');
require_once($CFG->dirroot . '/local/coursegen/db/upgrade.php');

/**
 * The db/upgrade migration rewrites stored blueprint JSON 'quiz' assessment types
 * to 'knowledgecheck', via blueprint::rewrite_legacy_assessment_json().
 */
#[CoversClass(\local_coursegen\local\blueprint::class)]
final class upgrade_test extends \advanced_testcase {
    /**
     * The P20 (D26) step adds the audiencelevel/depth columns and backfills
     * existing rows with the house defaults (intermediate / standard).
     *
     * @return void
     */
    public function test_p20_adds_depth_columns_with_defaults(): void {
        global $DB;
        $this->resetAfterTest();
        $dbman = $DB->get_manager();
        $table = new \xmldb_table('coursegen_job');

        // Simulate a pre-P20 install: drop the two new columns.
        foreach (['depth', 'audiencelevel'] as $name) {
            $field = new \xmldb_field($name);
            if ($dbman->field_exists($table, $field)) {
                $dbman->drop_field($table, $field);
            }
        }
        $this->assertFalse($dbman->field_exists($table, new \xmldb_field('audiencelevel')));

        // A legacy row with no depth controls.
        $now = time();
        $jobid = $DB->insert_record('coursegen_job', (object) [
            'userid' => 2,
            'contextid' => \context_system::instance()->id,
            'mode' => 'outlinefirst',
            'status' => 'awaiting_review',
            'timecreated' => $now,
            'timemodified' => $now,
            'usermodified' => 2,
        ]);

        // Run only the P20 step. The savepoint stamps the plugin version, so the
        // stored version must sit below it for the upgrade not to look like a
        // downgrade (resetAfterTest restores it).
        set_config('version', 2026061619, 'local_coursegen');
        xmldb_local_coursegen_upgrade(2026061619);

        $this->assertTrue($dbman->field_exists($table, new \xmldb_field('audiencelevel')));
        $this->assertTrue($dbman->field_exists($table, new \xmldb_field('depth')));
        $job = $DB->get_record('coursegen_job', ['id' => $jobid], '*', MUST_EXIST);
        $this->assertSame('intermediate', $job->audiencelevel);
        $this->assertSame('standard', $job->depth);
    }

    /**
     * A 'quiz' type is rewritten to 'knowledgecheck'; other types are left alone.
     *
     * @return void
     */
    public function test_rewrite_rewrites_quiz(): void {
        $content = json_encode(['title' => 'T', 'sections' => [
            ['title' => 'A', 'assessment' => ['type' => 'quiz', 'questioncount' => 3]],
            ['title' => 'B', 'assessment' => ['type' => 'none']],
        ]]);

        $rewritten = blueprint::rewrite_legacy_assessment_json($content);

        $this->assertNotNull($rewritten);
        $decoded = json_decode($rewritten, true);
        $this->assertSame('knowledgecheck', $decoded['sections'][0]['assessment']['type']);
        $this->assertSame(3, $decoded['sections'][0]['assessment']['questioncount']);
        $this->assertSame('none', $decoded['sections'][1]['assessment']['type']);
        $this->assertStringNotContainsString('"type":"quiz"', $rewritten);
    }

    /**
     * Content with no legacy 'quiz' (or non-JSON) returns null — nothing to write.
     *
     * @return void
     */
    public function test_rewrite_leaves_others_null(): void {
        $clean = json_encode(['title' => 'C', 'sections' => [
            ['title' => 'X', 'assessment' => ['type' => 'knowledgecheck', 'questioncount' => 2]],
            ['title' => 'Y', 'assessment' => ['type' => 'none']],
        ]]);
        $this->assertNull(blueprint::rewrite_legacy_assessment_json($clean));
        $this->assertNull(blueprint::rewrite_legacy_assessment_json('not valid json'));
        $this->assertNull(blueprint::rewrite_legacy_assessment_json(json_encode(['title' => 'No sections'])));
    }
}
