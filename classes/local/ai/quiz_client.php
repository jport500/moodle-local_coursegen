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
 * Seam over delegated quiz-question generation (local_quizgenpro).
 *
 * @package    local_coursegen
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen\local\ai;

/**
 * Generates quiz questions for a section's reading content. The real adapter
 * delegates to local_quizgenpro (D5/D10) — coursegen never generates questions
 * itself; tests inject a stub so no live model is contacted. The materializer
 * then places the mod_quiz and attaches the questions (quizgenpro does not
 * place quizzes). Questions are returned in local_quizgenpro's exporter shape.
 */
interface quiz_client {
    /**
     * Generate questions from reading content.
     *
     * @param string $content The section reading content (plain text).
     * @param int $count Desired number of questions.
     * @param \context $context The course context for the generation call.
     * @return \stdClass[] Exporter-ready question objects; empty on failure or
     *                     when no usable questions were produced.
     */
    public function generate_questions(string $content, int $count, \context $context): array;
}
