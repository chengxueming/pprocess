<?php
/**
 * Created by PhpStorm.
 * User: cxm
 * Date: 2020/12/12
 * Time: 9:43 AM
 */
include './vendor/autoload.php';
use PProcess\{
    Manager,
    TaskGroup
};

class TaskGroupTest extends TaskGroup {
    public function doRun() {
        while (true) {
            $this->log('hello world');
            $this->heartbeat();
        }
    }
}

$m = new Manager();
$m->addTaskGroup(new TaskGroupTest(), 'test');
$m->run();