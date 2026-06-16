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
 * Test double for the reasoning text client — returns canned responses.
 *
 * @package    local_coursegen
 * @copyright  2026 LMS Light
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_coursegen\local\ai;

/**
 * Returns queued {@see text_result}s in order so blueprint generation can be
 * tested with no live model call.
 */
class stub_text_client implements text_client {
    /** @var text_result[] Queued responses, consumed in order. */
    private array $responses;

    /** @var int Number of generate() calls made. */
    private int $calls = 0;

    /** @var string[] Prompts received, in order. */
    private array $prompts = [];

    /**
     * Queue the responses to return.
     *
     * @param text_result[] $responses Responses to return, one per call.
     */
    public function __construct(array $responses) {
        $this->responses = array_values($responses);
    }

    /**
     * Return the next queued response (repeats the last if over-called).
     *
     * @param string $prompt The prompt.
     * @param \context $context The context.
     * @param int $userid The user id.
     * @return text_result
     */
    public function generate(string $prompt, \context $context, int $userid): text_result {
        $this->prompts[] = $prompt;
        $response = $this->responses[$this->calls] ?? end($this->responses);
        $this->calls++;
        return $response;
    }

    /**
     * Number of calls made.
     *
     * @return int
     */
    public function call_count(): int {
        return $this->calls;
    }

    /**
     * The prompts received so far.
     *
     * @return string[]
     */
    public function prompts(): array {
        return $this->prompts;
    }
}
