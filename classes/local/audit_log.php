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
 * The single writer for the coursegen_log audit trail (SPEC §7, §10.2).
 *
 * @package    local_coursegen
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen\local;

/**
 * Records per-stage / per-call audit rows. The outcome is a REQUIRED argument
 * (no forgiving default), so a step can never be silently logged as a success —
 * the class of bug seen in earlier phases. AI fields (tier/provider/model/
 * tokens/images) are passed in an options array. Credential values are never
 * logged (CONTEXT.md).
 */
class audit_log {
    /** @var string Successful outcome. */
    public const SUCCESS = 'success';

    /** @var string Failed outcome. */
    public const FAILURE = 'failure';

    /**
     * Write an audit row.
     *
     * @param int $jobid The job id.
     * @param int|null $userid The attributable user, if any.
     * @param string $stage Pipeline stage (ingest|extract|blueprint|review|materialize|...).
     * @param string $outcome self::SUCCESS or self::FAILURE — required, never defaulted.
     * @param string $detail Non-sensitive note (never credentials).
     * @param array $ai Optional AI fields: tier, actionname, provider, model,
     *                   tokensin, tokensout, imagecount, estimatedcost.
     * @return int The new coursegen_log row id.
     */
    public static function record(
        int $jobid,
        ?int $userid,
        string $stage,
        string $outcome,
        string $detail,
        array $ai = []
    ): int {
        global $DB;
        return (int) $DB->insert_record('coursegen_log', (object) [
            'jobid' => $jobid,
            'userid' => $userid ?: null,
            'stage' => $stage,
            'tier' => $ai['tier'] ?? null,
            'actionname' => $ai['actionname'] ?? null,
            'provider' => $ai['provider'] ?? null,
            'model' => $ai['model'] ?? null,
            'tokensin' => $ai['tokensin'] ?? null,
            'tokensout' => $ai['tokensout'] ?? null,
            'imagecount' => $ai['imagecount'] ?? null,
            'estimatedcost' => $ai['estimatedcost'] ?? null,
            'outcome' => $outcome,
            'detail' => $detail,
            'timecreated' => time(),
        ]);
    }
}
