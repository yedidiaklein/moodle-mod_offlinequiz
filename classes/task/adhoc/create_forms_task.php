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
 * Adhoc task for creating offlinequiz forms in the background.
 *
 * @package    mod_offlinequiz
 * @copyright  2025 Academic Moodle Cooperation
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_offlinequiz\task\adhoc;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/offlinequiz/locallib.php');
require_once($CFG->dirroot . '/mod/offlinequiz/pdflib.php');

/**
 * Adhoc task for creating offlinequiz forms in the background.
 *
 * @package    mod_offlinequiz
 * @copyright  2025 Academic Moodle Cooperation
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class create_forms_task extends \core\task\adhoc_task {

    /**
     * Create a new instance of the task.
     *
     * @param int $offlinequizid The offlinequiz ID
     * @param int $userid The user ID who initiated the task
     * @param int $cmid The course module ID
     * @return self
     */
    public static function instance(int $offlinequizid, int $userid, int $cmid): self {
        $task = new self();
        $task->set_custom_data((object) [
            'offlinequizid' => $offlinequizid,
            'userid' => $userid,
            'cmid' => $cmid,
        ]);
        return $task;
    }

    /**
     * Execute the task.
     */
    public function execute() {
        global $DB, $CFG;

        $data = $this->get_custom_data();
        $offlinequizid = $data->offlinequizid;
        $userid = $data->userid;
        $cmid = $data->cmid;

        try {
            // Get the offlinequiz and related data.
            $offlinequiz = $DB->get_record('offlinequiz', ['id' => $offlinequizid], '*', MUST_EXIST);
            $course = $DB->get_record('course', ['id' => $offlinequiz->course], '*', MUST_EXIST);
            $cm = get_coursemodule_from_id('offlinequiz', $cmid, $course->id, false, MUST_EXIST);
            $context = \context_module::instance($cm->id);

            // Get all groups for this offlinequiz.
            $groups = $DB->get_records(
                'offlinequiz_groups',
                ['offlinequizid' => $offlinequiz->id],
                'groupnumber',
                '*',
                0,
                $offlinequiz->numgroups
            );

            if (empty($groups)) {
                throw new \moodle_exception('nogroupsexist', 'mod_offlinequiz');
            }

            // Initialize PDF library and create forms for each group.
            $offlinequiz->docscreated = 1;
            $filenames = [];

            foreach ($groups as $group) {
                if (!$group->templateusageid) {
                    continue;
                }

                $templateusage = \question_engine::load_questions_usage_by_activity($group->templateusageid);

                if (!$templateusage) {
                    throw new \moodle_exception('missingquestions', 'mod_offlinequiz');
                }

                // Create question form.
                if ($offlinequiz->fileformat == OFFLINEQUIZ_DOCX_FORMAT) {
                    require_once($CFG->dirroot . '/mod/offlinequiz/docxlib.php');
                    $questionfile = \offlinequiz_create_docx_question($templateusage, $offlinequiz, $group, $course->id, $context);
                } else if ($offlinequiz->fileformat == OFFLINEQUIZ_LATEX_FORMAT) {
                    require_once($CFG->dirroot . '/mod/offlinequiz/latexlib.php');
                    $questionfile = \offlinequiz_create_latex_question($templateusage, $offlinequiz, $group, $course->id, $context);
                } else {
                    $questionfile = \offlinequiz_create_pdf_question($templateusage, $offlinequiz, $group, $course->id, $context);
                }

                if ($questionfile) {
                    $filenames[] = $questionfile->get_filename();
                    $group->questionfilename = $questionfile->get_filename();
                }

                // Create answer form.
                $maxanswers = \offlinequiz_get_maxanswers($offlinequiz);
                $answerfile = \offlinequiz_create_pdf_answer($maxanswers, $templateusage, $offlinequiz, $group, $course->id, $context);
                if ($answerfile) {
                    $filenames[] = $answerfile->get_filename();
                    $group->answerfilename = $answerfile->get_filename();
                }

                // Create correction form.
                $correctionfile = \offlinequiz_create_pdf_correction($templateusage, $offlinequiz, $group, $course->id, $context);
                if ($correctionfile) {
                    $filenames[] = $correctionfile->get_filename();
                    $group->correctionfilename = $correctionfile->get_filename();
                }

                // Update the group record.
                $DB->update_record('offlinequiz_groups', $group);
            }

            // Update the offlinequiz record.
            $DB->update_record('offlinequiz', $offlinequiz);

            // Send completion notification.
            $this->send_completion_notification($offlinequiz, $course, $userid);

        } catch (\Exception $e) {
            // Send error notification.
            $this->send_error_notification($offlinequizid, $userid, $e->getMessage());
            throw $e;
        }
    }

    /**
     * Send completion notification to the user.
     *
     * @param \stdClass $offlinequiz The offlinequiz object
     * @param \stdClass $course The course object
     * @param int $userid The user ID to notify
     */
    private function send_completion_notification($offlinequiz, $course, $userid) {
        $user = \core_user::get_user($userid);
        if (!$user) {
            return;
        }

        $subject = get_string('offlineformscreatedsubject', 'mod_offlinequiz');
        $message = get_string('offlineformscompletedmessage', 'mod_offlinequiz', $offlinequiz->name);
        
        $downloadurl = new \moodle_url('/mod/offlinequiz/createquiz.php', ['id' => $offlinequiz->id, 'mode' => 'createpdf']);
        $message .= "\n\n" . get_string('offlineformslink', 'mod_offlinequiz', $downloadurl->out());

        $messageobject = new \core\message\message();
        $messageobject->component = 'mod_offlinequiz';
        $messageobject->name = 'formsready';
        $messageobject->userfrom = \core_user::get_noreply_user();
        $messageobject->userto = $user;
        $messageobject->subject = $subject;
        $messageobject->fullmessage = $message;
        $messageobject->fullmessageformat = FORMAT_PLAIN;
        $messageobject->fullmessagehtml = '';
        $messageobject->smallmessage = $subject;
        $messageobject->notification = 1;

        message_send($messageobject);
    }

    /**
     * Send error notification to the user.
     *
     * @param int $offlinequizid The offlinequiz ID
     * @param int $userid The user ID to notify
     * @param string $error The error message
     */
    private function send_error_notification($offlinequizid, $userid, $error) {
        global $DB;

        $user = \core_user::get_user($userid);
        if (!$user) {
            return;
        }

        $offlinequiz = $DB->get_record('offlinequiz', ['id' => $offlinequizid]);
        if (!$offlinequiz) {
            return;
        }

        $subject = get_string('offlineformserrorsubject', 'mod_offlinequiz');
        $message = get_string('offlineformserrormessage', 'mod_offlinequiz', $error);

        $messageobject = new \core\message\message();
        $messageobject->component = 'mod_offlinequiz';
        $messageobject->name = 'formserror';
        $messageobject->userfrom = \core_user::get_noreply_user();
        $messageobject->userto = $user;
        $messageobject->subject = $subject;
        $messageobject->fullmessage = $message;
        $messageobject->fullmessageformat = FORMAT_PLAIN;
        $messageobject->fullmessagehtml = '';
        $messageobject->smallmessage = $subject;
        $messageobject->notification = 1;

        message_send($messageobject);
    }

    /**
     * Get a descriptive name for this task (shown in admin screens).
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('createformsoffline', 'mod_offlinequiz');
    }
}
