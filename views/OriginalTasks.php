<?php
use Drupal\ClassLearning\Models\WorkflowTask as Task,
  Drupal\ClassLearning\Models\Workflow,
  Drupal\ClassLearning\Common\Accordion;

function groupgrade_tasks_dashboard() {
  return groupgrade_tasks_view_specific('pending');
}



function groupgrade_tasks_view_specific($specific = '') {
  global $user;
  $tasks = Task::queryByStatus($user->uid, $specific)->get();
  $rows = [];
  $return = '';

  switch($specific)
  {
    case 'pending' :
      $headers = ['Due Date', 'Type', 'Course', 'Assignment'];
      
      $return .= sprintf('<p>%s</p>', t('These are the pending tasks you need to do. Click on a due date to open the task.'));
      if (count($tasks) > 0) : foreach($tasks as $task) :
        $row_t = [];
        $row_t[] = sprintf(
          '<a href="%s">%s</a>',
          url('class/task/'.$task->task_id), groupgrade_carbon_span($task->forceEndTime()) .
            (($task->status == 'timed out') ? '(late)' : '')
        );

        $row_t[] = $task->humanTask();

        $section = $task->section()->first();
        $course = $section->course()->first();
        $assignment = $task->assignment()->first();
        $semester = $section->semester()->first();

        $row_t[] = sprintf('%s &mdash; %s &mdash; %s', 
          $course->course_name, 
          $section->section_name,
          $semester->semester_name
        );

        $row_t[] = $assignment->assignment_title;

        $rows[] = $row_t;
      endforeach; endif;
      break;

    // All/completed tasks
    default :
      $headers = array('Assignment', 'Task', 'Course', /*'Problem',*/ 'Date Completed');

      if (count($tasks) > 0) : foreach($tasks as $task) :
        $rowt = [];
        $rowt[] = sprintf(
          '<a href="%s">%s</a>',
          url('class/task/'.$task->task_id), $task->assignment()->first()->assignment_title
        ); 

        $rowt[] = t(ucwords($task->type));

        // Course information
        $section = $task->section()->first();
        $course = $section->course()->select('course_name')->first();
        $semester = $section->semester()->select('semester_name')->first();

        $rowt[] = sprintf('%s &mdash; %s &mdash; %s',
          $course->course_name,
          $section->section_name,
          $semester->semester_name
        );

        $rowt[] = ($task->end == NULL) ? 'n/a' : gg_time_human($task->end);

        $rows[] = $rowt;
      endforeach; endif;
      break;
  }

  $return .= theme('table', array(
    'header' => $headers, 
    'rows' => $rows,
    'attributes' => ['width' => '100%'],
    'empty' => 'No tasks found.',
  ));

  return $return;
}

function groupgrade_user_grades() {
  global $user;
  $return = '';

  // Retrieve all of their workflows where they are assigned to "create solution"
  $tasks = Task::where('user_id', $user->uid)
    ->whereType('create solution')
    ->get();

  $rows = [];

  foreach ($tasks as $task) :
    $assignment = $task->assignment()->first();
    $asec = $task->assignmentSection()->first();
    $section = $asec->section()->first();
    $course = $section->course()->first();

    $workflow = $task->workflow()->first();
    $grade = (isset($workflow->data['grade'])) ? ((int) $workflow->data['grade']).'%' : 'n/a';
    $rows[] = [
      sprintf('%s %s', $course->course_name, $section->section_name),
      sprintf('<a href="%s">%s</a>', url('class/workflow/'.$task->workflow_id), $assignment->assignment_title),
      $grade
    ];

  endforeach;
  
  $return .= theme('table', array(
    'header' => ['Course', 'Assignment', 'Grade Recieved'],
    'rows' => $rows,
    'attributes' => ['width' => '100%'],
    'empty' => 'No grades recieved.',
  ));
  return $return;
}

/**
 * View a specific task
 *
 * @param int Task ID
 * @param string How to display it (default = everything, overview = Just submitted data, no other info)
 * @param bool View the task with admin permissions
 */
function groupgrade_view_task($task_id, $action = 'display', $admin = FALSE)
{
  global $user;

  if (is_object($task_id)) :
    $task = $task_id;
    $task_id = $task->task_id;
  else :
    $task = Task::find($task_id);
  endif;

  // Permissions
  if ($task == NULL OR (! $admin AND ! in_array($task->status, ['triggered', 'started', 'complete', 'timed out']) ))
    return drupal_not_found();

  if ($task->status !== 'complete' AND (int) $task->user_id !== (int) $user->uid AND ! $admin)
    return drupal_not_found();

  $anon = ((int) $task->user_id !== (int) $user->uid AND ! user_access('administer')) ? TRUE : FALSE;

  // Related Information
  $assignment = $task->assignment()->first();

  $return = '';
  drupal_set_title(t(sprintf('%s: %s', $task->humanTask(), $assignment->assignment_title)));

  if ($action == 'display') :
    $return .= sprintf('<p><a href="%s">%s %s</a>', url('class/default/all'), HTML_BACK_ARROW, t('Back to All Tasks'));

    // Course information
    $section = $task->section()->first();
    $course = $section->course()->first();
    $semester = $section->semester()->first();

    $return .= sprintf('<p><strong>%s</strong>: %s &mdash; %s &mdash; %s</p>',
      t('Course'),
      $course->course_name,
      $section->section_name,
      $semester->semester_name
    );

    $return .= '<hr />';
    
    $return .= sprintf('<h4>%s</h4>', t('Assignment Description'));
    $return .= sprintf('<p class="summary">%s</p>', nl2br($assignment->assignment_description));
    $return .= '<hr />';
  endif;

  $params = [];
  $params['task'] = $task;
  $params['anon'] = $anon;
  $params['action'] = $action;

  if ($task->type == 'edit problem')
  {
    $params['previous task'] = Task::where('workflow_id', '=', $task->workflow_id)
      ->whereType('create problem')
      ->first();
  } else {
    // Automatically include the edited problem working with
    $params['problem'] = Task::where('workflow_id', '=', $task->workflow_id)
      ->whereType('edit problem')
      ->first();
  }
  
  if ($task->type == 'grade solution' OR $task->type == 'dispute' OR $task->type == 'resolve dispute' OR $task->type == 'resolution grader')
  {
    $params['solution'] = Task::where('workflow_id', '=', $task->workflow_id)
      ->whereType('create solution')
      ->first();
  }

  if ($task->type == 'create solution')
  {
    $params['previous task'] = Task::where('workflow_id', '=', $task->workflow_id)
      ->whereType('edit problem')
      ->first();
  }

  $params['workflow'] = $task->workflow()->first();
  
  if (! $admin)
    $params['edit'] = ( in_array($task->status, ['triggered', 'started', 'timed out']) );
  else
    $params['edit'] = FALSE;

  $form = drupal_get_form('gg_task_'.str_replace(' ', '_', $task->type).'_form', $params);
  $return .= drupal_render($form);
  return $return;
}

/**
 * Impliments a create problem form
 */
function gg_task_create_problem_form($form, &$form_state, $params) {
  $items = [];

  if (! $params['edit']) :
    $items['edited problem lb'] = [
      '#markup' => '<strong>'.t('Problem').':</strong>',
    ];
    $items['edited problem'] = [
      '#type' => 'item',
      '#markup' => (isset($params['task']->data['problem'])) ? nl2br($params['task']->data['problem']) : '',
    ];

    return $items;
  endif;

  if (isset($params['task']->settings['instructions']))
    $items[] = [
      '#markup' => sprintf('<p>%s</p>', t($params['task']->settings['instructions']))
    ];

  $items['body'] = [
    '#type' => 'textarea',
    '#required' => true,
    '#default_value' => (isset($params['task']->data['problem'])) ? $params['task']->data['problem'] : '',
  ];

  $items['save'] = [
    '#type' => 'submit',
    '#value' => 'Save Problem For Later',
  ];
  $items['submit'] = [
    '#type' => 'submit',
    '#value' => 'Submit Problem',
  ];
  return $items;
}

/**
 * Callback submit function for class/task/%
 */
function gg_task_create_problem_form_submit($form, &$form_state) {
  $task = $form_state['build_info']['args'][0]['task'];

  if (! $form_state['build_info']['args'][0]['edit'])
    return drupal_not_found();
  
  $save = ($form_state['clicked_button']['#id'] == 'edit-save' );
  $task->setDataAttribute(['problem' =>  $form['body']['#value']]);
  if ($task->status !== 'timed out') $task->status = ($save) ? 'started' : 'completed';
  $task->save();

  if (! $save)
    $task->complete();
  
  drupal_set_message(sprintf('%s %s', t('Problem'), ($save) ? 'saved. (You must submit this still to complete the task.)' : 'created.'));

  if (! $save)
    return drupal_goto('class');
}

/**
 * Impliments a edit problem form
 */
function gg_task_edit_problem_form($form, &$form_state, $params) {
  $problem = $comment = '';
  $problem = $params['previous task']->data['problem'];

  if (! empty($params['task']->data['problem']))
    $problem = $params['task']->data['problem'];

  if (! empty($params['task']->data['comment']))
    $comment = $params['task']->data['comment'];

  $items = [];

  if ($params['action'] == 'display')
    $items['original problem'] = [
      '#markup' => sprintf('<p><strong>%s:</strong></p><p>%s</p><hr />',
        t('Original Problem'),
        nl2br($params['previous task']->data['problem'])
      )
    ];

  if (! $params['edit']) :
    $items['edited problem lb'] = [
      '#markup' => sprintf('<strong>%s:</strong>', t('Edited Problem')),
    ];
    $items['edited problem'] = [
      '#type' => 'item',
      '#markup' => nl2br($problem),
    ];

    $items['comment lb'] = [
      '#markup' => sprintf('<strong>%s:</strong>', t('Edited Comments')),
    ];
    $items['comment'] = [
      '#type' => 'item',
      '#markup' => (empty($comment)) ? sprintf('<em>%s</em>', t('none')) : nl2br($comment),
    ];

    return $items;
  endif;

  if (isset($params['task']->settings['instructions']))
    $items[] = [
      '#markup' => sprintf('<p>%s</p>', t($params['task']->settings['instructions']))
    ];

  $items['body'] = [
    '#type' => 'textarea',
    '#required' => true,
    '#title' => 'Edited Problem',
    '#default_value' => $problem,
  ];

  $items['comment'] = [
    '#type' => 'textarea',
    '#required' => true,
    '#title' => 'Editing Comments',
    '#default_value' => $comment,
  ];

  $items['save'] = [
    '#type' => 'submit',
    '#value' => 'Save Edited Problem For Later',
  ];
  $items['submit'] = [
    '#type' => 'submit',
    '#value' => 'Submit Edited Problem',
  ];
  return $items;
}

/**
 * Callback submit function for class/task/%
 */
function gg_task_edit_problem_form_submit($form, &$form_state) {
  $task = $form_state['build_info']['args'][0]['task'];

  if (! $form_state['build_info']['args'][0]['edit'])
    return drupal_not_found();

  $save = ($form_state['clicked_button']['#id'] == 'edit-save' );
  $task->setDataAttribute([
    'problem' =>  $form['body']['#value'],
    'comment' => $form['comment']['#value'],
  ]);

  if ($task->status !== 'timed out') $task->status = ($save) ? 'started' : 'completed';
  $task->save();

  if (! $save)
    $task->complete();
  
  drupal_set_message(sprintf('Edited problem %s', ($save) ? 'saved. (You must submit this still to complete the task.)' : 'completed.'));

  if (! $save)
    return drupal_goto('class');
}

/**
 * Impliments a edit problem form
 */
function gg_task_create_solution_form($form, &$form_state, $params) {
  $problem = (isset($params['task']->data['solution'])) ? $params['task']->data['solution'] : '';
  $items = [];

  if ($params['action'] == 'display')
    $items['original problem'] = [
      '#markup' => '<p><strong>'.t('Problem').':</strong></p><p>'.nl2br($params['previous task']->data['problem']).'</p><hr />'
    ];

  if (! $params['edit']) :
    $items['problem lb'] = [
      '#markup' => '<strong>'.t('Solution').':</strong>',
    ];
    $items['problem'] = [
      '#type' => 'item',
      '#markup' => nl2br($problem),
    ];

    return $items;
  endif;

  if (isset($params['task']->settings['instructions']))
    $items[] = [
      '#markup' => sprintf('<p>%s</p>', t($params['task']->settings['instructions']))
    ];

  $items[] = ['#markup' => sprintf('<p><strong>%s</strong></p>', t('Create Solution'))];

  $items['body'] = [
    '#type' => 'textarea',
    '#required' => true,
    '#default_value' => (isset($params['task']->data['solution'])) ? $params['task']->data['solution'] : '',
  ];

  $items['save'] = [
    '#type' => 'submit',
    '#value' => 'Save Solution For Later',
  ];
  $items['submit'] = [
    '#type' => 'submit',
    '#value' => 'Submit Solution',
  ];
  return $items;
}

/**
 * Callback submit function for class/task/%
 */
function gg_task_create_solution_form_submit($form, &$form_state) {
  $task = $form_state['build_info']['args'][0]['task'];

  if (! $form_state['build_info']['args'][0]['edit'])
    return drupal_not_found();
  
  $save = ($form_state['clicked_button']['#id'] == 'edit-save' );
  $task->setDataAttribute(['solution' =>  $form['body']['#value']]);
  if ($task->status !== 'timed out') $task->status = ($save) ? 'started' : 'completed';
  $task->save();

  if (! $save)
    $task->complete();
  
  drupal_set_message(sprintf(t('Solution').' %s', ($save) ? 'saved. (You must submit this still to complete the task.)' : 'completed.'));

  if (! $save)
    return drupal_goto('class');
}

/**
 * Impliments a edit problem form
 */
function gg_task_grade_solution_form($form, &$form_state, $params) {
  $problem = $params['problem'];
  $solution = $params['solution'];
  $task = $params['task'];

  $items = [];

  if (! $params['edit']) :
    $items['grade lb'] = [
      '#markup' => '<strong>'.t('Correctness Grade').':</strong>',
    ];
    $items['grade'] = [
      '#type' => 'item',
      '#markup' => (((isset($task->data['correctness-grade'])) ? $task->data['correctness-grade'] : '')),
    ];

    $items['correctness lb'] = [
      '#markup' => '<strong>'.t('Grade Correctness').':</strong>',
    ];
    $items['correctness'] = [
      '#type' => 'item',
      '#markup' => (! isset($task->data['correctness'])) ? '' : nl2br($task->data['correctness']),
    ];

    $items['completeness grade lb'] = [
      '#markup' => '<strong>'.t('Completeness Grade').':</strong>',
    ];
    $items['completeness grade'] = [
      '#type' => 'item',
      '#markup' => (((isset($task->data['completeness-grade'])) ? $task->data['completeness-grade'] : '')),
    ];

    $items['completeness lb'] = [
      '#markup' => '<strong>'.t('Grade Completeness').':</strong>',
    ];
    $items['completeness'] = [
      '#type' => 'item',
      '#markup' => (! isset($task->data['completeness'])) ? '' : nl2br($task->data['completeness']),
    ];

    return $items;
  endif;

  $items['problem'] = [
    '#markup' => '<h4>'.t('Problem').'</h4><p>'.nl2br($problem->data['problem']).'</p><hr />',
  ];
  $items['solution'] = [
    '#markup' => '<h4>'.t('Solution').'</h4><p>'.nl2br($solution->data['solution']).'</p><hr />',
  ];

  if (isset($params['task']->settings['instructions']))
    $items[] = [
      '#markup' => sprintf('<p>%s</p>', t($params['task']->settings['instructions']))
    ];

  $items[] = ['#markup' => sprintf('<h5>%s: %s</h5>', t('Current Task'), t($params['task']->humanTask()))];
  $items['completeness-grade'] = [
    '#type' => 'textfield',
    '#title' => 'Grade how complete the solution is. (0-50)',
    '#required' => true,
    '#default_value' => (isset($task->data['completeness-grade'])) ? $task->data['completeness-grade'] : '',
  ];

  $items['completeness'] = [
    '#type' => 'textarea',
    '#title' => 'Justify your grade of the solution\'s completeness',
    '#required' => true,
    '#default_value' => (isset($task->data['completeness'])) ? $task->data['completeness'] : '',
  ];

  $items['correctness-grade'] = [
    '#type' => 'textfield',
    '#title' => 'Grade how correct the solution is. (0-50)',
    '#required' => true,
    '#default_value' => (isset($task->data['correctness-grade'])) ? $task->data['correctness-grade'] : '',
  ];

  $items['correctness'] = [
    '#type' => 'textarea',
    '#title' => 'Justify your grade of the solution\'s correctness',
    '#required' => true,
    '#default_value' => (isset($task->data['correctness'])) ? $task->data['correctness'] : '',
  ];

  $items['save'] = [
    '#type' => 'submit',
    '#value' => 'Save Grade For Later',
  ];
  $items['submit'] = [
    '#type' => 'submit',
    '#value' => 'Submit Grade',
  ];
  return $items;
}

/**
 * Callback submit function for class/task/%
 */
function gg_task_grade_solution_form_submit($form, &$form_state) {
  $params = $form_state['build_info']['args'][0];
  $task = $params['task'];

  if (! $form_state['build_info']['args'][0]['edit'])
    return drupal_not_found();
    
  foreach (['completeness-grade', 'correctness-grade'] as $grade) :
    $form[$grade]['#value'] = (int) $form[$grade]['#value'];

    if ($form[$grade]['#value'] !== abs($form[$grade]['#value'])
      OR $form[$grade]['#value'] < 0 OR $form[$grade]['#value'] > 50) :

      // Force to save, not submit
      $save = true;
      drupal_set_message(t('Invalid grade: '.$grade), 'error');
    endif;
  endforeach;

  $dataFields = ['completeness-grade', 'completeness', 'correctness-grade', 'correctness'];
  $save = ($form_state['clicked_button']['#id'] == 'edit-save' );

  // Save the data
  foreach ($dataFields as $field)
    $task->setData($field, $form[$field]['#value']);

  if ($task->status !== 'timed out') $task->status = ($save) ? 'started' : 'completed';
  $task->save();

  if (! $save)
    $task->complete();
  
  drupal_set_message(sprintf(t('Grade').' %s', ($save) ? 'saved. (You must submit this still to complete the task.)' : 'submitted.'));

  if (! $save)
    return drupal_goto('class');
}

/**
 * Dispute
 */
function gg_task_dispute_form($form, &$form_state, $params)
{
  $items = [];
  $task = $params['task'];
  $workflow = $params['workflow'];

  if (! $params['edit']) :
    $items[] = [
      '#markup' => sprintf('<p>%s <strong>%s</strong>.</p>',
        t('The solution grade was'),
        (($task->data['value']) ? 'disputed' : 'not disputed')
      )
    ];

    // It was disputed, show the propsed grade and justification
    if ($task->data['value']) :
      foreach (['completeness', 'correctness'] as $aspect) :
        $grade = (isset($task->data['proposed-'.$aspect.'-grade'])) ? $task->data['proposed-'.$aspect.'-grade'] : '';

        $items['proposed-'.$aspect.'-grade'] = [
          '#markup' => '<h5>Proposed '.ucfirst($aspect).' Grade: '.$grade.'</h5>'
        ];

        $items['proposed-'.$aspect] = [
          '#markup' => '<h5>Proposed '.ucfirst($aspect).' Justification:</h5> <p>'
          .((isset($task->data['proposed-'.$aspect])) ? nl2br($task->data['proposed-'.$aspect]) : '').'</p>',
        ];

      endforeach;
      $items['justice lb'] = [
        '#markup' => '<p><strong>'.t('Grade Justification').':</strong></p>',
      ];
      $items['justice'] = [
        '#type' => 'item',
        '#markup' => '<p>'.nl2br($task->data['justification']).'</p>',
      ];
    endif;
    return $items;
  endif;

  $a = new Accordion('dispute-'.$task->task_id);

  // Problem for the Workflow
  $a->addGroup('Problem', 'problem-'.$task->task_id, sprintf('<h4>%s:</h4><p>%s</p>',
    t('Problem'),
    nl2br($params['problem']->data['problem'])
  ), true);

  // Solution for the Workflow
  $a->addGroup('Solution', 'solution-'.$task->task_id, sprintf('<h4>%s:</h4><p>%s</p><hr />',
    t('Solution'),
    nl2br($params['solution']->data['solution'])
  ), true);

  // Grades for the workflow
  $grades = Task::whereType('grade solution')
    ->where('workflow_id', '=', $task->workflow_id)
    ->get();

  if (count($grades) > 0) : foreach ($grades as $grade) :
    $c = '';
    
    foreach (['completeness', 'correctness'] as $aspect) :
      $c .= '<h4>'.t('Grade '.ucfirst($aspect)).': '.((isset($grade->data[$aspect.'-grade'])) ? $grade->data[$aspect.'-grade'] : '').'</h4>';

      if (isset($grade->data[$aspect]))
        $c .= '<p>'.nl2br($grade->data[$aspect]).'</p>';
    endforeach;

    $a->addGroup('Grader #'.$grade->task_id, 'grade-'.$grade->task_id, $c);
  endforeach; endif;

  // Dispute Grader
  $resolutionGrader = Task::whereType('resolution grader')
    ->where('workflow_id', '=', $task->workflow_id)
    ->whereStatus('complete')
    ->first();

  if ($resolutionGrader) :
    $c = '';
    
    foreach (['completeness', 'correctness'] as $aspect) :
      $c .= '<h4>'.t('Grade '.ucfirst($aspect)).': '.((isset($resolutionGrader->data[$aspect.'-grade'])) ? $resolutionGrader->data[$aspect.'-grade'] : '').'</h4>';

      if (isset($resolutionGrader->data[$aspect]))
        $c .= '<p>'.nl2br($resolutionGrader->data[$aspect]).'</p>';
    endforeach;

    $a->addGroup('Resolution Grader #'.$resolutionGrader->task_id, 'grade-'.$resolutionGrader->task_id, $c);
  endif;

  // Resolved Grade
  $c = '';
  $c .= sprintf('<h4>%s: %d%%</h4>', t('Grade Recieved'), $workflow->data['grade']);
  $a->addGroup('Resolved Grade', $task->task_id.'-resolved-grade', $c, true);

  // Add accordion to form
  $items[] = ['#markup' => $a];
  $items[] = ['#markup' => sprintf('<h5>%s</h5>', t('Current Task: Decide Whether to Dispute'))];

  if (isset($params['task']->settings['instructions']))
    $items[] = [
      '#markup' => sprintf('<p>%s</p>', t($params['task']->settings['instructions']))
    ];

  $items['no-dispute'] = [
    '#type' => 'submit',
    '#value' => 'Do Not Dispute',
  ];

  $items[] = [
    '#markup' => sprintf('<hr /><p>%s</p>', t('Complete the following only if you are going to dispute.'))
  ];
  
  foreach (['completeness', 'correctness'] as $aspect) :
    $items['proposed-'.$aspect.'-grade'] = [
      '#type' => 'textfield',
      '#title' => 'Proposed '.ucfirst($aspect).' Grade (0-50)',
      '#default_value' => (isset($task->data['proposed-'.$aspect.'-grade'])) ? $task->data['proposed-'.$aspect.'-grade'] : '',
    ];

    $items['proposed-'.$aspect] = [
      '#type' => 'textarea',
      '#title' => 'Proposed '.ucfirst($aspect).' Justification',
      '#default_value' => (isset($task->data['proposed-'.$aspect])) ? $task->data['proposed-'.$aspect] : '',
    ];
  endforeach;

  $items['justification'] = [
    '#type' => 'textarea',
    '#title' => 'Explain fully why all prior graders were wrong, and your regrading is correct.',
    '#default_value' => (isset($task->data['justification'])) ? $task->data['justification'] : '',
  ];

  $items['dispute-save'] = [
    '#type' => 'submit',
    '#value' => 'Save Dispute',
  ];

  $items['dispute-submit'] = [
    '#type' => 'submit',
    '#value' => 'Submit Dispute',
  ];

  $items['no-dispute-two'] = [
    '#type' => 'submit',
    '#value' => 'Do Not Dispute',
  ];
  return $items;
}

function gg_task_dispute_form_submit($form, &$form_state)
{
  $params = $form_state['build_info']['args'][0];
  $task = $params['task'];

  if (! $form_state['build_info']['args'][0]['edit'])
    return drupal_not_found();

  if (in_array($form_state['clicked_button']['#id'], ['edit-dispute-save', 'edit-dispute-submit']))
    $dispute = true;
  else
    $dispute = false;

  $task->setData('value', $dispute);

  if ($dispute) :
    foreach (['completeness', 'correctness'] as $aspect) :
      if (empty($form['proposed-'.$aspect.'-grade']['#value']) OR empty($form['proposed-'.$aspect]['#value']))
        return drupal_set_message(t('You didn\'t submit the '.$aspect.' justification and/or the propsed '.$aspect.' grade.'), 'error');

      // Save the fields
      $form['proposed-'.$aspect.'-grade']['#value'] = (int) $form['proposed-'.$aspect.'-grade']['#value'];

      if (
        $form['proposed-'.$aspect.'-grade']['#value'] !== abs($form['proposed-'.$aspect.'-grade']['#value'])
      OR
        $form['proposed-'.$aspect.'-grade']['#value'] < 0
      OR
        $form['proposed-'.$aspect.'-grade']['#value'] > 100
      )
        return drupal_set_message(t('Invalid grade: '.$form['proposed-'.$aspect.'-grade']['#value']));
      
      $task->setData('proposed-'.$aspect.'-grade', $form['proposed-'.$aspect.'-grade']['#value']);
      $task->setData('proposed-'.$aspect, trim($form['proposed-'.$aspect]['#value']));
    endforeach;

    // Overall Justice.
    if (empty($form['justification']['#value']))
      return drupal_set_message(t('You didn\'t pass the justification.'), 'error');
    else
      $task->setData('justification', trim($form['justification']['#value']));

    // Are they saving or doing it now
    $submit = ($form_state['clicked_button']['#id'] == 'edit-dispute-submit') ? TRUE : FALSE;

    if ($submit) :
      $task->complete();

      drupal_set_message(t('Your dispute has been submitted.'));
      return drupal_goto('class');
    else :
      $task->status = 'started';
      $task->save();

      drupal_set_message(t('Your dispute has been saved. (You must submit this still to complete the task.)'));
    endif;
  else :
    $task->save();
    $task->complete();
    drupal_set_message(t('Your decision to not dispute has been submitted.'));
  endif;
}



/**
 * Resolve Dispute
 */
function gg_task_resolve_dispute_form($form, &$form_state, $params)
{
  $task = $params['task'];

  $items = [];

  if (! $params['edit']) :
    
    $dataFields = ['completeness-grade', 'completeness', 'correctness-grade', 'correctness', 'justification'];
    
    $data = [];
    foreach ($dataFields as $field)
      $data[$field] = (isset($task->data[$field])) ? $task->data[$field] : '';

    $items['correctness-grade'] = [
      '#type' => 'item',
      '#markup' => sprintf('<p><strong>%s:</strong> %d%%', t('Correctness Grade'), $data['correctness-grade'])
    ];

    $items['correctness'] = [
      '#type' => 'item',
      '#markup' => sprintf('<p><strong>%s:</strong><br /> %s', t('Correctness'), nl2br($data['correctness']))
    ];

    $items['completeness-grade'] = [
      '#type' => 'item',
      '#markup' => sprintf('<p><strong>%s:</strong> %d%%', t('Completeness Grade'), $data['completeness-grade'])
    ];

    $items['completeness'] = [
      '#type' => 'item',
      '#markup' => sprintf('<p><strong>%s:</strong><br /> %s', t('Completeness'), nl2br($data['completeness']))
    ];

    $items['justice lb'] = [
      '#markup' => '<strong>'.t('Grade Justification').':</strong>',
    ];
    $items['justice'] = [
      '#type' => 'item',
      '#markup' => sprintf('<p>%s</p>', nl2br($data['justification'])),
    ];
    return $items;
  endif;

  if (isset($params['task']->settings['instructions']))
    $items[] = [
      '#markup' => sprintf('<p>%s</p>', t($params['task']->settings['instructions']))
    ];

  $items[] = [
    '#markup' => '<h4>'.t('Problem').':</h4>'
    .'<p>'.nl2br($params['problem']->data['problem']).'</p>'
  ];

  $items[] = [
    '#markup' => '<h4>'.t('Solution').':</h4>'
    .'<p>'.nl2br($params['solution']->data['solution']).'</p><hr />'
  ];

  $a = new Drupal\ClassLearning\Common\Accordion('resolve-dispute');

  // Get the grades
  $grades = Task::whereType('grade solution')
    ->where('workflow_id', '=', $task->workflow_id)
    ->get();

  if (count($grades) > 0) : foreach ($grades as $grade) :
    $c = '';

    foreach (['completeness', 'correctness'] as $aspect) :
      $c .= '<h4>'.t('Grade '.ucfirst($aspect)).': '.((isset($grade->data[$aspect.'-grade'])) ? $grade->data[$aspect.'-grade'] : '').'</h4>';

      if (isset($grade->data[$aspect]))
        $c .= '<p>'.nl2br($grade->data[$aspect]).'</p>';
    endforeach;

    $a->addGroup('Grader #'.$grade->task_id, 'grade-'.$grade->task_id, $c);
  endforeach; endif;

  // Resolved Grade (automatically or via resolution grader)
  $c = '';
  $c .= '<h4>'.t('Grade Recieved').': '.$params['workflow']->data['grade'].'%</h4>';
  $a->addGroup('Resolved Grade', 'resolved-grade', $c);

  // Dispute Grader
  $disputeTask = Task::whereType('dispute')
    ->where('workflow_id', '=', $task->workflow_id)
    ->first();

  if ($disputeTask) :
    $c = '';
    foreach (['completeness', 'correctness'] as $aspect) :
      $c .= '<h4>'.t('Proposed '.ucfirst($aspect).' Grade').': '.$disputeTask->data['proposed-'.$aspect.'-grade'].'</h4>';
    $c .= '<h4>'.t('Proposed '.ucfirst($aspect).' Justification').': </h4><p>'.nl2br($disputeTask->data['proposed-'.$aspect]).'</p>';
    endforeach;
    
    $c .= '<h4>'.t('Explain fully why all prior graders were wrong, and your regrading is correct').':</h4>';
    $c .= '<p>'.nl2br($disputeTask->data['justification']).'</p>';

    $a->addGroup('Dispute Grader #'.$disputeTask->task_id, 'grade-'.$disputeTask->task_id, $c);
  endif;

  // Accordion
  $items[] = [
    '#markup' => $a.'<hr />',
  ];


  $items['completeness-grade'] = [
    '#type' => 'textfield',
    '#title' => 'Completeness Grade (0-50)',
    '#required' => true,
    '#default_value' => (isset($task->data['completeness-grade'])) ? $task->data['completeness-grade'] : '',
  ];
  $items['completeness'] = [
    '#type' => 'textarea',
    '#title' => 'Grade Completeness',
    '#required' => true,
    '#default_value' => (isset($task->data['completeness'])) ? $task->data['completeness'] : '',
  ];

  $items['correctness-grade'] = [
    '#type' => 'textfield',
    '#title' => 'Correctness Grade (0-50)',
    '#required' => true,
    '#default_value' => (isset($task->data['correctness-grade'])) ? $task->data['correctness-grade'] : '',
  ];
  $items['correctness'] = [
    '#type' => 'textarea',
    '#title' => 'Correctness',
    '#required' => true,
    '#default_value' => (isset($task->data['correctness'])) ? $task->data['correctness'] : '',
  ];

  $items['justification'] = [
    '#type' => 'textarea',
    '#title' => 'Grade Justification',
    '#required' => true,
    '#default_value' => (isset($task->data['justification'])) ? $task->data['justification'] : '',
  ];
  $items['save'] = [
    '#type' => 'submit',
    '#value' => 'Save Grade For Later',
  ];
  $items['submit'] = [
    '#type' => 'submit',
    '#value' => 'Submit Grade',
  ];
  return $items;
}

/**
 * Callback submit function for class/task/%
 */
function gg_task_resolve_dispute_form_submit($form, &$form_state) {
  $params = $form_state['build_info']['args'][0];
  $task = $params['task'];

  if (! $form_state['build_info']['args'][0]['edit'])
    return drupal_not_found();
  
  $gradeSum = 0;

  foreach (['completeness-grade', 'correctness-grade'] as $grade) :
    $form[$grade]['#value'] = (int) $form[$grade]['#value'];

    if ($form[$grade]['#value'] !== abs($form[$grade]['#value'])
      OR $form[$grade]['#value'] < 0 OR $form[$grade]['#value'] > 50)
      return drupal_set_message(t('Invalid grade: '.$grade));
    else
      $gradeSum += $form[$grade]['#value'];
  endforeach;

  $save = ($form_state['clicked_button']['#id'] == 'edit-save' );

  $dataFields = ['completeness-grade', 'completeness', 'correctness-grade', 'correctness', 'justification'];
  foreach ($dataFields as $field)
    $task->setData($field, $form[$field]['#value']);

  if ($task->status !== 'timed out') $task->status = ($save) ? 'started' : 'completed';
  $task->save();

  drupal_set_message(sprintf('%s %s', t('Grade'), ($save) ? 'saved. (You must submit this still to complete the task.)' : 'submitted.'));

  if (! $save) :
    $task->complete();

    // Save to the workflow
    $params['workflow']->setData('grade', $gradeSum);
    $params['workflow']->save();

    return drupal_goto('class');
  endif;
}

/**
 * View a workflow
 * @param int
 */
function gg_view_workflow($workflow_id, $admin = false)
{
  global $user;

  if ($admin AND is_object($workflow_id)) :
    $workflow = $workflow_id;
    $workflow_id = $workflow->workflow_id;
  else :
    $workflow = Workflow::find($workflow_id);
    if ($workflow == NULL) return drupal_not_found();
  endif;

  $tasks = $workflow->tasks();

  if (! $admin)
    $tasks->whereStatus('complete');

  $tasks = $tasks->get();

  $return = '';

  $asec = $workflow->assignmentSection()->first();
  $assignment = $asec->assignment()->first();

  // Back Link
  if (! $admin)
    $return .= sprintf(
      '<p><a href="%s">%s %s</a></p>', url('class/assignments/'.$asec->section_id.'/'.$asec->asec_id), 
      HTML_BACK_ARROW,
      t('Back to Problem Listing')
    );

  // Course/section/semester
  $section = $asec->section()->first();
  $course = $section->course()->first();
  $semester = $section->semester()->first();
  $students = $section->students()->get();

  if (! $admin)
    $return .= sprintf('<p><strong>%s</strong>: %s &mdash; %s &mdash; %s',
      t('Course'),
      $course->course_name,
      $section->section_name,
      $semester->semester_name
    );

  $return .= '<p class="summary">'.nl2br($assignment->assignment_description).'</p><hr />';

  // Special ADMIN instructions
  if ($admin)
  {
    $return .= sprintf('<p>%s</p>',
      t('Below you see the tasks that are part of this single '
        .'problem from this assignment. Some tasks may not have been completed or even '
        .'started or assigned yet. Notes:'
      ));

    $return .= '<ol>';
    $strings = [
      'Any tasks with a yellow background require your attention in that they have timed out, thus halting the flow of the problem.',
'The status "triggered" means the task has been assigned but not completed yet. It is not necessarily late.',
'The status "not triggered" means the task has been allocated but is not ready to be started yet, because a prior task first needs to complete.',
'The status "task expired (skipped)" means that this task was not needed and was skipped over.',
'You have permission reallocate participants to do a task, but be very careful since this could cause confusion and unintended complications.',
    ];

    foreach ($strings as $s)
      $return .= sprintf('<li>%s</li>', $s);

    $return .= '</ol>';
  }

  // Wrap it all inside an accordion
  $a = new Accordion('workflow-'.$workflow->workflow_id);

  if (count($tasks) > 0) : foreach ($tasks as $task) :
    if (! $admin AND $task->type !== 'grades ok' AND isset($task->settings['internal']) AND $task->settings['internal'])
      continue;

    // Options passed to the accordion
    $options = [];

    $panelContents = '';

    // Add user information if they're an admin
    if ($admin) :
      if ($task->user_id !== NULL) :
        $taskUser = user_load($task->user_id);

        $panelContents .= sprintf('<p><strong>%s:</strong> <a href="%s">%s</a></p>',
          t('Assigned User'),
          url('user/'.$task->user_id),
          ggPrettyName($taskUser)
        );

        $form = drupal_get_form('gg_reassign_task', $task, $section, $students);
        $panelContents .= drupal_render($form);
      endif;

      $panelContents .= sprintf('<p><strong>%s:</strong> %s</p>', t('Status'), t(ucwords($task->status)));
      $panelContents .= '<hr />';

      if ($task->status == 'timed out')
        $options['style'] = 'background-color: yellow;';
    endif;

    if ($task->user_id == $user->uid)
      $panelContents .= sprintf('<p><em>%s</em></p>', t('You performed this task!'));
    // Determine the panel contents
    if (in_array($task->status, ['triggered', 'complete', 'started']))
      $panelContents .= groupgrade_view_task($task, 'overview', $admin);
    elseif ($task->status == 'not triggered')
      $panelContents .= sprintf('<div class="alert">%s</div>', t('Task not triggered.'));
    elseif ($task->status == 'expired')
      $panelContents .= sprintf('<div class="alert">%s</div>', t('Task bypassed.'));
    elseif ($task->status == 'timed out')
      $panelContents .= sprintf('<div class="alert">%s</div>', t('Task timed out (failed to submit).'));

    $a->addGroup(t(ucwords($task->type)), $workflow->workflow_id.'-'.$task->task_id, $panelContents, false, $options);
  endforeach; endif;

  // Append the accordions
  $return .= $a;

  drupal_set_title(sprintf('%s: %s', t('Assignment'), $assignment->assignment_title));

  return $return;
}

function gg_task_grades_ok_form($form, &$form_state, $params) {
  $workflow = $params['task']->workflow()->first();

  $items = [];
  $items['final grade'] = [
    '#markup' => sprintf('<p><strong>%s:</strong> %d', t('Final Grade (Highest grade used)'), $workflow->data['grade']),
  ];
  return $items;
}


/**
 * Impliments a edit problem form
 */
function gg_task_resolution_grader_form($form, &$form_state, $params) {
  $problem = $params['problem'];
  $solution = $params['solution'];
  $task = $params['task'];

  // Previous Grades
  $grades = Task::where('workflow_id', '=', $task->workflow_id)
    ->whereType('grade solution')
    ->whereStatus('complete')
    ->get();

  $items = [];
  $items['problem'] = [
    '#markup' => '<h4>Problem</h4><p>'.nl2br($problem->data['problem']).'</p><hr />',
  ];
  $items['solution'] = [
    '#markup' => '<h4>Solution</h4><p>'.nl2br($solution->data['solution']).'</p><hr />',
  ];

  if (! $params['edit']) :
    $items['grade lb'] = [
      '#markup' => sprintf('<strong>%s:</strong>', t('Grade')),
    ];
    $items['grade'] = [
      '#type' => 'item',
      '#markup' => (isset($task->data['grade'])) ? $task->data['grade'] : '',
    ];

    $items['justice lb'] = [
      '#markup' => sprintf('<strong>%s:</strong>', t('Grade Justification')),
    ];
    $items['justice'] = [
      '#type' => 'item',
      '#markup' => (isset($task->data['justification'])) ? nl2br($task->data['justification']) : '',
    ];

    $items['comment lb'] = [
      '#markup' => sprintf('<strong>%s:</strong>', t('Why was it resolved it this way?')),
    ];
    $items['comment'] = [
      '#type' => 'item',
      '#markup' => (isset($task->data['comment'])) ? nl2br($task->data['comment']) : '',
    ];

    return $items;
  endif;

  if (isset($params['task']->settings['instructions']))
    $items[] = [
      '#markup' => sprintf('<p>%s</p>', t($params['task']->settings['instructions']))
    ];

  // Previous grades
  if (count($grades) > 0) : foreach ($grades as $grade) :
    $items[] = [
      '#markup' => sprintf('<h4>%s: %s</h4>', t('Completeness Grade'), $grade->data['completeness-grade'])
    ];

    $items[] = [
      '#markup' => sprintf('<p><strong>%s</strong>: %s</p>', t('Completeness'), nl2br($grade->data['completeness']))
    ];

    $items[] = [
      '#markup' => sprintf('<h4>%s: %s</h4>', t('Correctness Grade'), $grade->data['correctness-grade'])
    ];

    $items[] = [
      '#markup' => sprintf('<p><strong>%s</strong>: %s</p>', t('Correctness'), nl2br($grade->data['correctness']))
    ];

    $items[] = [
      '#markup' => '<hr />',
    ];
  endforeach; endif;

  $items['grade'] = [
    '#type' => 'textfield',
    '#title' => 'Grade (0-100)',
    '#required' => true,
    '#default_value' => (isset($task->data['grade'])) ? $task->data['grade'] : '',
  ];

  $items['justification'] = [
    '#type' => 'textarea',
    '#title' => 'Grade Justification',
    '#required' => true,
    '#default_value' => (isset($task->data['justification'])) ? $task->data['justification'] : '',
  ];

  $items['comment'] = [
    '#type' => 'textarea',
    '#title' => 'Why did you resolve it this way?',
    '#required' => true,
    '#default_value' => (isset($task->data['comment'])) ? $task->data['comment'] : '',
  ];
  $items['save'] = [
    '#type' => 'submit',
    '#value' => 'Save Grade For Later',
  ];
  $items['submit'] = [
    '#type' => 'submit',
    '#value' => 'Submit Grade',
  ];
  return $items;
}

/**
 * Callback submit function for class/task/%
 */
function gg_task_resolution_grader_form_submit($form, &$form_state) {
  $params = $form_state['build_info']['args'][0];
  $task = $params['task'];

  if (! $form_state['build_info']['args'][0]['edit'])
    return drupal_not_found();
    
  $grade = (int) $form['grade']['#value'];
  if ($grade !== abs($grade) OR $grade < 0 OR $grade > 100)
    return drupal_set_message(t('Invalid grade: '.$grade));
  
  $save = ($form_state['clicked_button']['#id'] == 'edit-save' );
  $task->setDataAttribute([
    'grade' =>  $grade,
    'justification' => $form['justification']['#value'],
    'comment' => $form['comment']['#value']
  ]);

  if ($task->status !== 'timed out') $task->status = ($save) ? 'started' : 'completed';
  $task->save();

  if (! $save) :
    $task->complete();

    $workflow = $task->workflow()->first();
    $workflow->setData('grade', $grade);
    $workflow->save();
  endif;
  
  drupal_set_message(sprintf('%s %s', t('Grade'), ($save) ? 'saved. (You must submit this still to complete the task.)' : 'submitted.'));

  if (! $save)
    return drupal_goto('class');
}

/**
 * Used only to display to instructors wheater grades were automatically resolved 
 */
function gg_task_resolve_grades_form($form, &$form_state, $params)
{
  $task = $params['task'];

  $items = [];
  $items[] = [
    '#markup' => sprintf('<p>Workflow grades <strong>%s</strong> automatically resolved.</p>',
      ($task->data['value']) ? 'were' : 'were not'
    )
  ];

  return $items;
}

/**
 * Form to handle reassigning a task
 */
function gg_reassign_task($form, &$form_state, $task, $section, $students)
{
  $items = $index = [];

  if (count($students) > 0) : foreach($students as $student) :
    $user = user_load($student->user_id);
    if (! $user) continue;

    $index[$student->user_id] = ggPrettyName($user);
  endforeach; endif;

  $items['user'] = array(
     '#type' => 'select',
     '#title' => t('Reassign Task to User'),
     '#options' => $index,
     '#default_value' => $task->user_id,
 );

  $items['section'] = array(
    '#value' => $section->section_id,
    '#type' => 'hidden'
  );

  $items['submit'] = [
    '#type' => 'submit',
    '#value' => 'Reassign Task (Will re-start the task)',
  ];

  $items[] = [
    '#markup' => '<hr />'
  ];

  return $items;
}

/**
 * Form Submit to handle reassigning a task
 */
function gg_reassign_task_submit($form, &$form_state)
{
  $task = $form_state['build_info']['args'][0];
  $section = $form_state['build_info']['args'][1];

  $user = (int) $form['user']['#value'];

  if ($user == $task->user_id)
    return drupal_set_message(t('You cannot reassign the same user to the task.'), 'error');

  $task->user_id = $user;
  $task->trigger(true);

  return drupal_set_message('User reassigned and task re-triggered.');
}

/**
 * Reassign to Contingency Tasks
 */
function groupgrade_reassign_to_contig() {
  $pool = $removePool = [];
  foreach ([
    'omf3', 'dkp35', 'eak8', 'dcs24', 'dbh2', 'jrb42', 'eos2', 'amr48', 'mr429', 'mm57'
  ] as $u)
    $pool[] =  user_load_by_name($u);

  // Let's find the people we're going to remove
  foreach ([
    'aza4', 'cjr29', 'fj37', 'sp279', 'fp38', 'gp88', 'sb455', 'jrm57', 'jcm38', 'pmv9', 'mc374', 'nac4', 'mlk6', 'ajp47', 'dbp35', 'scf22', 'dka8'
  ] as $u)
    $removePool[] = user_load_by_name($u);

  // Get all of their tasks and reassign them randomly
  if ($removePool) : foreach ($removePool as $removeUser) :
    echo "Removing tasks for ".$removeUser->name.PHP_EOL;

    $tasks = Task::where('user_id', $removeUser->uid)
      ->groupBy('workflow_id')
      ->whereIn('status', ['not triggered', 'triggered', 'started', 'timed out'])
      ->get();

    // They're not assigned any tasks that we're going to change
    if (count($tasks) == 0) :
      echo "No tasks to remove!".PHP_EOL;
      continue;
    endif;

    // Go though all assigned tasks
    foreach ($tasks as $task)
    {
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
        if (Task::where('workflow_id', $task->workflow_id)
          ->where('user_id', $reassignUser->uid)
          ->count() == 0)
          // They're not in the workflow!
          $foundUser = TRUE;
      }

      // Now that we've found the user, let's reassign it
      // We're going to reassign all tasks assigned to this user in the workflow
      Task::where('user_id', $removeUser->uid)
        ->where('workflow_id', $task->workflow_id)
        ->whereIn('status', ['triggered', 'started', 'timed out'])
        ->update([
          'user_id' => $reassignUser->uid,
          'status' => 'triggered',
          'start' => Carbon\Carbon::now()->toDateTimeString(),
         // 'force_end' => $this->timeoutTime()->toDateTimeString()
        ]);

        // Different for non-triggered already
        Task::where('user_id', $removeUser->uid)
        ->where('workflow_id', $task->workflow_id)
        ->where('status', 'not triggered')
        ->update([
          'user_id' => $reassignUser->uid,
        ]);
    }
  endforeach; endif;
  echo PHP_EOL.PHP_EOL."DONE!!!!";
  exit;
}