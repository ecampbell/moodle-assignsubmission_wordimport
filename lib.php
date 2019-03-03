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
 * Definition of the library class for the Microsoft Word (.docx) file conversion plugin.
 *
 * @package   assignsubmission_word2pdf
 * @copyright 2019 Eoin Campbell
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot.'/mod/assign/locallib.php');

/**
 * General definitions
 */

define('ASSIGNSUBMISSION_WORD2PDF_STATUS_NOTSUBMITTED', 0);
define('ASSIGNSUBMISSION_WORD2PDF_STATUS_SUBMITTED', 1);
define('ASSIGNSUBMISSION_WORD2PDF_STATUS_RESPONDED', 2);
define('ASSIGNSUBMISSION_WORD2PDF_STATUS_EMPTY', 3);

/**
 * File areas for file submission assignment
 */
define('ASSIGNSUBMISSION_WORD2PDF_FA_DRAFT', 'submission_word2pdf_draft'); // Files uploaded but not submitted for marking.
define('ASSIGNSUBMISSION_WORD2PDF_FA_FINAL', 'submission_word2pdf_final'); // Generated combined PDF.

define('ASSIGNSUBMISSION_WORD2PDF_FILENAME', 'submission.pdf');

/**
 * Returns the subplugin information to attach to submission element
 *
 * @param stdClass $course Course object.
 * @param stdClass $cm Course module object.
 * @param context $context context of the assignment.
 * @param string $filearea
 * @param array $args
 * @param bool $forcedownload
 * @return backup_subplugin_element
 */
function assignsubmission_word2pdf_pluginfile($course, $cm, context $context, $filearea, $args, $forcedownload) {
    global $DB, $USER;

    if ($context->contextlevel != CONTEXT_MODULE) {
        return false;
    }

    // Submission file.
    $submissionid = array_shift($args);
    $submission = $DB->get_record('assign_submission', array('id' => $submissionid));

    if ($submission->assignment != $cm->instance) {
        return false; // Submission does not belong to this assignment.
    }

    if (!has_capability('mod/assign:grade', $context)) { // Graders can see all files.
        if (!has_capability('mod/assign:submit', $context)) {
            return false; // Cannot grade or submit => cannot see any files.
        }
        // Can submit, but not grade => see if this file belongs to the user or their group.
        if ($submission->groupid) {
            if (!groups_is_member($submission->groupid)) {
                return false; // Group submission for a group the user doesn't belong to.
            }
        } else if ($USER->id != $submission->userid) {
            return false; // Individual submission for another user.
        }
    }

    $filename = array_pop($args);
    if ($filearea == ASSIGNSUBMISSION_WORD2PDF_FA_DRAFT) {
        if ($submission->status == ASSIGN_SUBMISSION_STATUS_SUBMITTED) {
            return false; // Already submitted for marking.
        }
    } else if ($filearea == ASSIGNSUBMISSION_WORD2PDF_FA_FINAL) {
        if ($filename != ASSIGNSUBMISSION_WORD2PDF_FILENAME) {
            return false; // Check filename.
        }
        if ($submission->status != ASSIGN_SUBMISSION_STATUS_SUBMITTED) {
            return false; // Not submitted for marking.
        }
    } else {
        return false;
    }

    $itemid = $submission->id;

    if (empty($args)) {
        $filepath = '/';
    } else {
        $filepath = '/'.implode('/', $args).'/';
    }

    $fs = get_file_storage();
    $file = $fs->get_file($context->id, 'assignsubmission_word2pdf', $filearea, $itemid, $filepath, $filename);
    if ($file) {
        send_stored_file($file, 86400, 0, $forcedownload);
    }

    return false;
}