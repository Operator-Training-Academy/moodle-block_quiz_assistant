<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Privacy provider for the Quiz assistant block.
 *
 * @package    block_quiz_assistant
 * @copyright  2026 Operator Training Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_quiz_assistant\privacy;

defined('MOODLE_INTERNAL') || die();

/**
 * The block stores no personal data.
 */
class provider implements \core_privacy\local\metadata\null_provider {
    /**
     * Explain why this plugin stores no personal data.
     *
     * @return string
     */
    public static function get_reason(): string {
        return 'privacy:metadata';
    }
}
