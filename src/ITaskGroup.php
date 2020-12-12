<?php
/**
 * Created by PhpStorm.
 * User: cxm
 * Date: 2020/12/12
 * Time: 9:32 AM
 */
namespace PProcess;

interface ITaskGroup {
    const FORK_FAILD = 2;
    const FORK_CHILD = 0;
    const FORK_PARENT = 1;

    public function start(): int;

    public function restart();

    public function quit();

    public function isRestarting();

    public function isStoping();

    public function getTaskCount(): int;

    public function setTaskCount(int $num);

    public function doRun();

    function isTaskPid(int $pid): bool;

    function removePid(int $pid);

    public function updateTasks();

    public function setMasterPid(int $pid);
}