<?php

chdir(__DIR__.'\..\..');
set_time_limit(0);
date_default_timezone_set('UTC');
ini_set('memory_limit',	'100M');
define('WINDOWS', strtolower(substr(PHP_OS,0,3))=='win');

require_once('environment\php-miio\MiIO.php');
require_once('environment\php-miio\MiRobotVacuumCleaner.php');

new MiRobo();

class MiRobo {
	
	var $version = '1.1.0';
	
	var $php;
	var $tar;
	var $sqlite;
	var $encrypt;
	var $decrypt;
	var $normalize;
	
	var $client_ip;
	var $device;
	var $device_ip;
	var $device_token;
	var $device_root;
	var $webserver;
	
	var $password = 'r0ckrobo#23456';
	var $delay	  = 300000;
	
	function __construct() {
		
		if(isset($_SERVER['REQUEST_URI'])) {
			$file = '.'.str_replace('/', DIRECTORY_SEPARATOR, $_SERVER['REQUEST_URI']);
			if(is_file($file)) {
				header('Content-Description: File Transfer');
				header('Content-Type: application/octet-stream');
				header('Content-Disposition: attachment; filename="'.basename($file).'"');
				header('Content-Length: '.filesize($file));
				header('Cache-Control: must-revalidate');
				header('Pragma: public');
				header('Expires: 0');
				readfile($file);
			} else {
				header('HTTP/1.0 404 Not Found');
				header('Cache-Control: must-revalidate');
				header('Pragma: public');
				header('Expires: 0');
			}
		} else {
			if(WINDOWS) {
				$this->php       = '.\environment\php\php.exe';
				$this->tar       = '.\environment\7zip\7za.exe';
				$this->sqlite    = '.\environment\sqlite\sqlite.exe';
				$this->encrypt   = '.\environment\ccrypt\ccrypt.exe -eqfK '."'{$this->password}'";
				$this->decrypt   = '.\environment\ccrypt\ccrypt.exe -dqfK '."'{$this->password}'";
				$this->normalize = '.\environment\normalize\normalize.exe -q';
			} else {
				exit('Linux version of MiRobo is not ready yet, sorry...');
			}
			$this->prepare_console();
			$this->kill_predecessors();
			$this->check_ip_token();
			$this->detect_client_ip();
			$this->device_connect();
			$this->voicepacks_decode();
			$this->webserver_start();
			$this->menu_main();
		}
	}
	
	function prepare_console() {
		wcli_set_console_title('WIN-MIROBO '.$this->version);
		wcli_set_console_size(100,40);
		wcli_hide_cursor();
		$this->clear();
	}
	
	function kill_predecessors() {
		$this->write("Killing predecessors...");
		usleep($this->delay);
		$processes = preg_split('#[\r\n]+#', trim(shell_exec('wmic process where "name=\'php.exe\'" get ProcessID,ExecutablePath /FORMAT:csv')));
		$realpath  = iconv('cp1251','cp866',realpath($this->php));
		foreach($processes as $process) {
			list($node, $path, $pid) = explode(',', $process);
			if($path==$realpath && $pid!=getmypid()) {
				exec("taskkill /F /PID $pid");
			}
		}
	}
	
	function check_ip_token() {
		$this->write("Checking device ip and token...");
		usleep($this->delay);
		if(is_file('miio2.db')) {
			list($this->device_ip, $this->device_token) = explode('|',trim(@shell_exec($this->sqlite.' miio2.db "select localIP,token from devicerecord;" >NUL 2>NUL')));
		}
		if(!$this->device_ip || !$this->device_token) {
			$ini   = @parse_ini_string(strtolower(@file_get_contents('win-mirobo.ini')),false,INI_SCANNER_RAW);
			$this->device_ip    = $ini['ip'];
			$this->device_token = $ini['token'];
		}
		if(strlen($this->device_token)>=64) {
			$this->device_token = @openssl_decrypt($this->device_token, 'AES-128-ECB', str_repeat("\0",16), OPENSSL_RAW_DATA|OPENSSL_NO_PADDING);
		}
		if(!$this->device_ip || !$this->device_token || !filter_var($this->device_ip,FILTER_VALIDATE_IP) || !preg_match('#^[a-f0-9]{32}$#',$this->device_token)) {
			$this->clear();
			$this->write("<c:red>WIN-MIROBO INITIALIZATION ERROR</c>\n");
			$this->write("<c:yellow>Please enter correct <c:green>IP</c> and <c:green>TOKEN</c> of your Mi Robot Vacuum Cleaner in <c:green>win-mirobo.ini</c> file,");
			$this->write("or just copy <c:green>miio2.db</c> file from your phone to <c:green>win-mirobo</c> folder...</c>");
			$this->halt();
		}
	}
	
	function detect_client_ip() {
		$this->write("Detecting client ip...");
		usleep($this->delay);
		if(WINDOWS) {
			$this->client_ip = shell_exec("pathping -q 1 -n {$this->device_ip}");
			preg_match('#^\s+\d\s+(\d+\.\d+\.\d+\.\d+)#m', $this->client_ip, $this->client_ip);
			$this->client_ip = $this->client_ip[1];
		} else {
			$this->client_ip = shell_exec("ip route get {$this->device_ip}");
			preg_match('#src\s+(\d+\.\d+\.\d+\.\d+)#', $this->client_ip, $this->client_ip);
			$this->client_ip = $this->client_ip[1];
		}
		if(!$this->client_ip || !filter_var($this->client_ip,FILTER_VALIDATE_IP)) {
			$this->clear();
			$this->write("<c:red>WIN-MIROBO INITIALIZATION ERROR</c>\n");
			$this->write("<c:yellow>Can not detect client ip for unknown reasons...</c>");
			$this->halt();
		}
	}
	
	function device_connect() {
		$this->write("Connecting to device...");
		$this->device = new MiRobotVacuumCleaner($this->client_ip, $this->device_ip, $this->device_token, 3, 3);
		if($error=$this->device->error()) {
			$this->clear();
			$this->write("<c:red>WIN-MIROBO INITIALIZATION ERROR</c>\n");
			$this->write("<c:yellow>Can not connect to device:</c> <c:red>$error...</c>");
			$this->halt();
		} else {
			@file_put_contents('win-mirobo.ini', 'ip='.$this->device_ip.PHP_EOL.'token='.$this->device_token.PHP_EOL);
			@unlink('miio2.db');
		}
	}
	
	function check_root() {
		$this->write("Connecting to device...");
		$this->device = new MiRobotVacuumCleaner($this->client_ip, $this->device_ip, $this->device_token, 3, 3);
		if($error=$this->device->error()) {
			$this->clear();
			$this->write("<c:red>WIN-MIROBO INITIALIZATION ERROR</c>\n");
			$this->write("<c:yellow>Can not connect to device:</c> <c:red>$error...</c>");
			$this->halt();
		} else {
			@file_put_contents('win-mirobo.ini', 'ip='.$this->device_ip.PHP_EOL.'token='.$this->device_token.PHP_EOL);
			@unlink('miio2.db');
		}
	}
	
	function voicepacks_decode() {
		if($voicepacks=glob('voicepacks\\*.pkg')) {
			$this->write("Decoding voice packages...");
			foreach($voicepacks as $voicepack) {
				$name = pathinfo($voicepack, PATHINFO_FILENAME);
				if(!file_exists("voicepacks\\{$name}")) {
					$temp = uniqid('voicepacks\temp');
					copy($voicepack,"$temp.pkg");
					system("{$this->decrypt} $temp.pkg >NUL 2>NUL", $error);
					if(!$error) {
						system("{$this->tar} x $temp.pkg -so 2>NUL | {$this->tar} x -aoa -si -ttar -ovoicepacks\\{$name} >NUL 2>NUL");
						if(count(glob("voicepacks\\{$name}\\*.wav"))) {
							@unlink($voicepack);
						} else {
							@rmdir("voicepacks\\{$name}");
						}
					}
					@unlink("$temp.pkg");
				}
			}
		}
	}
	
	function webserver_start() {
		$this->write("Starting webserver...");
		usleep($this->delay);
		$this->webserver       = new stdClass();
		$this->webserver->ip   = $this->client_ip;
		$this->webserver->port = 64999;
		while(++$tries<100) {
		    if(!$socket=@fsockopen($this->webserver->ip,++$this->webserver->port,$errno,$error,0.01)) break;
		    fclose($socket);
		}
		$this->webserver->shell   = new COM('WScript.Shell');
		$this->webserver->process = $this->webserver->shell->Exec("{$this->php} -S {$this->webserver->ip}:{$this->webserver->port} environment\php-mirobo\mirobo.php");
		$this->webserver->url  	  = "http://{$this->webserver->ip}:{$this->webserver->port}";
		register_shutdown_function(array($this,'webserver_stop'));
	}
	
	function webserver_stop() {
		$this->clear();
		$this->write("Stopping webserver...");
		usleep($this->delay);
		exec("taskkill /F /PID ".$this->webserver->process->ProcessID);
		$this->clear();
	}
	
	function mirobot_prepare() {
		$this->device = new miRobotVacuum($ip, $this->client_ip, $token, false);
		$this->device->enableAutoMsgID();
	}
	
	function mirobot_state() {
		$this->clear();
		$this->write("Mi Robot Vacuum Cleaner route: <c:green>{$this->client_ip}</c> <c:green2><-></c> <c:green>{$this->device_ip}</c>");
		$this->write("Mi Robot Vacuum Cleaner state: <c:white2>...</c>");
		
		if(!$status=$this->device->status()) {
			$this->clear();
			$this->write("Mi Robot Vacuum Cleaner route: <c:green>{$this->client_ip}</c> <c:green2><-></c> <c:green>{$this->device_ip}</c>");
			$this->write("Mi Robot Vacuum Cleaner state: <c:red>".$this->device->error()."</c>");
			$this->halt();
		} else {
			$color_battery = $status['battery']<20 ? 'red' : $status['battery']<50 ? 'yellow' : 'green';
			$color_state   = $status['state']!=8 ? 'red' : 'green';
			if($color_battery=='red' || $color_state=='red') {
				$this->clear();
				$this->write("Mi Robot Vacuum Cleaner route: <c:green>{$this->client_ip}</c> <c:green2><-></c> <c:green>{$this->device_ip}</c>");
				$this->write("Mi Robot Vacuum Cleaner state: <c:$color_state>{$status['state_text']}</c> <c:$color_battery>({$status['battery']}%)</c>\n");
				$this->write("<c:yellow>Mi Robot Vacuum Cleaner must be at the dock station and have battery charge at least 20%...</c>");
				$this->halt();
			} else {
				$this->clear();
				$this->write("Mi Robot Vacuum Cleaner route: <c:green>{$this->client_ip}</c> <c:green2><-></c> <c:green>{$this->device_ip}</c>");
				$this->write("Mi Robot Vacuum Cleaner state: <c:$color_state>{$status['state_text']}</c> <c:$color_battery>({$status['battery']}%)</c>\n");
			}
		}
		return $status;
	}
	
	function menu_main() {
		while(true) {
			$this->mirobot_state();
			$this->write("<c:green>1:</c> <c:aqua>Flash firmware</c>");
			$this->write("<c:green>2:</c> <c:aqua>Flash voice package</c>");
			$this->write("<c:green>3:</c> <c:aqua>Flash patch</c>");
			$input = trim($this->readline("Your selection <c:white2>(leave empty for exit)</c>: "));
			if($input==1) {
				return $this->menu_firmware();
			}
			if($input==2) {
				return $this->menu_voicepack();
			}
			if($input==3) {
				return $this->menu_patch();
			}
			if(empty($input)) {
				exit;
			}
			return $this->menu_main();
		}
	}
	
	function menu_firmware() {
		while(true) {
			$this->mirobot_state();
			if(!$firmwares=(array)glob('.\firmwares\*.pkg')) {
				$this->write("<c:yellow>There is no available packages in <c:green>firmwares</c> folder...</c>");
				$this->pause("Press any key for main menu...");
				return $this->menu_main();
			} else {
				$this->write("Available firmwares:\n");
				foreach($firmwares as $key=>$val) {
					$this->write('<c:green>'.($key+1).':</c> <c:aqua>'.pathinfo($val,PATHINFO_BASENAME).'</c>');
				}
				$input = trim($this->readline("Your selection <c:white2>(leave empty for main menu)</c>: "));
				if($firmwares[$input-1]) {
					return $this->flash_firmware($firmwares[$input-1]);
				}
				if(empty($input)) {
					return $this->menu_main();
				}
				return $this->menu_firmware();
			}
		}
	}
	
	function menu_voicepack() {
		while(true) {
			$this->mirobot_state();
			if(!$voicepacks=(array)glob('.\voicepacks\*',GLOB_ONLYDIR)) {
				$this->write("<c:yellow>There is no available packages in 'voicepacks' folder...</c>");
				$this->pause("Press any key for main menu...");
				return $this->menu_main();
			} else {
				$this->write("Available voice packages:\n");
				foreach($voicepacks as $key=>$val) {
					$this->write('<c:green>'.($key+1).':</c> <c:aqua>'.pathinfo($val,PATHINFO_BASENAME).'</c>');
				}
				$input = trim($this->readline("Your selection <c:white2>(leave empty for main menu)</c>: "));
				if($voicepacks[$input-1]) {
					return $this->flash_voicepack($voicepacks[$input-1]);
				}
				if(empty($input)) {
					return $this->menu_main();
				}
				return $this->menu_voicepack();
			}
		}
	}
	
	function menu_patch() {
		while(true) {
			$status = $this->mirobot_state();
			$volume = $this->device->sound_volume();
			$this->write("Available patches:\n");
			$this->write("<c:green>1:</c> <c:aqua>Set volume to 90%</c> <c:yellow>(current is $volume%)</c>");
			$this->write("<c:green>2:</c> <c:aqua>Set volume to 100%</c> <c:yellow>(current is $volume%)</c>");
			$this->write("<c:green>3:</c> <c:aqua>Set fan power to 90%</c> <c:yellow>(current is {$status['fan_power']}%)</c> <c:green>[ Defualt for MAX mode ]</c>");
			$this->write("<c:green>4:</c> <c:aqua>Set fan power to 100%</c> <c:yellow>(current is {$status['fan_power']}%)</c> <c:red>[ Experimental! At your own risk! ]</c>");
			$input = trim($this->readline("Your selection <c:white2>(leave empty for exit)</c>: "));
			if($input==1) {
				$this->device->sound_volume(90);
				$this->device->sound_test();
				return $this->menu_patch();
			}
			if($input==2) {
				$this->device->sound_volume(100);
				$this->device->sound_test();
				return $this->menu_patch();
			}
			if($input==3) {
				$this->device->fan_power(90);
				return $this->menu_patch();
			}
			if($input==4) {
				$this->device->fan_power(100);
				return $this->menu_patch();
			}
			if(empty($input)) {
				return $this->menu_main();
			}
			return $this->menu_patch();
		}
	}
	
	function flash_firmware($firmware) {
		$this->mirobot_state();
		$name = pathinfo($firmware, PATHINFO_BASENAME);
		$this->write("Flashing firmware: <c:aqua>$name</c>\n");
		$this->write("Sending firmware: <c:white2>...</c>");
		if($result=$this->device->firmware_install("{$this->webserver->url}/firmwares/$name",md5_file($firmware))) {
			$this->write("Sending firmware: <c:green>OK</c>", true);
			$this->write("Updating firmware: <c:white2>...</c>");
			$starttime = microtime(true);
			while(true) {
				$state = $this->device->firmware_state();
				if(is_string($state)) {
					$laststate = " ($state)";
				}
				if($state==='idle') {
					$this->write("Updating firmware: <c:green>OK</c>", true);
					break;
				} else {
					$this->write("Updating firmware: <c:yellow>".date('i:s', round(microtime(true)-$starttime))."$laststate</c>", true);
					usleep(500000);
				}
			}
		} else {
			$this->write("Sending firmware: <c:red>{$this->device->error}</c>", true);
		}
		$this->pause("Press any key for main menu...");
		return $this->menu_main();
		
	}
	
	function flash_voicepack($voicepack) {
		$this->mirobot_state();
		$name = pathinfo($voicepack, PATHINFO_BASENAME);
		@unlink("{$voicepack}\\{$name}.pkg");
		$this->write("Flashing voice package: <c:aqua>{$name}</c>\n");
		$this->write("Normalizing wav files: <c:white2>...</c>");
		system("{$this->normalize} {$voicepack}\\*.wav  >NUL 2>NUL");
		$this->write("Normalizing wav files: <c:green>OK</c>", true);
		$this->write("Creating voice package: <c:white2>...</c>");
		system("{$this->tar}  -so -ttar a {$name} {$voicepack}\\*.wav | {$this->tar} -si -tgzip a {$voicepack}\\{$name}.pkg >NUL 2>NUL");
		$this->write("Creating voice package: <c:green>OK</c>", true);
		$this->write("Encrypting voice package: <c:white2>...</c>");
		system("{$this->encrypt} {$voicepack}\\{$name}.pkg >NUL 2>NUL");
		rename("{$voicepack}\\{$name}.pkg.cpt","{$voicepack}\\{$name}.pkg");
		$this->write("Encrypting voice package: <c:green>OK</c>", true);
		$this->write("Sending voice package: <c:white2>...</c>");
		if($result=$this->device->sound_install("{$this->webserver->url}/voicepacks/{$name}/{$name}.pkg",md5_file("{$voicepack}\\{$name}.pkg"))) {
			$this->write("Sending voice package: <c:green>OK</c>", true);
			$this->write("Installing voice package: <c:white2>...</c>");
			$starttime = microtime(true);
			while(true) {
				$progress = $this->device->sound_progress();
				if($progress['state']===3) {
					$this->write("Installing voice package: <c:green>OK</c>", true);
					$this->device->find();
					break;
				} else
				if($progress['state']===4) {
					$this->write("Installing voice package: <c:red>Something goes wrong...</c>", true);
					break;
				} else {
					$this->write("Installing voice package: <c:yellow>".date('i:s', round(microtime(true)-$starttime))."</c>", true);
					usleep(500000);
				}
			}
		} else {
			$this->write("Sending voice package: <c:red>{$this->device->error}</c>", true);
		}
		$this->pause("Press any key for main menu...");
		return $this->menu_main();
	}
	
	function write($message='', $clearline=false) {
		static $pattern = 'black|blue2|green2|aqua2|red2|purple2|yellow2|white2|gray|blue|green|aqua|red|purple|yellow|white';
		static $colors  = array('black'=>0,'blue2'=>1,'green2'=>2,'aqua2'=>3,'red2'=>4,'purple2'=>5,'yellow2'=>6,'white2'=>7,'gray'=>8,'blue'=>9,'green'=>10,'aqua'=>11,'red'=>12,'purple'=>13,'yellow'=>14,'white'=>15);
		static $history = array(array(15,0));
		if($clearline) {
			$position = wcli_get_cursor_position();
			wcli_clear_line($position[1]);
			wcli_set_cursor_position(1, $position[1]);
		}
		$messages = preg_split('#\r?\n#', $message);
		foreach($messages as $line=>$message) {
			if(!$clearline || $line) {
				wcli_echo(PHP_EOL.' ');
			}
			$submessages = explode("\n", preg_replace(array('#<c:#i','#</c>#i'),array("\n<c:","\n</c>"),$message));
			foreach($submessages as $submessage) {
				if(preg_match("#^</c>(?'m'.*)#i",$submessage,$matches)) {
					$submessage = $matches['m'];
					if(count($history)>1) {
						array_pop($history);
					}
				} else
				if(preg_match("#^<c:(?'f'(?:$pattern)?)(?::(?'b'$pattern)|())>(?'m'.*)#i",$submessage,$matches)) {
					$submessage = $matches['m'];
					$current    = end($history);
					$history[]  = array(
						$matches['f'] ? $colors[strtolower($matches['f'])] : $current[0],
						$matches['b'] ? $colors[strtolower($matches['b'])] : $current[1],
					);
				}
				$current = end($history);
				wcli_echo($submessage, $current[0], $current[1]);
			}
		}
	}
	
	function pause($prompt) {
		$this->write("\n<c:white2>$prompt</c>");
		wcli_get_key();
	}
	
	function halt() {
		$this->write("\n<c:white2>Press any key for exit...</c>");
		wcli_get_key();
		$this->clear();
		exit;
	}
	
	function clear() {
		wcli_clear();
	}
	
	function readline($prompt) {
		$this->write();
		$this->write("$prompt");
		$color = wcli_get_foreground_color();
		wcli_show_cursor();
		wcli_set_foreground_color(10);
		$stdin = fopen('php://stdin', 'r');
		$input = trim(fgets($stdin));
		fclose($stdin);
		wcli_set_foreground_color($color);
		wcli_hide_cursor();
		return $input;
	}
	
}