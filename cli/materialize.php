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
 * Developer CLI: materialize an approved job into a hidden course using the
 * live text + image providers. Doubles as the real-transport smoke.
 *
 * Example: php cli/materialize.php --jobid=42
 *
 * @package    local_coursegen
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_coursegen\local\ai\core_ai_image_client;
use local_coursegen\local\ai\core_ai_text_client;
use local_coursegen\local\ai\quizgenpro_quiz_client;
use local_coursegen\local\materializer;

[$options, $unrecognised] = cli_get_params([
    'help' => false,
    'jobid' => 0,
], ['h' => 'help']);

if ($options['help'] || !$options['jobid']) {
    cli_writeln("Materialize an approved job into a hidden course (real provider calls).\n");
    cli_writeln("  --jobid=ID   The coursegen job id (must be 'approved').");
    cli_writeln("  -h, --help   This help.");
    exit(0);
}

$job = $DB->get_record('coursegen_job', ['id' => (int) $options['jobid']], '*', MUST_EXIST);
$user = core_user::get_user($job->userid, '*', MUST_EXIST);
\core\cron::setup_user($user);

cli_writeln("Materializing job {$job->id} (status: {$job->status})...");
$ok = (new materializer(new core_ai_text_client(), new core_ai_image_client(), new quizgenpro_quiz_client()))
    ->materialize($job);

$job = $DB->get_record('coursegen_job', ['id' => $job->id], '*', MUST_EXIST);
cli_writeln('Result: ' . ($ok ? 'success' : 'FAILED') . "; job status: {$job->status}");
if ($job->courseid) {
    $course = $DB->get_record('course', ['id' => $job->courseid]);
    cli_writeln("Course id {$job->courseid}: \"{$course->fullname}\" (visible={$course->visible}, "
        . "format={$course->format}); labels=" . $DB->count_records('label', ['course' => $job->courseid]));
    cli_writeln("Actual spend (generation units): " . (int) $job->actualspend);
}
exit($ok ? 0 : 1);
