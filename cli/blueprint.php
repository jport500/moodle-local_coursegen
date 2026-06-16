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
 * Developer CLI: generate the blueprint for an extracted job and print it.
 * Doubles as the real-transport smoke against the configured reasoning provider.
 *
 * Example: php cli/blueprint.php --jobid=42
 *
 * @package    local_coursegen
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_coursegen\local\blueprint;
use local_coursegen\local\blueprint_generator;
use local_coursegen\local\ai\core_ai_text_client;

[$options, $unrecognised] = cli_get_params([
    'help' => false,
    'jobid' => 0,
], ['h' => 'help']);

if ($options['help'] || !$options['jobid']) {
    cli_writeln("Generate the blueprint for an extracted job (real provider call).\n");
    cli_writeln("  --jobid=ID   The coursegen job id (must be in 'extracted' state).");
    cli_writeln("  -h, --help   This help.");
    exit(0);
}

$job = $DB->get_record('coursegen_job', ['id' => (int) $options['jobid']], '*', MUST_EXIST);
$user = core_user::get_user($job->userid, '*', MUST_EXIST);
\core\cron::setup_user($user);

// Report the provider core_ai will resolve for text generation.
$manager = \core\di::get(\core_ai\manager::class);
$providers = $manager->get_providers_for_actions(['core_ai\\aiactions\\generate_text'], true);
$list = $providers['core_ai\\aiactions\\generate_text'] ?? [];
$resolved = reset($list);
cli_writeln('Resolved text provider: ' . ($resolved ? $resolved->get_name() : '(none enabled!)'));

cli_writeln("Generating blueprint for job {$job->id} (status: {$job->status})...");
$ok = (new blueprint_generator(new core_ai_text_client()))->generate_for_job($job);

$job = $DB->get_record('coursegen_job', ['id' => $job->id], '*', MUST_EXIST);
cli_writeln('Result: ' . ($ok ? 'success' : 'FAILED') . "; job status: {$job->status}");
if (!$ok) {
    exit(1);
}

$record = $DB->get_record('coursegen_blueprint', ['jobid' => $job->id, 'iscurrent' => 1], '*', MUST_EXIST);
$blueprint = blueprint::from_json($record->content);
cli_writeln("Title: {$blueprint->get_title()}");
cli_writeln("Estimate (generation units): " . (int) $job->estimatedspend);
cli_writeln("Sections ({$blueprint->section_count()}):");
foreach ($blueprint->get_sections() as $i => $section) {
    cli_writeln(sprintf(
        '  %d. %s [%s, %d objectives, image=%s, assess=%s]',
        $i + 1,
        $section['title'],
        $section['contenttype'],
        count($section['objectives']),
        !empty($section['image']['generate']) ? 'yes' : 'no',
        $section['assessment']['type']
    ));
}
exit(0);
