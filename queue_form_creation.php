<?php
// This file is part of mod_offlinequiz for Moodle - http://moodle.org/
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
 * Handler script for queueing offline form creation tasks.
 *
 * @package    mod_offlinequiz
 * @copyright  2025 Academic Moodle Cooperation
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once("../../config.php");
require_once('locallib.php');

$id = required_param('id', PARAM_INT);    // Course Module ID.
$q = required_param('q', PARAM_INT);      // Offlinequiz ID.

require_sesskey();

list($offlinequiz, $course, $cm) = get_course_objects($id, $q);

require_login($course->id, false, $cm);
$context = context_module::instance($cm->id);

// Check if user has permission to create forms.
if (!has_capability('mod/offlinequiz:createofflinequiz', $context)) {
    throw new \moodle_exception('nopermissions', 'error', '', get_string('createofflinequiz', 'mod_offlinequiz'));
}

// Check if forms already exist or are being created.
if ($offlinequiz->docscreated) {
    redirect(
        new moodle_url('/mod/offlinequiz/createquiz.php', ['q' => $offlinequiz->id, 'mode' => 'createpdfs']),
        get_string('formsalreadyexist', 'mod_offlinequiz'),
        null,
        \core\output\notification::NOTIFY_WARNING
    );
}

// Check if any groups are empty.
$emptygroups = offlinequiz_get_empty_groups($offlinequiz);
if (!empty($emptygroups)) {
    $letterstr = 'ABCDEFGHIJKL';
    $emptygroupletters = [];
    foreach ($emptygroups as $groupnumber) {
        $emptygroupletters[] = $letterstr[$groupnumber - 1];
    }
    $errorstring = get_string('noquestionsfound', 'offlinequiz') . ': ' . implode(', ', $emptygroupletters);
    redirect(
        new moodle_url('/mod/offlinequiz/createquiz.php', ['q' => $offlinequiz->id]),
        $errorstring,
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}

// Queue the adhoc task for background form creation.
$task = \mod_offlinequiz\task\adhoc\create_forms_task::instance(
    $offlinequiz->id,
    $USER->id,
    $cm->id
);

\core\task\manager::queue_adhoc_task($task);

// Notify user that task has been queued.
redirect(
    new moodle_url('/mod/offlinequiz/createquiz.php', ['q' => $offlinequiz->id]),
    get_string('offlineformsqueued', 'mod_offlinequiz'),
    null,
    \core\output\notification::NOTIFY_SUCCESS
);
