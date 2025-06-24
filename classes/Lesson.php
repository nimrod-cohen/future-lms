<?php 
namespace FutureLMS\classes;
use FutureLMS\classes\BaseObject;

  /*
  Should've been named Class but its a reserved word in PHP
  */
  class Lesson extends BaseObject {
    public function __construct($lesson_id_or_params = null) {
        parent::__construct('lesson', $lesson_id_or_params);
    }
  }