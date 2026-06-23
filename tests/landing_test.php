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
 * Tests for the course-builder landing page's category gate (Surface 1, D35).
 *
 * @package    local_coursegen
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen;

use local_coursegen\local\job_manager;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The landing page lists exactly the categories the current user may build in.
 */
#[CoversClass(\local_coursegen\local\job_manager::class)]
final class landing_test extends \advanced_testcase {
    /**
     * The list contains only the categories where the user has builder access,
     * keyed by category context id.
     *
     * @return void
     */
    public function test_lists_only_buildable_categories(): void {
        global $DB;
        $this->resetAfterTest();
        $cat1 = $this->getDataGenerator()->create_category();
        $cat2 = $this->getDataGenerator()->create_category();
        $ctx1 = \context_coursecat::instance($cat1->id);
        $ctx2 = \context_coursecat::instance($cat2->id);

        // A user with the builder capability in cat1 only.
        $user = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('local/coursegen:generate', CAP_ALLOW, $roleid, $ctx1->id);
        role_assign($roleid, $user->id, $ctx1->id);
        $this->setUser($user);

        $categories = job_manager::buildable_categories();
        $this->assertArrayHasKey($ctx1->id, $categories);
        $this->assertArrayNotHasKey($ctx2->id, $categories);
    }

    /**
     * A user with builder access nowhere gets an empty list (the landing page
     * renders its empty state, not an error).
     *
     * @return void
     */
    public function test_empty_for_user_with_no_access(): void {
        $this->resetAfterTest();
        $this->getDataGenerator()->create_category();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $this->assertSame([], job_manager::buildable_categories());
    }

    /**
     * An admin (builder access everywhere) sees every category.
     *
     * @return void
     */
    public function test_admin_sees_all_categories(): void {
        $this->resetAfterTest();
        $cat1 = $this->getDataGenerator()->create_category();
        $cat2 = $this->getDataGenerator()->create_category();
        $this->setAdminUser();

        $categories = job_manager::buildable_categories();
        $this->assertArrayHasKey(\context_coursecat::instance($cat1->id)->id, $categories);
        $this->assertArrayHasKey(\context_coursecat::instance($cat2->id)->id, $categories);
    }
}
