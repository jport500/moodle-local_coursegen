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
 * Tests for the category settings navigation entry point.
 *
 * @package    local_coursegen
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen;

use PHPUnit\Framework\Attributes\CoversFunction;

/**
 * The category settings hook adds the create-job link only where the entry point
 * would actually work: on a category, for a user who holds the generate capability.
 */
#[CoversFunction('local_coursegen_extend_navigation_category_settings')]
final class navigation_test extends \advanced_testcase {
    /**
     * A user with local/coursegen:generate gets the link, pointing at the
     * category context the entry point requires.
     *
     * @return void
     */
    public function test_link_added_for_capable_user(): void {
        global $CFG;
        require_once($CFG->dirroot . '/local/coursegen/lib.php');
        $this->resetAfterTest();
        $this->setAdminUser();

        $category = $this->getDataGenerator()->create_category();
        $context = \context_coursecat::instance($category->id);
        $node = \navigation_node::create('Category', null, \navigation_node::TYPE_CATEGORY);

        local_coursegen_extend_navigation_category_settings($node, $context);

        $added = $node->find('local_coursegen_create', \navigation_node::TYPE_SETTING);
        $this->assertNotFalse($added, 'The create-job link was not added for a capable user.');
        $this->assertStringContainsString(
            '/local/coursegen/index.php',
            $added->action()->out(false)
        );
        $this->assertStringContainsString('contextid=' . $context->id, $added->action()->out(false));
    }

    /**
     * A user without the capability gets no link.
     *
     * @return void
     */
    public function test_link_hidden_without_capability(): void {
        global $CFG;
        require_once($CFG->dirroot . '/local/coursegen/lib.php');
        $this->resetAfterTest();

        $category = $this->getDataGenerator()->create_category();
        $context = \context_coursecat::instance($category->id);
        $this->setUser($this->getDataGenerator()->create_user());
        $node = \navigation_node::create('Category', null, \navigation_node::TYPE_CATEGORY);

        local_coursegen_extend_navigation_category_settings($node, $context);

        $this->assertFalse(
            $node->find('local_coursegen_create', \navigation_node::TYPE_SETTING),
            'The create-job link was shown to a user without the generate capability.'
        );
    }
}
