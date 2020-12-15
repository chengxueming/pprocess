<?php
namespace PProcess;


class Manager implements IManager {
    const STAGE_NORMAL = 'normal';
    const STAGE_QUIT = 'quit';
    const STAGE_RESTARTING = 'restart';

    /**
     * @var ITaskGroup[]
     */
    private $taskGroups = [];

    /**
     * 标识子进程是否处于退出的状态
     *
     * @var boolean
     */
    private $inShutDown = false;

    /**
     * 正在运行的进程
     *
     * @var int
     */
    private $runningPid = -1;

    /**
     * @var ITaskGroup
     */
    private $runningTask = null;

    /**
     * 主进程的状态
     *
     * @var string
     */
    private $masterStage = self::STAGE_NORMAL;

    /**
     *  用全局变量标识父进程还是子进程
     *
     * @var boolean
     */
    private $isParent = true; //

    /**
     * 处理信号量的管道，$sockets[0] 用来写，$sockets[1] 用来读
     *
     * @var array
     */
    private $sockets = [];

    /**
     * 错误日志写入资源句柄
     *
     * @var resource
     */
    private $logFd;

    /**
     * event loop 每次检查信号的间隔，间隔越小，越能及时处理进程的死亡和拉起
     *
     * @var int
     */
    private $eventLoopInterval = 1;

    /**
     * 主进程的pid
     *
     * @var integer
     */
    private $masterPid = 0;

    public function __construct($logFd = STDOUT) {
        $this->logFd = $logFd;
    }

    /**
     * @param ITaskGroup $task
     * @param string $name
     */
    public function addTaskGroup(ITaskGroup $task, string $name) {
        $this->taskGroups[$name] = $task;
    }

    /**
     * 运行程序入口
     *
     * @return void
     */
    public function run()
    {
        $this->masterPid = posix_getpid();
        // 创建socket（相当于匿名管道）
        $this->sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        // 注册信号处理函数
        pcntl_signal(SIGCHLD, array($this, "sigAction"));
        pcntl_signal(SIGQUIT, array($this, "sigAction"));
        pcntl_signal(SIGUSR2, array($this, "sigAction"));
        // 初始化子进程
        foreach ($this->taskGroups as $groupName => $task) {
            $task->setMasterPid($this->masterPid);
            $startRet = $task->start();
            if($startRet == ITaskGroup::FORK_CHILD) {
                $this->isParent = false;
                $this->runningPid = posix_getgid();
                $this->runningTask = $task;
                break;
            } elseif ($startRet == ITaskGroup::FORK_FAILD) {
                // TODO 这里没有处理子进程变成孤儿进程的情况
                $msg = sprintf("fatal fork failed %s %s ", $groupName, pcntl_strerror(pcntl_get_last_error()));
                throw new \RuntimeException($msg);
            }
        }
        // 父进程
        if($this->isParent) {
            // 设置具柄为非阻塞的
            stream_set_blocking( $this->sockets[1], False );
            // cli_set_process_title("php master");
            // 相当于 fpm_event_loop
            while (true) {
                pcntl_signal_dispatch();
                $this->checkSignal(); // 检查是否有信号发生
                if(!$this->isParent) { // 可能在checkSiganl里fork了子进程，这里要退出
                    break;
                }
                $hasChildRunning = false;
                foreach ($this->taskGroups as $group => $task) {
                    if($task->getTaskCount() != 0) {
                        $hasChildRunning = true;
                    }
                }
                if(!$hasChildRunning) { // 父进程回收完所有子进程退出
                    if($this->masterStage == self::STAGE_QUIT) {
                        $this->log("master quiting");
                        break;
                    } else if($this->masterStage == self::STAGE_RESTARTING) {
                        // // 父进程回收完所有子进程重启
                        global $argv;
                        $this->log("master restarting");
                        pcntl_exec(exec("which php"), $argv);
                    }
                }
                foreach ($this->taskGroups as $group => $taskGroup) {
                    $taskGroup->updateTasks();
                }
                $this->log("stage " . $this->masterStage . " child count " . $this->getRunningTaskCount(). " fall sleep");
                sleep($this->eventLoopInterval);
            }
            if($this->isParent) { // 关闭打开的管道
                fclose($this->sockets[0]);
                fclose($this->sockets[1]);
            }
        }

        // 子进程
        if(!$this->isParent) {
            pcntl_signal(SIGCHLD, SIG_IGN); // 忽略SIGCHLD，尽管子进程应该没有child才合理
            pcntl_signal(SIGQUIT, [$this, "sigActionChild"]);
            //cli_set_process_title("php worker");
            fclose($this->sockets[0]); // 子进程用不上
            fclose($this->sockets[1]);
            $this->runningTask->doRun();
            exit; // 要及时退出
        }
    }

    /**
     * 真正的，信号处理函数 对应fpm中的 fpm_got_signal，从管道中读取信号
     *
     * @return void
     */
    private function checkSignal()
    {
        do {
            $c = fread($this->sockets[1], 1);
            if(empty($c)) {
                break;
            }
            switch ($c) {
                case 'C':
                    // 表示有子进程挂掉了，pcntl_waitpid循环检查（因为SIGCHLD多个信号同时到来，没有及时处理掉，只会触发一次，为了防止僵尸进程的出现要循环检查，WNOHANG是不阻塞）
                    while(($pid = pcntl_waitpid(-1,$status,WNOHANG)) > 0) {
                        foreach ($this->taskGroups as $group => $taskGroup) {
                            if($taskGroup->isTaskPid($pid)) {
                                $taskGroup->removePid($pid);
                                $this->log("child reaped ". $pid . " group " . $group);
                                break;
                            }
                        }
                    }
                    break;
                case "Q": // 退出
                    if($this->masterStage != self::STAGE_NORMAL) {
                        break;
                    }
                    $this->masterStage = self::STAGE_QUIT;
                    foreach ($this->taskGroups as $group => $taskGroup) {
                        $taskGroup->quit();
                    }
                    break;
                case '2': // 重启
                    if($this->masterStage != self::STAGE_NORMAL) {
                        break;
                    }
                    $this->masterStage = self::STAGE_RESTARTING;
                    foreach ($this->taskGroups as $group => $taskGroup) {
                        $taskGroup->restart();
                    }
                    break;
            }
        } while(1);
    }

    /**
     * 信号处理函数，只负责从管道写入信号
     *
     * @param int $signo
     * @return void
     */
    private function sigAction($signo) {
        switch ($signo) {
            case SIGCHLD:
                fwrite($this->sockets[0], "C");
                break;
            case SIGQUIT:
                fwrite($this->sockets[0], "Q");
                break;
            case SIGUSR2:
                fwrite($this->sockets[0], "2");
                break;
        }
    }

    /**
     * 子进程的信号函数
     *
     * @param int $signo
     * @return void
     */
    private function sigActionChild($signo) {
        switch ($signo) {
            case SIGQUIT:
                // 标记退出状态
                $this->inShutDown = true;
                break;
        }
    }

    /**
     * 写入日志
     *
     * @param string $str
     * @param string $level
     * @return void
     */
    private function log($str, $level = ErrorLevel::DEBUG){
        $str = sprintf("pid %s time %s level %s: %s" . PHP_EOL, posix_getpid(), microtime(true), $level, $str);
        fwrite ($this->logFd , $str);
    }

    /**
     * 获取正在运行的任务数量
     *
     * @return int
     */
    private function getRunningTaskCount(): int {
        $num = 0;
        foreach ($this->taskGroups as $task) {
            $num += $task->getTaskCount();
        }
        return $num;
    }
}