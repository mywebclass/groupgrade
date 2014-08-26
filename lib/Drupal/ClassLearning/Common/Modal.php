<?php

//Container for Twitter Bootstrap's Modal

namespace Drupal\ClassLearning\Common;

class Modal{
	
	private $id;
	private $body;
	private $title;
	
	public function __construct($id = 'modal'){
		$this->id = $id;
	}
	
	public function printLink(){
		return sprintf('<a href="#" data-toggle="modal" data-target="#%s">Click here to view.</a>',$this->id);
	}
	
	public function setBody($body){
		$this->body = $body;
	}
	
	public function setTitle($title){
		$this->title = $title;
	}
	
	public function build(){
		sprintf('
		
		<div class="modal fade" id="%s" tabindex="-1" role="dialog" aria-labelledby="MyModalLabel" aria-hidden="true">
		  <div class="modal-dialog">
		    <div class="modal-content">
		      <div class="modal-header">
		        <button type="button" class="close" data-dismiss="modal"><span aria-hidden="true">&times;</span><span class="sr-only">Close</span></button>
		        <h4 class="modal-title" id="myModalLabel">%s</h4>
		      </div>
		      
			  <div class="modal-body">%s</div>
			  
			  <div class="modal-footer">
			    <button type="button" class="btn btn-default" data-dismiss="modal">Close</button>
			  </div>
			</div>
		  </div>
		</div>
		
		',$id,$title,$body);
	}
	
}
