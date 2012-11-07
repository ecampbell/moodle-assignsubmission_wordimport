<?php
// This file is part of the submit PDF plugin for Moodle - http://moodle.org/
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
 * This file contains the definition for the library class for pdf
 *  submission plugin
 *
 * This class provides all the functionality for the new assign module.
 *
 * @package   assignsubmission_pdf
 * @copyright 2012 Davo Smith
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot.'/mod/assign/locallib.php');

/**
 * General definitions
 */
define('ASSIGNSUBMISSION_PDF_MAXFILES', 20);
define('ASSIGNSUBMISSION_PDF_MAXSUMMARYFILES', 5);

/**
 * File areas for file submission assignment
 */
define('ASSIGNSUBMISSION_PDF_FA_COVERSHEET', 'submission_pdf_coversheet'); // Coversheet to attach
define('ASSIGNSUBMISSION_PDF_FA_DRAFT', 'submission_pdf_draft'); // Files that have been uploaded but not submitted for marking
define('ASSIGNSUBMISSION_PDF_FA_FINAL', 'submission_pdf_final'); // Generated combined PDF (with coversheet)

define('ASSIGNSUBMISSION_PDF_FILENAME', 'submission.pdf');

function assignsubmission_pdf_pluginfile($course, $cm, context $context, $filearea, $args, $forcedownload, $opts) {
    global $DB, $USER;

    if ($context->contextlevel != CONTEXT_MODULE) {
        return false;
    }

    $submissionid = array_shift($args);
    $submission = $DB->get_record('assign_submission', array('id' => $submissionid));

    if ($submission->assignment != $cm->instance) {
        return false; // Submission does not belong to this assignment.
    }

    if ($USER->id == $submission->userid) {
        // Own submission - check permission to submit.
        if (!has_capability('mod/assign:submit', $context)) {
            return false;
        }
    } else {
        // Another user's submission - check permission to grade.
        if (!has_capability('mod/assign:grade', $context)) {
            return false;
        }
    }

    $filename = array_pop($args);
    $filepath = '/';
    if ($filearea == ASSIGNSUBMISSION_PDF_FA_DRAFT) {
        if ($submission->status == ASSIGN_SUBMISSION_STATUS_SUBMITTED) {
            return false; // Already submitted for marking.
        }
        if (empty($args)) {
            $filepath = '/';
        } else {
            $filepath = '/'.implode('/', $args).'/';
        }
    } else if ($filearea == ASSIGNSUBMISSION_PDF_FA_FINAL) {
        if ($filename != ASSIGNSUBMISSION_PDF_FILENAME || !empty($args)) {
            return false; // Check filename and path (empty)
        }
        if ($submission->status != ASSIGN_SUBMISSION_STATUS_SUBMITTED) {
            return false; // Not submitted for marking.
        }
    } else {
        return false;
    }

    $fs = get_file_storage();
    $file = $fs->get_file($context->id, 'assignsubmission_pdf', $filearea, $submission->id, $filepath, $filename);
    if ($file) {
        send_stored_file($file, 86400, 0, $forcedownload);
    }

    return false;
}