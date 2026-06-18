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
 * Upgrade steps for local_coursegen.
 *
 * @package    local_coursegen
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Apply the local_coursegen schema upgrades.
 *
 * @param int $oldversion the version we are upgrading from
 * @return bool
 */
function xmldb_local_coursegen_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026061601) {
        // P1: store the normalized source corpus (ordered blocks JSON) per source.
        $table = new xmldb_table('coursegen_source');
        $field = new xmldb_field('corpus', XMLDB_TYPE_TEXT, null, null, null, null, null, 'extractedchars');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026061601, 'local', 'coursegen');
    }

    if ($oldversion < 2026061607) {
        // P6: the original P0 default spend cap of 50 generation units predated
        // token-scale estimates and silently hard-stops generation on installs
        // that never changed it. Bump it to the current default, but ONLY where
        // the stored value is still exactly the untouched old default — never
        // overwrite a cap an administrator deliberately set.
        if ((string) get_config('local_coursegen', 'cap_period_spend') === '50') {
            set_config('cap_period_spend', '500000', 'local_coursegen');
        }
        upgrade_plugin_savepoint(true, 2026061607, 'local', 'coursegen');
    }

    if ($oldversion < 2026061615) {
        // P14 (D21): the assessment type 'quiz' was a misnomer — it always built a
        // knowledge check. Rewrite stored blueprint JSON 'quiz' -> 'knowledgecheck'
        // so no legacy 'quiz' remains (the normalizer now coerces any other value
        // to 'none', and P15 reclaims 'quiz' for a real graded quiz).
        $rs = $DB->get_recordset('coursegen_blueprint');
        foreach ($rs as $record) {
            $rewritten = \local_coursegen\local\blueprint::rewrite_legacy_assessment_json($record->content);
            if ($rewritten !== null) {
                $DB->set_field('coursegen_blueprint', 'content', $rewritten, ['id' => $record->id]);
            }
        }
        $rs->close();
        upgrade_plugin_savepoint(true, 2026061615, 'local', 'coursegen');
    }

    if ($oldversion < 2026061619) {
        // P18 (D24): the cert/program wrap was removed (credentialing is out of
        // scope for a course builder). Null the orphaned wrap settings.
        unset_config('wrap_muprog', 'local_coursegen');
        unset_config('wrap_mucertify', 'local_coursegen');
        upgrade_plugin_savepoint(true, 2026061619, 'local', 'coursegen');
    }

    if ($oldversion < 2026061700) {
        // P20 (D26): operator-controlled course depth. Add the two create-time
        // params; existing rows take the column DEFAULTs (intermediate / standard).
        $table = new xmldb_table('coursegen_job');
        $audiencelevel = new xmldb_field(
            'audiencelevel',
            XMLDB_TYPE_CHAR,
            '20',
            null,
            XMLDB_NOTNULL,
            null,
            'intermediate',
            'mode'
        );
        if (!$dbman->field_exists($table, $audiencelevel)) {
            $dbman->add_field($table, $audiencelevel);
        }
        $depth = new xmldb_field(
            'depth',
            XMLDB_TYPE_CHAR,
            '20',
            null,
            XMLDB_NOTNULL,
            null,
            'standard',
            'audiencelevel'
        );
        if (!$dbman->field_exists($table, $depth)) {
            $dbman->add_field($table, $depth);
        }
        upgrade_plugin_savepoint(true, 2026061700, 'local', 'coursegen');
    }

    return true;
}
