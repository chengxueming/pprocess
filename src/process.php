<?php
class ErrorLevel {
	const WARNNING = 'warnning';
	const FATAL = 'fatal';
	const NOTICE = 'notice';
	const DEBUG = 'debug';
}

class Process {
	const STAGE_NORMAL = 'normal';
	const STAGE_QUIT = 'quit';
	const STAGE_RESTARTING = 'restart';

	/**
	 * 标识子进程是否处于退出的状态
	 *
	 * @var boolean
	 */
	private $inShutDown = false;

	/**
	 * 主进程的状态
	 *
	 * @var string
	 */
	private $masterStage = self::STAGE_NORMAL;

	/**
	 * 记录所有当前存在的进程，只在父进程中维护，子进程的没有意义
	 *
	 * @var array
	 */
	private $pids = [];
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
	 * 开启的进程数量
	 *
	 * @var int
	 */
	private $forkCount;

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

	public function __construct($forkCount, $logFd = STDOUT) {
		$this->forkCount = $forkCount;
		$this->logFd = $logFd;
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
		$ret = $this->makeChildren($this->forkCount);
		if($ret == 2) { // fork失败，程序退出，TODO 这里没有处理子进程变成孤儿进程的情况
			$this->log("fork failed " . pcntl_strerror(pcntl_get_last_error()), "fatal");
			exit;
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
				if(count($this->pids) == 0) { // 父进程回收完所有子进程退出
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
				$this->log("stage " . $this->masterStage . " child count " . count($this->pids). " fall sleep");
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
			// sleep(mt_rand(1, 5); 验证拉起子进程
			// exit;
			while(true) { // 验证重启和退出信号的代码
				sleep(mt_rand(1, 5));
				$this->log("I'm sleeping");
				$this->heartbeat();
			}
			exit; // 要及时退出
		}
	}

	/**
	 * 子进程中当一个单位的任务完成后调用，防止在一个任务执行中被中断
	 *
	 * @param \Callable $clearUpFunc
	 * @return void
	 */
	public function heartbeat($clearUpFunc = null){
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
						unset($this->pids[$pid]);  // 从进程列表中移除
						$this->log("child reaped ". $pid);
						if($this->masterStage != self::STAGE_NORMAL) {
							continue;
						}
						$ret = $this->makeChildren(1); // 拉起一个新的进程
						if($ret == 2) {
							$this->log("fork failed " . pcntl_strerror(pcntl_get_last_error()), 'fatal');
							exit;
						}
						if(!$this->isParent) { // 子进程不处理信号，只有父进程处理
							return;
						}
					}
				break;
				case "Q": // 退出
					if($this->masterStage != self::STAGE_NORMAL) {
						break;
					}
					$this->masterStage = self::STAGE_QUIT;
					$this->killAll(SIGQUIT);
				break;
				case '2': // 重启
					if($this->masterStage != self::STAGE_NORMAL) {
						break;
					}
					$this->masterStage = self::STAGE_RESTARTING;
					$this->killAll(SIGQUIT);
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
	 * 
	 *
	 * @param int $n fork的进程数量
	 * @return void
	 */
	private function makeChildren($n)
	{
		for($i = 0; $i < $n; $i++) {
			$pid = pcntl_fork();
			switch ($pid) {
				case 0:    
					$this->isParent = false;            
					return 0;
				case -1:
					return 2;
				default:
					$this->pids[$pid] = 1; // 记录当前存活的进程
					break;
			}
		}
		return 1;
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
}

$p = new Process(10);
$p->run();
