<?php
namespace Drupal\ClassLearning\Models;
use Illuminate\Database\Eloquent\Model as ModelBase,
  Drupal\ClassLearning\Models\WorkflowTask,
  Drupal\ClassLearning\Exception as ModelException;

class TaskActivity extends ModelBase {
  protected $primaryKey = 'TA_id';
  protected $table = 'task_activity';
  public $timestamps = false;

  public function getTask()
  {
    return WorkflowTask::where('ta_id','=',$this->ta_id)
	  ->first();
  }
  
  
  
}
