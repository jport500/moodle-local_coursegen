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
 * Tests for blueprint generation (offline, via a stubbed text client).
 *
 * @package    local_coursegen
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen;

use local_coursegen\local\ai\stub_text_client;
use local_coursegen\local\ai\text_client;
use local_coursegen\local\ai\text_result;
use local_coursegen\local\blueprint_generator;
use local_coursegen\local\corpus;
use local_coursegen\local\job_manager;
use local_coursegen\task\generate_blueprint;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/stub_text_client.php');

/**
 * Tests the reasoning-tier blueprint generator and the task that wraps it.
 */
#[CoversClass(\local_coursegen\local\blueprint_generator::class)]
#[CoversClass(\local_coursegen\task\generate_blueprint::class)]
final class blueprint_generator_test extends \advanced_testcase {
    /**
     * A small corpus is blueprinted in a single call, with the estimate, status
     * transition and §10.2 audit row all recorded.
     *
     * @return void
     */
    public function test_single_call_blueprint(): void {
        global $DB;
        $this->resetAfterTest();
        $job = $this->extracted_job(['A short paragraph about widgets and gears.']);

        $stub = new stub_text_client([$this->ok($this->blueprint_json())]);
        $ok = (new blueprint_generator($stub))->generate_for_job($job);

        $this->assertTrue($ok);
        $this->assertSame(1, $stub->call_count());

        $job = $DB->get_record('coursegen_job', ['id' => $job->id], '*', MUST_EXIST);
        $this->assertSame(job_manager::STATUS_BLUEPRINTED, $job->status);
        $this->assertEquals(5 * 700 + 1 * 1000, (int) $job->estimatedspend);

        $record = $DB->get_record('coursegen_blueprint', ['jobid' => $job->id, 'iscurrent' => 1], '*', MUST_EXIST);
        $this->assertSame('Test Course', $record->title);
        $this->assertSame(5, \local_coursegen\local\blueprint::from_json($record->content)->section_count());

        $log = $DB->get_record(
            'coursegen_log',
            ['jobid' => $job->id, 'stage' => 'blueprint', 'outcome' => 'success'],
            '*',
            MUST_EXIST
        );
        $this->assertSame('reasoning', $log->tier);
        $this->assertSame('generate_text', $log->actionname);
        $this->assertSame('stubprovider', $log->provider);
        $this->assertSame('stub-model', $log->model);
        $this->assertEquals(11, $log->tokensin);
        $this->assertEquals(22, $log->tokensout);
    }

    /**
     * A tenant already over its period spend cap is refused before any AI call.
     *
     * @return void
     */
    public function test_over_cap_refuses_before_call(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('cap_period_spend', '100', 'local_coursegen');
        $DB->insert_record('coursegen_log', (object) [
            'jobid' => 1, 'userid' => null, 'stage' => 'materialize', 'outcome' => 'success',
            'detail' => 'prior spend', 'tokensin' => 200, 'tokensout' => 0, 'timecreated' => time(),
        ]);
        $job = $this->extracted_job(['A short paragraph about widgets and gears.']);

        $stub = new stub_text_client([$this->ok($this->blueprint_json())]);
        ob_start(); // The failure path emits an mtrace line for cron logs.
        $ok = (new blueprint_generator($stub))->generate_for_job($job);
        ob_end_clean();

        $this->assertFalse($ok);
        $this->assertSame(0, $stub->call_count(), 'A reasoning call was made despite the cap.');
        $job = $DB->get_record('coursegen_job', ['id' => $job->id], '*', MUST_EXIST);
        $this->assertSame(job_manager::STATUS_FAILED, $job->status);
    }

    /**
     * The operator's depth/level (D26) is woven into the assembled synthesis
     * prompt: a Comprehensive + Advanced job carries the wide section range and
     * the Evaluate-level framing; a Brief + Beginner job carries the opposite.
     *
     * @return void
     */
    public function test_depth_targets_are_woven_into_the_prompt(): void {
        global $DB;
        $this->resetAfterTest();

        $job = $this->extracted_job(['A short paragraph about widgets and gears.']);
        $DB->set_field('coursegen_job', 'audiencelevel', 'advanced', ['id' => $job->id]);
        $DB->set_field('coursegen_job', 'depth', 'comprehensive', ['id' => $job->id]);
        $job = $DB->get_record('coursegen_job', ['id' => $job->id], '*', MUST_EXIST);

        $stub = new stub_text_client([$this->ok($this->blueprint_json())]);
        (new blueprint_generator($stub))->generate_for_job($job);

        $prompt = $stub->prompts()[0];
        $this->assertStringContainsString('COURSE DESIGN TARGETS', $prompt);
        $this->assertStringContainsString('8-12 sections', $prompt);
        $this->assertStringContainsString('Evaluate', $prompt);
        // The JSON contract is unchanged — the shape the model must return is intact.
        $this->assertStringContainsString('"objectives"', $prompt);
        $this->assertStringContainsString('"assessment"', $prompt);

        // The opposite end produces the opposite guidance.
        $job2 = $this->extracted_job(['Another short paragraph about levers.']);
        $DB->set_field('coursegen_job', 'audiencelevel', 'beginner', ['id' => $job2->id]);
        $DB->set_field('coursegen_job', 'depth', 'brief', ['id' => $job2->id]);
        $job2 = $DB->get_record('coursegen_job', ['id' => $job2->id], '*', MUST_EXIST);

        $stub2 = new stub_text_client([$this->ok($this->blueprint_json())]);
        (new blueprint_generator($stub2))->generate_for_job($job2);
        $prompt2 = $stub2->prompts()[0];
        $this->assertStringContainsString('3-4 sections', $prompt2);
        $this->assertStringContainsString('no prior knowledge', $prompt2);
        $this->assertStringNotContainsString('8-12 sections', $prompt2);
    }

    /**
     * Length enforcement (D26 Fix 1): a first blueprint whose section count misses
     * the depth range triggers exactly one re-prompt, and the in-range retry is the
     * version that gets stored.
     *
     * @return void
     */
    public function test_out_of_range_count_triggers_one_reprompt(): void {
        global $DB;
        $this->resetAfterTest();

        // Brief => range 3-4. First response 7 sections (over), retry 4 (in range).
        $job = $this->extracted_job(['A short paragraph about widgets and gears.']);
        $DB->set_field('coursegen_job', 'depth', 'brief', ['id' => $job->id]);
        $job = $DB->get_record('coursegen_job', ['id' => $job->id], '*', MUST_EXIST);

        $stub = new stub_text_client([
            $this->ok($this->blueprint_json_n(7)),
            $this->ok($this->blueprint_json_n(4)),
        ]);
        $ok = (new blueprint_generator($stub))->generate_for_job($job);

        $this->assertTrue($ok);
        $this->assertSame(2, $stub->call_count(), 'Expected exactly one re-prompt.');
        // The re-prompt cites the observed miss and the target range.
        $this->assertStringContainsString('produced 7 sections', $stub->prompts()[1]);
        $this->assertStringContainsString('3-4', $stub->prompts()[1]);

        $record = $DB->get_record('coursegen_blueprint', ['jobid' => $job->id, 'iscurrent' => 1], '*', MUST_EXIST);
        $this->assertSame(4, \local_coursegen\local\blueprint::from_json($record->content)->section_count());
    }

    /**
     * An in-range first response is accepted with no re-prompt (D26 Fix 1).
     *
     * @return void
     */
    public function test_in_range_count_does_not_reprompt(): void {
        global $DB;
        $this->resetAfterTest();

        // Standard => range 5-7. A 6-section response is in range.
        $job = $this->extracted_job(['A short paragraph about widgets and gears.']);
        $DB->set_field('coursegen_job', 'depth', 'standard', ['id' => $job->id]);
        $job = $DB->get_record('coursegen_job', ['id' => $job->id], '*', MUST_EXIST);

        $stub = new stub_text_client([$this->ok($this->blueprint_json_n(6))]);
        $ok = (new blueprint_generator($stub))->generate_for_job($job);

        $this->assertTrue($ok);
        $this->assertSame(1, $stub->call_count(), 'No re-prompt expected for an in-range count.');
        $record = $DB->get_record('coursegen_blueprint', ['jobid' => $job->id, 'iscurrent' => 1], '*', MUST_EXIST);
        $this->assertSame(6, \local_coursegen\local\blueprint::from_json($record->content)->section_count());
    }

    /**
     * If the re-prompt also misses (or returns junk), the original is kept and the
     * job still succeeds — enforcement is best-effort, never fatal (D26 Fix 1).
     *
     * @return void
     */
    public function test_failed_retry_keeps_original_and_succeeds(): void {
        global $DB;
        $this->resetAfterTest();

        // Comprehensive => range 8-12. First gives 5 (under); retry returns junk.
        $job = $this->extracted_job(['A short paragraph about widgets and gears.']);
        $DB->set_field('coursegen_job', 'depth', 'comprehensive', ['id' => $job->id]);
        $job = $DB->get_record('coursegen_job', ['id' => $job->id], '*', MUST_EXIST);

        $stub = new stub_text_client([
            $this->ok($this->blueprint_json_n(5)),
            $this->ok('not valid json'),
        ]);
        $ok = (new blueprint_generator($stub))->generate_for_job($job);

        $this->assertTrue($ok, 'A failed retry must not fail the job.');
        $this->assertSame(2, $stub->call_count());
        $record = $DB->get_record('coursegen_blueprint', ['jobid' => $job->id, 'iscurrent' => 1], '*', MUST_EXIST);
        $this->assertSame(5, \local_coursegen\local\blueprint::from_json($record->content)->section_count());
    }

    /**
     * A corpus over the working budget is summarized in chunks, then synthesised.
     *
     * @return void
     */
    public function test_map_reduce(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('reasoning_budget_tokens', 5, 'local_coursegen'); // Roughly 20 chars per call.
        $job = $this->extracted_job([
            'First block of source text well beyond the tiny budget here.',
            'Second block of source text also beyond the tiny budget here.',
            'Third block of source text again beyond the tiny budget here.',
        ]);

        $stub = new stub_text_client([
            $this->ok('Summary one.'),
            $this->ok('Summary two.'),
            $this->ok('Summary three.'),
            $this->ok($this->blueprint_json()),
        ]);
        $ok = (new blueprint_generator($stub))->generate_for_job($job);

        $this->assertTrue($ok);
        $this->assertSame(4, $stub->call_count());
        // The final (synthesis) prompt is built from the summaries, not raw corpus.
        $synthesisprompt = $stub->prompts()[3];
        $this->assertStringContainsString('Summary one.', $synthesisprompt);
        $this->assertSame(
            job_manager::STATUS_BLUEPRINTED,
            $DB->get_field('coursegen_job', 'status', ['id' => $job->id])
        );
        // Four §10.2 rows: three summarize + one synthesis.
        $this->assertEquals(4, $DB->count_records(
            'coursegen_log',
            ['jobid' => $job->id, 'stage' => 'blueprint', 'outcome' => 'success']
        ));
    }

    /**
     * JSON wrapped in code fences is still parsed.
     *
     * @return void
     */
    public function test_parses_fenced_json(): void {
        global $DB;
        $this->resetAfterTest();
        $job = $this->extracted_job(['Some source.']);
        $fence = str_repeat(chr(96), 3);
        $stub = new stub_text_client([$this->ok($fence . "json\n" . $this->blueprint_json() . "\n" . $fence)]);

        $this->assertTrue((new blueprint_generator($stub))->generate_for_job($job));
        $this->assertSame(
            job_manager::STATUS_BLUEPRINTED,
            $DB->get_field('coursegen_job', 'status', ['id' => $job->id])
        );
    }

    /**
     * Non-JSON output fails the job with an audit row and no blueprint.
     *
     * @return void
     */
    public function test_invalid_json_fails_job(): void {
        global $DB;
        $this->resetAfterTest();
        $job = $this->extracted_job(['Some source.']);
        $stub = new stub_text_client([$this->ok('Sorry, I cannot do that.')]);

        ob_start(); // The failure path emits an mtrace line for cron logs.
        $ok = (new blueprint_generator($stub))->generate_for_job($job);
        ob_end_clean();

        $this->assertFalse($ok);
        $this->assertSame(
            job_manager::STATUS_FAILED,
            $DB->get_field('coursegen_job', 'status', ['id' => $job->id])
        );
        $this->assertFalse($DB->record_exists('coursegen_blueprint', ['jobid' => $job->id]));
        $this->assertTrue($DB->record_exists(
            'coursegen_log',
            ['jobid' => $job->id, 'stage' => 'blueprint', 'outcome' => 'failure']
        ));
    }

    /**
     * A failed provider call fails the job and is logged with the provider.
     *
     * @return void
     */
    public function test_client_failure_fails_job(): void {
        global $DB;
        $this->resetAfterTest();
        $job = $this->extracted_job(['Some source.']);
        $stub = new stub_text_client([new text_result(success: false, provider: 'stubprovider', error: 'boom')]);

        $this->assertFalse((new blueprint_generator($stub))->generate_for_job($job));
        $this->assertSame(
            job_manager::STATUS_FAILED,
            $DB->get_field('coursegen_job', 'status', ['id' => $job->id])
        );
        $this->assertTrue($DB->record_exists(
            'coursegen_log',
            ['jobid' => $job->id, 'stage' => 'blueprint', 'outcome' => 'failure', 'provider' => 'stubprovider']
        ));
    }

    /**
     * The generate_blueprint task delegates to the generator (client injected).
     *
     * @return void
     */
    public function test_task_generates_blueprint(): void {
        global $DB;
        $this->resetAfterTest();
        $job = $this->extracted_job(['Some source.']);

        $task = new class extends generate_blueprint {
            /** @var text_client The injected stub client. */
            public text_client $injected;

            /**
             * Return the injected stub instead of the live client.
             *
             * @return text_client
             */
            protected function get_text_client(): text_client {
                return $this->injected;
            }
        };
        $task->injected = new stub_text_client([$this->ok($this->blueprint_json())]);
        $task->set_custom_data((object) ['jobid' => $job->id]);
        $task->set_userid(2);
        ob_start();
        $task->execute();
        ob_end_clean();

        // The task generates the blueprint and applies the gate; an
        // outline-first job lands at awaiting_review.
        $this->assertTrue($DB->record_exists(
            'coursegen_blueprint',
            ['jobid' => $job->id, 'iscurrent' => 1]
        ));
        $this->assertSame(
            job_manager::STATUS_AWAITING_REVIEW,
            $DB->get_field('coursegen_job', 'status', ['id' => $job->id])
        );
    }

    /**
     * Insert an extracted job with a single text source carrying the given blocks.
     *
     * @param string[] $paragraphs Paragraph texts for the corpus.
     * @return \stdClass The job record.
     */
    private function extracted_job(array $paragraphs): \stdClass {
        global $DB;
        $category = $this->getDataGenerator()->create_category();
        $context = \context_coursecat::instance($category->id);
        $now = time();
        $jobid = $DB->insert_record('coursegen_job', (object) [
            'userid' => 2,
            'contextid' => $context->id,
            'courseid' => null,
            'mode' => 'outlinefirst',
            'status' => job_manager::STATUS_EXTRACTED,
            'timecreated' => $now,
            'timemodified' => $now,
            'usermodified' => 2,
        ]);
        $corpus = new corpus();
        foreach ($paragraphs as $text) {
            $corpus->add_paragraph($text);
        }
        $DB->insert_record('coursegen_source', (object) [
            'jobid' => $jobid,
            'type' => 'text',
            'filename' => 'a.txt',
            'itemid' => $jobid,
            'extractedchars' => $corpus->char_count(),
            'corpus' => $corpus->to_json(),
            'status' => 'extracted',
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        return $DB->get_record('coursegen_job', ['id' => $jobid], '*', MUST_EXIST);
    }

    /**
     * A successful text_result wrapping the given content.
     *
     * @param string $content The generated content.
     * @return text_result
     */
    private function ok(string $content): text_result {
        return new text_result(
            success: true,
            content: $content,
            model: 'stub-model',
            provider: 'stubprovider',
            prompttokens: 11,
            completiontokens: 22,
        );
    }

    /**
     * A valid blueprint JSON document with exactly $n plain sections.
     *
     * @param int $n The section count.
     * @return string
     */
    private function blueprint_json_n(int $n): string {
        $sections = [];
        for ($i = 1; $i <= $n; $i++) {
            $sections[] = [
                'title' => "Section {$i}",
                'objectives' => ["Objective {$i}"],
                'summary' => "Summary {$i}",
                'image' => ['generate' => false],
                'assessment' => ['type' => 'none', 'questioncount' => 0],
            ];
        }
        return json_encode(['title' => 'Sized Course', 'description' => 'A course.', 'sections' => $sections]);
    }

    /**
     * A valid blueprint JSON document (5 sections, 1 image) — sized within the
     * default 'standard' depth range (5-7) so length enforcement does not fire.
     *
     * @return string
     */
    private function blueprint_json(): string {
        $sections = [
            [
                'title' => 'Intro',
                'objectives' => ['Understand widgets'],
                'contenttype' => 'page',
                'summary' => 'Intro summary',
                'image' => ['generate' => true, 'prompthint' => 'a diagram'],
                'assessment' => ['type' => 'knowledgecheck', 'questioncount' => 3],
            ],
            [
                'title' => 'Advanced',
                'objectives' => ['Apply widgets'],
                'contenttype' => 'book',
                'summary' => 'Advanced summary',
                'image' => ['generate' => false],
                'assessment' => ['type' => 'none', 'questioncount' => 0],
            ],
        ];
        for ($i = 3; $i <= 5; $i++) {
            $sections[] = [
                'title' => "Section {$i}",
                'objectives' => ["Objective {$i}"],
                'summary' => "Summary {$i}",
                'image' => ['generate' => false],
                'assessment' => ['type' => 'none', 'questioncount' => 0],
            ];
        }
        return json_encode(['title' => 'Test Course', 'description' => 'A test course.', 'sections' => $sections]);
    }
}
