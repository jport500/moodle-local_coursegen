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
 * Tests for the spend governor (rolling-period cap accounting, SPEC §7, D16).
 *
 * @package    local_coursegen
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen;

use local_coursegen\local\spend_governor;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Verifies that spend and image usage are totalled over the rolling window, that
 * a cap of 0 means unlimited, and that entries older than the window age out.
 */
#[CoversClass(\local_coursegen\local\spend_governor::class)]
final class spend_governor_test extends \advanced_testcase {
    /**
     * Insert an audit-log row with an explicit age (so window edges can be tested).
     *
     * @param int $tokensin Prompt tokens.
     * @param int $tokensout Completion tokens.
     * @param int $images Image count.
     * @param int $ageseconds How long ago the row was created.
     * @return void
     */
    private function log_row(int $tokensin, int $tokensout, int $images, int $ageseconds): void {
        global $DB;
        $DB->insert_record('coursegen_log', (object) [
            'jobid' => 1,
            'userid' => null,
            'stage' => 'materialize',
            'outcome' => 'success',
            'detail' => 'test',
            'tokensin' => $tokensin,
            'tokensout' => $tokensout,
            'imagecount' => $images,
            'timecreated' => time() - $ageseconds,
        ]);
    }

    /**
     * Spend is summed across token columns within the window; a cap of 0 disables.
     *
     * @return void
     */
    public function test_spend_cap_accounting(): void {
        $this->resetAfterTest();
        set_config('cap_period_spend', '1000', 'local_coursegen');
        set_config('period_days', '30', 'local_coursegen');

        $this->log_row(400, 300, 0, HOURSECS); // 700 units, recent.
        $this->assertEquals(700, spend_governor::period_spent());
        $this->assertFalse(spend_governor::over_spend_cap());
        $this->assertFalse(spend_governor::would_exceed(300));
        $this->assertTrue(spend_governor::would_exceed(301));

        $this->log_row(200, 200, 0, HOURSECS); // Adds 400 → 1100, over.
        $this->assertEquals(1100, spend_governor::period_spent());
        $this->assertTrue(spend_governor::over_spend_cap());

        // A cap of 0 means unlimited.
        set_config('cap_period_spend', '0', 'local_coursegen');
        $this->assertFalse(spend_governor::over_spend_cap());
        $this->assertFalse(spend_governor::would_exceed(PHP_INT_MAX - 1));
    }

    /**
     * Spend older than the rolling window does not count (the cap resets).
     *
     * @return void
     */
    public function test_period_window_resets(): void {
        $this->resetAfterTest();
        set_config('cap_period_spend', '1000', 'local_coursegen');
        set_config('period_days', '30', 'local_coursegen');

        $this->log_row(5000, 5000, 0, 40 * DAYSECS); // 40 days ago — outside the window.
        $this->assertEquals(0, spend_governor::period_spent());
        $this->assertFalse(spend_governor::over_spend_cap());

        $this->log_row(600, 0, 0, HOURSECS); // Inside the window.
        $this->assertEquals(600, spend_governor::period_spent());
    }

    /**
     * The image sub-cap is window-scoped and 0 means unlimited.
     *
     * @return void
     */
    public function test_image_budget(): void {
        $this->resetAfterTest();
        set_config('cap_image_count', '10', 'local_coursegen');
        set_config('period_days', '30', 'local_coursegen');

        $this->log_row(0, 0, 3, HOURSECS);
        $this->log_row(0, 0, 50, 40 * DAYSECS); // Outside window — ignored.
        $this->assertEquals(7, spend_governor::image_remaining());

        set_config('cap_image_count', '0', 'local_coursegen');
        $this->assertEquals(PHP_INT_MAX, spend_governor::image_remaining());
    }
}
