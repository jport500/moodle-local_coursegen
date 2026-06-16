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
 * Adhoc task that extracts the source corpus for a generation job.
 *
 * @package    local_coursegen
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen\task;

use local_coursegen\local\corpus;
use local_coursegen\local\extractor\extraction_exception;
use local_coursegen\local\extractor\factory;
use local_coursegen\local\job_manager;

/**
 * Runs asynchronously (queued by job_manager). The task carries the requesting
 * user's id, so cron establishes that user's context — and therefore the
 * correct tenant and file access (SPEC §10.6). P1 ends when the corpus is
 * ready; moving the job to "blueprinted" is P2.
 */
class extract_corpus extends \core\task\adhoc_task {
    /**
     * Human-readable task name.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_extractcorpus', 'local_coursegen');
    }

    /**
     * Extract every pending source, enforce the corpus token cap, and finalize
     * the job status.
     *
     * @return void
     */
    public function execute() {
        global $DB;

        $data = $this->get_custom_data();
        $jobid = (int) ($data->jobid ?? 0);
        $job = $DB->get_record('coursegen_job', ['id' => $jobid]);
        if (!$job) {
            mtrace("local_coursegen: job {$jobid} not found; skipping.");
            return;
        }

        $context = \context::instance_by_id($job->contextid, IGNORE_MISSING);
        if (!$context) {
            $this->fail_job($job, 'context missing');
            return;
        }

        // Index permanent source files by filename for this job.
        $filesbyname = [];
        foreach (job_manager::stored_source_files($context, $job->id) as $file) {
            $filesbyname[$file->get_filename()] = $file;
        }

        $sources = $DB->get_records('coursegen_source', ['jobid' => $job->id], 'id ASC');
        $extracted = 0;
        $totaltokens = 0;

        foreach ($sources as $source) {
            if ($source->status === job_manager::SOURCE_EXTRACTED) {
                // Already done (e.g. topic-only sources).
                $totaltokens += corpus::from_json($source->corpus)->estimate_tokens();
                $extracted++;
                continue;
            }
            try {
                $file = $filesbyname[$source->filename] ?? null;
                if (!$file) {
                    throw new extraction_exception('source file missing');
                }
                $corpus = factory::make($source->type)->extract($file);
                $this->store_corpus($source, $corpus, job_manager::SOURCE_EXTRACTED);
                $totaltokens += $corpus->estimate_tokens();
                $extracted++;
                $this->log($job, $source, 'success', $corpus->count() . ' blocks');
            } catch (extraction_exception $e) {
                $this->mark_source_failed($source);
                $this->log($job, $source, 'failure', $e->debuginfo ?? 'extraction failed');
                mtrace("local_coursegen: source {$source->id} extraction failed: " . $e->getMessage());
            }
        }

        if ($extracted === 0) {
            $this->fail_job($job, 'no source could be extracted');
            return;
        }
        if ($totaltokens > job_manager::max_corpus_tokens()) {
            $this->fail_job($job, "corpus {$totaltokens} tokens exceeds cap " . job_manager::max_corpus_tokens());
            return;
        }

        $this->set_job_status($job, job_manager::STATUS_EXTRACTED);

        // Wire the pipeline: queue blueprint generation (P2). The user id is
        // carried forward so the reasoning call runs in the right context.
        $next = new generate_blueprint();
        $next->set_custom_data((object) ['jobid' => $job->id]);
        $next->set_userid($this->get_userid() ?: (int) $job->userid);
        \core\task\manager::queue_adhoc_task($next);
    }

    /**
     * Persist a corpus against a source row.
     *
     * @param \stdClass $source The coursegen_source row.
     * @param corpus $corpus The extracted corpus.
     * @param string $status The new source status.
     * @return void
     */
    private function store_corpus(\stdClass $source, corpus $corpus, string $status): void {
        global $DB;
        $source->corpus = $corpus->to_json();
        $source->extractedchars = $corpus->char_count();
        $source->status = $status;
        $source->timemodified = time();
        $DB->update_record('coursegen_source', $source);
    }

    /**
     * Mark a source row failed.
     *
     * @param \stdClass $source The coursegen_source row.
     * @return void
     */
    private function mark_source_failed(\stdClass $source): void {
        global $DB;
        $source->status = job_manager::SOURCE_FAILED;
        $source->timemodified = time();
        $DB->update_record('coursegen_source', $source);
    }

    /**
     * Set the job status and bump timemodified.
     *
     * @param \stdClass $job The coursegen_job row.
     * @param string $status The new status.
     * @return void
     */
    private function set_job_status(\stdClass $job, string $status): void {
        global $DB;
        $job->status = $status;
        $job->timemodified = time();
        $DB->update_record('coursegen_job', $job);
    }

    /**
     * Mark the whole job failed and log why.
     *
     * @param \stdClass $job The coursegen_job row.
     * @param string $reason Non-sensitive reason.
     * @return void
     */
    private function fail_job(\stdClass $job, string $reason): void {
        $this->set_job_status($job, job_manager::STATUS_FAILED);
        $this->log($job, null, 'failure', $reason);
        mtrace("local_coursegen: job {$job->id} failed: {$reason}");
    }

    /**
     * Append an audit-log row for the extract stage.
     *
     * @param \stdClass $job The job.
     * @param \stdClass|null $source The source, if the entry is source-specific.
     * @param string $outcome 'success' or 'failure'.
     * @param string $detail Non-sensitive note (never credentials).
     * @return void
     */
    private function log(\stdClass $job, ?\stdClass $source, string $outcome, string $detail): void {
        global $DB;
        $DB->insert_record('coursegen_log', (object) [
            'jobid' => $job->id,
            'userid' => $this->get_userid() ?: null,
            'stage' => 'extract',
            'tier' => null,
            'actionname' => null,
            'provider' => null,
            'model' => null,
            'tokensin' => null,
            'tokensout' => null,
            'imagecount' => null,
            'estimatedcost' => null,
            'outcome' => $outcome,
            'detail' => $source ? ($source->type . ': ' . $detail) : $detail,
            'timecreated' => time(),
        ]);
    }
}
