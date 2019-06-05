<?php

class MiRobotVacuumCleaner extends MiIO {
	
	private $status_state = array(
		1  => 'Starting',
        2  => 'Sleeping',
        3  => 'Waiting',
        4  => 'Remote control active',
        5  => 'Cleaning',
        6  => 'Returning home',
        7  => 'Manual mode',
        8  => 'Charging',
        9  => 'Charging problem',
        10 => 'Paused',
        11 => 'Spot cleaning',
        12 => 'Error',
        13 => 'Shutting down',
        14 => 'Updating',
        15 => 'Docking',
        16 => 'Going to target',
        17 => 'Zoned cleaning',
	);
	
	private $status_error = array(
		0  => 'No error',
		1  => 'Laser distance sensor error',
		2  => 'Collision sensor error',
		3  => 'Wheels on top of void, move robot',
		4  => 'Clean hovering sensors, move robot',
		5  => 'Clean main brush',
		6  => 'Clean side brush',
		7  => 'Main wheel stuck?',
		8  => 'Device stuck, clean area',
		9  => 'Dust collector missing',
		10 => 'Clean filter',
		11 => 'Stuck in magnetic barrier',
		12 => 'Low battery',
		13 => 'Charging fault',
		14 => 'Battery fault',
		15 => 'Wall sensors dirty, wipe them',
		16 => 'Place me on flat surface',
		17 => 'Side brushes problem, reboot me',
		18 => 'Suction fan problem',
		19 => 'Unpowered charging station',
	);
	
	private $sound_state = array(
		1 => 'Downloading',
	    2 => 'Installing',
	    3 => 'Installed',
	    4 => 'Error',
	);
	
	private $sound_error = array(
		0 => 'No error',
		2 => 'Can not connect to host'
	);
	
	public function find() {
		if($response=$this->call('find_me')) {
			return $response;
		} else {
			return false;
		}
	}
	
	public function status() {
		if($response=$this->call('get_status')) {
			if(is_array($response)) {
				$response['state_text'] = $this->status_state[$response['state']];
				$response['error_text'] = $this->status_state[$response['error_code']];
			}
			return $response;
		} else {
			return false;
		}
	}
	
	public function sound_install($url, $md5) {
		if($response=$this->call('dnld_install_sound',array('sid'=>1,'url'=>$url,'md5'=>$md5))) {
			sleep(1);
			return $response;
		} else {
			return false;
		}
	}
	
	public function sound_progress() {
		if($response=$this->call('get_sound_progress')) {
			if(is_array($response)) {
				$response['state_text'] = $this->sound_state[$response['state']];
			}
			return $response;
		} else {
			return false;
		}
	}
	
	public function sound_volume($percent=false) {
		if($percent===false) {
			if($response=$this->call('get_sound_volume')) {
				return $response;
			} else {
				return false;
			}
		} else {
			if($response=$this->call('change_sound_volume',array(min(max($percent,0),100)))) {
				return $response;
			} else {
				return false;
			}
		}
	}
	
	public function sound_test() {
		if($response=$this->call('test_sound_volume')) {
			return $response;
		} else {
			return false;
		}
	}
	
	public function fan_power($percent) {
		if($response=$this->call('set_custom_mode',array(min(max($percent,0),100)))) {
			return $response;
		} else {
			return false;
		}
	}
	
}