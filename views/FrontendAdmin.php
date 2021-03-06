<?php
use Drupal\ClassLearning\Models\User,
  Drupal\ClassLearning\Models\Section,
  Drupal\ClassLearning\Models\SectionUsers,
  Drupal\ClassLearning\Models\Semester,
  Drupal\ClassLearning\Models\AssignmentSection,
  Drupal\ClassLearning\Models\Assignment,
  Drupal\ClassLearning\Models\WorkflowTask,
  Drupal\ClassLearning\Models\Workflow,
  Drupal\ClassLearning\Workflow\Manager;

/**
 * @file
 */

function groupgrade_instructor_dash() {
  $sections = User::sectionsWithRole('instructor')->get();

  $return = '';
  //$return .= '<h2>Course <small>'.$course->course_name.' &mdash; '.$course->course_title.'</small></h2>';
  $return .= '<div class=" clearfix">';
  $return .= '<h3>Sections</h3>';
  $return .= sprintf('<p>%s</p>', t('Select the section to view and manage that section\'s assignments.'));

  $rows = array();
  if (count($sections) > 0) : foreach($sections as $section) :
    $semester = $section->semester()->first();
    $course = $section->course()->first();
    $semester = $section->semester()->first();

    $rows[] = array(
      '<a href="'.url('class/instructor/'.$section->section_id).'">'
        .$course->course_name.' '
        .$section->section_name
        .' &mdash; '.$semester->semester_name
        .'</a>',
      $section->section_description,
      number_format($section->students()->count())
    );
  endforeach; endif;
  $return .= theme('table', array(
    'header' => array('Section Name', 'Description', 'Students'),
    'rows' => $rows,
    'attributes' => array('width' => '100%'),
    'empty' => 'No sections found.'
  ));
  
  $return .= '</div>';
  return $return;
}


function groupgrade_adminview_section($id) {
  $section = Section::find((int) $id);
  if ($section == NULL) return drupal_not_found();

  drupal_set_title(t('Section Dashboard'));

  $return = '';

  return $return;
}

function groupgrade_view_user($section_id) {
  $return = '';
  $section = Section::find($section_id);
  
  if ($section == NULL) return drupal_not_found();

  drupal_set_title(t('Section Users'));

  foreach(['instructor', 'student'] as $role):
    $return .= '<h4>'.ucfirst($role).'s</h4>';
    $students = $section->users($role)
      ->where('su_role', '=', $role)
      ->get();

    $rows = array();
    if (count($students) > 0) : foreach($students as $student) :
      $user = $student->user();
      $rows[] = array(
        sprintf('%s (<a href="%s">%s</a>)', ggPrettyName($user), $user->mail, $user->mail),
        $student->su_status,
        '<a href="'.url('class/instructor/'.$section->section_id.'/remove-from-section/'.$student->user_id).'">'.t('remove').'</a> &mdash;
        <a href="'.url('class/instructor/'.$section->section_id.'/swap-status/'.$student->user_id).'">change to '.(($student->su_status !== 'active') ? 'active' : 'inactive').'</a>',
      );
    endforeach; endif;

    $return .= theme('table', array(
      'rows' => $rows,
      'header' => array('User', 'Status', 'Operations'),
      'empty' => 'No users found.',
      'attributes' => array('width' => '100%'),
    ));
  endforeach;

  // Add User Form
  require_once (__DIR__.'/SectionAdmin.php');
  $form = drupal_get_form('groupgrade_add_student_form', $section->section_id);

  $return .= sprintf('<h5>%s</h5>', t('Add Users to Section'));
  $return .= drupal_render($form);

  return $return;
}

function groupgrade_view_reports($asec_id){
	
	$return = '';
	
	// Get all assignment section objects
	$asec = AssignmentSection::where('asec_id','=',$asec_id)
	  ->first();
	
	$section = Section::where('section_id','=',$asec->section_id)
	  ->first();
	
	if($section == null)
	  return drupal_not_found();

    $course = $section->course()->first();
    $semester = $section->semester()->first();
    $assignment = $asec->assignment()->first();
  
    drupal_set_title(sprintf('%s: %s', $assignment->assignment_title, t('View + Reassign')));
  
    $return .= sprintf('<p><a href="%s">%s %s</a>', url('class/instructor/'.$asec->section_id), HTML_BACK_ARROW, t('Back to Select Assignment (this section)'));
  
    $return .= sprintf('<p><strong>%s:</strong> %s &mdash; %s &mdash; %s</p>', 
      t('Course'),
      $course->course_name, 
      $section->section_name,
      $semester->semester_name
    );
	
	drupal_set_title(sprintf('%s: %s',$assignment->assignment_title,t('Reports')));
	
	$return .= '<h1>Completed Tasks and Grades</h1><br>';
	
	// Get all the students  
	$students = array();  
	  
	$section_users = $section->students()->get();
	if(count($section_users) > 0) { foreach($section_users as $i){
		$students[] = user_load($i->user_id);
	}
	}
	else{
		$return .= "No students found.";
		return $return;
	}
	
	//For each assignment section object...
	
		$assignment = Assignment::where('assignment_id','=',$asec->assignment_id)
		  ->first();
		
		$workflows = Workflow::where('assignment_id','=',$asec->asec_id)
		  ->get();
		  
		$return .= "<h3>" . $assignment->assignment_title . "</h3>";
		
		//For each student...
		$return .= "<table><tr><th>UCID</th><th>Name</th><th>Tasks Completed</th><th>Extra Credit Completed</th></tr>";
		foreach($students as $student){
			
			$return .= "<tr>";
			$return .= "<td>" . $student->name . "</td>";
			$return .= "<td>" . ggPrettyName($student) . "</td>";
			
			// Get EVERY task done by this student.
			$normalTasks = array();
			$extraTasks = array();
			
			foreach($workflows as $workflow){
				$tasks = WorkflowTask::where('workflow_id','=',$workflow->workflow_id)
				  ->where('user_id','=',$student->uid)
				  ->get();
				  
				foreach($tasks as $task){
					if($task->status != 'complete')
					  continue;
					if($task->user_history == null)
					  $normalTasks[] = $task;
					else
					  $extraTasks[] = $task;
				}
			}
			
			// Now that we have every task from all workflows, print out tasks completed
			$return .= "<td>";
			foreach($normalTasks as $task){
				$return .= "<a href=" . url('class/task/' . $task->task_id) . ">" . Manager::humanTaskName($task->type);
				  if($task->type == 'create solution'){
				  	$wf = $task->workflow()->first();
				  	if(isset($wf->data['grade']))
				      $return .= '(' . $wf->data['grade'] . ')';
				  }
				$return .= "</a><br>";
			}
			$return .= "</td>";
			
			// Finally, print out extra credit tasks
			$return .= "<td>";
			foreach($extraTasks as $task){
				
				$return .= "<a href=" . url('class/task/' . $task->task_id) . ">" . Manager::humanTaskName($task->type);
				  if($task->type == 'create solution'){
				  	$wf = $task->workflow()->first();
				  	if(isset($wf->data['grade']))
				      $return .= '(' . $wf->data['grade'] . ')';
				  }
				$return .= "</a><br>";
			}
			$return .= "</td>";
			
			$return .= "</tr>";
			
			unset($normalTasks);
			unset($extraTasks);
			
		}
		
		$return .= "</table>";
	
	
	return $return;
}

function groupgrade_view_assignments($id) {
  $return = '';
  drupal_set_title(t('Section Assignments'));

  $section = Section::find($id);
  
  if ($section == NULL) return drupal_not_found();

  $assignments = $section->assignments()->get();
  $rows = array();

  $course = $section->course()->first();
  $semester = $section->semester()->first();

  // Information about this course
  $return .= sprintf('<p><strong>%s</strong>: %s &mdash; %s &mdash; %s</p>',
    t('Course'),
    $course->course_name,
    $section->section_name,
    $semester->semester_name
  );

  $return .= sprintf('<p><a href="%s">%s %s</a></p>',
    url('class/instructor'),
    HTML_BACK_ARROW,
    t('Back to Section Management')
  );

  $return .= sprintf('<p><a href="%s">%s</a></p>',
    url('class/instructor/assignments/new'),
    t('Create new Assignment')
  );

  $return .= sprintf('<p>%s</p>', t('Select an assignment title to see all student entries. Select an operation to manage the assignment.'));

  if (count($assignments) > 0) : foreach($assignments as $assignment) :
    $rows[] = [
      sprintf('<a href="%s">%s</a>',
        url('class/instructor/'.$assignment->section_id.'/assignment/'.$assignment->asec_id),
        $assignment->assignment_title
      ),
      gg_time_human($assignment->asec_start), 
      
    ];
  endforeach; endif;

  $return .= theme('table', array(
    'rows' => $rows,
    'header' => array('Title', 'Start Date'),
    'empty' => 'No assignments found.',
    'attributes' => array('width' => '100%'),
  ));

  return $return;
}


/**
 * View an Assignment
 *
 * @param int Section ID
 * @param int AssignmentSection ID
 */
function groupgrade_view_assignment($section_id, $asec_id, $type = NULL)
{
  $section_id = (int) $section_id;
  $assignmentSection = AssignmentSection::find($asec_id);
  if ($assignmentSection == NULL) return drupal_not_found();

  $section = $assignmentSection->section()->first();

  // Logic Check
  if ((int) $section->section_id !== (int) $section_id) return drupal_not_found();

  $course = $section->course()->first();
  $semester = $section->semester()->first();
  $assignment = $assignmentSection->assignment()->first();

  // Specify the title
  drupal_set_title(sprintf('%s: %s', $assignment->assignment_title, t('View + Reassign')));

  $return = '';
  // Back Link
  $return .= sprintf('<p><a href="%s">%s %s</a>', url('class/instructor/'.$section_id), HTML_BACK_ARROW, t('Back to Select Assignment (this section)'));

  $return .= sprintf('<p><strong>%s:</strong> %s &mdash; %s &mdash; %s</p>', 
    t('Course'),
    $course->course_name, 
    $section->section_name,
    $semester->semester_name
  );

  // Data for the table (the workflows)
  $workflows = WorkflowTask::whereIn('workflow_id', function($query) use ($asec_id)
  {
    $query->select('workflow_id')
      ->from('workflow')
      ->where('assignment_id', '=', $asec_id);
  });

  if ($type == 'timed out') :
    $workflows->whereStatus('timed out')
      ->groupBy('workflow_id');
  else :
    $workflows->whereType('create problem');
  endif;

  $workflows = $workflows->get();

  $headers = ['Problems'];
  $rows = [];

  if (count($workflows) > 0) : foreach ($workflows as $t) :
    $url = url(
      sprintf('class/instructor/%d/assignment/%d/%d',
        $section_id,
        $asec_id,
        $t->workflow_id
      )
    );

    $rows[] = [
      sprintf(
        '<a href="%s">%s</a>',
        $url,
        (isset($t->data['problem'])) ? word_limiter($t->data['problem'], 20) : 'Workflow #'.$t->workflow_id
      )
    ];
  endforeach; endif;

  // Assignment Description
  $return .= sprintf('<p class="summary">%s</p>', nl2br($assignment->assignment_description));
  $return .= '<hr />';
    
  // Instructions
  $return .= sprintf('<p>%s<p>',
    t('Select a question to see the work on that question so far.')
  );

  // Render the workflow
  $return .= theme('table', array(
    'header' => $headers, 
    'rows' => $rows,
    'empty' => 'No problems found.',
    'attributes' => array('width' => '100%')
  ));

  return $return;
}

function groupgrade_view_timedout($section_id, $asec_id)
{
  return groupgrade_view_assignment($section_id, $asec_id, 'timed out');
}


/**
 * View an Assignment Workflow
 *
 * @param int Section ID
 * @param int AssignmentSection ID
 */
function groupgrade_view_assignmentworkflow($section_id, $asec_id, $workflow_id)
{
  $assignmentSection = AssignmentSection::find($asec_id);
  if ($assignmentSection == NULL) return drupal_not_found();

  $workflow = Workflow::find($workflow_id);
  if ($workflow == NULL OR $workflow->assignment_id != $asec_id)
    return drupal_not_found();

  $section = $assignmentSection->section()->first();
  $course = $section->course()->first();
  $semester = $section->semester()->first();
  $assignment = $assignmentSection->assignment()->first();
  $tasks = $workflow->tasks()->get();

  // Set the Page title
  drupal_set_title($assignment->assignment_title.': '.t('Workflow').' #'.$workflow_id);

  $return = '';

  // Information about the course
  $return .= sprintf('<p><strong>%s:</strong> %s &mdash; %s &mdash; %s</p>', 
    t('Course'),
    $course->course_name, 
    $section->section_name,
    $semester->semester_name
  );

  // Call on a common function so we don't duplicate things
  require_once (__DIR__.'/Tasks.php');
  $return .= gg_view_workflow($workflow, true);

  return $return;
}

function groupgrade_frontend_remove_user_section($section, $user)
{
  SectionUsers::where('section_id', '=', $section)
    ->where('user_id', '=', $user)
    ->delete();

  foreach(array('student', 'instructor') as $role)
    gg_acl_remove_user('section-'.$role, $user, $section);

  drupal_set_message(sprintf('User %d removed from section %d', $user, $section));
  return drupal_goto('class/instructor/'.$section.'/users');
}

/**
 * Swap a user's status in a section between active and inactive
 */
function groupgrade_frontend_swap_status($section, $user)
{
  $userInSection = SectionUsers::where('section_id', '=', $section)
    ->where('user_id', '=', $user)
  ->first();

  if (! $userInSection) return drupal_not_found();

  $userInSection->su_status = ($userInSection->su_status == 'active') ? 'inactive' : 'active';
  $userInSection->save();
  $userData = user_load($user);

  drupal_set_message(sprintf('%s %d %s %s.',
    t('User'),
    ggPrettyName($userData),
    t('status set to'),
    $userInSection->su_status
  ));

  return drupal_goto('class/instructor/'.$section.'/users');
}

function groupgrade_remove_reassign_form($form, &$form_state, $asec_id){
  
  $asec = AssignmentSection::where('asec_id','=',$asec_id)
	  ->first();
  $section = Section::find($asec->section_id);
  $course = $section->course()->first();
  $semester = $section->semester()->first();
  $assignment = Assignment::find($asec->assignment_id);
  
  drupal_set_title(sprintf('%s: %s', $assignment->assignment_title, t('View + Reassign')));
  
  $items = array();
  
  $items[] = array(
    '#markup' => sprintf('<p><a href="%s">%s %s</a>', url('class/instructor/'.$asec->section_id), HTML_BACK_ARROW, t('Back to Select Assignment (this section)')),
  );
  
  $items[] = array(
    '#markup' => sprintf('<p><strong>%s:</strong> %s &mdash; %s &mdash; %s</p>', 
    t('Course'),
    $course->course_name, 
    $section->section_name,
    $semester->semester_name
    )
  );
  
	  
	if($asec == null)
	  return drupal_not_found();
	
	$assignment = Assignment::find($asec->assignment_id);
	
	drupal_set_title(sprintf('%s: %s', $assignment->assignment_title, t('View + Reassign')));
	
	$items['removeLabel'] = array(
	  '#type' => 'item',
	  '#markup' => '<h3>Students to be Removed</h3>
	  <p><strong>Please enter the students you wish to be removed from this assignment.</strong></p>
	  <p>Enter user IDs as seen in the task table, separated by spaces.</p>',
	);
	
	$items['remove'] = array(
	  '#type' => 'textfield',
	);
	
	$items['separator'] = array( '#markup' => '<hr>', );
	
	$items['replaceLabel'] = array(
	  '#type' => 'item',
	  '#markup' => '<h3>Replacement Students</h3>
	  <p><strong>Please enter the students you wish to have replace the removed students.</strong></p>
	  <p>Enter user IDs as seen in the task table, separated by spaces.</p>',
	);
	
	$items['replace'] = array(
	  '#type' => 'textfield',
	);
	
	$items['separator2'] = array( '#markup' => '<hr><br>', );
	
	$items['asec'] = array(
	  '#type' => 'hidden',
	  '#value' => $asec_id,
	);
	
	$items['submit'] = array(
	  '#type' => 'submit',
	  '#value' => 'Submit',
	);
	
	return $items;
}

function groupgrade_remove_reassign_form_submit($form, &$form_state){
	
	$remove = $form['remove']['#value'];
	$replace = $form['replace']['#value'];
	
	$removeArray = explode(' ', $remove);
	$replaceArray = explode(' ', $replace);
	
	$asec = $form['asec']['#value'];
	$asec = AssignmentSection::find($asec);
	$students = $asec->section()->first()->students()->get();
	if($students == FALSE){
		drupal_set_message("No students found in this section.");
		return drupal_not_found();
	}
	$studentIDs = array();
	foreach($students as $student){
		$studentIDs[] = $student->user_id;
	}
	//$students = $section->students()->get();
	//echo print_r($students,1);
	
	//Below is a copy of the groupgrade_reassign_to_contig function.
	//It has been edited to work with this function.
	
	$pool = $removePool = [];
  foreach ($replaceArray as $u){
  	$add = user_load_by_name($u);
		if(!$add){
			drupal_set_message('User ' . $u . ' not found.','error');
			return drupal_not_found();
		}
    $pool[] =  $add;
  }

  //Make sure everyone in $pool is in the section
  foreach($pool as $p){
  	drupal_set_message($p->uid);
  	if(!in_array($p->uid,$studentIDs)){
  		drupal_set_message($p->name . " is not enrolled in this class.",'error');
		return drupal_not_found();
  	}
  }

  // Let's find the people we're going to remove
  foreach ($removeArray as $u){
  	$remove = user_load_by_name($u);
	  if(!$remove){
			drupal_set_message('User ' . $u . ' not found.','error');
			return drupal_not_found();
		}
    $removePool[] = $remove;
  }

  // Get all of their tasks and reassign them randomly
  if ($removePool) : foreach ($removePool as $removeUser) :
    echo "Removing tasks for ".$removeUser->name.PHP_EOL;

    $tasks = WorkflowTask::where('user_id', $removeUser->uid)
      ->groupBy('workflow_id')
      ->whereIn('status', ['not triggered', 'triggered', 'started', 'timed out','expired'])
      ->get();

    // They're not assigned any tasks that we're going to change
    if (count($tasks) == 0) :
      echo "No tasks to remove!".PHP_EOL;
      continue;
    endif;

    // Go though all assigned tasks
    foreach ($tasks as $task)
    {
    
	  
	  $a = $task->assignmentSection()->first();
	  //echo $a->asec_id . '-' . $asec->asec_id;
	  if($a->asec_id != $asec->asec_id)
	    continue;	
	
      echo "Removing task ".$task->id.PHP_EOL;
      $foundUser = false;
      $i = 0;
      while (! $foundUser) {
        $i++;

        // We cannot continue since we've gone through all the users
        if ($i > count($pool))
          throw new \Exception('Contingency exception: cannot assign user due to unavailable users.');

        $reassignUser = $pool[array_rand($pool)];

        // Let's check if the user we found is in the workflow
        if (WorkflowTask::where('workflow_id', $task->workflow_id)
          ->where('user_id', $reassignUser->uid)
          ->count() == 0)
          // They're not in the workflow!
          $foundUser = TRUE;
      }

      // Now that we've found the user, let's reassign it
      // We're going to reassign all tasks assigned to this user in the workflow
      
      // Before anything, let's update the user history field.
      $update = null;
	  if($task->user_history == '')
	  	$update = array();
	  else
	  	$update = json_decode($task->user_history,true);
      
	  $ar = array();
	  $new_user = user_load($user);
	  $ar = array();
	  $ar['previous_uid'] = $removeUser->uid;
	  $ar['previous_name'] = $removeUser->name;
	  $ar['time'] = Carbon\Carbon::now()->toDateTimeString();
	  $ar['new_uid'] = $reassignUser->uid;
	  $ar['new_name'] = $reassignUser->name;
	  
	  $update[] = $ar;
	  $task->user_history = json_encode($update);
	  
      WorkflowTask::where('user_id', $removeUser->uid)
        ->where('workflow_id', $task->workflow_id)
        ->whereIn('status', ['triggered', 'started', 'timed out', 'expired'])
        ->update([
          'user_id' => $reassignUser->uid,
          'status' => 'triggered',
          'start' => Carbon\Carbon::now()->toDateTimeString(),
          'user_history' => $task->user_history,
         // 'force_end' => $this->timeoutTime()->toDateTimeString()
        ]);
      
      /*
      Task::where('user_id', $removeUser->uid)
        ->where('workflow_id', $task->workflow_id)
        ->whereIn('status', ['triggered', 'started', 'timed out'])
        ->update([
          'user_id' => $reassignUser->uid,
          'status' => 'triggered',
          'start' => Carbon\Carbon::now()->toDateTimeString(),
         // 'force_end' => $this->timeoutTime()->toDateTimeString()
        ]);
	  */
        // Different for non-triggered already
        WorkflowTask::where('user_id', $removeUser->uid)
        ->where('workflow_id', $task->workflow_id)
        ->where('status', 'not triggered')
        ->update([
          'user_id' => $reassignUser->uid,
          'user_history' => $task->user_history,
        ]);
    }
  endforeach; endif;
  echo PHP_EOL.PHP_EOL."DONE!!!!";
  exit;

  drupal_set_message("Finished. Check the task table to ensure users have been replaced with no issues.");
	
}
