<?php

use Illuminate\Database\Capsule\Manager as DB;

class User {
    // Database fields from user table
    public $uid;
    public $login;
    public $jelszo;
    public $jogok;
    public $regdatum;
    public $lastlogin;
    public $lastactive;
    public $email;
    public $notifications;
    public $becenev;
    public $nev;
    public $volunteer;
    
    // Derived/computed properties
    public $username;
    public $nickname;
    public $name;
    public $roles;
    public $isadmin;
    public $loggedin;
    public $responsible;
    public $responsibilities;
    public $remarksCount;
    public $remarks;
    public $newpwd;
    public $presaved;
    public $favorites;
    public $inactiveDays;

    function __construct($uid = false) {
        if (isset($uid) AND $uid != false) {
            $user = DB::table('user')
                    ->select('*');
            
            if(!is_numeric($uid) AND filter_var($uid, FILTER_VALIDATE_EMAIL) ) {
                $user = $user->where('email', $uid);
            } elseif (!is_numeric($uid)) {
                $user = $user->where('login', $uid);
            } else {
                $user = $user->where('uid', $uid);
            }
            $user = $user->first();

            if($user) {
                foreach ($user as $key => $value) {
                    $this->$key = $value;
                }
                $this->username = $user->login;
                $this->nickname = $user->becenev;
                $this->name = $user->nev;
                $this->roles = explode('-', trim($this->jogok, " \t\n\r\0\x0B-"));
                foreach($this->roles as $k => $v)
                    if($v == '')
                        unset($this->roles[$k]);                
                $this->getResponsabilities();
                if ($this->checkRole('miserend')) {
                    $this->isadmin = true;
                }
                return true;   

            } else {
                 //TODO: kitalálni mit csináljon, ha  nincs megfelelő azobosítójú user. Legyen vendég?
                // There is no user with this uid;
                $uid = 0;
                //return false;
            }        
        }
        //Lássuk a vendégeket
        if (!isset($uid) OR ! is_numeric($uid) OR $uid == 0) {
            $this->loggedin = false;
            $this->uid = 0;
            $this->username = '*vendeg*';
            $this->nickname = '*vendég*';
            $this->responsible = false;
        } 
    }

    function checkRole($role = false) {
        if ($role == false)
            return true;

        if ($role == '"any"' OR $role == "'any'") {
            if (!isset($this->jogok)) {
                return false;
            }
            if (trim(preg_replace('/-/i', '', $this->jogok)) != '')
                return true;
            else
                return false;
        } elseif (preg_match('/^ehm:([0-9]{1,3})$/i', $role, $match)) {
            $isResponsible = DB::table('egyhazmegye')->where('id', $match[1])->where('felelos', $this->username)->first();
            if ($isResponsible)
                return true;
            else
                return false;
        } elseif (isset($this->jogok) AND preg_match('/(^|-)' . $role . '(-|$)/i', $this->jogok)) {
            return true;
        } else
            return false;
    }

    function getHoldingData($church_id) {
        $holding = \Eloquent\ChurchHolder::where('user_id',$this->uid)->where('church_id',$church_id)->orderBy('updated_at','desc')->first();
        if($holding) $holding->setAppends([]);
        return $holding;
    }
    
    function getResponsabilities() {
        $this->responsibilities = array(
            'diocese' => array(),
            'church' => array()
        );
        if ($this->uid > 0) {
            $results = DB::table('egyhazmegye')
                    ->select('id')
                    ->where('ok', 'i')
                    ->where('felelos', $this->username)
                    ->get();
            foreach ($results as $result) {
                $this->responsible['diocese'][] = $result->id;
            }
            
            
            $this->responsibilities['church'] = \Eloquent\ChurchHolder::where('user_id',$this->uid)->get()->groupBy('status');
            
            
            $this->responsible['church'] = \Eloquent\ChurchHolder::where('user_id',$this->uid)->where('status','allowed')->get()->Pluck('church_id')->toArray();
        }
        
    }

    function processResponsabilities() {
        if (!isset($this->responsible)) {
            $this->getResponsabilities();
        }

        $tmp = array();
        foreach ($this->responsible['church'] as $church) {
            $tmp[$church] = \Eloquent\Church::find($church);
        }
        $this->responsible['church'] = $tmp;
    }

    function getRemarks($limit = false, $ago = false) {
        if ($limit == false OR ! is_numeric($limit))
            $limit = 5;
        
        $query = \Eloquent\Remark::select('*',DB::raw('count(*) as total'))->where(function ($q) {
                    $q->where('login', $this->username)->orWhere('email', $this->email);
                });
        if ($ago != false)
            $query = $query->where('datum','>', date('Y-m-d H:i:s', strtotime("-" . $ago)));
            
        $query = $query->groupBy('church_id')->orderBy('created_at','desc');
        
        $this->remarksCount = $query->count();
        $this->remarks = $query->limit($limit)->get();
                
        return true;
    }

    function submit($vars) {
        $return = true;

        if (isset($vars['uid']) AND ! is_numeric($vars['uid']) AND $vars['uid'] != '') {
            addMessage('Nincs ilyen felhasználónk!', 'danger');
            return false;
        }

        $dangers = array(
            'uid' => 'Probléma támadt az azonosítóval!',
            'username' => 'Probléma a felhasználónévvel! (Nem megfelelő karakterek, vagy már használatban van. A felhasználó nevet nem lehet megváltoztatni.)',
            'nickname' => 'Probléma a becenévvel!',
            'name' => 'Probléma a névvel!',
            'email' => 'Nem megfelelő email cím! Talán már használatban van?',
            'volunteer' => 'Hibás értéke van az önkéntességnek!',
            'roles' => 'Hibás formátumú jogkörök!',
            'notifications' => 'Email értesítések engedélyezése körül hiba lépett fel!',
        );

        foreach (array('uid', 'username', 'nickname', 'name', 'email', 'volunteer', 'roles','notifications') as $input) {
            if (isset($vars[$input])) {
                if (!$this->presave($input, $vars[$input])) {
                    $return = false;
                    addMessage($dangers[$input], 'danger');
                }
            }
        }

        
        if (isset($vars['uid']) AND ( $vars['password1'] != '' OR $vars['password2'] != '')) {
            if ($vars['password1'] != $vars['password2'] OR $vars['password1'] == '') {
                addMessage('A két jelszó nem egyezik meg egymással', 'danger');
                $return = false;
            } else {
                if (!$this->presave('password', $vars['password1'])) {
                    $return = false;
                    addMessage('Sajnos nem megfelelő a jelszó!', 'danger');
                }
            }
        }
        
        if ($return == false)
            return false;
               
        if (!isset($vars['uid'])) {
            $pwd = $this->generatePassword();
            $this->presave('password', $pwd);                
        }

        if (!$this->save()) {
            addMessage("Nem sikerült elmenteni. Pedig minden rendben volt előtte.", "warning");
            return false;
        } else {
            if (!isset($vars['uid'])) {
                addMessage("A felhasználót sikeresen létrehoztuk.", "success");
                
                $this->newpwd = $pwd;
                $email = new \Eloquent\Email();
                $email->render('user_welcome', $this);
                if ($email->send($this->email))
                    addMessage("Elküldtük az emailt az új regisztrációról.", "success");                                
            }    
            else
                addMessage("A változásokat elmentettük.", "success");
        }
        return true;
    }

    function presave($key, $val) {
        if (!isset($this->presaved))
            $this->presaved = array();
        //TODO: check duplicate for: logn + email
        //TODO: van, amit ne engedjen, csak, amikor még tök új a cuccos.
        //TODO: a nickname - becenev / name - nev esetén ez nem segít, bár nem sok dupla munka azért
        //TODO: elrontja ...
        //if($this->$key == $val) return true;
        //TODO: szóljon vissza a kötelező
        if ($val == '' AND in_array($key, array('username', 'login', 'email'))) {
            return false;
        }

        if ($key == 'uid') {
            if ($this->uid != $val)
                return false;
        } elseif (in_array($key, array('username', 'login'))) {
            if ($this->uid == 0) {
                if (!checkUsername($val))
                    return false;                    
                $this->presaved['login'] = sanitize($val);
            } elseif ($this->username != $val) {
                return false;
            }
        } elseif (in_array($key, array('jelszo', 'password'))) {
            $this->presaved['jelszo'] = password_hash($val, PASSWORD_BCRYPT);
        } elseif ($key == 'roles' OR $key == 'jogok') {
            if (!is_array($val))
                $val = array($val);
            $jogok = [];
            foreach ($val as $k => $i) {
                if($k == $i ) {
                    $jogok[$k] = trim(sanitize($i), "-");
                }
            }
            $jogok = array_unique($jogok);
            $this->presaved['jogok'] = implode('-', $jogok);
        } elseif ($key == 'nickname' or $key == 'becenev') {
            $this->presaved['becenev'] = sanitize($val);
        } elseif ($key == 'name' or $key == 'nev') {
            $this->presaved['nev'] = sanitize($val);
        } elseif ($key == 'volunteer') {
            if ($val == '')
                $this->presaved[$key] = 0;
            elseif (in_array($val, array(0, 1)))
                $this->presaved[$key] = $val;
            else
                return false;       
        } elseif (in_array($key, array('regdatum', 'lastlogin', 'lastactive'))) {
            if (is_numeric($val)) {
                $this->presaved[$key] = date('Y-m-d H:i:s', $val);
            } elseif (strtotime($val))
                $this->presaved[$key] = date('Y-m-d H:i:s', strtotime($val));
            else
                return false;
        } elseif ($key == 'email') {
            if (!filter_var($val, FILTER_VALIDATE_EMAIL)) {
                return false;
            }
            if ($this->isEmailInUse($val) AND ( !isset($this->email) OR $val != $this->email )) {
                return false;
            }
            $this->presaved[$key] = $val;
        } elseif ($key == 'notifications') {
            if(!in_array($val,[0,1])) return false;            
            $this->presaved[$key] = $val;
        } else
            return false;

        return true;
    }

    function save() {
        if (!$this->presaved)
            return false;

        //Set Deafult
        if ($this->uid < 1) {
            if (!isset($this->presaved['regdatum']))
                $this->presave('regdatum', time());
        }
        
        if ($this->uid == 0 AND isset($this->presaved['login'])) {
            try {
                $this->uid = DB::table('user')->insertGetId($this->presaved);
            } catch (Exception $e) {
                addMessage($e->getMessage(),'danger');
                return false;
            }           
        } elseif ($this->uid > 0) {
            try {
                DB::table('user')->where('uid',$this->uid )->update($this->presaved);
            } catch (Exception $e) {
                addMessage($e->getMessage(),'danger');
                return false;
            }           
            
        }

        foreach ($this->presaved as $key => $val)
            $this->$key = $val;

        //TODO: ezt már egyszer leírtam
        $this->username = $this->login;
        $this->nickname = $this->becenev;
        $this->name = $this->nev;
        if(isset($this->jogok))
            $this->roles = explode('-', trim($this->jogok, " \t\n\r\0\x0B-"));
        else
            $this->roles = [];

        unset($this->presaved);

        return $this->uid;
    }

    function delete() {
        if ($this->uid == 0)
            return false;

        \Eloquent\ChurchHolder::where('user_id',$this->uid)->delete();
        \Eloquent\Favorite::where('uid',$this->uid)->delete();
        
        \Eloquent\Remark::where('login', $this->username)->update([
            'login' => 'deleted_user',
            'email' => 'deleted_user@miserend.invalid',
            'nev'   => '*törölt felhasználó*'
        ]);
        
        \Eloquent\CalSuggestionPackage::where('sender_user_id', $this->uid)->update([
            'sender_user_id' => 0,
            'sender_email'   => 'deleted_user@miserend.invalid',
            'sender_name'    => '*törölt felhasználó*'
        ]);
        

        DB::table('user')->where('uid', $this->uid)->delete();
        
        foreach ($this as $key => $value)
            unset($this->$key);
        $this->loggedin = false;
        $this->uid = 0;
        $this->username = '*vendeg*';
        $this->nickname = '*vendeg*';
        
        addMessage('Sikeresen töröltük a felhasználót.', 'success');
        return true;
    }

    function generatePassword($length = 8) {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $count = mb_strlen($chars);

        for ($i = 0, $result = ''; $i < $length; $i++) {
            $index = rand(0, $count - 1);
            $result .= mb_substr($chars, $index, 1);
        }

        return $result;
    }

    function newPassword($text) {
        $this->presave('password', $text);
        $this->save();
    }

    function active() {
        DB::table('user')->where('uid', $this->uid)->update(['lastactive' => date('Y-m-d H:i:s')]);
    }

    function getFavorites() {
        $favorites = array();

        if ($this->uid > 0) {
            $favorites = \Eloquent\Favorite::where('uid',$this->uid)->get()->sortBy(function($favorite){
                        return $favorite->church->nev;
                });
        }
        else {
            $favorites = \Eloquent\Favorite::groupBy('tid')->select('tid', DB::raw('count(*) as total'))->orderBy('total','DESC')->limit(10)->get();
        }        
        
        foreach ($favorites as $favorite) {
            $this->favorites[$favorite->tid] = $favorite; 
        }

        return $favorites;
    }

    function checkFavorite($tid) {
        if (!isset($this->favorites)) {
            $this->favorites = $this->getFavorites();
        }
        foreach ($this->favorites as $favorite) {
            if ($favorite['tid'] == $tid) {
                return true;
            }
        }
        return false;
    }

    function addFavorites($tids) {
        if (!is_array($tids))
            $tids = array($tids);
        foreach ($tids as $tid) {
            if (!is_numeric($tid))
                return false;
        }
        foreach ($tids as $key => $tid) {
            if (!\Eloquent\Church::find($tid))
                unset($tids[$key]);
            else {
                $favorite = new Eloquent\Favorite;
                $favorite->uid = $this->uid;
                $favorite->tid = $tid;
                $favorite->save();
                }
        }
        return true;
    }

    function removeFavorites($tids) {
        if (!is_array($tids))
            $tids = array($tids);
        foreach ($tids as $tid) {
            if (!is_numeric($tid))
                return false;
        }
        try {
            $query = \Eloquent\Favorite::where('uid',$this->uid)->whereIn('tid',$tids)->delete();
            return true;
        } catch (Exception $ex) {
            addMessage($ex->getMessage(), 'danger');
            return false;
        }                
    }

    function isEmailInUse($val) {
        $result = DB::table('user')
                ->select('email')
                ->where('email', $val)
                ->limit(1)
                ->get();
        if (count($result)) {
            return true;
        } else
            return false;
    }

    static function load() {        
        if (!isset($_COOKIE['token'])) 
            return new \User();
                        
        $token = \Eloquent\Token::where('name',$_COOKIE['token'])->first();
        if(!$token or !$token->isValid) {
            \Token::delete();
            return new \User();
        }                        

        $token->extend();
        
        $user = new \User($token->uid);
        $user->loggedin = true;
        $user->active();
        return $user;
    }

    static function login($name, $password) {
        \Token::delete();
        $name = sanitize($name);
        $userRow = DB::table('user')->where('login', $name)->first();
        if (!$userRow) {
            throw new \Exception("There is no such user.");
        }
        if (!password_verify($password, $userRow->jelszo)) {
            throw new \Exception("Invalid password.");
        }
  
        Token::create($userRow->uid, 'web');
        
        DB::table('user')->where('uid', $userRow->uid)->update(['lastlogin' => date('Y-m-d H:i:s')]);
        return $userRow->uid;
    }

    static function logout() {
        addMessage('Sikeresen kijelentkeztél.', 'success');
        \Token::delete();        
    } 
	
	static function deleteNonActivatedUsers() {
		$waitingBeforeDelete = '2 weeks';
		
		$users2delete = DB::table('user')
			->where('lastlogin', '0000-00-00 00:00:00')
			->where('regdatum','<',date('Y-m-d H:i:s',strtotime('-'.$waitingBeforeDelete)));						
		//We delete on if we have already sent user_pleaseactivate message		
		$users2delete->whereRaw(DB::RAW(" EXISTS ( 
					SELECT * 
					FROM emails
					WHERE
						`type` = 'user_pleaseactivate' AND 
						`status` = 'sent' AND
						emails.to = user.email AND
						updated_at < '".date('Y-m-d H:i:s',strtotime('-2 days'))."'
						ORDER BY updated_at DESC
						LIMIT 1
					) "));
		
		$results = $users2delete->orderByRaw("RAND()")
			->limit(20)			// Lehetne végtelen, de jobb az óvatosság. Pláne, hogy még egyesével mennek az emailek
			->get();			// Lehetne itt rögtön ->delete(), de a debug dolog miatt jobb, ha tovább is van

        $countDeleted = 0;
		foreach($results as $result) {
            try {
                $user2delete = new User($result->uid);
                $user2delete->delete();
                $countDeleted++;
            } catch (Exception $e) {
                addMessage('Nem sikerül törölni a felhasználót: '.$result->uid, 'error');
                echo "Nem sikerült törölni a felhasználót: ".$result->uid." ".$result->email." ".$result->nev." ".$result->regdatum." ".$result->lastlogin." ".$result->jogok." ".print_r($result,1)." ";                
                continue;
            }
            
			$email = new \Eloquent\Email();
			$email->to = $result->email;
			$email->render('user_youhavebeendeleted',$result);			
			// $email->addToQueue();
			$email->send();
			
		}
		$count = DB::table('user')->whereIN('uid',$ids2delete)->delete();
									
	}
	

	static function sendActivationNotification() {
		$lastEmailDiff = '-1 week';
	
		$users2notify = DB::table('user')
			->where('lastlogin', '0000-00-00 00:00:00')			
			->where('notifications', 1) 
			->orderByRaw("RAND()")
			->limit(5)			
			->get();
			

		foreach($users2notify as $user) {					
			$lastEmail = DB::table('emails')
				->where('type','user_pleaseactivate')
				->where('to',$user->email)
				->whereIn('status',['queued','sent'])				
				->orderBy('updated_at','desc')				
				->first();
					
			// Van olyan emlékztetőnk ami a sorban várakozik
			// vagy nincs egy hete hogy küldtünk neki értesítőt
			if (isset($lastEmail) AND (
					$lastEmail->status == 'queued' OR 
					strtotime($lastEmail->updated_at) > strtotime($lastEmailDiff)
					) ) {
				// Nincs mit tenni
			} else {
			
				// Nincs még korábbi értesítő, vagy az már öregebb mint egy hét			
				$user->inactiveDays = abs( round ( ( time() - strtotime($user->regdatum)) / 86400 ) );
				
				$email = new \Eloquent\Email();
				$email->to = $user->email;
				$email->render('user_pleaseactivate',$user);			
				// $email->addToQueue();
				$email->send();
			}
		}
		
		return true;
	}
	
	static function sendInactivityNotification() {
		$lastEmailDiff = '-3 week';
		$inactivityPeriod = '-5 years';
	
		$users2notify = DB::table('user')
			->where('lastlogin', '<', date('Y-m-d H:i:s',strtotime( $inactivityPeriod )) )			
			->orderByRaw("RAND()")
			->limit(5)			
			->get();
			

		foreach($users2notify as $user) {					
			$lastEmail = DB::table('emails')
				->where('type','user_pleaselogin')
				->where('to',$user->email)
				->whereIn('status',['queued','sent'])				
				->orderBy('updated_at','desc')				
				->first();
					
			// Van olyan emlékztetőnk ami a sorban várakozik
			// vagy nincs egy hete hogy küldtünk neki értesítőt
			if (isset($lastEmail) AND (
					$lastEmail->status == 'queued' OR 
					strtotime($lastEmail->updated_at) > strtotime($lastEmailDiff)
					) ) {
				// Nincs mit tenni
                    }   
            // Már régen küldtünk emailt, itt az ideje törölni.        
            elseif( isset($lastEmail) AND 
                $lastEmail->status == 'sent' AND 
                strtotime($lastEmail->updated_at) < strtotime($lastEmailDiff) )
            {

                    
                $email = new \Eloquent\Email();
                $email->to = $user->email;
                $email->render('user_youhavebeendeleted',$user);			
                // $email->addToQueue();
                $email->send();
                if (!DB::table('user')->where('uid',$user->uid)->limit(1)->delete())  {
                            addMessage('Nem sikerül mindenkit törölni.', 'error');
                            echo "Nem sikerült mindenkit aki még nem lépett be törölni! ".print_r($user,1)." ";
                }

        } else {
			
				// Nincs még korábbi értesítő, vagy az már öregebb mint egy hét			
				$user->inactiveDays = abs( round ( ( time() - strtotime($user->lastlogin)) / 86400 ) );
				
				$email = new \Eloquent\Email();
				$email->to = $user->email;
				$email->render('user_pleaselogin',$user);			
				// $email->addToQueue();
				$email->send();
			}
		}
		
		return true;
	}
		static function sendUpdateNotification() {
	

			$users2notify = DB::table('templomok')
				->select('templomok.id as tid','templomok.nev','templomok.ismertnev','templomok.varos','templomok.frissites')
				->join('church_holders','templomok.id','=','church_holders.church_id')
				->addSelect('church_holders.description')
				->join('user','user.uid','=','church_holders.user_id')
				->addSelect('user.*');
				
				$users2notify->whereRaw(DB::RAW(" NOT EXISTS ( 
					SELECT * 
					FROM emails
					WHERE
						`type` = 'user_pleaseupdate' AND 
						`status` IN ('sent','queued') AND
						emails.to = user.email AND
						updated_at > '".date('Y-m-d H:i:s',strtotime('-2 weeks'))."'
						ORDER BY updated_at DESC
						LIMIT 1
					) "));
				
			$users2notify = $users2notify->where('church_holders.status','allowed')
				->whereNull('church_holders.deleted_at')
				->where('user.jogok','not like','%miserend%')->where('user.notifications',1)
				->whereNotNull('user.email')
				->where('user.email','<>','')
				->where('templomok.frissites','<',date('Y-m-d',strtotime('-1 year')))->where('templomok.ok','i')
								
			->groupBy('user.email')
			->orderByRaw("RAND()")
			->limit(5)
			->get();
		
			/*
		global $config;
		$config['debug'] = 2;
		$config['mail']['debug'] = 3;
		*/
		// printr($users2notify);		
		// $tmp = new stdClass(); $tmp->uid = 1595; $users2notify = [ $tmp ];
				
		foreach($users2notify as $user2notify) {
			$user = new User($user2notify->uid);
			$user->getResponsabilities();

			foreach($user->responsible['church'] as $key => $churchID) {
				$user->responsible['church'][$churchID] = \Eloquent\Church::find($churchID);
				unset($user->responsible['church'][$key]);
			}

			$batchId = bin2hex(random_bytes(16));
			$churchTokens = [];
			foreach ($user->responsible['church'] as $churchID => $church) {
				$token = bin2hex(random_bytes(32));
				\Eloquent\ChurchUpdateToken::create([
					'token'          => $token,
					'uid'            => $user->uid,
					'church_id'      => $churchID,
					'email_batch_id' => $batchId,
					'expires_at'     => date('Y-m-d H:i:s', strtotime('+3 weeks')),
				]);
				$churchTokens[$churchID] = $token;
			}
			$allToken = bin2hex(random_bytes(32));
			\Eloquent\ChurchUpdateToken::create([
				'token'          => $allToken,
				'uid'            => $user->uid,
				'church_id'      => null,
				'email_batch_id' => $batchId,
				'expires_at'     => date('Y-m-d H:i:s', strtotime('+3 weeks')),
			]);
			$user->churchTokens = $churchTokens;
			$user->allToken     = $allToken;

			$email = new \Eloquent\Email();
			$email->to = $user->email;
			$email->render('user_pleaseupdate',$user);
			// $email->addToQueue();
			$email->send();
					
		}
		
		return true;
		}

	/**
	 * #290: Ünnep-emlékeztető a templomgondnokoknak, 2 héttel a parancsolt ünnepek előtt.
	 *
	 * Parancsolt ünnepek a cal_periods seedből (verifikált ID-k): 22 Karácsony,
	 * 25 Szent Három nap (húsvét), 21 Hamvazószerda, 40 Mindenszentek,
	 * 41 Nagyboldogasszony, 42 Vízkereszt. (Pünkösd/12-31 borazslo szerint nem
	 * külön periódus.) A napi cron megnézi, mely ünnep KEZDŐDIK pontosan 2 hét
	 * múlva (materializált cal_generated_periods), és az érintett gondnokoknak küld.
	 */
	static function sendHolidayReminder() {
		$feastIds = [22, 25, 21, 40, 41, 42];
		$target = date('Y-m-d', strtotime('+2 weeks'));

		$feasts = \Eloquent\CalGeneratedPeriod::whereIn('period_id', $feastIds)
			->whereRaw('DATE(start_date) = ?', [$target])
			// #290: a jegy szerint CSAK a NEM vasárnapra eső parancsolt ünnepekre
			// emlékeztetünk (vasárnap úgyis mennek misére). DAYOFWEEK: 1 = vasárnap.
			->whereRaw('DAYOFWEEK(start_date) <> 1')
			->get();

		foreach ($feasts as $feast) {
			self::sendHolidayReminderForFeast($feast);
		}
		return true;
	}

	/** #290: egy konkrét, 2 hétre lévő ünnepre küld a gondnokoknak (gondnokonként 1 email). */
	private static function sendHolidayReminderForFeast($feast) {
		$type = 'holder_holiday_reminder';
		$feastPeriodId = $feast->period_id;

		// Gondnok-kiválasztás (a sendUpdateNotification query klónja) + type-alapú dedup.
		$users2notify = DB::table('templomok')
			->select('user.*')
			->join('church_holders', 'templomok.id', '=', 'church_holders.church_id')
			->join('user', 'user.uid', '=', 'church_holders.user_id')
			->whereRaw(" NOT EXISTS ( SELECT 1 FROM emails WHERE `type` = ? AND `status` IN ('sent','queued') AND emails.to = user.email AND updated_at > ? LIMIT 1 ) ",
				[$type, date('Y-m-d H:i:s', strtotime('-2 weeks'))])
			->where('church_holders.status', 'allowed')
			->whereNull('church_holders.deleted_at')
			->where('user.jogok', 'not like', '%miserend%')
			->where('user.notifications', 1)
			->whereNotNull('user.email')->where('user.email', '<>', '')
			->where('templomok.ok', 'i')
			->groupBy('user.email')
			// #290: NINCS limit/RAND — egynapos trigger + napi cron mellett a limit(5)
			// ünnepenként csak 5 gondnokot érne el. A rate-limitet az Email::sendQueued
			// drainer intézi (külön cron), nem a kiválasztás.
			->get();

		$today = date('Y-m-d');

		foreach ($users2notify as $user2notify) {
			$user = new User($user2notify->uid);
			$user->getResponsabilities();

			// Csak azok a templomok, amelyeknek KELL az emlékeztető erre az ünnepre.
			$churches = [];
			foreach ($user->responsible['church'] as $churchID) {
				$church = \Eloquent\Church::find($churchID);
				if (!$church || $church->ok !== 'i') continue;
				$filled = \Eloquent\CalMass::where('church_id', $churchID)
					->where('period_id', $feastPeriodId)->exists();
				if (!self::holidayReminderNeeded($filled, $church->frissites, $today)) continue;
				$church->holidayFilled = $filled;
				$churches[$churchID] = $church;
			}
			if (empty($churches)) continue;

			// Tokenek (klón): per-templom + egy "minden naprakész".
			$batchId = bin2hex(random_bytes(16));
			$churchTokens = [];
			foreach ($churches as $churchID => $church) {
				$token = bin2hex(random_bytes(32));
				\Eloquent\ChurchUpdateToken::create([
					'token' => $token, 'uid' => $user->uid, 'church_id' => $churchID,
					'email_batch_id' => $batchId,
					'expires_at' => date('Y-m-d H:i:s', strtotime('+3 weeks')),
				]);
				$churchTokens[$churchID] = $token;
			}
			$allToken = bin2hex(random_bytes(32));
			\Eloquent\ChurchUpdateToken::create([
				'token' => $allToken, 'uid' => $user->uid, 'church_id' => null,
				'email_batch_id' => $batchId,
				'expires_at' => date('Y-m-d H:i:s', strtotime('+3 weeks')),
			]);

			$user->responsible['church'] = $churches;
			$user->churchTokens = $churchTokens;
			$user->allToken = $allToken;
			$user->feastName = $feast->name;
			$user->feastStart = $feast->start_date;

			$email = new \Eloquent\Email();
			$email->to = $user->email;
			$email->render('holder_holiday_reminder', $user);
			// #290: queue-ba tesszük (nem azonnali); az Email::sendQueued cron küldi ki.
			$email->addToQueue();
		}
	}

	/**
	 * #290: Kell-e ünnep-emlékeztetőt küldeni erre a (templom, ünnep) párra? Tiszta logika.
	 *
	 * borazslo spec-je: Karácsony/Húsvét -> küldj, ha 6 hó nincs frissítés (kitöltöttségtől
	 * függetlenül) VAGY nincs kitöltve. Többi ünnep -> küldj, ha nincs kitöltve; ha kitöltve,
	 * csak ha 6 hó nincs frissítés. Mindkét szabály erre egyszerűsödik:
	 *   küldj, ha (nincs kitöltve) VAGY (a frissítés 6 hónapnál régebbi).
	 *
	 * @param bool    $periodHasMass  van-e mise az adott ünnep-periódussal (kitöltött-e)
	 * @param ?string $lastUpdate     templomok.frissites (Y-m-d) vagy null/üres
	 * @param string  $today          mai dátum (Y-m-d) — teszthez injektálható
	 */
	static function holidayReminderNeeded(bool $periodHasMass, ?string $lastUpdate, string $today): bool {
		if (!$periodHasMass) return true;
		$sixMonthsAgo = date('Y-m-d', strtotime($today . ' -6 months'));
		return empty($lastUpdate) || $lastUpdate < $sixMonthsAgo;
	}

}
