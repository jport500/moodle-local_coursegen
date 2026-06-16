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
            set_config('cap_period_spend', '1000000', 'local_coursegen');
        }
        upgrade_plugin_savepoint(true, 2026061607, 'local', 'coursegen');
    }

    return true;
}
