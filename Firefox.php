<?php
class Firefox extends Window{
	public function __construct(string $url = ''){
		parent::__construct('firefox --new-window "'.$url.'"');
	}

	public function newTab(string $url = ''){
		$this->exec('xdotool windowfocus --sync [wid] ; (firefox --new-tab "'.$url.'" >/dev/null 2>&1 &)');
		return $this;
	}

	public function switchTab(int $tab){
		if($tab >= 1 && $tab <= 9) $this->press('alt+'.$tab);
		else trigger_error('Tab number not between 1 and 9', E_USER_WARNING);
		return $this;
	}

	public function closeTab(int $tab){
		$this->switchTab($tab);
		$this->press('ctrl+w');
		return $this;
	}
}
