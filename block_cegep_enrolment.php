<?php

require_once('../../config.php');
require('lib.php');

$courseid = required_param('id', PARAM_INT);
$action   = optional_param('a', null, PARAM_ACTION);

global $CFG, $USER, $COURSE;

// Module unavailable for course id 0 or 1
if ($courseid == 1 || !$COURSE = get_record('course', 'id', $courseid))
    print_error('invalidcourse');

require_login($COURSE);

// Set up and display page header
$strtitle = get_string('enrolment','block_cegep');
$navlinks = array();
$navlinks[] = array('name' => $strtitle, 'link' => null, 'type' => 'misc');
$navigation = build_navigation($navlinks);
print_header("$COURSE->shortname: $strtitle", $COURSE->fullname, $navigation);

// Enrol and unenrol actions not applicable to metacourses
if ($COURSE->metacourse && !empty($action))
    redirect($CFG->wwwroot.'/course/view.php?id='.$COURSE->id, get_string('erroractionnotavailable','block_cegep').'<br /><br />',10);

// This module only available to teachers and admins
$context = get_context_instance(CONTEXT_COURSE, $COURSE->id);
if (isguest() or !has_capability('moodle/course:update', $context)) {
    print_error('mustbeteacher','block_cegep');
}

// Verify if external database enrolment is enabled
if (!in_array('database',explode(',',$CFG->enrol_plugins_enabled)))
    print_error('errorenroldbnotavailable','block_cegep');

// Prepare external enrolment database connection
if ($enroldb = enroldb_connect()) {
    $enroldb->Execute("SET NAMES 'utf8'");
}
else {
    error_log('[ENROL_DB] Could not make a connection');
    print_error('dbconnectionfailed','error');
}

// Prepare external SIS database connection
if ($sisdb = sisdb_connect()) {
    $sisdb->Execute("SET NAMES 'utf8'");
}
else {
    error_log('[SIS_DB] Could not make a connection');
    print_error('dbconnectionfailed','error');
}

// Check how many coursegroups/programs are enrolled at the moment
$select = "SELECT COUNT(DISTINCT `coursegroup_id`) AS num FROM `$CFG->enrol_dbtable` WHERE `$CFG->enrol_remotecoursefield` = '$COURSE->idnumber' AND `$CFG->enrol_db_remoterolefield` = '$CFG->block_cegep_studentrole' AND `coursegroup_id` IS NOT NULL LIMIT 1";
$num_enrolments = $enroldb->Execute($select)->fields['num'];
$select = "SELECT COUNT(DISTINCT `program_idyear`) AS num FROM `$CFG->enrol_dbtable` WHERE `$CFG->enrol_remotecoursefield` = '$COURSE->idnumber' AND `$CFG->enrol_db_remoterolefield` = '$CFG->block_cegep_studentrole' AND `program_idyear` IS NOT NULL LIMIT 1";
$num_programenrolments = $enroldb->Execute($select)->fields['num'];


// Main switch
switch ($action) {
    case 'enrol' :
        cegep_enrol();
        break;
   case 'unenrol' : 
        if ($num_enrolments > 0)
            cegep_unenrol();
        else
            notify(get_string('nocoursegroupsenrolled','block_cegep'));
        break;
     case 'enrolprogram' :
        cegep_enrolprogram();
        break;
    case 'unenrolprogram' : 
        if ($num_programenrolments > 0)
            cegep_unenrolprogram();
        else
            notify(get_string('noprogramsenrolled','block_cegep'));
        break;
    default : 
        cegep_studentlist();
        break;
}

// Enrol a coursegroup into a Moodle course
function cegep_enrol() {
    global $CFG, $COURSE, $enroldb, $sisdb;
    
    $context = get_context_instance(CONTEXT_SYSTEM);

    if (!has_capability('moodle/site:doanything', $context)) { 
        return call_user_func('cegep_restricted_enrol');
    }

    $currenttab = 'enrol';
    require('block_cegep_tabs.php');
    
    // Set up enrolment form
    require('block_cegep_enrol_form.php');
    $enrolform = new cegep_enrol_form('block_cegep_enrolment.php?a=enrol&id='.$COURSE->id.'');

    // If course isn't visible, offer the option
    if (!$COURSE->visible) {
        $enrolform->_form->insertElementBefore($enrolform->_form->createElement('checkbox', 'makevisible', null, get_string('make_visible','block_cegep')),'buttonar');
    }

    // Go back to course page if cancelled
    if ($enrolform->is_cancelled()) {
        redirect($CFG->wwwroot.'/course/view.php?id='.$COURSE->id);
    }
    // Process validated data
    elseif ($data = $enrolform->get_data()) {
   
        // Extract course code info
        if (empty($data->coursecode) || !has_capability('moodle/site:doanything', $context)) { 
            $coursecode = substr($COURSE->idnumber, 0, strripos($COURSE->idnumber, '_'));
        } else {
            $coursecode = $data->coursecode;
        }

        // Extract term info
        $term = $data->year . $data->semester;

        // Fetch record of the coursegroup from SIS
        $select_coursegroup = "SELECT * FROM `$CFG->sisdb_name`.`coursegroup` WHERE `coursecode` = '$coursecode' AND `term` = '$term' AND `group` = '$data->coursegroup'";
        $coursegroup = $sisdb->Execute($select_coursegroup)->fields;

        // Fetch records of students enrolled into this course from SIS
        $select_students = "SELECT * FROM `$CFG->sisdb_name`.`student_enrolment` WHERE `coursegroup_id` = '$coursegroup[id]'";
        $students_rs = $sisdb->Execute($select_students);
        
        if (empty($coursegroup) || !$students_rs) {
            print_error('errorimportingstudentlist','block_cegep');
        }

        $context = get_context_instance(CONTEXT_COURSE, $COURSE->id);
        $student_role = get_record('role','shortname',$CFG->block_cegep_studentrole);

        // Go through each student and insert Moodle external enrolment database record
        $studentlist = '';
        while ($students_rs && !$students_rs->EOF) {
            $student = $students_rs->fields;
            $insert = "INSERT INTO `$CFG->enrol_dbtable` (`$CFG->enrol_remotecoursefield` , `$CFG->enrol_remoteuserfield`, `$CFG->enrol_db_remoterolefield`, `coursegroup_id`) VALUES ('$COURSE->idnumber', '$student[username]', '$CFG->block_cegep_studentrole', '$coursegroup[id]');";
            $result = $enroldb->Execute($insert);
            if (!$result) {
                trigger_error(get_string('errorimportingstudentlist','block_cegep'), E_USER_ERROR);
                break;
            } else {
                $studentlist .= $student['username'].'<br />';
            }
            // If user exists in database, assign its role right away
            if ($student_user = get_record('user', 'username', $student['username'])) {
                role_assign($student_role->id, $student_user->id, 0, $context->id);
            }
            $students_rs->MoveNext();
        }

        if (!$COURSE->visible && $data->makevisible) {
            $COURSE->visible = 1;
            update_record('course',$COURSE);
        }
        
        // Display nice confirmation with student list and buttons
        notify(get_string('coursegroupenrolled','block_cegep',array($studentlist)),'notifysuccess');
        echo "<div class='continuebutton'>";
        print_single_button('block_cegep_enrolment.php', array('a' => 'enrol', 'id' => $COURSE->id), get_string('enrolanother','block_cegep'));
        echo "</div><br />";
        echo "<div class='continuebutton'>";
        print_single_button($CFG->wwwroot.'/course/view.php', array('id' => $COURSE->id), get_string('continuetocourse'));
        echo "</div>";
    }
    // Display the enrolment form
    else {
        print_heading(get_string('enrol','block_cegep'), 'center', 3);
        $enrolform->display();
    }
}

/* 'cegep_restricted_enrol'
 *
 * This function is aimed at teachers enrolling a section into
 * a course. It restricts them to enrolling sections
 * that the DB says they are teaching.
 *
 * TODO: This function is dawson-specific right now. Make it generic.
 */
function cegep_restricted_enrol() {
  global $CFG, $COURSE, $enroldb, $sisdb, $USER, $ME, $courseid;

  $currenttab = 'enrol';
  require('block_cegep_tabs.php');

  $course_to_enrol = optional_param('coursenumber', null, PARAM_ALPHANUM);
  $term_to_enrol = optional_param('session', null, PARAM_ALPHANUM);
  $section_to_enrol = optional_param('sectionid', null, PARAM_ALPHANUM);

    if ($course_to_enrol && $term_to_enrol && $section_to_enrol) {

        $coursecode = $course_to_enrol;

        echo get_string('coursecode', 'block_cegep') . $coursecode;

        $section = $section_to_enrol;

        if ($CFG->block_cegep_autogroups == true) {
            /* Check if a group already exists for this course */
            $group = get_record("groups", "courseid", $COURSE->id, "name", "Section " . $section);

            if ($group) {
                $groupid = $group->id;
            }
            else {
                $groupdata->name = "Section " . $section;
                $groupdata = new stdClass();
                $groupdata->name = "Section " . $section;
                $groupdata->description = '';
                $groupdata->enrolmentkey = '';
                $groupdata->hidepicture = 0;
                $groupdata->id = 0;
                $groupdata->courseid = $COURSE->id;
                $groupdata->submitbutton = "Save changes";
                $groupdata->timecreated = time();
                $groupdata->timemodified = $groupdata->timecreated;
                $groupid = insert_record('groups', $groupdata);
            }
        }
        
        // Extract term info
        $term = $term_to_enrol;

        $select_coursegroup = "SELECT * FROM `$CFG->sisdb_name`.`coursegroup` WHERE `coursecode` = '$coursecode' AND `term` = '$term' AND `group` = '". $section ."'";

        $coursegroup = $sisdb->Execute($select_coursegroup)->fields;

        // Fetch records of students enrolled into this course from SIS
        $select_students = "SELECT * FROM `$CFG->sisdb_name`.`student_enrolment` WHERE `coursegroup_id` = '$coursegroup[id]'";


        $students_rs = $sisdb->Execute($select_students);

        if (empty($coursegroup) || !$students_rs) {
            print_error('erroremptygroupindatabase','block_cegep');
        }

        $context = get_context_instance(CONTEXT_COURSE, $COURSE->id);
        $student_role = get_record('role','shortname',$CFG->block_cegep_studentrole);

        // Go through each student and insert Moodle external enrolment database record
        $studentlist = '';
        while ($students_rs && !$students_rs->EOF) {
            $student = $students_rs->fields;
            $insert = "INSERT ignore INTO `$CFG->enrol_dbtable` (`$CFG->enrol_remotecoursefield` , `$CFG->enrol_remoteuserfield`, `$CFG->enrol_db_remoterolefield`, `coursegroup_id`) VALUES ('$COURSE->idnumber', '$student[username]', '$CFG->block_cegep_studentrole', '$coursegroup[id]');";
            $result = $enroldb->Execute($insert);
            if (!$result) {
                trigger_error(get_string('errorimportingstudentlist','block_cegep'), E_USER_ERROR);
                break;
            } else {
                $studentlist .= $student['username'].'<br />';
            }
            // If user exists in database, assign its role right away
            if ($student_user = get_record('user', 'username', $student['username'])) {
                role_assign($student_role->id, $student_user->id, 0, $context->id);
                if ($CFG->block_cegep_autogroups == true && !empty($groupid)) {
                    groups_add_member($groupid, $student_user->id);
                }
            }
            $students_rs->MoveNext();
        }

        // Display nice confirmation with student list and buttons
        notify(get_string('coursegroupenrolled','block_cegep',array($studentlist)),'notifysuccess');
        echo "<div class='continuebutton'>";
        print_single_button('block_cegep_enrolment.php', array('a' => 'enrol', 'id' => $COURSE->id), get_string('enrolanother','block_cegep'));
        echo "</div><br />";
        echo "<div class='continuebutton'>";
        print_single_button($CFG->wwwroot.'/course/view.php', array('id' => $COURSE->id), get_string('continuetocourse'));
        echo "</div>";
    }

    $coursecode = substr($COURSE->idnumber, 0, strpos($COURSE->idnumber, '_'));
    
    $term = cegep_local_date_to_datecode();

    $select = "
        SELECT
            cg.coursecode,
            cg.group,
            cg.term, 
        FROM teacher_enrolment te
        LEFT JOIN coursegroup cg ON cg.id = te.coursegroup_id
        LEFT JOIN course c ON c.coursecode = cg.coursecode
        WHERE 
            te.idnumber = '$USER->idnumber' AND
            cg.term >= '$term' AND
            cg.coursecode = '$coursecode'
        ORDER BY
            cg.term, cg.coursecode, cg.group
    ";

    $sisdb_rs = $sisdb->Execute($select); 

    $terms_coursegroups = array();
    $terms_coursegroups = cegep_local_get_enrolled_sections();

    echo '<div class="help">' . get_string('enrolhelp', 'block_cegep') . '</div>';

    while ($sisdb_rs && !$sisdb_rs->EOF) {
        $row = $sisdb_rs->fields;

        if (is_numeric($row['group']) && $row['group'] < 10) {
            $coursegroup = sprintf("%02d", $row['group']);
        }
        else {
            $coursegroup = $row['group'];
        }

        // TODO: skip any section that is already enrolled somewhere.
        echo '<form action="'. $ME .'" method="POST">'.
          '<div class="coursegroup-enrol-list">'.
          '<span class="coursegrouprow">'.
          '<input type="hidden" name="coursenumber" value="'. $row['coursecode'] .'" />'.
          '<input type="hidden" name="session" value="' . $row['term'] . '" />'.
          '<input type="hidden" name="sectionid" value=" ' . $coursegroup . '" />'.
          '<input type="hidden" name="a" value="enrol" />'.
          '<input type="hidden" name="id" value="'. $courseid .'" />'.
          'Session <b>'. $row['term'] .'</b>, section <b>' . $coursegroup . '</b>';

        if (!is_array($terms_coursegroups) || !in_array($term, $terms_coursegroups)) {
            if (!in_array($coursegroup, $terms_coursegroups[$term])) {
                echo '<input type="submit" name="submit" value="' . get_string('enrolsection', 'block_cegep') . '" />';
            }
            else {
                echo '<em>' . get_string('sectionalreadyenrolled', 'block_cegep') . '</em>';
            }
        }
        else {
            echo '<em>' . get_string('sectionalreadyenrolled', 'block_cegep') . '</em>';
        }
        echo '</span>' . '</div>' . '</form>';
        $sisdb_rs->moveNext();
    }
    $sisdb->Close();
}

// Unenrol a coursegroup from a Moodle course
function cegep_unenrol() {
    global $CFG, $COURSE, $enroldb;
    
    $currenttab = 'unenrol';
    require('block_cegep_tabs.php');
    
    // Set up enrolment form
    require('block_cegep_unenrol_form.php');
    $unenrolform = new cegep_unenrol_form('block_cegep_enrolment.php?a=unenrol&id='.$COURSE->id.'');

    // Go back to course page if cancelled
    if ($unenrolform->is_cancelled()) {
        redirect($CFG->wwwroot.'/course/view.php?id='.$COURSE->id);
    }
    // Process validated data
    elseif ($data = $unenrolform->get_data()) {

        $coursegroup_list = implode(', ', $data->coursegroup);

        // Get usernames before removing
        $select = "SELECT `$CFG->enrol_remoteuserfield` FROM `$CFG->enrol_dbname`.`$CFG->enrol_dbtable` WHERE `$CFG->enrol_remotecoursefield` = '$COURSE->idnumber' AND `$CFG->enrol_db_remoterolefield` = '$CFG->block_cegep_studentrole' AND `coursegroup_id` IN ($coursegroup_list);";

        $usernames = recordset_to_array($enroldb->Execute($select));

        // If user exists, unassign role right away
        if ($usernames) {
            $context = get_context_instance(CONTEXT_COURSE, $COURSE->id);
            $student_role = get_record('role','shortname',$CFG->block_cegep_studentrole);
            foreach ($usernames as $username) {
                if ($student_user = get_record('user', 'username', $username->username)) {
                    role_unassign($student_role->id, $student_user->id, 0, $context->id);
                }
            }
        }

        // Go through each coursegroup and remove Moodle external enrolment database record
        $delete = "DELETE FROM `$CFG->enrol_dbtable` WHERE `$CFG->enrol_remotecoursefield` = '$COURSE->idnumber' AND `$CFG->enrol_db_remoterolefield` = '$CFG->block_cegep_studentrole' AND `coursegroup_id` IN ($coursegroup_list);";
        
        $result = $enroldb->Execute($delete);
        
        if (!$result) {
            trigger_error($enroldb->ErrorMsg() .' STATEMENT: '. $delete);
            print_error('errordeletingenrolment','block_cegep');
            break;
        } else {
            notify(get_string('coursegroupunenrolled','block_cegep',array($enroldb->Affected_Rows())),'notifysuccess');
            echo "<div class='continuebutton'>";
            print_single_button($CFG->wwwroot.'/course/view.php', array('id' => $COURSE->id), get_string('continuetocourse'));
            echo "</div>";
        }       
    }
    // Display the enrolment form
    else {
        print_heading(get_string('unenrol','block_cegep'), 'center', 3);
        $unenrolform->display();
    }
}

// Enrol a program into a Moodle course
function cegep_enrolprogram() {
    global $CFG, $COURSE, $enroldb, $sisdb;

    $currenttab = 'enrolprogram';
    require('block_cegep_tabs.php');
    
    // Set up enrolment form
    require('block_cegep_enrolprogram_form.php');
    $enrolprogramform = new cegep_enrolprogram_form('block_cegep_enrolment.php?a=enrolprogram&id='.$COURSE->id.'');

    // Go back to course page if cancelled
    if ($enrolprogramform->is_cancelled()) {
        redirect($CFG->wwwroot.'/course/view.php?id='.$COURSE->id);
    }
    // Process validated data
    elseif ($data = $enrolprogramform->get_data()) {

        $program_idyear = $data->program_id . '_' . $data->program_year;
   
        // Fetch records of students enrolled into this program/year from SISDB 
        $select_students = "SELECT * FROM `$CFG->sisdb_name`.`student` WHERE `program_id` = '".$data->program_id."' AND program_year = '".$data->program_year."';";
        $students_rs = $sisdb->Execute($select_students);
        
        if (!$students_rs) {
            print_error('errorimportingstudentlist','block_cegep');
        }

        $context = get_context_instance(CONTEXT_COURSE, $COURSE->id);
        $student_role = get_record('role','shortname',$CFG->block_cegep_studentrole);

        // Go through each student and insert Moodle external enrolment database record
        $studentlist = '';
        while ($students_rs && !$students_rs->EOF) {
            $student = $students_rs->fields;
            $insert = "INSERT INTO `$CFG->enrol_dbtable` (`$CFG->enrol_remotecoursefield` , `$CFG->enrol_remoteuserfield`, `$CFG->enrol_db_remoterolefield`, `coursegroup_id`, `program_idyear`) VALUES ('$COURSE->idnumber', '$student[username]', '$CFG->block_cegep_studentrole', NULL, '$program_idyear');";
            $result = $enroldb->Execute($insert);
            if (!$result) {
                echo $insert;
                trigger_error(get_string('errorimportingstudentlist','block_cegep'), E_USER_ERROR);
                break;
            } else {
                $studentlist .= $student['username'].'<br />';
            }
            // If user exists in database, assign its role right away
            if ($student_user = get_record('user', 'username', $student['username'])) {
                role_assign($student_role->id, $student_user->id, 0, $context->id);
            }
            $students_rs->MoveNext();
        }
        
        // Display nice confirmation with student list and buttons
        notify(get_string('programenrolled','block_cegep'),'notifysuccess');
        echo "<div class='continuebutton'>";
        print_single_button($CFG->wwwroot.'/course/view.php', array('id' => $COURSE->id), get_string('continuetocourse'));
        echo "</div>";
    }
    // Display the enrolment form
    else {
        print_heading(get_string('enrolprogram','block_cegep'), 'center', 3);
        $enrolprogramform->display();
    }
}

// Unenrol a program from a Moodle course
function cegep_unenrolprogram() {
    global $CFG, $COURSE, $enroldb;
    
    $currenttab = 'unenrolprogram';
    require('block_cegep_tabs.php');
    
    // Set up enrolment form
    require('block_cegep_unenrolprogram_form.php');
    $unenrolprogramform = new cegep_unenrolprogram_form('block_cegep_enrolment.php?a=unenrolprogram&id='.$COURSE->id.'');

    // Go back to course page if cancelled
    if ($unenrolprogramform->is_cancelled()) {
        redirect($CFG->wwwroot.'/course/view.php?id='.$COURSE->id);
    }
    // Process validated data
    elseif ($data = $unenrolprogramform->get_data()) {

        foreach ($data->program as $p) {
            $program_list = "'$p',";
        }
        $program_list = rtrim($program_list, ',');

        // Get usernames before removing
        $select = "SELECT `$CFG->enrol_remoteuserfield` FROM `$CFG->enrol_dbname`.`$CFG->enrol_dbtable` WHERE `$CFG->enrol_remotecoursefield` = '$COURSE->idnumber' AND `$CFG->enrol_db_remoterolefield` = '$CFG->block_cegep_studentrole' AND `program_idyear` IN ($program_list);";

        $usernames = recordset_to_array($enroldb->Execute($select));

        // If user exists, unassign role right away
        if ($usernames) {
            $context = get_context_instance(CONTEXT_COURSE, $COURSE->id);
            $student_role = get_record('role','shortname',$CFG->block_cegep_studentrole);
            foreach ($usernames as $username) {
                if ($student_user = get_record('user', 'username', $username->username)) {
                    role_unassign($student_role->id, $student_user->id, 0, $context->id);
                }
            }
        }

        // Go through each program/year and remove Moodle external enrolment database record
        $delete = "DELETE FROM `$CFG->enrol_dbtable` WHERE `$CFG->enrol_remotecoursefield` = '$COURSE->idnumber' AND `$CFG->enrol_db_remoterolefield` = '$CFG->block_cegep_studentrole' AND `program_idyear` IN ($program_list);";
        
        $result = $enroldb->Execute($delete);
        
        if (!$result) {
            trigger_error($enroldb->ErrorMsg() .' STATEMENT: '. $delete);
            print_error('errordeletingenrolment','block_cegep');
            break;
        } else {
            notify(get_string('programunenrolled','block_cegep',array($enroldb->Affected_Rows())),'notifysuccess');
            echo "<div class='continuebutton'>";
            print_single_button($CFG->wwwroot.'/course/view.php', array('id' => $COURSE->id), get_string('continuetocourse'));
            echo "</div>";
        }       
    }
    // Display the unenrolment form
    else {
        print_heading(get_string('unenrolprogram','block_cegep'), 'center', 3);
        $unenrolprogramform->display();
    }
}

// List all coursegroups and students enrolled in this Moodle course
function cegep_studentlist() {
    global $CFG, $COURSE, $enroldb, $sisdb;

    $currenttab = 'studentlist';
    require('block_cegep_tabs.php');
    
    $body = '';

    ($COURSE->metacourse) ? ($courses = get_courses_in_metacourse($COURSE->id)) : ($courses = array($COURSE));

    if ($courses) {
        
        foreach ($courses as $c) {
    
            if (empty($c->idnumber)) { $c = get_record('course', 'id', $c->id); }
    
            $select = "SELECT DISTINCT `program_idyear` FROM `$CFG->enrol_dbtable` WHERE `$CFG->enrol_remotecoursefield` = '$c->idnumber' AND `program_idyear` IS NOT NULL";

            $program_idyears = $enroldb->Execute($select);
           
            $select = "SELECT DISTINCT `coursegroup_id` FROM `$CFG->enrol_dbtable` WHERE `$CFG->enrol_remotecoursefield` = '$c->idnumber' AND `coursegroup_id` IS NOT NULL";
    
            $coursegroups = $enroldb->Execute($select);
            
            if (!$coursegroups && !$program_idyears) {
                trigger_error($enroldb->ErrorMsg() .' STATEMENT: '. $select);
            }
            else {

                $program_idyear = '';
                $coursegroup_id = '';

                while (!$program_idyears->EOF) {

                    $program_idyear = $program_idyears->fields['program_idyear'];
                    $program_idyear = explode('_', $program_idyear);

                    $program_id = $program_idyear[0];
                    $program_year = $program_idyear[1];

                    // Get title
                    $select = "SELECT `title` FROM `$CFG->sisdb_name`.`program` WHERE `id` = '$program_id'";
                    $program = $sisdb->Execute($select)->fields;

                    $notice = '';

                    $notice .= '<strong>'.get_string('program','block_cegep').'</strong> : ' . $program_id . ' - ' . $program['title'] . '<br />';
                    $notice .= '<strong>'.get_string('programyear','block_cegep').'</strong> : ' . get_string('programyear'.$program_year,'block_cegep');

                    $select = "SELECT * FROM `$CFG->enrol_dbtable` WHERE `$CFG->enrol_remotecoursefield` = '$c->idnumber' AND `program_idyear` = '".$program_id."_".$program_year."' AND `$CFG->enrol_db_remoterolefield` = '$CFG->block_cegep_studentrole' ORDER BY `$CFG->enrol_remoteuserfield` ASC";
                   
                    $table = cegep_studentlist_enrolmenttable($select);

                    if (count($table->data) > 0) {
                        $notice .= print_table($table,true);
                        $notice .= '<br /><strong>'.get_string('total').'</strong> : ' . count($table->data);
                    } else { ($notice .= "Il n'y a aucun étudiant inscrit à ce cours.<br /><br />"); }
 
                    $body .= print_simple_box($notice, 'center', '700px', '', 5, 'generalbox', '', true);

                    $program_idyears->MoveNext();
                }
                
                while (!$coursegroups->EOF) {
                    
                    $coursegroup_id = $coursegroups->fields['coursegroup_id'];
                    
                    $term = '';
                    $year = '';
            
                    $select = "SELECT * FROM `$CFG->sisdb_name`.`coursegroup` WHERE `id` = '$coursegroup_id'";
                    $coursegroup = $sisdb->Execute($select)->fields;
            
                    switch (substr($coursegroup['term'],-1)) {
                        case '1' : $term = get_string('winter', 'block_cegep'); break;
                        case '2' : $term = get_string('summer', 'block_cegep'); break;
                        case '3' : $term = get_string('autumn', 'block_cegep'); break;
                    }
                    $year = substr($coursegroup['term'],0,4);
            
                    $notice = '';
            
                    if ($COURSE->metacourse) { $notice .= '<strong>'.get_string('childcoursetitle','block_cegep').'</strong> : ' . $c->fullname . ' ('. $c->idnumber .')<br />'; }
                    $notice .= '<strong>'.get_string('semester','block_cegep').'</strong> : ' . $term . '&nbsp;' . $year . '<br />';
                    $notice .= '<strong>'.get_string('coursecode','block_cegep').'</strong> : ' . $coursegroup['coursecode'] . '<br />';
                    $notice .= '<strong>'.get_string('coursegroupnumber','block_cegep').'</strong> : ' . $coursegroup['group'] . '<br /><br />';

                    $select = "SELECT * FROM `$CFG->enrol_dbtable` WHERE `$CFG->enrol_remotecoursefield` = '$c->idnumber' AND `coursegroup_id` = '$coursegroup[id]' AND `$CFG->enrol_db_remoterolefield` = '$CFG->block_cegep_studentrole' ORDER BY `$CFG->enrol_remoteuserfield` ASC";
                   
                    $table = cegep_studentlist_enrolmenttable($select);
           
                    if (count($table->data) > 0) {
                        $notice .= print_table($table,true);
                        $notice .= '<br /><strong>'.get_string('total').'</strong> : ' . count($table->data);
                    } 
                    else if (is_null($coursegroup[id])) {
                        $coursegroups->MoveNext();
                        continue;
                    }
                    else { 
                        $notice .= get_string('nocoursegroupsenrolled','block_cegep');
                    }
            
                    $body .= print_simple_box($notice, 'center', '700px', '', 5, 'generalbox', '', true);
                    
                    $coursegroups->MoveNext();
                }
            }
        }
    }
    
    if (!empty($body)) {
        print_heading(get_string('studentlisttitle','block_cegep'), 'center', 3);
        print($body);
    } else {
        notify(get_string('nocoursegroupsenrolled', 'block_cegep'));
    }
}

function cegep_studentlist_enrolmenttable($select) {
    global $CFG, $COURSE, $enroldb, $sisdb;

    $table = new stdClass;
    $table->class = 'flexible';
    $table->width = '100%';
    $table->cellpadding = 4;
    $table->cellspacing = 3;
    $table->align = array('left','left','left');
    $table->head = array(get_string('username'),get_string('firstname'),get_string('lastname'),get_string('program','block_cegep'));
    $table->data = array();

    // Obtenir la liste des étudiants
    $enrolments_rs = $enroldb->Execute($select);

    // Inscription des étudiants
    $lastnames = array();
    while ($enrolments_rs and !$enrolments_rs->EOF) {
        $select = "SELECT * FROM `$CFG->sisdb_name`.`student` WHERE `username` = '" . $enrolments_rs->fields[$CFG->enrol_remoteuserfield] . "'";
        $student_rs = $sisdb->Execute($select);
        if ($student_rs && $student_rs->RecordCount() == 1) {
            $student_sisdb = $student_rs->fields;
            $student_moodle = get_record('user', 'username', $student_sisdb['username']);
            if ($student_moodle) {
                $table->data[] = array('<a href="'.$CFG->wwwroot.'/user/view.php?id='.$student_moodle->id.'" title="'.get_string('accessuserprofile','block_cegep').'">'.$student_sisdb['username'].'</a>', $student_sisdb['firstname'], $student_sisdb['lastname'], $student_sisdb['program_id']);
                $lastnames[] = $student_sisdb['lastname'];
            } else {
                $table->data[] = array($student_sisdb['username'], $student_sisdb['firstname'], $student_sisdb['lastname'], $student_sisdb['program_id']);
                $lastnames[] = $student_sisdb['lastname'];
            }
        }
        $enrolments_rs->MoveNext();
    }
    array_multisort($lastnames, SORT_ASC, $table->data);
    return $table;
}

print_footer();

$enroldb->Close();
$sisdb->Close();

?>
