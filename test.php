<?php
class Dad {
    function __construct(){
		echo 'constructor of ' . __CLASS__ . ' is called (dad).<br>';
    }

	function getMyName(){
		echo __CLASS__;
	}

}

class Child extends Dad {
    function __construct(){
		parent::__construct();
		echo 'constructor of ' . __CLASS__ . ' is called. (child)<br>';
    }
}

$obj = new Child();
echo $obj->getMyName();
