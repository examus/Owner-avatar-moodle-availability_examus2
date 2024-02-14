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
 * Availability plugin for integration with Examus.
 *
 * @package    availability_examus2
 * @copyright  2019-2022 Maksim Burnin <maksim.burnin@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace availability_examus2;
use \html_writer;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/tablelib.php');

/**
 * Displays and filters log entries
 */
class log {
    /**
     * @var array Entries to display
     */
    protected $entries = [];

    /**
     * @var integer Total count of entries
     */
    protected $entriescount = null;

    /**
     * @var integer
     */
    protected $perpage = 30;

    /**
     * @var integer
     */
    protected $page = 0;

    /**
     * @var \flexible_table
     */
    protected $table = null;

    /**
     * @var string URL
     */
    protected $url = null;

    /**
     * @var array list of filters
     */
    protected $filters = null;

    /**
     * Constructor
     * @param array $filters Filters
     * @param integer $page Page
     */
    public function __construct($filters, $page, $url_param_link) {
        global $PAGE;

        $this->url = $PAGE->url;
        $this->filters = $filters;
        $this->page = $page;
        $this->url_param_link = $url_param_link;

        $this->url->params($filters);

        $this->setup_table();
        $this->fetch_data();
    }

    /**
     * Fetches data for log table
     */
    protected function fetch_data() {
        global $DB;
        $select = [
            'e.id id',
            'e.timemodified timemodified',
            'a.timefinish timefinish',
            'timescheduled',
            'u.firstname u_firstname',
            'u.lastname u_lastname',
            'u.email u_email',
            'u.username u_username',
            'u.id userid',
            'e.status status',
            'review_link',
            'archiveurl',
            'cmid',
            'courseid',
            'score',
            'comment',
            'threshold',
        ];

        $where = [];
        $params = $this->filters;

        if (isset($params['from[day]']) && isset($params['from[month]']) && isset($params['from[year]'])) {
            $month = $params['from[month]'];
            $day = $params['from[day]'];
            $year = $params['from[year]'];
            unset($params['from[month]'], $params['from[day]'], $params['from[year]']);

            $params['from'] = mktime(0, 0, 0, $month, $day, $year);
        };

        if (isset($params['to[day]']) && isset($params['to[month]']) && isset($params['to[year]'])) {
            $month = $params['to[month]'];
            $day = $params['to[day]'];
            $year = $params['to[year]'];
            unset($params['to[month]'], $params['to[day]'], $params['to[year]']);

            $params['to'] = mktime(23, 59, 59, $month, $day, $year);
        };

        foreach ($params as $key => $value) {
            if (empty($value)) {
                continue;
            }
            $value = trim($value);
            switch ($key) {
                case 'from':
                    $where[] = 'e.timemodified > :'.$key;
                    break;

                case 'to':
                    $where[] = 'e.timemodified <= :'.$key;
                    break;

                case 'userquery':
                    $params[$key.'1'] = $value.'%';
                    $params[$key.'2'] = $value.'%';

                    $where[] = '(u.email LIKE :'.$key.'1 OR u.username LIKE :'.$key.'2)';
                    break;

                default:
                    $where[] = $key.' = :'.$key;
            }
        }

        $courseids = array_keys($this->get_course_list());
        if (!empty($courseids)) {
            $where[] = 'courseid IN('.implode(',', $courseids).')';
        } else {
            // Always false condition. Can't use FALSE because of mssql.
            $where[] = '1=0';
        }

        $orderby = $this->table->get_sql_sort();

        $limitfrom = ($this->page * $this->perpage);
        $limitnum  = $this->perpage;

        $query = 'SELECT '.implode(', ', $select).' FROM {availability_examus2_entries} e '
               . ' LEFT JOIN {user} u ON u.id=e.userid '
               . ' LEFT JOIN {quiz_attempts} a ON a.id=e.attemptid '
               . (count($where) ? ' WHERE '.implode(' AND ', $where) : '')
               . ' ORDER BY ' . ($orderby ? $orderby : 'id');

        $querycount = 'SELECT count(e.id) as count FROM {availability_examus2_entries} e '
                    . ' LEFT JOIN {user} u ON u.id=e.userid '
                    . ' LEFT JOIN {quiz_attempts} a ON a.id=e.attemptid '
                    . (count($where) ? ' WHERE '.implode(' AND ', $where) : '');

        $this->entries = $DB->get_records_sql($query, $params, $limitfrom, $limitnum);

        $result = $DB->get_records_sql($querycount, $params);
        $this->entriescount = reset($result)->count;

        $this->table->pagesize($this->perpage, $this->entriescount);
    }

    /**
     * Sets up \flexible_table instance
     */
    protected function setup_table() {
        $table = new \flexible_table('availability_examus2_table');

        $table->define_columns([
            'selected', 'timefinish', 'timescheduled', 'u_email',
            'courseid', 'cmid', 'status', 'review_link', 'score', 'details',
            'create_entry',
        ]);

        $table->define_headers([
            "<input class='js-all-checkbox' type='checkbox' name='id' value='" . $entry->id . "'>",
            get_string('time_finish', 'availability_examus2'),
            get_string('time_scheduled', 'availability_examus2'),
            get_string('user'),
            get_string('course'),
            get_string('module', 'availability_examus2'),
            get_string('status', 'availability_examus2'),
            get_string('log_review', 'availability_examus2'),
            get_string('score', 'availability_examus2'),
            '',
            '<input type="button" value="' . get_string('new_entry', 'availability_examus2') . '" class="btn btn-secondary js-new-entry-all-btn" disabled="disabled">'
        ]);
        
        $table->define_baseurl($this->url);
        $table->sortable(true);
        $table->no_sorting('courseid');
        $table->no_sorting('cmid');
        $table->set_attribute('id', 'entries');
        $table->set_attribute('class', 'generaltable generalbox');
        $table->setup();
        $this->table = $table;
    }

    /**
     * Renders and echoes log table
     */
    public function render_table() {
        $entries = $this->entries;
        $table = $this->table;

        if (!empty($entries)) {
            foreach ($entries as $entry) {
                $scheduled = $entry->status == 'scheduled' && $entry->timescheduled;

                $notstarted = $entry->status == 'new' || $scheduled;
                
                $row = [];
                $row[] = "<input class='js-item-checkbox' type='checkbox' name='id' force='" . (bool)$notstarted . "' value='" . $entry->id . "'>";
                $row[] = common::format_date($entry->timefinish);

                if ($entry->timescheduled) {
                    $row[] = common::format_date($entry->timescheduled);
                } else {
                    $row[] = '';
                }

                $row[] = $entry->u_firstname . " " . $entry->u_lastname . "<br>"
                       . $entry->u_username
                       . ' (' . $entry->u_email . ')';

                $course = get_course($entry->courseid);
                $modinfo = get_fast_modinfo($course);
                try {
                    $cm = $modinfo->get_cm($entry->cmid);
                } catch (\moodle_exception $e) {
                    $cm = null;
                }

                $reportlinks = [];
                if ($entry->review_link !== null) {
                     $reportlinks[] = "<a href='" . $entry->review_link . "'>"
                                    . get_string('log_report_link', 'availability_examus2')
                                    . "</a>";
                }
                if ($entry->archiveurl !== null) {
                     $reportlinks[] = "<a href='" . $entry->archiveurl . "'>"
                                    . get_string('log_archive_link', 'availability_examus2')
                                    . "</a>";
                }

                $row[] = $course->fullname;
                $row[] = $cm ? $cm->get_formatted_name() : '';
                $row[] = get_string('status_' . $entry->status, 'availability_examus2');
                $row[] = implode(',&nbsp;', $reportlinks);

                $row[] = $entry->score;

                $detailsurl = new \moodle_url('/availability/condition/examus2/index.php', [
                    'id' => $entry->id,
                    'action' => 'show'
                ]);

                $row[] = '<a href="' . $detailsurl . '">' . get_string('details', 'availability_examus2') . '</a>';

                // Changed condition. Allow to reset all entries.
                // Consequences unknown.
                if (!$notstarted) {
                    $row[] =
                        "<form action='index.php?" . $this->url_param_link . "' method='post'>" .
                           "<input type='hidden' name='id' value='" . $entry->id . "'>" .
                           "<input type='hidden' name='action' value='renew'>" .
                           "<input type='submit' value='" . get_string('new_entry', 'availability_examus2') . "'>".
                        "</form>";
                } else {
                    $row[] =
                        "<form action='index.php?" . $this->url_param_link . "' method='post'>" .
                           "<input type='hidden' name='id' value='" . $entry->id . "'>" .
                           "<input type='hidden' name='force' value='true'>" .
                           "<input type='hidden' name='action' value='renew'>" .
                           "<input type='submit' value='" . get_string('new_entry_force', 'availability_examus2') . "'>".
                        "</form>";
                }
                $table->add_data($row);
            }
            $table->print_html();
        }

    }

    /**
     * Return list of modules to show in selector.
     *
     * @return array list of courses.
     */
    public function get_module_list() {
        global $DB;

        $courses = ['' => 'All modules'];

        if ($courserecords = $DB->get_records("module", null, "fullname", "id,shortname,fullname,category")) {
            foreach ($courserecords as $course) {
                if ($course->id == SITEID) {
                    $courses[$course->id] = format_string($course->fullname) . ' (' . get_string('site') . ')';
                } else {
                    $courses[$course->id] = format_string(get_course_display_name_for_list($course));
                }
            }
        }
        \core_collator::asort($courses);

        return $courses;
    }

    /**
     * Return list of courses to show in selector.
     *
     * @return array list of courses.
     */
    public function get_course_list() {
        global $DB;
        global $USER;

        $courses = [];

        $sitecontext = \context_system::instance();

        if ($courserecords = $DB->get_records("course", null, "fullname", "id,shortname,fullname,category")) {
            foreach ($courserecords as $course) {
                $coursecontext = \context_course::instance($course->id);
                if (!has_capability('availability/examus2:logaccess_all', $sitecontext)) {
                    if (!has_capability('availability/examus2:logaccess_course', $coursecontext)) {
                        continue;
                    }
                }

                if (!is_siteadmin($USER->id)) {
                    if (has_capability('availability/examus2:logaccess_course', $coursecontext) && 
                        !is_enrolled($coursecontext, $USER->id)) {
                        continue;
                    }
                }

                if ($course->id == SITEID) {
                    $courses[$course->id] = format_string($course->fullname) . ' (' . get_string('site') . ')';
                } else {
                    $courses[$course->id] = format_string(get_course_display_name_for_list($course));
                }
            }
        }
        \core_collator::asort($courses);

        return $courses;
    }

    /**
     * Return list of courses to show in selector.
     *
     * @return array list of courses.
     */
    public function get_status_list() {
        $statuses = ['new', 'started', 'unknown', 'accepted', 'rejected', 'force_reset', 'finished', 'scheduled'];
        $statuslist = [];

        foreach ($statuses as $status) {
            $statuslist[$status] = get_string('status_' . $status, 'availability_examus2');
        }

        return $statuslist;
    }

    /**
     * Return list of users.
     *
     * @return array list of users.
     */
    public function get_user_list() {
        global $CFG, $SITE;

        $courseid = $SITE->id;
        if (!empty($this->course)) {
            $courseid = $this->course->id;
        }
        $context = \context_course::instance($courseid);
        $limitfrom = 0;
        $limitnum  = 10000;
        $courseusers = get_enrolled_users($context, '', null, 'u.id, ' . get_all_user_name_fields(true, 'u'),
                null, $limitfrom, $limitnum);

        $users = array();
        if ($courseusers) {
            foreach ($courseusers as $courseuser) {
                $users[$courseuser->id] = fullname($courseuser, has_capability('moodle/site:viewfullnames', $context));
            }
        }
        $users[$CFG->siteguest] = get_string('guestuser');

        return $users;
    }

    /**
     * Return list of date options.
     *
     * @return array date options.
     */
    public function get_date_options() {
        global $SITE;

        $strftimedate = get_string("strftimedate");
        $strftimedaydate = get_string("strftimedaydate");

        // Get all the possible dates.
        // Note that we are keeping track of real (GMT) time and user time.
        // User time is only used in displays - all calcs and passing is GMT.
        $timenow = time(); // GMT.

        // What day is it now for the user, and when is midnight that day (in GMT).
        $timemidnight = usergetmidnight($timenow);

        // Put today up the top of the list.
        $dates = array("$timemidnight" => get_string("today").", ".userdate($timenow, $strftimedate) );

        // If course is empty, get it from frontpage.
        $course = $SITE;
        if (!empty($this->course)) {
            $course = $this->course;
        }
        if (!$course->startdate || ($course->startdate > $timenow)) {
            $course->startdate = $course->timecreated;
        }

        $numdates = 1;
        while ($timemidnight > $course->startdate && $numdates < 365) {
            $timemidnight = $timemidnight - 86400;
            $timenow = $timenow - 86400;
            $dates["$timemidnight"] = userdate($timenow, $strftimedaydate);
            $numdates++;
        }
        return $dates;
    }

    /**
     * Renders end echoes table filters form
     */
    public function render_filter_form() {
        global $OUTPUT;

        $courseid = $this->filters['courseid'];

        $userquery = $this->filters['userquery'];
        $status = $this->filters['status'];

        echo html_writer::start_tag('form', ['class' => 'examus2logselecform', 'action' => $this->url, 'method' => 'get']);
        echo html_writer::start_div();

        // Add course selector.
        $courses = $this->get_course_list();
        $statuses = $this->get_status_list();

        echo html_writer::start_div(null, ['class' => '', 'style' => 'padding: 0 0 0.8rem;']);
        echo html_writer::label(get_string('selectacourse'), 'menuid', false, ['class' => 'accesshide']);
        echo html_writer::select(
            $courses,
            "courseid",
            $courseid,
            get_string('allcourses', 'availability_examus2'),
            ['style' => 'height: 2.5rem;margin-right: 0.5rem']
        );

        // Add user selector.
        echo html_writer::label(get_string('selctauser'), 'menuuser', false, ['class' => 'accesshide']);
        echo html_writer::empty_tag('input', [
            'name' => 'userquery',
            'value' => $userquery,
            'placeholder' => get_string("userquery", 'availability_examus2'),
            'class' => 'form-control',
            'style' => implode(';', [
                'width: auto',
                'clear: none',
                'display: inline-block',
                'vertical-align: middle',
                'font-size:inherit',
                'height: 2.5rem',
                'margin-right: 0.5rem'
            ])
        ]);

        // Add status selector.
        echo html_writer::select(
            $statuses,
            "status",
            $status,
            get_string('allstatuses', 'availability_examus2'),
            ['style' => 'height: 2.5rem;margin-right: 0.5rem']
        );

        // Add date selector.

        // Get the calendar type used - see MDL-18375.
        $calendartype = \core_calendar\type_factory::get_calendar_instance();
        $dateformat = $calendartype->get_date_order(2000, date('Y'));
        // Reverse date element (Day, Month, Year), in RTL mode.
        if (right_to_left()) {
            $dateformat = array_reverse($dateformat);
        }

        echo html_writer::end_div();

        // From date.
        echo html_writer::start_div(null, ['class' => 'fdate_selector', 'style' => 'padding: 0 0 0.8rem;']);

        echo html_writer::label(get_string('fromdate',  'availability_examus2'), '', false, ['style' => 'width: 12%;']);

        foreach ($dateformat as $key => $value) {
            $name = 'from['.$key.']';
            $current = isset($this->filters[$name]) ? $this->filters[$name] : null;

            echo html_writer::select($value, $name, $current, null, ['style' => 'height: 2.5rem;margin-right: 0.5rem']);
        }
        // The YUI2 calendar only supports the gregorian calendar type so only display the calendar image if this is being used.
        if ($calendartype->get_name() === 'gregorian') {
            form_init_date_js();
            echo html_writer::start_tag('a', [
                'href' => '#',
                'title' => get_string('calendar', 'calendar'),
                'class' => 'visibleifjs',
                'name' => 'from[calendar]'
            ]);
            echo $OUTPUT->pix_icon('i/calendar', get_string('calendar', 'calendar') , 'moodle');
            echo html_writer::end_tag('a');
        }
        echo html_writer::end_div();

        // To date.
        echo html_writer::start_div(null, ['class' => 'fdate_selector', 'style' => 'padding: 0 0 0.8rem;']);

        echo html_writer::label(get_string('todate',  'availability_examus2'), '', false, ['style' => 'width: 12%;']);

        foreach ($dateformat as $key => $value) {
            $name = 'to['.$key.']';
            $current = isset($this->filters[$name]) ? $this->filters[$name] : null;

            echo html_writer::select($value, $name, $current, null, ['style' => 'height: 2.5rem;margin-right: 0.5rem']);
        }
        // The YUI2 calendar only supports the gregorian calendar type so only display the calendar image if this is being used.
        if ($calendartype->get_name() === 'gregorian') {
            form_init_date_js();
            echo html_writer::start_tag('a', [
                'href' => '#',
                'title' => get_string('calendar', 'calendar'),
                'class' => 'visibleifjs',
                'name' => 'to[calendar]'
            ]);
            echo $OUTPUT->pix_icon('i/calendar', get_string('calendar', 'calendar') , 'moodle');
            echo html_writer::end_tag('a');
        }
        echo html_writer::end_div();

        echo html_writer::start_div(null, ['class' => 'd-flex', 'style' => 'gap: 10px;']);

        echo html_writer::empty_tag('input', [
            'type' => 'submit',
            'value' => get_string('apply_filter', 'availability_examus2'),
            'class' => 'btn btn-secondary'
        ]);

        echo html_writer::end_div();
        
        echo html_writer::end_div();
        echo html_writer::end_tag('form');
    }
}
