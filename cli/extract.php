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
 * Developer CLI: create an ingestion job from a local file and/or topic and
 * run extraction inline, printing the resulting corpus summary.
 *
 * Example:
 *   php cli/extract.php --file=/path/to/notes.pdf --categoryid=1
 *   php cli/extract.php --topic="Intro to widgets"
 *
 * @package    local_coursegen
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/filelib.php');

use local_coursegen\local\corpus;
use local_coursegen\local\job_manager;
use local_coursegen\task\extract_corpus;

[$options, $unrecognised] = cli_get_params([
    'help' => false,
    'file' => '',
    'topic' => '',
    'categoryid' => 0,
    'userid' => 0,
    'mode' => 'outlinefirst',
], ['h' => 'help']);

if ($options['help'] || ($options['file'] === '' && $options['topic'] === '')) {
    cli_writeln("Create a coursegen ingestion job and run extraction inline.\n");
    cli_writeln("Options:");
    cli_writeln("  --file=PATH       Local source file to ingest (pdf/docx/pptx/txt/md).");
    cli_writeln("  --topic=TEXT      Topic prompt.");
    cli_writeln("  --categoryid=ID   Course category id (default: site default category).");
    cli_writeln("  --userid=ID       Acting user id (default: admin).");
    cli_writeln("  --mode=MODE       outlinefirst | automatic (default: outlinefirst).");
    cli_writeln("  -h, --help        This help.");
    exit(0);
}

$user = $options['userid'] ? core_user::get_user((int) $options['userid'], '*', MUST_EXIST) : get_admin();
\core\cron::setup_user($user);

$categoryid = (int) $options['categoryid'] ?: (int) core_course_category::get_default()->id;
$context = context_coursecat::instance($categoryid);

// If a file was given, place it into the acting user's draft area.
$draftitemid = null;
if ($options['file'] !== '') {
    if (!is_readable($options['file'])) {
        cli_error('Cannot read file: ' . $options['file']);
    }
    $fs = get_file_storage();
    $draftitemid = file_get_unused_draft_itemid();
    $fs->create_file_from_pathname((object) [
        'contextid' => context_user::instance($user->id)->id,
        'component' => 'user',
        'filearea' => 'draft',
        'itemid' => $draftitemid,
        'filepath' => '/',
        'filename' => basename($options['file']),
    ], $options['file']);
}

$jobid = job_manager::create_job(
    $context,
    (int) $user->id,
    $options['mode'],
    $options['topic'] !== '' ? $options['topic'] : null,
    $draftitemid
);
cli_writeln("Created job {$jobid}; running extraction inline...");

// Run extraction now rather than waiting for cron (idempotent with the queued task).
$task = new extract_corpus();
$task->set_custom_data((object) ['jobid' => $jobid]);
$task->set_userid((int) $user->id);
$task->execute();

$job = $DB->get_record('coursegen_job', ['id' => $jobid], '*', MUST_EXIST);
cli_writeln("Job status: {$job->status}");
$sources = $DB->get_records('coursegen_source', ['jobid' => $jobid], 'id ASC');
foreach ($sources as $source) {
    $blocks = corpus::from_json($source->corpus);
    $label = $source->filename ?? '(topic)';
    cli_writeln(sprintf(
        "  [%s] %s: status=%s, blocks=%d, chars=%d",
        $source->type,
        $label,
        $source->status,
        $blocks->count(),
        (int) $source->extractedchars
    ));
}
exit(0);
