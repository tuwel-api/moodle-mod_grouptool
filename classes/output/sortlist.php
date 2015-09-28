<?php
// This file is part of local_checkmarkreport for Moodle - http://moodle.org/
//
// It is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// It is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * A sortable list of course groups including some additional information and fields
 *
 * @package       mod_grouptool
 * @author        Andreas Hruska (andreas.hruska@tuwien.ac.at)
 * @author        Katarzyna Potocka (katarzyna.potocka@tuwien.ac.at)
 * @author        Philipp Hager (office@phager.at)
 * @copyright     2015 Academic Moodle Cooperation {@link http://www.academic-moodle-cooperation.org}
 * @license       http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_grouptool\output;
 
defined('MOODLE_INTERNAL') || die();

class sortlist implements \renderable {

    public $tableclass = 'coloredrows';

    public $groupings = array();

    public $groups = array();

    public $globalsize = 0;

    public $usesize = 0;

    public $useindividual = 0;

    public $filter = null;

    public $cm = null;

    public function __construct($courseid, $cm, $filter=null) {
        global $SESSION, $DB, $OUTPUT;

        $this->filter = $filter;

        if ($moveup = optional_param('moveup', 0, PARAM_INT)) {
            // Move up!
            $a = $DB->get_record('grouptool_agrps', array('groupid' => $moveup,
                                                          'grouptoolid' => $cm->instance));
            $b = $DB->get_record('grouptool_agrps', array('grouptoolid' => $a->grouptoolid,
                                                          'sort_order' => ($a->sort_order - 1)));
            if (empty($a) || empty($b)) {
                echo $OUTPUT->notification(get_string('couldnt_move_up', 'grouptool'), 'notifyproblem');
            } else {
                $DB->set_field('grouptool_agrps', 'sort_order', $a->sort_order, array('id' => $b->id));
                $DB->set_field('grouptool_agrps', 'sort_order', $b->sort_order, array('id' => $a->id));
            }
        }

        if ($movedown = optional_param('movedown', 0, PARAM_INT)) {
            // Move up!
            $a = $DB->get_record('grouptool_agrps', array('groupid' => $movedown,
                                                          'grouptoolid' => $cm->instance));
            $b = $DB->get_record('grouptool_agrps', array('grouptoolid' => $a->grouptoolid,
                                                          'sort_order' => ($a->sort_order + 1)));
            if (empty($a) || empty($b)) {
                echo $OUTPUT->notification(get_string('couldnt_move_down', 'grouptool'), 'notifyproblem');
            } else {
                $DB->set_field('grouptool_agrps', 'sort_order', $a->sort_order, array('id' => $b->id));
                $DB->set_field('grouptool_agrps', 'sort_order', $b->sort_order, array('id' => $a->id));
            }
        }

        if ($courseid != null) {
            $this->loadgroups($courseid, $cm);
            $this->cm = $cm;
            $grouptool = $DB->get_record('grouptool', array('id' => $cm->instance));
            $this->usesize = $grouptool->use_size;
            $this->useindividual = $grouptool->use_individual;
        }

        $this->selected = optional_param_array('selected', null, \PARAM_BOOL);
        if (!isset($SESSION->sortlist)) {
            $SESSION->sortlist = new \stdClass();
        }
        if (!isset($SESSION->sortlist->selected)) {
            $SESSION->sortlist->selected = array();
        }

        if ($this->selected == null) {
            $this->selected = $SESSION->sortlist->selected;
        } else {
            $SESSION->sortlist->selected = $this->selected;
        }
    }

    public function loadgroups($courseid, $cm) {
        global $DB;

        $grouptool = $DB->get_record('grouptool', array('id' => $cm->instance));
        // Prepare agrp-data!
        $coursegroups = groups_get_all_groups($courseid, null, null, "id");
        if (is_array($coursegroups) && !empty($coursegroups)) {
            $groups = array();
            foreach ($coursegroups as $group) {
                $groups[] = $group->id;
            }
            list($grpssql, $params) = $DB->get_in_or_equal($groups);

            if ($this->filter == \mod_grouptool::FILTER_ACTIVE) {
                $activefilter = ' AND active = 1 ';
            } else if ($this->filter == \mod_grouptool::FILTER_INACTIVE) {
                $activefilter = ' AND active = 0 ';
            } else {
                $activefilter = '';
            }
            if (!is_object($cm)) {
                $cm = get_coursemodule_from_id('grouptool', $cm);
            }
            $params = array_merge(array($cm->instance), $params);
            $groupdata = (array)$DB->get_records_sql("
                    SELECT MAX(grp.id) groupid, MAX(agrp.id) id,
                           MAX(agrp.grouptoolid) grouptoolid,  MAX(grp.name) name,
                           MAX(agrp.grpsize) size, MAX(agrp.sort_order) 'order',
                           MAX(agrp.active) status
                    FROM {groups} grp
                    LEFT JOIN {grouptool_agrps} agrp
                         ON agrp.groupid = grp.id AND agrp.grouptoolid = ?
                    WHERE grp.id ".$grpssql.$activefilter."
                    GROUP BY grp.id
                    ORDER BY active DESC, sort_order ASC, name ASC", $params);

            // Convert to multidimensional array and add groupings.
            $runningidx = 1;
            foreach ($groupdata as $key => $group) {
                $groupdata[$key] = $group;
                $groupdata[$key]->selected = 0;
                $groupdata[$key]->sort_order = $runningidx;
                $runningidx++;
                $groupdata[$key]->groupings = $DB->get_records_sql_menu("
                                                    SELECT DISTINCT groupingid, name
                                                      FROM {groupings_groups}
                                                 LEFT JOIN {groupings} ON {groupings_groups}.groupingid = {groupings}.id
                                                     WHERE {groupings}.courseid = ? AND {groupings_groups}.groupid = ?",
                                                                        array($courseid, $group->groupid));
            }
        }

        if (!empty($groupdata) && is_array($groupdata)) {
            $this->globalgrpsize = $grouptool->grpsize ?
                                   $grouptool->grpsize :
                                   get_config('mod_grouptool', 'grpsize');

            foreach ($groupdata as $key => $group) {
                if ($grouptool->use_size && (!$grouptool->use_individual || ($groupdata[$key]->size == null))) {
                    $groupdata[$key]->size = $this->globalgrpsize.'*';
                }

                // Convert to activegroup object!
                $groupdata[$key] = activegroup::construct_from_obj($group);
            }

            $this->groups = $groupdata;

            // Add groupings...
            $this->groupings = $DB->get_records_sql_menu("
                    SELECT DISTINCT groupingid, name
                      FROM {groupings_groups}
                 LEFT JOIN {groupings} ON {groupings_groups}.groupingid = {groupings}.id
                     WHERE {groupings}.courseid = ?
                  ORDER BY name ASC", array($courseid));
        }
    }

    /**
     * compares if two groups are in correct order
     */
    public function cmp($element1, $element2) {
        if ($element1->order == $element2->order) {
            return 0;
        } else {
            return $element1->order > $element2->order ? 1 : -1;
        }
    }

    /**
     * updates the element selected-state if corresponding params are set
     */
    public function _refresh_select_state() {
        global $COURSE;
        $classes = optional_param_array('groupings', array(0), \PARAM_INT);
        $action = optional_param('class_action', 0, \PARAM_ALPHA);
        $gobutton = optional_param('do_class_action', 0, \PARAM_BOOL);

        if (empty($gobutton)) {
            return;
        }

        if ( $groupings == null || count($groupings) == 0 ) {
            return;
        }

        if (!empty($action)) {
            $keys = array();

            $groups = array();
            foreach ($groupings as $groupingid) {
                $groups = array_merge($groups, groups_get_all_groups($COURSE->id, 0, $groupingid));
            }

            foreach ($groups as $current) {
                switch ($action) {
                    case 'select':
                        $sortlist->groups[$current->id]['selected'] = 1;
                        break;
                    case 'deselect':
                        $sortlist->groups[$current->id]['selected'] = 0;
                        break;
                    case 'toggle':
                        $next = !$sortlist->groups[$current->id]['selected'];
                        $sortlist->groups[$current->id]['selected'] = $next;
                        break;
                }
            }
        }
    }
}