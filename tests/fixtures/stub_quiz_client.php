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
 * Test double for the quiz client — returns canned questions (no live call).
 *
 * @package    local_coursegen
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen\local\ai;

/**
 * Returns N canned true/false questions in local_quizgenpro's exporter shape,
 * or an empty array to simulate a generation failure.
 */
class stub_quiz_client implements quiz_client {
    /** @var bool Whether to return questions. */
    private bool $succeed;

    /** @var int Number of generate_questions() calls made. */
    private int $calls = 0;

    /**
     * Configure the stub.
     *
     * @param bool $succeed Whether to return questions (false simulates failure/none).
     */
    public function __construct(bool $succeed = true) {
        $this->succeed = $succeed;
    }

    /**
     * Return canned questions (or none).
     *
     * @param string $content The content.
     * @param int $count Desired count.
     * @param \context $context The context.
     * @return \stdClass[]
     */
    public function generate_questions(string $content, int $count, \context $context): array {
        $this->calls++;
        if (!$this->succeed) {
            return [];
        }
        $questions = [];
        for ($i = 1; $i <= max(1, $count); $i++) {
            $questions[] = (object) [
                'text' => "Stub question {$i}?",
                'type' => 'truefalse',
                'options' => [],
                'correctanswer' => 'true',
                'explanation' => 'Because the stub says so.',
            ];
        }
        return $questions;
    }

    /**
     * Number of calls made.
     *
     * @return int
     */
    public function call_count(): int {
        return $this->calls;
    }
}
