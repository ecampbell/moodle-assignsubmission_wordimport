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
require_once($CFG->dirroot . '/mod/assign/locallib.php');
require_once($CFG->dirroot . '/lib/editor/atto/plugins/wordimport/xslemulatexslt.inc');
require_once($CFG->libdir . '/filestorage/file_storage.php');
require_once($CFG->dirroot . '/repository/lib.php');
require_once($CFG->libdir . '/xmlize.php');

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
        $filepath = '/' . implode('/', $args) . '/';
    }

    $fs = get_file_storage();
    $file = $fs->get_file($context->id, 'assignsubmission_word2pdf', $filearea, $itemid, $filepath, $filename);
    if ($file) {
        send_stored_file($file, 86400, 0, $forcedownload);
    }

    return false;
}

/**
 * Extract the WordProcessingML XML files from the .docx file, and use a sequence of XSLT
 * steps to convert it into XHTML
 *
 * @param string $filename name of file uploaded to file repository as a draft
 * @param int $usercontextid ID of draft file area where images should be stored
 * @param int $draftitemid ID of particular group in draft file area where images should be stored
 * @return string XHTML content extracted from Word file
 */
function assignsubmission_word2pdf_convert_to_xhtml($filename, $usercontextid, $draftitemid) {
    global $CFG, $USER;

    $word2xmlstylesheet1 = $CFG->dirroot . '/lib/editor/atto/plugins/wordimport/wordml2xhtmlpass1.xsl'; // WordML to XHTML.
    $word2xmlstylesheet2 = $CFG->dirroot . '/lib/editor/atto/plugins/wordimport/wordml2xhtmlpass2.xsl'; // Clean up XHTML.

    // @codingStandardsIgnoreLine debugging(__FUNCTION__ . ":" . __LINE__ . ": filename = \"{$filename}\"", DEBUG_WORDIMPORT);
    // Check that we can unzip the Word .docx file into its component files.
    $zipres = zip_open($filename);
    if (!is_resource($zipres)) {
        // Cannot unzip file.
        atto_wordimport_debug_unlink($filename);
        throw new moodle_exception('cannotunzipfile', 'error');
    }

    // Check that XSLT is installed.
    if (!class_exists('XSLTProcessor') || !function_exists('xslt_create')) {
        // PHP extension 'xsl' is required for this action.
        throw new moodle_exception(get_string('extensionrequired', 'tool_xmldb', 'xsl'));
    }

    // Uncomment next line to give XSLT as much memory as possible, to enable larger Word files to be imported.
    // @codingStandardsIgnoreLine raise_memory_limit(MEMORY_HUGE);

    if (!file_exists($word2xmlstylesheet1)) {
        // XSLT stylesheet to transform WordML into XHTML is missing.
        throw new moodle_exception('filemissing', 'moodle', $word2xmlstylesheet1);
    }

    // Set common parameters for all XSLT transformations.
    $parameters = array (
        'moodle_language' => current_language(),
        'moodle_textdirection' => (right_to_left()) ? 'rtl' : 'ltr',
        'heading1stylelevel' => get_config('atto_wordimport', 'heading1stylelevel'),
        'pluginname' => 'atto_wordimport', // Include plugin name to control image data handling inside XSLT.
        'debug_flag' => DEBUG_WORDIMPORT
    );

    // Pre-XSLT preparation: merge the WordML and image content from the .docx Word file into one large XML file.
    // Initialise an XML string to use as a wrapper around all the XML files.
    $xmldeclaration = '<?xml version="1.0" encoding="UTF-8"?>';
    $wordmldata = $xmldeclaration . "\n<pass1Container>\n";
    $imagestring = "";

    $fs = get_file_storage();
    // Prepare filerecord array for creating each new image file.
    $fileinfo = array(
        'contextid' => $usercontextid,
        'component' => 'user',
        'filearea' => 'draft',
        'userid' => $USER->id,
        'itemid' => $draftitemid,
        'filepath' => '/',
        'filename' => ''
        );

    $zipentry = zip_read($zipres);
    while ($zipentry) {
        if (!zip_entry_open($zipres, $zipentry, "r")) {
            // Can't read the XML file from the Word .docx file.
            zip_close($zipres);
            throw new moodle_exception('errorunzippingfiles', 'error');
        }

        $zefilename = zip_entry_name($zipentry);
        $zefilesize = zip_entry_filesize($zipentry);

        // Insert internal images into the files table.
        if (strpos($zefilename, "media")) {
            // @codingStandardsIgnoreLine $imageformat = substr($zefilename, strrpos($zefilename, ".") + 1);
            $imagedata = zip_entry_read($zipentry, $zefilesize);
            $imagename = basename($zefilename);
            $imagesuffix = strtolower(substr(strrchr($zefilename, "."), 1));
            // GIF, PNG, JPG and JPEG handled OK, but bmp and other non-Internet formats are not.
            if ($imagesuffix == 'gif' or $imagesuffix == 'png' or $imagesuffix == 'jpg' or $imagesuffix == 'jpeg') {
                // Prepare the file details for storage, ensuring the image name is unique.
                $imagenameunique = $imagename;
                $file = $fs->get_file($usercontextid, 'user', 'draft', $draftitemid, '/', $imagenameunique);
                while ($file) {
                    $imagenameunique = basename($imagename, '.' . $imagesuffix) . '_' . substr(uniqid(), 8, 4) .
                        '.' . $imagesuffix;
                    $file = $fs->get_file($usercontextid, 'user', 'draft', $draftitemid, '/', $imagenameunique);
                }

                $fileinfo['filename'] = $imagenameunique;
                $fs->create_file_from_string($fileinfo, $imagedata);

                $imageurl = "$CFG->wwwroot/draftfile.php/$usercontextid/user/draft/$draftitemid/$imagenameunique";
                // Return all the details of where the file is stored, even though we don't need them at the moment.
                $imagestring .= "<file filename=\"media/{$imagename}\"";
                $imagestring .= " contextid=\"{$usercontextid}\" itemid=\"{$draftitemid}\"";
                $imagestring .= " name=\"{$imagenameunique}\" url=\"{$imageurl}\">{$imageurl}</file>\n";
            // @codingStandardsIgnoreLine } else {
                // @codingStandardsIgnoreLine debugging(__FUNCTION__ . ":" . __LINE__ . ": ignore unsupported media file $zefilename" .
                // @codingStandardsIgnoreLine     " = $imagename, imagesuffix = $imagesuffix", DEBUG_WORDIMPORT);
            }
        } else {
            // Look for required XML files, read and wrap it, remove the XML declaration, and add it to the XML string.
            // Read and wrap XML files, remove the XML declaration, and add them to the XML string.
            $xmlfiledata = preg_replace('/<\?xml version="1.0" ([^>]*)>/', "", zip_entry_read($zipentry, $zefilesize));
            switch ($zefilename) {
                case "word/document.xml":
                    $wordmldata .= "<wordmlContainer>" . $xmlfiledata . "</wordmlContainer>\n";
                    break;
                case "docProps/core.xml":
                    $wordmldata .= "<dublinCore>" . $xmlfiledata . "</dublinCore>\n";
                    break;
                case "docProps/custom.xml":
                    $wordmldata .= "<customProps>" . $xmlfiledata . "</customProps>\n";
                    break;
                case "word/styles.xml":
                    $wordmldata .= "<styleMap>" . $xmlfiledata . "</styleMap>\n";
                    break;
                case "word/_rels/document.xml.rels":
                    $wordmldata .= "<documentLinks>" . $xmlfiledata . "</documentLinks>\n";
                    break;
                case "word/footnotes.xml":
                    $wordmldata .= "<footnotesContainer>" . $xmlfiledata . "</footnotesContainer>\n";
                    break;
                case "word/_rels/footnotes.xml.rels":
                    $wordmldata .= "<footnoteLinks>" . $xmlfiledata . "</footnoteLinks>\n";
                    break;
                // @codingStandardsIgnoreLine case "word/_rels/settings.xml.rels":
                    // @codingStandardsIgnoreLine $wordmldata .= "<settingsLinks>" . $xmlfiledata . "</settingsLinks>\n";
                    // @codingStandardsIgnoreLine break;
                default:
                    // @codingStandardsIgnoreLine debugging(__FUNCTION__ . ":" . __LINE__ . ": Ignore $zefilename", DEBUG_WORDIMPORT);
            }
        }
        // Get the next file in the Zip package.
        $zipentry = zip_read($zipres);
    }  // End while loop.
    zip_close($zipres);

    // Add images section.
    $wordmldata .= "<imagesContainer>\n" . $imagestring . "</imagesContainer>\n";
    // Close the merged XML file.
    $wordmldata .= "</pass1Container>";

    // Pass 1 - convert WordML into linear XHTML.
    // Create a temporary file to store the merged WordML XML content to transform.
    $tempwordmlfilename = $CFG->tempdir . '/' . basename($filename, ".tmp") . ".wml";
    if ((file_put_contents($tempwordmlfilename, $wordmldata)) === 0) {
        // Cannot save the file.
        throw new moodle_exception('cannotsavefile', 'error', $tempwordmlfilename);
    }

    $xsltproc = xslt_create();
    if (!($xsltoutput = xslt_process($xsltproc, $tempwordmlfilename, $word2xmlstylesheet1, null, null, $parameters))) {
        // Transformation failed.
        atto_wordimport_debug_unlink($tempwordmlfilename);
        throw new moodle_exception('transformationfailed', 'atto_wordimport', $tempwordmlfilename);
    }
    atto_wordimport_debug_unlink($tempwordmlfilename);
    // @codingStandardsIgnoreLine debugging(__FUNCTION__ . ":" . __LINE__ . ": Import XSLT Pass 1 succeeded, XHTML output fragment = " .
    // @codingStandardsIgnoreLine     str_replace("\n", "", substr($xsltoutput, 0, 200)), DEBUG_WORDIMPORT);

    // Write output of Pass 1 to a temporary file, for use in Pass 2.
    $tempxhtmlfilename = $CFG->tempdir . '/' . basename($filename, ".tmp") . ".if1";
    $xsltoutput = str_replace('<p xmlns="http://www.w3.org/1999/xhtml"', '<p', $xsltoutput);
    $xsltoutput = str_replace('<span xmlns="http://www.w3.org/1999/xhtml"', '<span', $xsltoutput);
    $xsltoutput = str_replace(' xmlns=""', '', $xsltoutput);
    if ((file_put_contents($tempxhtmlfilename, $xsltoutput )) === 0) {
        // Cannot save the file.
        throw new moodle_exception('cannotsavefile', 'error', $tempxhtmlfilename);
    }

    // Pass 2 - tidy up linear XHTML a bit.
    if (!($xsltoutput = xslt_process($xsltproc, $tempxhtmlfilename, $word2xmlstylesheet2, null, null, $parameters))) {
        // Transformation failed.
        atto_wordimport_debug_unlink($tempxhtmlfilename);
        throw new moodle_exception('transformationfailed', 'atto_wordimport', $tempxhtmlfilename);
    }
    atto_wordimport_debug_unlink($tempxhtmlfilename);

    // Strip out superfluous namespace declarations on paragraph elements, which Moodle 2.7+ on Windows seems to throw in.
    $xsltoutput = str_replace('<p xmlns="http://www.w3.org/1999/xhtml"', '<p', $xsltoutput);
    $xsltoutput = str_replace('<span xmlns="http://www.w3.org/1999/xhtml"', '<span', $xsltoutput);
    $xsltoutput = str_replace(' xmlns=""', '', $xsltoutput);
    // Remove 'mml:' prefix from child MathML element and attributes for compatibility with MathJax.
    $xsltoutput = str_replace('<mml:', '<', $xsltoutput);
    $xsltoutput = str_replace('</mml:', '</', $xsltoutput);
    $xsltoutput = str_replace(' mathvariant="normal"', '', $xsltoutput);
    $xsltoutput = str_replace(' xmlns:mml="http://www.w3.org/1998/Math/MathML"', '', $xsltoutput);
    $xsltoutput = str_replace('<math>', '<math xmlns="http://www.w3.org/1998/Math/MathML">', $xsltoutput);

    // @codingStandardsIgnoreLine debugging(__FUNCTION__ . ":" . __LINE__ . ": Import XSLT Pass 2 succeeded, output = " .
    // @codingStandardsIgnoreLine     str_replace("\n", "", substr($xsltoutput, 500, 2000)), DEBUG_WORDIMPORT);

    // Keep the converted XHTML file for debugging if developer debugging enabled.
    if (DEBUG_WORDIMPORT == DEBUG_DEVELOPER and debugging(null, DEBUG_DEVELOPER)) {
        $tempxhtmlfilename = $CFG->tempdir . '/' . basename($filename, ".tmp") . ".xhtml";
        file_put_contents($tempxhtmlfilename, $xsltoutput);
    }

    return $xsltoutput;
}   // End function atto_wordimport_convert_to_xhtml.


/**
 * Get the HTML body from the converted Word file
 *
 * @param string $xhtmlstring complete XHTML text including head element metadata
 * @return string XHTML text inside <body> element
 */
function assignsubmission_word2pdf_get_html_body($xhtmlstring) {
    $matches = null;
    if (preg_match('/<body[^>]*>(.+)<\/body>/is', $xhtmlstring, $matches)) {
        return $matches[1];
    } else {
        return $xhtmlstring;
    }
}

/**
 * Delete temporary files if debugging disabled
 *
 * @param string $filename name of file to be deleted
 * @return void
 */
function assignsubmission_word2pdf_debug_unlink($filename) {
    if (DEBUG_WORDIMPORT == 0) {
        unlink($filename);
    }
}

