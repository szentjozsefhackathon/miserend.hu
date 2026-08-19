<?php

namespace ExternalApi;

# https://wiki.openstreetmap.org/wiki/API_v0.6

class OpenstreetmapApi extends \ExternalApi\ExternalApi {

	/*
		Info: https://wiki.openstreetmap.org/wiki/OAuth
		
		dev: 'https://master.apis.dev.openstreetmap.org/'
		prod: 
		
		1) Be kell lépni az weboldalon. 
		2) Létrehozni egy alkalmazás, ahol a redirect_uri a 'urn:ietf:wg:oauth:2.0:oob'. Feljegyezni a client_id-t és client_secretet.
		3) Böngészőbe behozni a {AUTHORIZE_URL}?response_type=code&client_id={CLIENT_ID}&redirect_uri={REDIRECT_URI}&scope={SCOPE1}%20{SCOPE2} oldalt. Belépni is feljegyezni az application_code-ot.
		4) fusson le az (new \ExternalApi\OpenstreetmapApi())->getAccessToken();
		4) Az access_token is menjen a config.php-ba.
		
	
	*/
		
    public $name = 'openstreetmap';    
	public $format = 'xml';
	public $testQuery = '/api/0.6/user/details';
	public $redirect_uri = 'urn:ietf:wg:oauth:2.0:oob';

	/*
	 * #791: a konstruktor a config összes kulcsát rámásolja az objektumra
	 * ($this->$key = $value), és ezek eddig DINAMIKUS property-ként jöttek létre —
	 * ami PHP 8.2 óta deprecated. Eddig nem tűnt fel, mert ezt az osztályt senki nem
	 * példányosította a szokásos futásokban; a clearAllOldCache() bekötésével viszont
	 * minden cron-futás négy figyelmeztetést írna a naplóba.
	 */
	public $client_id;
	public $client_secret;
	public $application_code;
	public $access_token;
	public $cache = false; // Ezt bizony csak olyan helyen használjuk leginkább ahol frissen kell minden.
	
	
	function __construct() {
		global $config;	
		if($config['openstreetmap'] == false) throw new Exception("OpenStreetMap API is disabled or undefined!");
		foreach($config['openstreetmap'] as $key => $value) 
			$this->$key = $value;
		
		$this->headerAuthorization = "Authorization: Bearer ".$this->access_token;
				
	}
	
	function run() {					
		parent::run();
	}
	
	function buildQuery() {      
        $this->rawQuery = $this->query;        
    }
	
	function prepareNewChangeset($tags = []) {

		if(!isset($tags['created_by'])) $tags['created_by'] = 'miserend.hu';
		if(!isset($tags['comment'])) $tags['comment'] = 'Changes were made based on miserend.hu\'s users\' experiences.';
		
		$changeset = new \SimpleXMLElement("<osm></osm>");
		$changeset->addChild('changeset');
		foreach($tags as $key => $value) {			
			$child = $changeset->changeset->addChild('tag');
			$child->addAttribute('k',$key);
			$child->addAttribute('v',$value);
		}
		
		//echo $changeset->asXML();
		return $changeset;		
	}
   
	function getAccessToken() {
		// Előbb kell friss application_code:
		// https://master.apis.dev.openstreetmap.org/oauth2/authorize?response_type=code&client_id=CLIEND_ID&redirect_uri=urn:ietf:wg:oauth:2.0:oob&scope=read_prefs%20write_api

		$this->postFields = "grant_type=authorization_code&code=".$this->application_code."&redirect_uri=".$this->redirect_uri."&client_id=".$this->client_id."&client_secret=".$this->client_secret."";
					
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $this->apiUrl. 'oauth2/token');
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS,$this->postFields);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		$server_output = curl_exec($ch);
		curl_close($ch);
			
		printr($server_output);		
		exit;
		
		
	}

	function changesetCreate($tags = []) {	
		$changeset = $this->prepareNewChangeset($tags);		
		$this->query = $this->rawQuery = "/api/0.6/changeset/create";
		$this->format = 'text';
		$this->curl_setopt(CURLOPT_CUSTOMREQUEST ,"PUT");		 	
		$this->curl_setopt(CURLOPT_POSTFIELDS,$changeset->asXML());
		$this->run();
				
		return (int) $this->rawData;
	}
	
	function putEntity($changesetID, $entityType, $entity) {			
		$entity->{$entityType}[0]->attributes()->changeset = $changesetID;	
		$xmlString = $entity->asXML();			
		
		$this->query = $this->rawQuery = '/api/0.6/'.$entityType."/".$entity->{$entityType}[0]->attributes()->id;			
		// $this->query = $this->rawQuery = '/api/0.6/'.$entityType."/create";				
		
		$this->curl_setopt(CURLOPT_CUSTOMREQUEST ,"PUT");		 	
		$this->format = 'text';
		$this->curl_setopt(CURLOPT_POSTFIELDS,$xmlString);
		$this->run();
		$versionID = (int) $this->rawData;		
		
		if($this->responseCode == 404) {
			addMessage ('Nem tudtuk elmenteni az adatokat. Mert a céladatbázisban nincs '.$entityType.':'.$entity->{$entityType}[0]->attributes()->id,'danger');
			return false;
		}
		
		return $versionID;
	}
	
	function changesetClose($changesetID) {

		$this->query = $this->rawQuery = "/api/0.6/changeset/".$changesetID."/close";
		$this->format = 'text';
		$this->curl_setopt(CURLOPT_CUSTOMREQUEST ,"PUT");		 			
		$this->run();
		
		$messageurl = $this->apiUrl."changeset/".$changesetID; 
		
		if($this->responseCode == 200 and $this->rawData == '')
			return true;
		else
			return false;
	}
	
}
