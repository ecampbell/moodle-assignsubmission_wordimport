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
 * This file contains the class for restore of this submission plugin
 *
 * @package assignsubmission_word2pdf
 * @copyright 2019 Eoin Campbell
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * restore subplugin class that provides the necessary information needed to restore one assign_submission subplugin.
 *
 * @package assignsubmission_word2pdf
 * @copyright 2019 Eoin Campbell
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_assignsubmission_word2pdf_subplugin extends restore_subplugin {

    /**
     * Returns the paths to be handled by the subplugin at workshop level
     * @return array
     */
    protected function define_submission_subplugin_structure() {
        $paths = array();

        $elename = $this->get_namefor('submission');
        $elepath = $this->get_pathfor('/submission_word2pdf'); // We used get_recommended_name() so this works.
        $paths[] = new restore_path_element($elename, $elepath);

        $elename = 'assignsubmission_word2pdf_comment';
        $elepath = $this->get_pathfor('/submission_word2pdf/pdfcomments/pdfcomment');
        $paths[] = new restore_path_element($elename, $elepath);

        $elename = 'assignsubmission_word2pdf_annotation';
        $elepath = $this->get_pathfor('/submission_word2pdf/pdfannotations/pdfannotation');
        $paths[] = new restore_path_element($elename, $elepath);

        return $paths; // And we return the interesting paths.
    }

    /**
     * Processes one submission_pdf element
     * @param mixed $data
     * @return void
     */
    public function process_assignsubmission_word2pdf_submission($data) {
        global $DB;

        $data = (object)$data;
        $data->assignment = $this->get_new_parentid('assign');
        $oldsubmissionid = $data->submission;
        // The mapping is set in the restore for the core assign activity. When a submission node is processed.
        $data->submission = $this->get_mappingid('submission', $data->submission);

        $DB->insert_record('assignsubmission_word2pdf', $data);

        $this->add_related_files('assignsubmission_word2pdf', 'submission_word2pdf_draft', 'submission', null, $oldsubmissionid);
        $this->add_related_files('assignsubmission_word2pdf', 'submission_word2pdf_final', 'submission', null, $oldsubmissionid);
    }

}
