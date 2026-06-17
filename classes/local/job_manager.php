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
 * Creation and lifecycle helpers for generation jobs and their sources.
 *
 * @package    local_coursegen
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen\local;

use local_coursegen\local\extractor\factory;
use local_coursegen\task\extract_corpus;

/**
 * Public API for starting an ingestion job: persists the job, moves uploaded
 * files into the permanent 'source' filearea, records each source, enforces
 * the per-job byte limit, and queues asynchronous extraction (SPEC §2, §4).
 */
class job_manager {
    /** @var string File API component. */
    public const COMPONENT = 'local_coursegen';

    /** @var string Filearea holding uploaded source material (matches the privacy provider). */
    public const FILEAREA_SOURCE = 'source';

    /** @var string Job status: created, sources awaiting async extraction. */
    public const STATUS_EXTRACTING = 'extracting';

    /** @var string Job status: corpus ready (P1 end state; P2 moves to blueprinted). */
    public const STATUS_EXTRACTED = 'extracted';

    /** @var string Job status: blueprint generated; a clean pass-through to the gate (P2). */
    public const STATUS_BLUEPRINTED = 'blueprinted';

    /** @var string Job status: outline-first hold for human review of the blueprint (P3). */
    public const STATUS_AWAITING_REVIEW = 'awaiting_review';

    /** @var string Job status: blueprint approved; ready for materialization (P3 end state). */
    public const STATUS_APPROVED = 'approved';

    /** @var string Job status: course is being built (P4). */
    public const STATUS_MATERIALIZING = 'materializing';

    /** @var string Job status: course built; ready for the instructor to publish (P4 end state). */
    public const STATUS_COMPLETE = 'complete';

    /** @var string Job status: a stage failed. */
    public const STATUS_FAILED = 'failed';

    /** @var string Wayfinding phase: the pipeline is running, nothing for the operator to do yet. */
    public const PHASE_PROCESSING = 'processing';

    /** @var string Wayfinding phase: the operator must review and approve the blueprint. */
    public const PHASE_REVIEW = 'review';

    /** @var string Wayfinding phase: the course has been built. */
    public const PHASE_COMPLETE = 'complete';

    /** @var string Wayfinding phase: a stage failed. */
    public const PHASE_FAILED = 'failed';

    /** @var string Source status: awaiting extraction. */
    public const SOURCE_PENDING = 'pending';

    /** @var string Source status: corpus produced. */
    public const SOURCE_EXTRACTED = 'extracted';

    /** @var string Source status: extraction failed. */
    public const SOURCE_FAILED = 'failed';

    /**
     * Create a generation job from uploaded files and/or a topic prompt, then
     * queue extraction. At least one source (a supported file or a non-empty
     * topic) is required.
     *
     * @param \context $context The category context the job is started in.
     * @param int $userid The requesting user.
     * @param string $mode 'outlinefirst' or 'automatic'.
     * @param string|null $topic Optional topic prompt.
     * @param int|null $draftitemid Optional draft area id holding uploaded files.
     * @return int The new job id.
     * @throws \moodle_exception When there is no usable source or limits are exceeded.
     */
    public static function create_job(
        \context $context,
        int $userid,
        string $mode,
        ?string $topic,
        ?int $draftitemid
    ): int {
        global $DB, $CFG;
        require_once($CFG->libdir . '/filelib.php');

        $topic = $topic !== null ? trim($topic) : '';
        $draftfiles = $draftitemid ? self::draft_files($draftitemid) : [];

        if ($topic === '' && $draftfiles === []) {
            throw new \moodle_exception('error_nosource', 'local_coursegen');
        }
        self::check_supported($draftfiles);
        self::check_source_bytes($draftfiles);

        $now = time();
        $job = (object) [
            'userid' => $userid,
            'contextid' => $context->id,
            'courseid' => null,
            'mode' => ($mode === 'automatic') ? 'automatic' : 'outlinefirst',
            'status' => self::STATUS_EXTRACTING,
            'estimatedspend' => null,
            'actualspend' => null,
            'timecreated' => $now,
            'timemodified' => $now,
            'usermodified' => $userid,
        ];
        $job->id = $DB->insert_record('coursegen_job', $job);

        // Move uploaded files into the permanent source area (itemid = job id).
        if ($draftitemid) {
            file_save_draft_area_files(
                $draftitemid,
                $context->id,
                self::COMPONENT,
                self::FILEAREA_SOURCE,
                $job->id
            );
        }

        // One source row per stored file.
        foreach (self::stored_source_files($context, $job->id) as $file) {
            $DB->insert_record('coursegen_source', (object) [
                'jobid' => $job->id,
                'type' => factory::type_for_filename($file->get_filename()),
                'filename' => $file->get_filename(),
                'itemid' => $job->id,
                'extractedchars' => null,
                'corpus' => null,
                'status' => self::SOURCE_PENDING,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
        }

        // Topic-only jobs skip extraction: produce a trivial corpus now.
        if ($topic !== '') {
            $corpus = (new \local_coursegen\local\extractor\text_extractor())->extract_string($topic);
            $DB->insert_record('coursegen_source', (object) [
                'jobid' => $job->id,
                'type' => factory::TYPE_TOPIC,
                'filename' => null,
                'itemid' => $job->id,
                'extractedchars' => $corpus->char_count(),
                'corpus' => $corpus->to_json(),
                'status' => self::SOURCE_EXTRACTED,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
        }

        // Always queue the task; it extracts file sources, enforces the corpus
        // token cap, and finalizes the job status (even for topic-only jobs).
        $task = new extract_corpus();
        $task->set_custom_data((object) ['jobid' => $job->id]);
        $task->set_userid($userid);
        \core\task\manager::queue_adhoc_task($task);

        return (int) $job->id;
    }

    /**
     * The maximum total uploaded source bytes allowed per job.
     *
     * @return int
     */
    public static function max_source_bytes(): int {
        return (int) (get_config('local_coursegen', 'max_source_bytes') ?: 20971520);
    }

    /**
     * The maximum total corpus tokens allowed per job.
     *
     * @return int
     */
    public static function max_corpus_tokens(): int {
        return (int) (get_config('local_coursegen', 'max_corpus_tokens') ?: 200000);
    }

    /**
     * Files currently in a draft area.
     *
     * @param int $draftitemid The draft area id.
     * @return \stored_file[]
     */
    private static function draft_files(int $draftitemid): array {
        global $USER;
        $fs = get_file_storage();
        $context = \context_user::instance($USER->id);
        return array_filter(
            $fs->get_area_files($context->id, 'user', 'draft', $draftitemid, 'filename', false),
            static fn(\stored_file $f): bool => !$f->is_directory()
        );
    }

    /**
     * Permanent source files for a job.
     *
     * @param \context $context The job context.
     * @param int $jobid The job id.
     * @return \stored_file[]
     */
    public static function stored_source_files(\context $context, int $jobid): array {
        $fs = get_file_storage();
        return array_filter(
            $fs->get_area_files($context->id, self::COMPONENT, self::FILEAREA_SOURCE, $jobid, 'filename', false),
            static fn(\stored_file $f): bool => !$f->is_directory()
        );
    }

    /**
     * The wayfinding phase a status belongs to: which kind of job page the
     * operator should land on (PHASE_PROCESSING|PHASE_REVIEW|PHASE_COMPLETE|
     * PHASE_FAILED). Both modes share this map — automatic never stops at
     * awaiting_review, outline-first does.
     *
     * @param string $status A STATUS_* value.
     * @return string A PHASE_* value.
     */
    public static function classify_status(string $status): string {
        return match ($status) {
            self::STATUS_AWAITING_REVIEW => self::PHASE_REVIEW,
            self::STATUS_COMPLETE => self::PHASE_COMPLETE,
            self::STATUS_FAILED => self::PHASE_FAILED,
            default => self::PHASE_PROCESSING,
        };
    }

    /**
     * Jobs in a category context, newest activity first, each with its current
     * blueprint title (null when none yet). Powers the category hub.
     *
     * @param int $contextid The category context id.
     * @return \stdClass[] Rows of (id, status, mode, timecreated, timemodified, courseid, title).
     */
    public static function jobs_in_context(int $contextid): array {
        global $DB;
        return $DB->get_records_sql(
            "SELECT j.id, j.status, j.mode, j.timecreated, j.timemodified, j.courseid, b.title
               FROM {coursegen_job} j
          LEFT JOIN {coursegen_blueprint} b ON b.jobid = j.id AND b.iscurrent = 1
              WHERE j.contextid = :contextid
           ORDER BY j.timemodified DESC, j.id DESC",
            ['contextid' => $contextid]
        );
    }

    /**
     * The most recent failure reason recorded for a job (§10.2 audit log), for
     * surfacing on a failed job page.
     *
     * @param int $jobid The job id.
     * @return string|null The detail, or null if none recorded.
     */
    public static function failure_reason(int $jobid): ?string {
        global $DB;
        $records = $DB->get_records(
            'coursegen_log',
            ['jobid' => $jobid, 'outcome' => audit_log::FAILURE],
            'timecreated DESC, id DESC',
            'id, detail',
            0,
            1
        );
        $row = reset($records);
        return $row ? $row->detail : null;
    }

    /**
     * Reject unsupported file types up front.
     *
     * @param \stored_file[] $files Draft files.
     * @return void
     * @throws \moodle_exception
     */
    private static function check_supported(array $files): void {
        foreach ($files as $file) {
            if (factory::type_for_filename($file->get_filename()) === null) {
                throw new \moodle_exception(
                    'error_unsupportedtype',
                    'local_coursegen',
                    '',
                    $file->get_filename()
                );
            }
        }
    }

    /**
     * Enforce the per-job total source byte cap.
     *
     * @param \stored_file[] $files Draft files.
     * @return void
     * @throws \moodle_exception
     */
    private static function check_source_bytes(array $files): void {
        $total = 0;
        foreach ($files as $file) {
            $total += $file->get_filesize();
        }
        if ($total > self::max_source_bytes()) {
            throw new \moodle_exception(
                'error_sourcetoolarge',
                'local_coursegen',
                '',
                display_size(self::max_source_bytes())
            );
        }
    }
}
