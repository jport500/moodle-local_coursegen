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
 * Privacy API provider for local_coursegen.
 *
 * The plugin records who generated what (coursegen_job), the source
 * material they supplied (coursegen_source + uploaded files via the
 * File API), the editable plan they authored (coursegen_blueprint), and
 * a per-stage audit of generation activity (coursegen_log). Jobs are
 * scoped to the category context they were started in; all user data is
 * exported and deleted within those contexts. Credential values are
 * never stored, so none are exported (SPEC §8, CONTEXT.md credential
 * rule).
 *
 * @package    local_coursegen
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider for local_coursegen.
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /** @var string File API component name. */
    private const COMPONENT = 'local_coursegen';

    /** @var string File area holding uploaded source material. */
    private const FILEAREA_SOURCE = 'source';

    /**
     * Describe the personal data stored by this plugin.
     *
     * @param collection $collection The collection to add metadata to.
     * @return collection The updated collection.
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(
            'coursegen_job',
            [
                'userid'         => 'privacy:metadata:coursegen_job:userid',
                'courseid'       => 'privacy:metadata:coursegen_job:courseid',
                'mode'           => 'privacy:metadata:coursegen_job:mode',
                'status'         => 'privacy:metadata:coursegen_job:status',
                'estimatedspend' => 'privacy:metadata:coursegen_job:estimatedspend',
                'actualspend'    => 'privacy:metadata:coursegen_job:actualspend',
                'timecreated'    => 'privacy:metadata:coursegen_job:timecreated',
            ],
            'privacy:metadata:coursegen_job'
        );

        $collection->add_database_table(
            'coursegen_source',
            [
                'type'           => 'privacy:metadata:coursegen_source:type',
                'filename'       => 'privacy:metadata:coursegen_source:filename',
                'itemid'         => 'privacy:metadata:coursegen_source:itemid',
                'extractedchars' => 'privacy:metadata:coursegen_source:extractedchars',
                'corpus'         => 'privacy:metadata:coursegen_source:corpus',
                'status'         => 'privacy:metadata:coursegen_source:status',
                'timecreated'    => 'privacy:metadata:coursegen_source:timecreated',
            ],
            'privacy:metadata:coursegen_source'
        );

        $collection->add_database_table(
            'coursegen_blueprint',
            [
                'title'       => 'privacy:metadata:coursegen_blueprint:title',
                'intro'       => 'privacy:metadata:coursegen_blueprint:intro',
                'content'     => 'privacy:metadata:coursegen_blueprint:content',
                'timecreated' => 'privacy:metadata:coursegen_blueprint:timecreated',
            ],
            'privacy:metadata:coursegen_blueprint'
        );

        $collection->add_database_table(
            'coursegen_log',
            [
                'userid'      => 'privacy:metadata:coursegen_log:userid',
                'stage'       => 'privacy:metadata:coursegen_log:stage',
                'tier'        => 'privacy:metadata:coursegen_log:tier',
                'actionname'  => 'privacy:metadata:coursegen_log:actionname',
                'provider'    => 'privacy:metadata:coursegen_log:provider',
                'model'       => 'privacy:metadata:coursegen_log:model',
                'outcome'     => 'privacy:metadata:coursegen_log:outcome',
                'detail'      => 'privacy:metadata:coursegen_log:detail',
                'timecreated' => 'privacy:metadata:coursegen_log:timecreated',
            ],
            'privacy:metadata:coursegen_log'
        );

        $collection->add_subsystem_link('core_files', [], 'privacy:metadata:filearea_source');

        return $collection;
    }

    /**
     * Return the category contexts that hold data for a user — either
     * because they own a job there or appear in a job's audit log.
     *
     * @param int $userid The user to search.
     * @return contextlist The list of contexts.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        $sql = "SELECT DISTINCT j.contextid
                  FROM {coursegen_job} j
             LEFT JOIN {coursegen_log} l ON l.jobid = j.id AND l.userid = :loguser
                 WHERE j.userid = :owner OR l.userid IS NOT NULL";
        $contextlist->add_from_sql($sql, ['owner' => $userid, 'loguser' => $userid]);

        return $contextlist;
    }

    /**
     * Populate the userlist with users who own a job or appear in a
     * job's audit log within the given (category) context.
     *
     * @param userlist $userlist The userlist to populate.
     * @return void
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if (!$context instanceof \context_coursecat) {
            return;
        }
        $params = ['contextid' => $context->id];

        $userlist->add_from_sql(
            'userid',
            "SELECT userid FROM {coursegen_job} WHERE contextid = :contextid",
            $params
        );
        $userlist->add_from_sql(
            'userid',
            "SELECT l.userid
               FROM {coursegen_log} l
               JOIN {coursegen_job} j ON j.id = l.jobid
              WHERE j.contextid = :contextid AND l.userid IS NOT NULL",
            $params
        );
    }

    /**
     * Export a user's generation data within the approved contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts.
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;
        $user = $contextlist->get_user();

        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_coursecat) {
                continue;
            }

            // Jobs this user owns, with their sources, blueprints and files.
            $jobs = $DB->get_records('coursegen_job', ['contextid' => $context->id, 'userid' => $user->id]);
            foreach ($jobs as $job) {
                $subcontext = [get_string('privacy:subcontext', 'local_coursegen'), 'job-' . $job->id];

                writer::with_context($context)->export_data($subcontext, (object) [
                    'mode'           => $job->mode,
                    'status'         => $job->status,
                    'courseid'       => $job->courseid !== null ? (int) $job->courseid : null,
                    'estimatedspend' => $job->estimatedspend,
                    'actualspend'    => $job->actualspend,
                    'timecreated'    => transform::datetime($job->timecreated),
                ]);

                $sources = $DB->get_records('coursegen_source', ['jobid' => $job->id], 'id ASC');
                if ($sources) {
                    writer::with_context($context)->export_related_data(
                        $subcontext,
                        'sources',
                        array_values(array_map(static function ($source): \stdClass {
                            return (object) [
                                'type'           => $source->type,
                                'filename'       => $source->filename,
                                'extractedchars' => $source->extractedchars !== null ? (int) $source->extractedchars : null,
                                'corpus'         => $source->corpus,
                                'status'         => $source->status,
                                'timecreated'    => transform::datetime($source->timecreated),
                            ];
                        }, $sources))
                    );
                }

                $blueprints = $DB->get_records('coursegen_blueprint', ['jobid' => $job->id], 'version ASC');
                if ($blueprints) {
                    writer::with_context($context)->export_related_data(
                        $subcontext,
                        'blueprints',
                        array_values(array_map(static function ($blueprint): \stdClass {
                            return (object) [
                                'version'     => (int) $blueprint->version,
                                'title'       => $blueprint->title,
                                'intro'       => $blueprint->intro,
                                'content'     => $blueprint->content,
                                'timecreated' => transform::datetime($blueprint->timecreated),
                            ];
                        }, $blueprints))
                    );
                }

                writer::with_context($context)->export_area_files(
                    $subcontext,
                    self::COMPONENT,
                    self::FILEAREA_SOURCE,
                    $job->id
                );
            }

            // Audit-log entries attributed to this user, including in jobs
            // owned by someone else.
            $logs = $DB->get_records('coursegen_log', ['userid' => $user->id], 'timecreated ASC');
            $logsincontext = [];
            foreach ($logs as $log) {
                $job = $DB->get_record('coursegen_job', ['id' => $log->jobid], 'id, contextid');
                if (!$job || (int) $job->contextid !== (int) $context->id) {
                    continue;
                }
                $logsincontext[] = (object) [
                    'jobid'       => (int) $log->jobid,
                    'stage'       => $log->stage,
                    'tier'        => $log->tier,
                    'actionname'  => $log->actionname,
                    'provider'    => $log->provider,
                    'model'       => $log->model,
                    'outcome'     => $log->outcome,
                    'detail'      => $log->detail,
                    'timecreated' => transform::datetime($log->timecreated),
                ];
            }
            if ($logsincontext) {
                writer::with_context($context)->export_data(
                    [get_string('privacy:subcontext', 'local_coursegen'), 'log'],
                    (object) ['entries' => $logsincontext]
                );
            }
        }
    }

    /**
     * Delete all generation data for everyone in a context.
     *
     * @param \context $context The context.
     * @return void
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;
        if (!$context instanceof \context_coursecat) {
            return;
        }
        $jobids = $DB->get_fieldset_select('coursegen_job', 'id', 'contextid = :contextid', ['contextid' => $context->id]);
        self::delete_jobs_and_children($context, $jobids);
    }

    /**
     * Delete a user's generation data within the approved contexts.
     *
     * Jobs the user owns are removed entirely (with their sources,
     * blueprints, logs and files). In jobs owned by others, only the
     * user's own audit-log rows are removed.
     *
     * @param approved_contextlist $contextlist The approved contexts.
     * @return void
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;
        $user = $contextlist->get_user();

        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_coursecat) {
                continue;
            }
            $ownedjobids = $DB->get_fieldset_select(
                'coursegen_job',
                'id',
                'contextid = :contextid AND userid = :userid',
                ['contextid' => $context->id, 'userid' => $user->id]
            );
            self::delete_jobs_and_children($context, $ownedjobids);

            // Remove the user's log rows from jobs they don't own.
            $sql = "jobid IN (SELECT id FROM {coursegen_job} WHERE contextid = :contextid) AND userid = :userid";
            $DB->delete_records_select('coursegen_log', $sql, ['contextid' => $context->id, 'userid' => $user->id]);
        }
    }

    /**
     * Delete data for several users within a single context.
     *
     * @param approved_userlist $userlist The approved users.
     * @return void
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;
        $context = $userlist->get_context();
        if (!$context instanceof \context_coursecat) {
            return;
        }
        $userids = $userlist->get_userids();
        if (empty($userids)) {
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $params['contextid'] = $context->id;

        $ownedjobids = $DB->get_fieldset_select(
            'coursegen_job',
            'id',
            "contextid = :contextid AND userid $insql",
            $params
        );
        self::delete_jobs_and_children($context, $ownedjobids);

        $sql = "jobid IN (SELECT id FROM {coursegen_job} WHERE contextid = :contextid) AND userid $insql";
        $DB->delete_records_select('coursegen_log', $sql, $params);
    }

    /**
     * Delete the given jobs and every child record and file they own.
     *
     * @param \context $context The owning category context.
     * @param int[] $jobids The job ids to delete.
     * @return void
     */
    private static function delete_jobs_and_children(\context $context, array $jobids): void {
        global $DB;
        if (empty($jobids)) {
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal($jobids, SQL_PARAMS_NAMED);

        $DB->delete_records_select('coursegen_log', "jobid $insql", $params);
        $DB->delete_records_select('coursegen_blueprint', "jobid $insql", $params);
        $DB->delete_records_select('coursegen_source', "jobid $insql", $params);

        $fs = get_file_storage();
        foreach ($jobids as $jobid) {
            $fs->delete_area_files($context->id, self::COMPONENT, self::FILEAREA_SOURCE, $jobid);
        }

        $DB->delete_records_select('coursegen_job', "id $insql", $params);
    }
}
