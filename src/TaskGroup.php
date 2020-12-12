<?php
/**
 * Created by PhpStorm.
 * User: cxm
 * Date: 2020/12/12
 * Time: 9:36 AM
 */
namespace PProcess;

abstract class TaskGroup implements ITaskGroup {

    private $pids;
    const STAGE_NORMAL = 'normal';
    const STAGE_QUIT = 'quit';
    const STAGE_RESTARTING = 'restart';
    private $task_count = 10;

    private $masterPid = -1;

    private $start_servers;

    private $inShutDown = false;

    private $stage = self::STAGE_NORMAL;

    public function start() : int {
        return $this->makeChildren($this->start_servers);
    }

    public function quit() {
        $this->stage = self::STAGE_QUIT;
        $this->killAll(SIGQUIT);
    }

    public function setMasterPid(int $pid) {
        $this->masterPid = $pid;
    }

    public function restart() {
        $this->stage = self::STAGE_RESTARTING;
        $this->killAll(SIGUSR2);
    }

    public function isRestarting() {
        return $this->stage == self::STAGE_RESTARTING;
    }

    public function isStoping() {
        return $this->stage == self::STAGE_QUIT;
    }

    public function getTaskCount() : int {
        return count($this->pids);
    }

    public function setTaskCount(int $num) {
        $this->task_count = $num;
    }

    public function updateTasks() {
        $this->makeChildren($this->task_count - count($this->pids));
    }

    public function isTaskPid(int $pid): bool {
        return !empty($this->pids[$pid]);
    }

    public function removePid(int $pid)
    {
        unset($this->pids[$pid]);
    }

    /**
     * 给所有子进程发送信号，重启或退出时用
     *
     * @param [type] $signo
     * @return void
     */
    private function killAll($signo){
        $aliveCount = 0;
        foreach($this->pids as $pid => $value) {
            $ret = posix_kill($pid, $signo);
            if(!$ret) {
                $aliveCount++;
            }
        }
        if($aliveCount > 0) {
            $this->log("kill all alive count is : " . $aliveCount, ErrorLevel::WARNNING);
        }
    }

    /**
     *
     *
     * @param int $n fork的进程数量
     * @return int
     */
    private function makeChildren($n)
    {
        for($i = 0; $i < $n; $i++) {
            $pid = pcntl_fork();
            switch ($pid) {
                case 0:
                    return self::FORK_CHILD;
                case -1:
                    return self::FORK_FAILD;
                default:
                    $this->pids[$pid] = 1; // 记录当前存活的进程
                    break;
            }
        }
        return self::FORK_CHILD;
    }

    /**
     * 子进程中当一个单位的任务完成后调用，防止在一个任务执行中被中断
     *
     * @param \Callable $clearUpFunc
     * @return void
     */
    protected function heartbeat($clearUpFunc = null){
        // 检查是否被父进程告知要死去
        pcntl_signal_dispatch();
        if($this->inShutDown) {
            $this->log("child quit for master notify");
        } else if(!file_exists("/proc/{$this->masterPid}")) {
            // 检查父进程是否已经死去
            $this->log("child quit for master die");
            $this->inShutDown = true;
        }
        if($this->inShutDown) {
            if(is_callable($clearUpFunc)) {
                call_user_func($clearUpFunc);
            }
            exit;
        }
    }

    /**
     * 写入日志
     *
     * @param string $str
     * @param string $level
     * @return void
     */
    protected function log($str, $level = ErrorLevel::DEBUG){
        $str = sprintf("pid %s time %s level %s: %s" . PHP_EOL, posix_getpid(), microtime(true), $level, $str);
        fwrite (STDOUT , $str);
    }
}