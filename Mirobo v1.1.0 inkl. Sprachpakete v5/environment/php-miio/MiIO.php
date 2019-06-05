<?php

class MiIO {
	
	private $client_ip;
	private $client_port;
	private $device_ip;
	private $device_port;
	private $device_token;
	private $device_handshake;
	private $device_info;
	private $device_code;
	private $device_timedelta;
	private $packet_id;
	private $packet_iv;
	private $packet_key;
	private $socket;
	private $socket_timeout;
	private $socket_attempts;
	private $error;
	
	public function __construct($client_ip, $device_ip, $device_token, $socket_timeout=3, $socket_attempts=3) {
		$this->client_ip        = $client_ip;
		$this->client_port      = 59999;
		$this->device_ip        = $device_ip;
		$this->device_port      = 54321;
		$this->device_token     = $device_token;
		$this->device_handshake = hex2bin('21310020ffffffffffffffffffffffffffffffffffffffffffffffffffffffff');
		$this->packet_id        = preg_replace('#\.?'.pathinfo(__FILE__,PATHINFO_EXTENSION).'$#','.pid',__FILE__);
		$this->packet_log       = preg_replace('#\.?'.pathinfo(__FILE__,PATHINFO_EXTENSION).'$#','.log',__FILE__);
		$this->packet_iv        = hex2bin(md5(hex2bin(md5(hex2bin($this->device_token)).$this->device_token)));
		$this->packet_key       = hex2bin(md5(hex2bin($this->device_token)));
		$this->socket_timeout   = max(1,intval($socket_timeout));
		$this->socket_attempts  = max(1,intval($socket_attempts));
		@file_put_contents($this->packet_log, '');
		if(!filter_var($this->client_ip,FILTER_VALIDATE_IP)) {
			$this->error('Invalid client ip');
		}
		if(!filter_var($this->device_ip,FILTER_VALIDATE_IP)) {
			return $this->error('Invalid device ip');
		}
		if(!preg_match('#^[a-f0-9]{32}$#',$this->device_token)) {
			return $this->error('Invalid device token');
		}
		if(@file_put_contents($this->packet_id,'',FILE_APPEND)===false) {
			return $this->error("File '$this->packet_id' is not writeable");
		}
		if(!$this->socket=@socket_create(AF_INET, SOCK_DGRAM, SOL_UDP)) {
			return $this->error('Socket create error');
		} 
		if(!@socket_set_option($this->socket, SOL_SOCKET, SO_SNDTIMEO, array('sec'=>$this->socket_timeout,'usec'=>0))) {
			return $this->error('Socket set timeout error');
		}
		if(!@socket_set_option($this->socket, SOL_SOCKET, SO_RCVTIMEO, array('sec'=>$this->socket_timeout,'usec'=>0))) {
			return $this->error('Socket set timeout error');
		}
		while(!@socket_bind($this->socket, $this->client_ip, ++$this->client_port)) {
			if(@++$attempts==20) {
				return $this->error('Socket bind error');
			}
		}
		if(!$this->info()) {
			return false;
		}
	}
	
	public function __destruct() {
		@socket_shutdown($this->socket);
		@socket_close($this->socket);
	}
	
	private function packet_send($message) {
		$this->error(null);
		while(true) {
			if(is_array($message)) {
				$message['id'] = $this->packet_id(@$attempts ? 100 : 1);
				$packet_out    = $this->packet_build($message);
				@file_put_contents($this->packet_log, 'REQUEST  : '.print_r($message,true), FILE_APPEND);
			} else {
				$packet_out    = $message;
				@file_put_contents($this->packet_log, 'REQUEST  : HANDSHAKE'.PHP_EOL, FILE_APPEND);
			}
			if(!@socket_sendto($this->socket, $packet_out, strlen($packet_out), 0, $this->device_ip, $this->device_port)) {
				$error = 'socket send';
			} else
			if(!@socket_recvfrom($this->socket, $packet_in, 65536, 0, $device_ip, $device_port)) {
				$error = 'socket receive';
			} else {
				$response = $this->packet_parse($packet_in);
				@file_put_contents($this->packet_log, 'RESPONSE : '.(is_array($response)?print_r($response,true):$response.PHP_EOL).PHP_EOL, FILE_APPEND);
				return $response;
			}
			if(++$attempts==$this->socket_attempts) {
				@file_put_contents($this->packet_log, 'RESPONSE : '.$error.' error'.PHP_EOL.PHP_EOL, FILE_APPEND);
				throw new Exception($error);
			}
			@file_put_contents($this->packet_log, 'RESPONSE : '.$error.' error'.PHP_EOL.PHP_EOL, FILE_APPEND);
			sleep(1);
		}
	}
	
	private function packet_id($step) {
		$ids = (array)@json_decode(@file_get_contents($this->packet_id),true);
		$ids[$this->device_ip] = intval($ids[$this->device_ip])+$step;
		if($ids[$this->device_ip]>=0) {
			$ids[$this->device_ip] = -1000000;
		}
		@file_put_contents($this->packet_id, json_encode($ids));
		return $ids[$this->device_ip];
	}
	
	private function packet_build($message) {
		$message   = bin2hex(openssl_encrypt(json_encode(array_filter($message)), 'AES-128-CBC', $this->packet_key, OPENSSL_RAW_DATA, $this->packet_iv));
		$length    = sprintf('%04x', strlen($message)/2+32);
		$timestamp = sprintf('%08x', time() + $this->device_timedelta);
		$packet    = '2131'.$length.'00000000'.$this->device_code.$timestamp.$this->device_token.$message;
		$packet    = '2131'.$length.'00000000'.$this->device_code.$timestamp.md5(hex2bin($packet)).$message;
		return hex2bin($packet);
	}
	
	private function packet_parse($packet) {
		if($packet=(array)@unpack('H4header/n1length/H8zeroes/H4type/H4serial/N1timestamp/H32checksum/A*result',$packet)) {
			if($packet['result']) {
				$packet['result'] = @openssl_decrypt($packet['result'], 'AES-128-CBC', $this->packet_key, OPENSSL_RAW_DATA, $this->packet_iv);
				$packet['result'] = (array)@json_decode(trim($packet['result']),true);
				$packet['result'] = isset($packet['result']['result'][0]) && count($packet['result']['result'])==1 ? $packet['result']['result'][0] : $packet['result']['result'];
			} else {
				$packet['result'] = true;
			}
			return $packet;
		} else {
			throw new Exception('packet parse');
		}
	}
	
	public function error() {
		$backtrace = debug_backtrace(2,2);
		if($backtrace[1]['class']==__CLASS__ && func_num_args()) {
			$this->error = func_get_arg(0);
			return false;
		} else {
			return $this->error ? $this->error : false;
		}
	}
	
	public function handshake() {
		try {
			$response = $this->packet_send($this->device_handshake);
			$this->device_code      = $response['type'].$response['serial'];
			$this->device_timedelta = $response['timestamp'] - time();
			return true;
		} catch (Exception $exception) {
			return $this->error('Device handshake error on '.$exception->getMessage());
		}
	}
	
	public function call($method, $params=false) {
		if(!$this->handshake()) {
			return false;
		}
		try {
			$response = $this->packet_send(array(
				'method' => $method,
				'params' => $params,
			));
			return $response['result'];
		} catch (Exception $exception) {
			return $this->error("Method '$method' error on ".$exception->getMessage());
		}
	}
	
	public function info() {
		if(!$this->device_info) {
			$this->device_info = $this->call('miIO.info');
		}
		return $this->device_info;
	}
	
	public function firmware_install($url, $md5) {
		$response = $this->call('miIO.ota', array(
			'mode'     => 'normal', 
			'install'  => '1', 
			'app_url'  => $url, 
			'file_md5' => $md5,
			'proc'     => 'dnld install'
		));
		if($response===false) {
			return false;
		} else
		if($response!='ok') {
			return $this->error($response);
		} else {
			sleep(1);
			return $response;
		}
	}
	
	public function firmware_progress() {
		if($response=$this->call('miIO.get_ota_progress')) {
			return $response;
		} else {
			return false;
		}
	}
	
	public function firmware_state() {
		if($response=$this->call('miIO.get_ota_state')) {
			return $response;
		} else {
			return false;
		}
	}
	
}