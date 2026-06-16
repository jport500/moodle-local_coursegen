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
 * Tests for the local_coursegen privacy provider.
 *
 * @package    local_coursegen
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen;

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\writer;
use local_coursegen\local\corpus;
use local_coursegen\privacy\provider;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests export and deletion across the four tables and the source filearea,
 * including the P1 corpus field.
 */
#[CoversClass(\local_coursegen\privacy\provider::class)]
final class privacy_provider_test extends \advanced_testcase {
    /**
     * Metadata declares the source corpus.
     *
     * @return void
     */
    public function test_metadata_includes_corpus(): void {
        $collection = new \core_privacy\local\metadata\collection('local_coursegen');
        $collection = provider::get_metadata($collection);
        $found = false;
        foreach ($collection->get_collection() as $item) {
            if ($item->get_name() === 'coursegen_source') {
                $this->assertArrayHasKey('corpus', $item->get_privacy_fields());
                $found = true;
            }
        }
        $this->assertTrue($found);
    }

    /**
     * A user's job, source corpus and uploaded file are exported.
     *
     * @return void
     */
    public function test_export_includes_corpus_and_file(): void {
        $this->resetAfterTest();
        [$user, $context, $jobid] = $this->make_job_with_data();

        $contextlist = new approved_contextlist($user, 'local_coursegen', [$context->id]);
        provider::export_user_data($contextlist);

        $writer = writer::with_context($context);
        $this->assertTrue($writer->has_any_data());

        $subcontext = [get_string('privacy:subcontext', 'local_coursegen'), 'job-' . $jobid];
        $sources = $writer->get_related_data($subcontext, 'sources');
        $this->assertNotEmpty($sources);
        $this->assertStringContainsString('widget', strtolower($sources[0]->corpus));

        $files = $writer->get_files($subcontext);
        $this->assertNotEmpty($files);
    }

    /**
     * Deleting a user's data removes rows and files.
     *
     * @return void
     */
    public function test_delete_for_user(): void {
        global $DB;
        $this->resetAfterTest();
        [$user, $context, $jobid] = $this->make_job_with_data();

        $contextlist = new approved_contextlist($user, 'local_coursegen', [$context->id]);
        provider::delete_data_for_user($contextlist);

        $this->assertFalse($DB->record_exists('coursegen_job', ['id' => $jobid]));
        $this->assertFalse($DB->record_exists('coursegen_source', ['jobid' => $jobid]));
        $this->assertFalse($DB->record_exists('coursegen_log', ['jobid' => $jobid]));

        $fs = get_file_storage();
        $this->assertEmpty($fs->get_area_files($context->id, 'local_coursegen', 'source', $jobid, 'id', false));
    }

    /**
     * Build a job owned by a fresh user, with a source corpus, a stored file
     * and an audit-log row, in a category context.
     *
     * @return array{0:\stdClass,1:\context_coursecat,2:int}
     */
    private function make_job_with_data(): array {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $category = $this->getDataGenerator()->create_category();
        $context = \context_coursecat::instance($category->id);
        $now = time();

        $jobid = $DB->insert_record('coursegen_job', (object) [
            'userid' => $user->id,
            'contextid' => $context->id,
            'courseid' => null,
            'mode' => 'outlinefirst',
            'status' => 'extracted',
            'timecreated' => $now,
            'timemodified' => $now,
            'usermodified' => $user->id,
        ]);

        $corpus = new corpus();
        $corpus->add_heading('Widgets', 1);
        $corpus->add_paragraph('All about widgets.');
        $DB->insert_record('coursegen_source', (object) [
            'jobid' => $jobid,
            'type' => 'docx',
            'filename' => 'notes.docx',
            'itemid' => $jobid,
            'extractedchars' => $corpus->char_count(),
            'corpus' => $corpus->to_json(),
            'status' => 'extracted',
            'timecreated' => $now,
            'timemodified' => $now,
        ]);

        $DB->insert_record('coursegen_log', (object) [
            'jobid' => $jobid,
            'userid' => $user->id,
            'stage' => 'extract',
            'outcome' => 'success',
            'timecreated' => $now,
        ]);

        get_file_storage()->create_file_from_string((object) [
            'contextid' => $context->id,
            'component' => 'local_coursegen',
            'filearea' => 'source',
            'itemid' => $jobid,
            'filepath' => '/',
            'filename' => 'notes.docx',
        ], 'source bytes');

        return [$user, $context, (int) $jobid];
    }
}
