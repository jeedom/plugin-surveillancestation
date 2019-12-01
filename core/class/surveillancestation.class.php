<?php

/* This file is part of Jeedom.
*
* Jeedom is free software: you can redistribute it and/or modify
* it under the terms of the GNU General Public License as published by
* the Free Software Foundation, either version 3 of the License, or
* (at your option) any later version.
*
* Jeedom is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
* GNU General Public License for more details.
*
* You should have received a copy of the GNU General Public License
* along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
*/

/* * ***************************Includes********************************* */
require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';

class surveillancestation extends eqLogic {
	/*     * *************************Attributs****************************** */
	
	private static $_sid = null;
	private static $_api_info = null;
	
	/*     * ***********************Methode static*************************** */
	
	public static function event() {
		$cmd = surveillancestationCmd::byId(init('id'));
		if (!is_object($cmd)) {
			throw new Exception('Commande ID surveillance station inconnu : ' . init('id'));
		}
		if ($cmd->getType() == 'action') {
			$cmd->execCmd(array());
		} else {
			$cmd->event(init('value'));
		}
	}
	
	public static function callUrl($_parameters = null, $_recall = 0) {
		$url = self::getUrl() . '/webapi/' . self::getApi($_parameters['api'], 'path') . '?version=' . self::getApi($_parameters['api'], 'version');
		if ($_parameters !== null && is_array($_parameters)) {
			foreach ($_parameters as $key => $value) {
				$url .= '&' . $key . '=' . urlencode($value);
			}
		}
		$url .= '&_sid=' . self::getSid();
		$http = new com_http($url);
		$result = json_decode($http->exec(15), true);
		if ($result['success'] != true) {
			if (($result['error']['code'] == 105) && $_recall < 3) {
				self::deleteSid();
				self::updateAPI();
				return self::callUrl($_parameters, $_recall + 1);
			}
			throw new Exception(__('Appel api : ', __FILE__) . print_r($_parameters, true) . __(',url : ', __FILE__) . $url . ' => ' . print_r($result, true));
		}
		return $result;
	}
	
	public static function getSid() {
		if (self::$_sid !== null) {
			return self::$_sid;
		}
		if (config::byKey('SYNO.SID.Session', 'surveillancestation') != '') {
			self::$_sid = config::byKey('SYNO.SID.Session', 'surveillancestation');
			return self::$_sid;
		}
		$url = self::getUrl() . '/webapi/' . self::getApi('SYNO.API.Auth', 'path') . '?api=SYNO.API.Auth&method=Login&version=' . self::getApi('SYNO.API.Auth', 'version') . '&account=' . urlencode(config::byKey('user', 'surveillancestation')) . '&passwd=' . urlencode(config::byKey('password', 'surveillancestation')) . '&session=SurveillanceStation&format=sid';
		$http = new com_http($url);
		$data = json_decode($http->exec(15), true);
		if ($data['success'] != true) {
			throw new Exception(__('Mise à jour des API SYNO.API.Auth en erreur, url : ', __FILE__) . $url . ' => ' . print_r($data, true));
		}
		config::save('SYNO.SID.Session', $data['data']['sid'], 'surveillancestation');
		self::$_sid = $data['data']['sid'];
		return $data['data']['sid'];
	}
	
	public static function deleteSid() {
		self::$_sid = null;
		if (config::byKey('SYNO.SID.Session', 'surveillancestation') == '') {
			return;
		}
		$url = self::getUrl() . '/webapi/' . self::getApi('SYNO.API.Auth', 'path') . '?api=SYNO.API.Auth&method=Logout&version=' . self::getApi('SYNO.API.Auth', 'version') . '&session=SurveillanceStation&_sid=' . self::getSid();
		$http = new com_http($url);
		$data = json_decode($http->exec(15), true);
		if ($data['success'] != true) {
			throw new Exception(__('Destruction de la session en erreur, code : ', __FILE__) . $url . __(' , code : ', __FILE__) . $data['error']['code']);
		}
		config::remove('SYNO.SID.Session', 'surveillancestation');
	}
	
	public static function getURL() {
		if (config::byKey('https', 'surveillancestation')) {
			return 'https://' . config::byKey('ip', 'surveillancestation') . ':' . config::byKey('port', 'surveillancestation');
		}
		return 'http://' . config::byKey('ip', 'surveillancestation') . ':' . config::byKey('port', 'surveillancestation');
	}
	
	public static function getApi($_api, $_key) {
		if (self::$_api_info == null && (config::byKey('api_info', 'surveillancestation') == '' || !is_array(config::byKey('api_info', 'surveillancestation')))) {
			self::updateAPI();
		}
		if (self::$_api_info == null) {
			self::$_api_info = config::byKey('api_info', 'surveillancestation');
		}
		if (isset(self::$_api_info[$_api][$_key])) {
			return self::$_api_info[$_api][$_key];
		}
		return '';
	}
	
	public static function updateAPI() {
		$list_API = array(
			'SYNO.API.Auth',
			'SYNO.SurveillanceStation.Camera',
		);
		$url = self::getUrl() . '/webapi/query.cgi?api=SYNO.API.Info&method=Query&version=1&query=SYNO.API.Auth,SYNO.SurveillanceStation.';
		$http = new com_http($url);
		$data = json_decode($http->exec(15), true);
		if ($data['success'] != true) {
			throw new Exception(__('Mise à jour des API SYNO.API.Inf en erreur, url : ', __FILE__) . $url . __(' , code : ', __FILE__) . $data['error']['code']);
		}
		$api = array();
		foreach ($list_API as $value) {
			if (!isset($data['data'][$value])) {
				continue;
			}
			$api[$value] = array(
				'path' => $data['data'][$value]['path'],
				'version' => $data['data'][$value]['maxVersion'],
			);
		}
		config::save('api_info', $api, 'surveillancestation');
	}
	
	public static function discover() {
		self::deleteSid();
		self::updateAPI();
		$data = self::callUrl(array('api' => 'SYNO.SurveillanceStation.Camera', 'method' => 'List'));
		log::add('surveillancestation','debug',json_encode($data['data']['cameras']));
		foreach ($data['data']['cameras'] as $camera) {
			$eqLogic = self::byLogicalId('camera' . $camera['id'], 'surveillancestation');
			if (!is_object($eqLogic)) {
				$eqLogic = new self();
				$eqLogic->setLogicalId('camera' . $camera['id']);
				$eqLogic->setName($camera['newName']);
				$eqLogic->setEqType_name('surveillancestation');
				$eqLogic->setIsVisible(0);
				$eqLogic->setIsEnable(1);
			}
			$eqLogic->setConfiguration('id', $camera['id']);
			$eqLogic->setConfiguration('model', $camera['model']);
			$eqLogic->setConfiguration('vendor', $camera['vendor']);
			$eqLogic->setConfiguration('ip', $camera['ip']);
			$eqLogic->save();
		}
	}
	
	public static function cron5() {
		$data = self::callUrl(array('api' => 'SYNO.SurveillanceStation.Camera', 'method' => 'List'));
		foreach ($data['data']['cameras'] as $camera) {
			$eqLogic = self::byLogicalId('camera' . $camera['id'], 'surveillancestation');
			if (!is_object($eqLogic)) {
				continue;
			}
			$eqLogic->checkAndUpdateCmd('state', self::convertState($camera['status']));
		}
		
	}
	
	public static function convertState($_state) {
		switch ($_state) {
			case 1:
			return __('Active', __FILE__);
			case 2:
			return __('Supprimée', __FILE__);
			case 3:
			return __('Déconnectée', __FILE__);
			case 4:
			return __('Indisponible', __FILE__);
			case 5:
			return __('Prête', __FILE__);
			case 6:
			return __('Inaccessible', __FILE__);
			case 7:
			return __('Désactivée', __FILE__);
			case 8:
			return __('Non reconnue', __FILE__);
			case 9:
			return __('Parametrage', __FILE__);
			case 10:
			return __('Serveur déconnecté', __FILE__);
			case 11:
			return __('Migration', __FILE__);
			case 12:
			return __('Autre', __FILE__);
			case 13:
			return __('Stockage retiré', __FILE__);
			case 14:
			return __('Arrêt', __FILE__);
			case 15:
			return __('Historique de connexion échoué', __FILE__);
			case 16:
			return __('Non autorisé', __FILE__);
			case 17:
			return __('Erreur RTSP', __FILE__);
			case 18:
			return __('Aucune video', __FILE__);
		}
		return __('Inconnu', __FILE__);
	}
	
	/*     * *********************Méthodes d'instance************************* */
	
	public function postSave() {
		$cmd = $this->getCmd(null, 'state');
		if (!is_object($cmd)) {
			$cmd = new surveillancestationCmd();
			$cmd->setLogicalId('state');
			$cmd->setName(__('Status', __FILE__));
			$cmd->setOrder(1);
		}
		$cmd->setType('info');
		$cmd->setSubType('string');
		$cmd->setEqLogic_id($this->getId());
		$cmd->setIsVisible(1);
		$cmd->save();
		$state_id = $cmd->getId();
		
		$cmd = $this->getCmd('action', 'enable');
		if (!is_object($cmd)) {
			$cmd = new surveillancestationCmd();
			$cmd->setName(__('Activer', __FILE__));
			$cmd->setOrder(2);
			
		}
		$cmd->setEqLogic_id($this->getId());
		$cmd->setLogicalId('enable');
		$cmd->setType('action');
		$cmd->setSubtype('other');
		$cmd->setIsVisible(1);
		$cmd->save();
		
		$cmd = $this->getCmd('action', 'disable');
		if (!is_object($cmd)) {
			$cmd = new surveillancestationCmd();
			$cmd->setName(__('Désactiver', __FILE__));
			$cmd->setOrder(3);
		}
		$cmd->setEqLogic_id($this->getId());
		$cmd->setLogicalId('disable');
		$cmd->setType('action');
		$cmd->setSubtype('other');
		$cmd->setIsVisible(1);
		$cmd->save();
		
		$refresh = $this->getCmd(null, 'refresh');
		if (!is_object($refresh)) {
			$refresh = new surveillancestationCmd();
		}
		$refresh->setName(__('Rafraîchir', __FILE__));
		$refresh->setEqLogic_id($this->getId());
		$refresh->setLogicalId('refresh');
		$refresh->setType('action');
		$refresh->setSubType('other');
		$refresh->save();
	}
	
	/*     * **********************Getteur Setteur*************************** */
}

class surveillancestationCmd extends cmd {
	/*     * *************************Attributs****************************** */
	
	/*     * ***********************Methode static*************************** */
	
	/*     * *********************Methode d'instance************************* */
	
	public function dontRemoveCmd() {
		if ($this->getLogicalId() != '') {
			return true;
		}
		return false;
	}
	
	public function execute($_options = array()) {
		if ($this->getType() == 'info') {
			return;
		}
		$eqLogic = $this->getEqLogic();
		if ($this->getLogicalId() == 'enable') {
			surveillancestation::callUrl(array('api' => 'SYNO.SurveillanceStation.Camera', 'method' => 'Enable', 'idList' => $eqLogic->getConfiguration('id')));
		}
		if ($this->getLogicalId() == 'disable') {
			surveillancestation::callUrl(array('api' => 'SYNO.SurveillanceStation.Camera', 'method' => 'Disable', 'idList' => $eqLogic->getConfiguration('id')));
		}
	}
	
	/*     * **********************Getteur Setteur*************************** */
}
