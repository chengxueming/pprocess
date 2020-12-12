<?php
/**
 * Created by PhpStorm.
 * User: cxm
 * Date: 2020/12/12
 * Time: 9:47 AM
 */

namespace PProcess;

interface IManager {
    function run();
    function addTaskGroup(ITaskGroup $task, string $name);
}