<?php
abstract class PWMC{
	const DATA_FILE_NAME = 'PWMCNames';
	const COMM_MAP = [
		'firefox' => 'Firefox'
	];

	protected static $windows = [];
	protected static $blockWait = [];
	protected static $commWait = [];
	protected static $searchWait = [];
	protected static $scanning = false;

	protected static $timeStack = [];
	protected static $timeout = 10000;
	protected static $timeoutStamp = 0;
	protected static $waiting = false;

	private static $monitors = null;

	protected $wid;
	protected $pid;
	protected $title;
	protected $comm;
	protected $state = 0;

	public function __construct(string $cmd){
		self::setup();
	}

	public static function millitime(){
		return round(microtime(true)*1000);
	}

	public static function arrayPluck(array &$array, $key){
		if(!isset($array[$key])) return null;
		$return = $array[$key];
		unset($array[$key]);
		return $return;
	}

	protected static function scan(){
		if(self::$scanning) return;
		self::$scanning = true;

		$data = shell_exec('wmctrl -lp');
		preg_match_all('/^(\S+)\s+\S+\s+(\S+)\s+\S+(.+)?$/m', $data, $matches, PREG_SET_ORDER);
		$found = [];
		$blocked = [];
		$old = self::$windows;
		$new = [];

		foreach($matches as $match){
			$info = ['wid' => intval($match[1], 16), 'pid' => intval($match[2], 10), 'title' => trim($match[3]) ?? ''];

			// Test if window already excists
			$win = self::arrayPluck($old, $info['wid']);
			if($win === null) $info['comm'] = self::getComm($info['pid']);

			// Test if window should be blocked
			if($win === null){
				$matchedBlocks = [];
				foreach(self::$blockWait as $block){
					if(self::testFilter($info, $block[0])) $matchedBlocks[] = $block;
				}
				if(!empty($matchedBlocks)){
					$win = self::newWindow($info['comm']);
					$blocked[] = $win;
					foreach($matchedBlocks as $block){
						if($block[1] !== null) $win->onReady($block[1], array_merge([$win], $block[2]));
					}
				}
			}

			// If win is still null treat as new
			if($win === null){
				// Test if window has been created with new Window()
				$win = self::arrayPluck(self::$commWait, $info['comm']);

				// Test if a search is waiting for window
				if($win === null){
					foreach(self::$searchWait as $key => $wait){
						if(self::testFilter($info, $wait[0])){
							$win = $wait[1];
							unset(self::$searchWait[$key]);
							break;
						}
					}
				}

				// Window apears to be unhandled by script
				if($win === null) $win = self::newWindow($info['comm']);

				$new[] = $win;
			}

			foreach($info as $k => $v) $win->$k = $v;
			if($win->state == Window::STATE_WAITING) self::$timeoutStamp = max((self::millitime() + self::$timeout), self::$timeoutStamp);
			$win->state = Window::STATE_READY;
			$found[$win->wid] = $win;
		}

		foreach($old as $v) $v->state = Window::STATE_CLOSED;
		self::$windows = $found;
		foreach(array_merge($blocked, $new) as $win) $win->runStack();
		self::$scanning = false;
	}

	private static function newWindow($comm){
		static $reflections = [];

		$class = self::COMM_MAP[$comm] ?? 'Window';
		if(!isset($reflections[$class])) $reflections[$class] = new ReflectionClass($class);
		return $reflections[$class]->newInstanceWithoutConstructor();
	}

	private static function testFilter($data, array $filter){
		foreach($filter as $prop => $value){
			switch($prop){
				case 'title';
					$test = is_object($data) ? $data->title : $data['title'];
					if(!preg_match('/'.$value.'/', $test)) return false;
					break;
				case 'pid': case 'wid':
					$value = (int)$value;
				case 'comm':
					$test = is_object($data) ? $data->$prop : $data[$prop];
					if($value !== $test) return false;
					break;
				default:
					trigger_error('Property '.$prop.' can\'t be used in filter', E_USER_WARNING);
			}
		}
		return true;
	}

	public static function block(array $filter, callable $callback = null, array $args = []){
		self::setup();
		self::$blockWait[] = [$filter, $callback, $args];
	}

	public static function search(array $filter, bool $wait = false){
		self::scan();
		foreach(self::$windows as $win){
			if(self::testFilter($win, $filter)) return $win;
		}
		if(!$wait) return null;
		$win = self::newWindow(isset($filter['comm']) ?? '');
		self::$searchWait[] = [$filter, $win];
		return $win;
	}

	public static function get(string $name){
		self::setup();
		$names = self::handleNames();
		return self::$windows[$names[$name]] ?? null;
	}

	public static function getNames(){
		self::setup();
		$names = self::handleNames();
		foreach($names as $k => $v) $names[$k] = self::$windows[$v];
		return $names;
	}

	protected static function handleNames(string $name = null, int $wid = 0){
		static $warned = false;

		$lock = !is_null($name) ? LOCK_EX : LOCK_SH;
		$file = rtrim(sys_get_temp_dir(), '/').'/'.self::DATA_FILE_NAME;
		$pointer = fopen($file, 'c+');
		if(!flock($pointer, $lock) && !$warned){
			trigger_error('PWMC failed to get lock for data file and is running in non threadsafe mode', E_USER_WARNING);
			$warned = true;
		}

		$data = fgets($pointer);
		$data = $data == '' ? [] : unserialize(trim($data));

		$names = [];
		self::scan();
		foreach($data as $k => $v){
			if(isset(self::$windows[$v]) && $v != $wid) $names[$k] = $v;
		}

		if(!is_null($name)){
			$names[$name] = $wid;
			rewind($pointer);
			ftruncate($pointer, 0);
		    fwrite($pointer, serialize($names));
			fflush($pointer);
		}

		flock($pointer, LOCK_UN);
		fclose($pointer);

		return $names;
	}

	protected abstract function runStack();

	public static function getComm(int $PID){
		$comm = trim(shell_exec('ps -o comm --no-headers -p '.$PID));
		if(substr($comm, -1) == '-'){
			$test = trim(shell_exec('which '.$comm));
			if($test == '') $comm = substr($comm, 0, -1);
		}
		return $comm;
	}

	public static function start(string $cmd){
		return (int)trim(shell_exec('('.$cmd.' >/dev/null 2>&1 & echo $!)'));
	}

	public static function getMonitors(){
		if(self::$monitors === null){
			$data = shell_exec('xrandr --query | grep connected | grep disconnected -v');
			preg_match_all('/\s(\d+)x(\d+)\+(\d+)\+(\d+)\s/', $data, self::$monitors, PREG_SET_ORDER);
			foreach(self::$monitors as &$monitor) array_shift($monitor);
			usort(self::$monitors, function($a, $b){
				if($a[3] != $b[3]){
					$diff = $a[3] - $b[3];
					$border = ($a[3] < $b[3] ? $a[1] : $b[1]) / 2;
					if(abs($diff) > $border) return $diff;
				}
				return $a[2] <=> $b[2];
			});
		}
		return self::$monitors;
	}

	public static function doin(callable $callback, int $time, array $args = []){
		self::setup();
		$stamp = self::millitime() + $time;
		self::$timeoutStamp = max(($stamp + self::$timeout), self::$timeoutStamp);
		self::$timeStack[] = [$stamp, $callback, $args];
	}

	private static function testWait(){
		self::scan();
		$wait = false;

		foreach(self::$commWait as $win){
			if($win->state == Window::STATE_WAITING){
				$wait = true;
				break;
			}
		}
		if(!$wait){
			foreach(self::$searchWait as $win){
				if($win[1]->state == Window::STATE_WAITING){
					$wait = true;
					break;
				}
			}
		}

		self::$waiting = $wait;
		if($wait) self::$timeStack[] = [self::millitime() + 250, [self::class, 'testWait'], []];
	}

	private static function setup(){
		static $hasRun = false;
		if($hasRun) return;
		$hasRun = true;

		self::scan();
		self::$timeoutStamp = self::millitime() + self::$timeout;

		register_shutdown_function(function(){
			self::testWait();
			$count = 0;
			while(!empty(self::$timeStack) && self::millitime() < self::$timeoutStamp){
				if($count != count(self::$timeStack)){
					usort(self::$timeStack, function($a1, $a2){return $a1[0] <=> $a2[0];});
					$count = count(self::$timeStack);
				}

				$action = array_shift(self::$timeStack);
				$count--;
				$wait = $action[0] - self::millitime();
				if($wait > 0) usleep($wait * 1000);
				call_user_func_array($action[1], $action[2]);

				if(!self::$waiting && (!is_array($action[1]) || $action[1][0] !== self::class || $action[1][1] != 'testWait')) self::testWait();
			}

			if(!empty(self::$timeStack)) trigger_error('PWMC timeouted without compleating all commands', E_USER_WARNING);
		});
	}
}
