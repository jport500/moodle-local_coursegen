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
 * Per-tenant generation spend governance (SPEC §7, DECISIONS D7, D16).
 *
 * @package    local_coursegen
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen\local;

/**
 * The single source of truth for the spend and image caps. Spend and image
 * usage are accumulated from the §10.2 audit log over a rolling period window
 * (so the "per period" cap actually resets), and a cap of 0 means "no cap".
 * Per-tenant isolation is automatic: each tenant has its own database, so the
 * log it reads is the tenant's own.
 */
class spend_governor {
    /** @var int Default rolling period if unconfigured (days). */
    public const DEFAULT_PERIOD_DAYS = 30;

    /**
     * The spend cap in generation units (0 = no cap).
     *
     * @return int
     */
    public static function spend_cap(): int {
        return (int) (get_config('local_coursegen', 'cap_period_spend') ?: 0);
    }

    /**
     * Generation units spent this period (text token totals from the audit log).
     *
     * @return int
     */
    public static function period_spent(): int {
        global $DB;
        return (int) $DB->get_field_sql(
            'SELECT COALESCE(SUM(COALESCE(tokensin, 0) + COALESCE(tokensout, 0)), 0)
               FROM {coursegen_log} WHERE timecreated >= :start',
            ['start' => self::window_start()]
        );
    }

    /**
     * Whether spend this period has already passed the cap (mid-run guard / a
     * gate for any further AI call).
     *
     * @return bool
     */
    public static function over_spend_cap(): bool {
        $cap = self::spend_cap();
        return $cap > 0 && self::period_spent() > $cap;
    }

    /**
     * Whether adding an estimate would exceed the period cap (pre-check).
     *
     * @param int $estimate The estimate in generation units.
     * @return bool
     */
    public static function would_exceed(int $estimate): bool {
        $cap = self::spend_cap();
        return $cap > 0 && (self::period_spent() + $estimate) > $cap;
    }

    /**
     * Whether adding an estimate would cross the soft-warning threshold.
     *
     * @param int $estimate The estimate in generation units.
     * @return bool
     */
    public static function crosses_warn_threshold(int $estimate): bool {
        $cap = self::spend_cap();
        $pct = (int) (get_config('local_coursegen', 'warn_threshold_pct') ?: 0);
        return $cap > 0 && $pct > 0 && (self::period_spent() + $estimate) >= ($cap * $pct / 100);
    }

    /**
     * Remaining image sub-cap budget this period. A cap of 0 means unlimited.
     *
     * @return int Remaining images, or PHP_INT_MAX when uncapped.
     */
    public static function image_remaining(): int {
        global $DB;
        $cap = (int) (get_config('local_coursegen', 'cap_image_count') ?: 0);
        if ($cap <= 0) {
            return PHP_INT_MAX; // 0 = unlimited.
        }
        $used = (int) $DB->get_field_sql(
            'SELECT COALESCE(SUM(imagecount), 0) FROM {coursegen_log} WHERE timecreated >= :start',
            ['start' => self::window_start()]
        );
        return max(0, $cap - $used);
    }

    /**
     * The start of the current rolling period window.
     *
     * @return int Unix timestamp.
     */
    private static function window_start(): int {
        $days = (int) (get_config('local_coursegen', 'period_days') ?: self::DEFAULT_PERIOD_DAYS);
        $days = max(1, $days);
        return time() - ($days * DAYSECS);
    }
}
