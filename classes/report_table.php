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
 * The report that displays issued certificates.
 *
 * @package    mod_customcert
 * @copyright  2016 Mark Nelson <markn@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_customcert;

use customcertelement_expiry\element as expiry_element;

defined('MOODLE_INTERNAL') || die;

global $CFG;

require_once($CFG->libdir . '/tablelib.php');

/**
 * Class for the report that displays issued certificates.
 *
 * @package    mod_customcert
 * @copyright  2016 Mark Nelson <markn@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class report_table extends \table_sql {

    /**
     * @var int $customcertid The custom certificate id
     */
    protected $customcertid;

    /**
     * @var \stdClass $cm The course module.
     */
    protected $cm;

    /**
     * @var bool $groupmode are we in group mode?
     */
    protected $groupmode;

    /**
     * @var array $userCertificateCounts User's certificate count
    */
    protected $userCertificateCounts = [];

    /**
     * @var array userLatestIssueIds  User's latest issue id
     */
    protected $userLatestIssueIds = [];

    /**
     * Sets up the table.
     *
     * @param int $customcertid
     * @param \stdClass $cm the course module
     * @param bool $groupmode are we in group mode?
     * @param string|null $download The file type, null if we are not downloading
     */
    public function __construct($customcertid, $cm, $groupmode, $download = null) {
        parent::__construct('mod_customcert_report_table');

        $context = \context_module::instance($cm->id);
        $extrafields = \core_user\fields::for_identity($context)->get_required_fields();
        $showexpiry = false;

        if (class_exists('\customcertelement_expiry\element')) {
            $showexpiry = expiry_element::has_expiry($customcertid);
        }

        $columns = [];
        $columns[] = 'fullname';
        foreach ($extrafields as $extrafield) {
            $columns[] = $extrafield;
        }
        $columns[] = 'timecreated';

        if ($showexpiry) {
            $columns[] = 'timeexpires';
        }

        $columns[] = 'code';

        $headers = [];
        $headers[] = get_string('fullname');
        foreach ($extrafields as $extrafield) {
            $headers[] = \core_user\fields::get_display_name($extrafield);
        }
        $headers[] = get_string('receiveddate', 'customcert');

        if ($showexpiry) {
            $headers[] = get_string('expireson', 'customcertelement_expiry');
        }

        $headers[] = get_string('code', 'customcert');

        // Check if we were passed a filename, which means we want to download it.
        if ($download) {
            $this->is_downloading($download, 'customcert-report');
        }

        if (!$this->is_downloading()) {
            $columns[] = 'download';
            $headers[] = get_string('file');
        }

        if (!$this->is_downloading() && has_capability('mod/customcert:manage', $context)) {
            $columns[] = 'actions';
            $headers[] = '';
        }

        $this->define_columns($columns);
        $this->define_headers($headers);
        $this->collapsible(false);
        $this->sortable(true);
        $this->no_sorting('code');
        $this->no_sorting('download');
        $this->is_downloadable(true);

        $this->customcertid = $customcertid;
        $this->cm = $cm;
        $this->groupmode = $groupmode;
    }

    /**
     * Generate the fullname column.
     *
     * @param \stdClass $user
     * @return string
     */
    public function col_fullname($user) {
        global $OUTPUT;

        if (!$this->is_downloading()) {
            return $OUTPUT->user_picture($user) . ' ' . fullname($user);
        } else {
            return fullname($user);
        }
    }

    /**
     * Generate the certificate time created column.
     *
     * @param \stdClass $user
     * @return string
     */
    public function col_timecreated($user) {
        if ($this->is_downloading() === '') {
            return userdate($user->timecreated);
        }
        $format = '%Y-%m-%d %H:%M';
        return userdate($user->timecreated, $format);
    }

    /**
     * Generate the optional certificate expires time column.
     *
     * @param \stdClass $user
     * @return string
     */
    public function col_timeexpires($user) {
        if ($this->is_downloading() === '') {
            return expiry_element::get_expiry_html($this->customcertid, $user->id);
        }
        $format = '%Y-%m-%d %H:%M';
        return userdate(expiry_element::get_expiry_date($this->customcertid, $user->id), $format);
    }

    /**
     * Generate the code column.
     *
     * @param \stdClass $user
     * @return string
     */
    public function col_code($user) {
        return $user->code;
    }

    /**
     * Generate the download column.
     *
     * @param \stdClass $user
     * @return string
     */
    public function col_download($user) {
        global $OUTPUT, $CFG;

        $icon = new \pix_icon('download', get_string('download'), 'customcert');

        if (!$this->user_has_multiple_certs($user->id) || $this->is_latest_record($user)) {
            $link = new \moodle_url('/mod/customcert/view.php',
                [
                    'id' => $this->cm->id,
                    'downloadissue' => $user->id,
                ]
            );
            return $OUTPUT->action_link($link, '', null, null, $icon);
        }

        // Custom document expiry link for older records
        require_once($CFG->dirroot . '/local/document_expiry/locallib.php');
        $documentLink = $user->code; // todo get dmsid based on customcert issue id from DMS table
        $context = \context_module::instance($this->cm->id);
        //$dclink = local_document_expiry_get_files($documentLink, $context, false, true);

        if (!empty($dclink)) {
            return html_writer::link(
                new \moodle_url($dclink),
                $OUTPUT->render($icon)
            );
        }

        return '';
    }

    /**
     * Generate the actions column.
     *
     * @param \stdClass $user
     * @return string
     */
    public function col_actions($user) {
        global $OUTPUT;

        $icon = new \pix_icon('i/delete', get_string('delete'));
        $link = new \moodle_url('/mod/customcert/view.php',
            [
                'id' => $this->cm->id,
                'deleteissue' => $user->issueid,
                'sesskey' => sesskey(),
            ]
        );

        return $OUTPUT->action_icon($link, $icon, null, ['class' => 'action-icon delete-icon']);
    }

    /**
     * Query the reader.
     *
     * @param int $pagesize size of page for paginated displayed table.
     * @param bool $useinitialsbar do you want to use the initials bar.
     */
    public function query_db($pagesize, $useinitialsbar = true) {
        global $DB;

        $total = \mod_customcert\certificate::get_number_of_issues($this->customcertid, $this->cm, $this->groupmode);

        $this->pagesize($pagesize, $total);

        $this->rawdata = \mod_customcert\certificate::get_issues($this->customcertid, $this->groupmode, $this->cm,
            $this->get_page_start(), $this->get_page_size(), $this->get_sql_sort());

        // Set initial bars.
        if ($useinitialsbar) {
            $this->initialbars($total > $pagesize);
        }

        // PREMERGENCY CODE
        if(!empty($this->rawdata)) {
            $userids = array_unique(array_column($this->rawdata, 'id'));

            if (!empty($userids)) {
                list($insql, $params) = $DB->get_in_or_equal($userids);
                $params[] = $this->customcertid;

                // Get certificate counts per user
                $sql = "SELECT userid, COUNT(*) as count
                        FROM {customcert_issues}
                        WHERE userid $insql
                        AND customcertid = ?
                        GROUP BY userid";
                $this->userCertificateCounts = $DB->get_records_sql_menu($sql, $params);

                $usersWithMultiple = array_keys(array_filter($this->userCertificateCounts, function($count) {
                    return $count > 1;
                }));

                if (!empty($usersWithMultiple)) {
                    list($multisql, $multiparams) = $DB->get_in_or_equal($usersWithMultiple);
                    $multiparams[] = $this->customcertid;

                    $sql = "SELECT userid, MAX(id) as latestid
                            FROM {customcert_issues}
                            WHERE userid $multisql
                            AND customcertid = ?
                            GROUP BY userid";
                    $this->userLatestIssueIds = $DB->get_records_sql_menu($sql, $multiparams);
                }
            }
        }
    }

    /**
     * Download the data.
     */
    public function download() {
        \core\session\manager::write_close();
        $total = \mod_customcert\certificate::get_number_of_issues($this->customcertid, $this->cm, $this->groupmode);
        $this->out($total, false);
        exit;
    }

    /**
     * Check if user has multiple certificates (uses cached data)
     * (Premergency code)
     */
    public function user_has_multiple_certs($userid) {
        return isset($this->userCertificateCounts[$userid]) &&
            $this->userCertificateCounts[$userid] > 1;
    }

    /**
     * Check if this is the latest record by comparing issue IDs
     * (Premergency code)
     */
    protected function is_latest_record($user) {
        // If user has only one cert, it's automatically the latest
        if (!$this->user_has_multiple_certs($user->id)) {
            return true;
        }

        // Compare issue IDs from cache
        return isset($this->userLatestIssueIds[$user->id]) &&
            $user->issueid == $this->userLatestIssueIds[$user->id];
    }
}
