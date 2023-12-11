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

require_once('../../../config.php');
require_once($CFG->libdir . "/formslib.php");
require_once($CFG->libdir . '/adminlib.php');

$context = context_system::instance();

require_login();
require_capability('availability/examus2:logaccess', $context);

global $PAGE;

$baseurl = '/availability/condition/examus2/index.php';

$action = optional_param('action', 'index', PARAM_ALPHA);

if ($action == 'renew') {
    $id = required_param('id', PARAM_TEXT);
    $force = optional_param('force', false, PARAM_TEXT);

    if (\availability_examus2\common::reset_entry(['id' => $id], $force)) {
        redirect('index.php', get_string('new_entry_created', 'availability_examus2'),
                 null, \core\output\notification::NOTIFY_SUCCESS);
    } else {
        redirect('index.php', get_string('entry_exist', 'availability_examus2'),
                 null, \core\output\notification::NOTIFY_ERROR);
    }
}

if ($action == 'index') {
    $PAGE->set_url(new \moodle_url($baseurl));
    $PAGE->set_context($context);

    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('pluginname', 'availability_examus2'));
    $filters = [
        'courseid'     => optional_param('courseid', null, PARAM_INT),
        'timemodified' => optional_param('timemodified', null, PARAM_INT),
        'moduleid'     => optional_param('moduleid', null, PARAM_INT),
        'userquery'    => optional_param('userquery', null, PARAM_TEXT),
        'status'       => optional_param('status', null, PARAM_TEXT),
    ];

    $from = optional_param_array('from', ['day' => null, 'month' => null, 'year' => null], PARAM_TEXT);
    $to = optional_param_array('to', ['day' => date('j'), 'month' => date('n'), 'year' => date('Y')], PARAM_TEXT);
    
    
    if ($from['day'] > 0 && $from['month'] > 0 && $from['year'] > 0) {
        $filters = array_merge($filters, [
            'from[day]'     => $from['day'],
            'from[month]'   => $from['month'],
            'from[year]'    => $from['year'],
        ]);
    }
    if ($to['day'] > 0 && $to['month'] > 0 && $to['year'] > 0) {
        $filters = array_merge($filters, [
            'to[day]'     => $to['day'],
            'to[month]'   => $to['month'],
            'to[year]'    => $to['year'],
        ]);
    }

    $page = optional_param('page', 0, PARAM_INT);
    $log = new \availability_examus2\log($filters, $page);
    $log->render_filter_form();
    $log->render_table();
}

if ($action == 'show') {
    $id = required_param('id', PARAM_TEXT);

    $url = new \moodle_url($baseurl, ['action' => $action, 'id' => $id]);
    $PAGE->set_url($url);
    $PAGE->set_context($context);

    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('pluginname', 'availability_examus2'));

    $logdetails = new \availability_examus2\log_details($id, $url);
    $logdetails->render();
}


echo $OUTPUT->footer();
