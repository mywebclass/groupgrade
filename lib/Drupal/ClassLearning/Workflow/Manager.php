<?php
namespace Drupal\ClassLearning\Workflow;

use Drupal\ClassLearning\Models\SectionUsers,
  Drupal\ClassLearning\Models\Section,
  Drupal\ClassLearning\Models\Semester,
  Drupal\ClassLearning\Models\Workflow,
  Drupal\ClassLearning\Models\WorkflowTask,
  Drupal\ClassLearning\Models\AssignmentSection,

  Drupal\ClassLearning\Workflow\Allocator,
  Drupal\ClassLearning\Workflow\TaskFactory,

  Drupal\ClassLearning\Exception as ManagerException,
  Illuminate\Database\Capsule\Manager as Capsule,
  Carbon\Carbon;

/**
 * Manager of the Workflow
 *
 * @package groupgrade
 * @subpackage workflows
 */
class Manager {
  /**
   * Check on Task Instances
   * 
   * Checks on the non-trigger tasks to see if they should be
   * triggered to start.
   *
   * This is handled by the cron
   * 
   * @access public
   */
  public static function checkTaskInstances()
  {
    $tasks = WorkflowTask::whereStatus('not triggered')
      ->get();

    if (count($tasks) > 0) : foreach ($tasks as $task) :
      self::checkTaskInstance($task);
    endforeach; endif;
  }

  /**
   * Check on a Task Instance
   * 
   * Checks on a task to see if it should be triggered to start.
   * 
   * @access public
   */
  public static function checkTaskInstance(WorkflowTask $task)
  {
    if ($task->triggerConditionsAreMet())
      $task->trigger();
  }

  /**
   * Notify the user of a status change on their assigned tasks
   *
   * @param string Type of change (triggered/expiring/expired)
   * @param WorkflowTask Workflow task to work with
   * @throws Drupal\ClassLearning\Exception
   */
  public static function notifyUser($event, &$task)
  {
    // Determine a few things
    $subject = $body = '';
    $user = user_load($task->user_id);

    // Determine what they're doing in a human format
    switch ($task->type)
    {
      case 'create problem' :
        $action_human = 'Create a Problem';
        break;

      case 'edit problem' :
        $action_human = 'Edit a Problem';
        break;
      
      case 'grade solution' :
        $action_human = 'Grade a Solution';
        break;
      
      case 'create solution' :
        $action_human = 'Create a Solution';
        break;
      
      case 'resolution grader' :
        $action_human = 'Resolve Grades';
        break;

      case 'dispute' :
        $action_human = 'Dispute Grade';
        break;

      case 'resolve dispute' :
        $action_human = 'Resolve Dispute';
        break;  

      default :
        $action_human = 'Unknown Action';    
    }

    // triggered/expiring/expired
    switch ($event)
    {
      case 'triggered' :
        $subject = 'CLASS: New Assignment Action';
        $body = sprintf('You have been assigned to :%s. You can view the task at <a href="%s">%s</a>', $action_human, url('class'), url('class'));
        break;

      case 'expiring' :
        $body = sprintf(
          'Your task that you have been assigned to (%s) is going to be ending soon.
          You should complete it to ensure you recieve full credit. You can view the task at <a href="%s">%s</a>',
          $action_human, 
          url('class'), 
          url('class')
        );
        break;

      case 'expiring' :
        $body = sprintf(
          'Your task that you have been assigned to (%s) has expired. You can no longer complete the task. View all of your tasks at <a href="%s">%s</a>',
          $action_human, 
          url('class'), 
          url('class')
        );
        break;

      default :
        throw new ManagerException(sprintf('Unknown event type %s to notify for task %d', $event, $task->task_id));
    }

    $from = variable_get('site_mail', 'noreply@groupgrade.dev');
    $params = compact($body, $subject, $event, $task);

    $result = drupal_mail('groupgrade', 'notify '.$event, $user->mail, language_default(), $params, $from, TRUE);

    if ($result['result'] !== TRUE)
      throw new ManagerException(
        sprintf('Error notifing user for task %s %s: %s', $event, $task->task_id, print_r($result))
      );
    else
      return TRUE;
  }

  /**
   * Check to see if an assignment section should be triggered to start
   * 
   * @param AssignmentSection
   * @return boolean
   */
  public static function checkAssignment(AssignmentSection &$assignment)
  {
    $date = Carbon::createFromFormat('Y-m-d H:i:s', $assignment->asec_start);

    // Did it pass yet?
    if ($date->isPast() AND ! $this->isStarted())
      return self::trigger($assignment);
    else
      return FALSE;
  }

  /**
   * See if an assignment has already been triggered to start
   *
   * @param AssignmentSection
   * @return bool
   */
  public static function isStarted(AssignmentSection $a)
  {
    return (Workflow::where('assignment_id', '=', $a->asec_id)->count() > 0) ? TRUE : FALSE;
  }

  /**
   * Trigger the start of a assignment's processing
   *
   * @param AssignmentSection
   * @return mixed
   */
  public static function trigger(AssignmentSection &$a)
  {
    // Let's get all the users and all the different roles
    $users = SectionUsers::where('section_id', '=', $a->section_id)
      ->where('su_status', '=', 'active')
      ->get();

    $workflows = [];

    // We're just creating a workflow for each user
    // They're not actually assigned to this workflow
    foreach($users as $null) :
      $w = new Workflow;
      $w->type = 'one_a';
      $w->assignment_id = $a->asec_id;
      $w->workflow_start = Carbon::now()->toDateTimeString();
      $w->save();

      // Create the workflows tasks
      self::triggerTaskCreation($w, $a, $users);

      $workflows[] = $w;
    endforeach;

    // Allocate the users
    self::allocateUsers($a, $users, $workflows);
  }

  /**
   * Assign the users to tasks
   *
   * @param AssignmentSection
   * @param SectionUsers
   * @return void
   */
  public static function allocateUsers(AssignmentSection $a, $users, &$workflows)
  {
    $allocator = new Allocator($users);

    foreach (self::getTasks() as $role_name => $role)
    {
      if (! isset($role['internal']) OR ! $role['internal']) :
        $count = 1;

        if (isset($role['count']))
          $count = $role['count'];

        for ($i = 0; $i < $count; $i++)
          $allocator->createRole($role_name);
      endif;
    }

    foreach ($workflows as $workflow)
      $allocator->addWorkflow($workflow->workflow_id);

    $allocator->assignmentRun();

    // Now we have to intepert the response of the allocator
    $taskInstances = $allocator->getTaskInstanceStorage();
    $workflows = $allocator->getWorkflows();

    foreach ($workflows as $workflow_id => $workflow)
    {
      foreach ($workflow as $role_id => $assigned_user)
      {
        $taskInstanceId = $taskInstances[$workflow_id][$role_id];
        $taskInstance = WorkflowTask::find($taskInstanceId);

        if ($taskInstance == NULL)
          throw new ManagerException(
            sprintf('Task instance %d cannot be found for workflow %d', $taskInstanceId, $workflow_id));

        $taskInstance->user_id = $assigned_user;
        $taskInstance->save();
      }
    }
  }

  /**
   * Trigger Task Creation
   *
   * @todo Make this dynamic in that it will trigger tasks based upon the workflow type. Also specify task time
   * @access protected
   * @param Workflow
   * @param AssignmentSection
   * @param SectionUsers
   */
  protected static function triggerTaskCreation(&$workflow, &$assignment, &$users)
  {
    $factory = new TaskFactory($workflow, self::getTasks());
    $factory->createTasks();
  }

  /**
   * Get the workflow tasks
   *
   * @return array
   */
  public static function getTasks()
  {
    return [
      'create problem' => [
        'duration' => 3,
        'trigger' => [
          [
            'type' => 'first task trigger',
          ]
        ],
      ],

      'edit problem' => [
        'pool' => 'instructor',
        'duration' => 1,
        'trigger' => [
          [
            'type' => 'reference task status',
            'task type' => 'create problem',
            'task status' => 'complete',
          ],
        ],
      ],

      'create solution' => [
        'duration' => 3,
        'trigger' => [
          [
            'type' => 'reference task status',
            'task type' => 'edit problem',
            'task status' => 'complete',
          ],
        ],
      ],

      'grade solution' => [
        'count' => 2,
        'duration' => 3,
        'trigger' => [
          [
            'type' => 'reference task status',
            'task type' => 'create solution',
            'task status' => 'complete',
          ],
        ],
      ],

      // Resolve the grades
      'resolve grades' => [
        'internal' => true,

        // Default value
        'value' => true,

        // Trigger once all the grades are submitted
        'trigger' => [
          [
            'type' => 'reference task status',
            'task type' => 'grade solution',
            'task status' => 'complete',
          ],
        ],
      ],

      // Grades are fine, store them in the workflow
      'grades ok' => [
        'internal' => true,
        'trigger' => [
          [
            'type' => 'value of task in range',
            'task type' => 'resolve grades',

            // Range of 15 points
            'range' => 15,
          ]
        ],
        
        // Expire if grades are out of range
        'expire' => [
          [
            'type' => 'value of task out of range',
            'task type' => 'resolve grades',

            // Range of 15 points
            'range' => 15,
          ]
        ],
      ],

      // Grades are out of a range and we need a second grader
      'resolution grader' => [
        'trigger' => [
          [
            'type' => 'value of task out of range',
            'task type' => 'resolve grades',

            // Range of 15 points
            'range' => 15,
          ]
        ],

        // Expire if grades are in range
        'expire' => [
          [
            'type' => 'value of task in range',
            'task type' => 'resolve grades',

            // Range of 15 points
            'range' => 15,
          ]
        ],
      ],

      // Dispute grades
      // This step gives the option to dispute the grade they have recieved on their
      // soln to yet-another-grader
      'dispute' => [
        'duration' => 2,
        'alias user' => 'create solution',
        
        // Default value
        'value' => false,

        // Trigger this if one of the tasks "resolution grader" or
        // "grades ok" is complete.
        'trigger' => [
          [
            'type' => 'check tasks for status',
            'task types' => ['resolution grader', 'grades ok'],
            'task status' => 'complete'
          ],
        ],
      ],

      // Resolve a dispute and end the workflow
      // Trigger only if the "dispute" task has a value of true
      'resolve dispute' => [
        'pool' => 'instructor',
        'duration' => 2,

        'trigger' => [
          [
            'type' => 'compare value of task',
            'task type' => 'dispute',
            'compare value' => true,
          ],
        ],
      ],
    ];
  }
}
