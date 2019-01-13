<?php
class Window extends PWMC{
	const STATE_INIT = 0;
	const STATE_WAITING = 1;
	const STATE_READY = 2;
	const STATE_CLOSED = 3;

	protected $stack = [];

	public function __construct(string $cmd){
		parent::__construct($cmd);
		$comm = explode(' ', trim($cmd))[0];
		if($comm != '') $this->startup($comm, $cmd);
	}

	protected function startup(string $comm, string $cmd){
		if(isset(self::$commWait[$comm])) self::$commWait[$comm]->onReady([$this, 'startup'], [$comm, $cmd]);
		else{
			self::$commWait[$comm] = $this;
			self::start($cmd);
		}
	}

	public function __get($key){
		if($key == 'state') return $this->state;
		if(in_array($key, ['wid', 'pid', 'title', 'comm'])){
			self::scan();
			if($this->state != self::STATE_READY){
				trigger_error('Can\'t get property '.$key.' on non-ready window', E_USER_WARNING);
				return '';
			}
			return $this->$key;
		}
		trigger_error('Property '.$key.' is not set on window', E_USER_WARNING);
		return '';
	}

	public function __set($key, $value){
		if($key == 'title'){
			$this->onReady(function() use($value){
				$this->exec('wmctrl -ir [wid] -N "'.$value.'"');
				$this->title = $value;
			});
		}
		elseif($key == 'name'){
			$this->onReady(function() use($value){
				parent::handleNames($value, $this->wid);
			});
		}
		else trigger_error('Can\'t set property '.$key.' on window', E_USER_WARNING);
	}

	public function onReady(callable $callback, array $args = []){
		self::scan();
		if($this->state == self::STATE_INIT) $this->state = self::STATE_WAITING;
		if($this->state < self::STATE_READY || !empty($this->stack)) array_push($this->stack, [$callback, $args]);
		elseif( $this->state == self::STATE_READY) call_user_func_array($callback, $args);
	}

	public function doAfterReady(callable $callback, int $time, array $args = []){
		$this->onReady(function() use($callback, $time, $args){
			PWMC::doin($callback, $time, $args);
		});
	}

	protected function runStack(){
		while(($entry = array_shift($this->stack)) !== null){
			call_user_func_array($entry[0], $entry[1]);
		}
	}

	protected function exec(string $cmd){
		$this->onReady(function($cmd){
			$cmd = str_replace('[wid]', $this->wid, $cmd);
			shell_exec($cmd);
		}, [$cmd]);
	}

	// wmctrl functions

	public function move(int $x, int $y, int $monitor = -1, int $desktop = -1){
		if($monitor >= 0){
			$monitors = self::getMonitors();
			if(!isset($monitors[$monitor])) trigger_error('PWMC can\'t find monitor '.$monitor, E_USER_WARNING);
			else{
				$x += $monitors[$monitor][2];
				$y += $monitors[$monitor][3];
			}
		}
		$this->exec("wmctrl -ir [wid] -e 0,$x,$y,-1,-1");
		if($desktop > -1) $this->exec("wmctrl -ir [wid] -t $desktop");
		return $this;
	}

	public function resize($width, $height){
		$this->exec("wmctrl -ir [wid] -e 0,-1,-1,$width,$height");
		return $this;
	}

	public function focus(){
		$this->exec('wmctrl -ia [wid]');
		return $this;
	}

	public function maximize(int $monitor = -1, int $desktop = -1){
		if($monitor > -1) $this->move(0, 0, $monitor);
		if($desktop > -1) $this->exec("wmctrl -ir [wid] -t $desktop");
		$this->exec("wmctrl -ir [wid] -b add,maximized_vert,maximized_horz");
		return $this;
	}

	public function normalize(){
		$this->exec("wmctrl -ir [wid] -b remove,maximized_vert,maximized_horz");
		$this->exec("wmctrl -ir [wid] -b remove,fullscreen");
		return $this;
	}

	public function fullScreen(int $monitor = -1, int $desktop = -1){
		if($monitor > -1) $this->move(0, 0, $monitor);
		if($desktop > -1) $this->exec("wmctrl -ir [wid] -t $desktop");
		$this->exec("wmctrl -ir [wid] -b add,fullscreen");
		return $this;
	}

	public function close(){
		$this->exec('wmctrl -ic [wid]');
		$this->onReady(function(){
			$this->state = self::STATE_CLOSED;
		});
	}

	// keyboard and mouse functions

	public function press(string $key){
		$this->exec("xdotool windowfocus --sync [wid] ; xdotool key $key");
		return $this;
	}

	public function type(string $str){
		$this->exec("xdotool windowfocus --sync [wid] ; xdotool type '$str'");
		return $this;
	}
}
