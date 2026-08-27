<?php

namespace Html\User;

class Edit extends \Html\Html {

    public function __construct() {
        global$user;

        $this->uid = \Request::IntegerwDefault('uid', $user->uid);

        $isForm = \Request::Text('submit');
        // #873: a felhasználói adatok (jelszó, email, értesítések) mentése — POST + token.
        if ($isForm) { \Csrf::guard(); }
        if ($isForm) {
            if($this->modify()) {
                /* Ha az aktuális felhasználót frissítettük, akkor be kell töltenünk újra a felhasználót a friss adatokkal */
                if($this->uid == $user->uid) {
                    $user2 = new \User($this->uid);
                    $user = $user2->load();
                }
            }
        }
        $this->preparePage();
    }

    function modify() {
        global $user;
        
        // #391: az `edituser` egy többdimenziós mezőcsoport (uid, roles, nev, ...) vegyes
        // értéktípusokkal, a submit() az egészet fogyasztja — mezőnkénti \Request::
        // hívásokra nem bontható. Az viszont igen, hogy ne nyersen a szuperglobálishoz
        // nyúljunk: a \Request::Fields() ellenőrzött MÁSOLATOT ad.
        //
        // A másolat itt előny is: a jogosultság-visszavonást eddig magán a $_REQUEST-en
        // végeztük (globális állapot módosítása), most a saját tömbünkön.
        $edituser = \Request::Fields('edituser');
        if ($edituser === false) {
            $edituser = [];
        }

        $newuser = new \User(isset($edituser['uid']) ? $edituser['uid'] : false);

        if ((\Request::get('terms') != 1) AND $newuser->uid == 0 AND $user->uid == 0) {
            addMessage("El kell fogadni a <i>Házirendet és szabályzatot</i>!", 'danger');
            return false;
        } else if ((\Request::get('robot') != 'MKPK') AND $newuser->uid == 0 AND $user->uid == 0) {
            addMessage("Sajnos, ha nem válaszol az MKPK-val kapcsolatos kérdésre, akkor önt robotnak nézzük és nem regisztrálhat!", 'danger');
            return false;
        } 		
		else {
            try {
                // Jogokat nem adhat akárki, de lemondhat akráki.
                if(!$user->checkRole('user') AND isset($edituser['roles']) AND is_array($edituser['roles'])) {
                    foreach($edituser['roles'] as $key => $value) {
                        /* Ha eddig nem volt joga, de a formban joga lenne, akkor baj van */
                        if(!in_array($key, (array) $user->roles) AND $key == $value) {
                            $edituser['roles'][$key] = false;
                            addMessage('A „'.$key.'” jogosultság megadásához nem rendelkezel elég jogosultsággal.','danger');
                        }
                    }
                }
                
                if($newuser->submit($edituser)) {                
                    if ($user->uid < 1) {
                        global $config;                    
                        //require_once('moduls/miserend.php');
                        //$tartalom = miserend_index();
                        $this->newusercreated = true;
                    } else {
                        $this->uid = $newuser->uid;
                    }
                    return true;
                } else {
                    return false;
                }
            } catch (\Exceptions $e) {
                addMessage($e->getMessage());
                return false;
            }
        }
    }

    function preparePage() {
        global $user;
        $uid = $this->uid;
        $roles = unserialize(ROLES);
        $vars['roles'] = array();        
        foreach ($roles as $role) {            
            $vars['roles'][$role] = $role;
        }


        //Ha folyamatban van új felszanáló szerkesztése
        $submitted = \Request::Fields('edituser');
        if ($user->uid == 0 AND $submitted !== false) {
            $edituser = new \User();
            foreach ($submitted as $key => $value) {
                $edituser->$key = $value;
            }

            
        } else
            $edituser = new \User($uid);

        if ($edituser->uid == 0 AND $user->uid == 0 AND preg_match('/\/new$/i',$_SERVER['REQUEST_URI'])) {                                
            $vars['title'] = "Regisztráció";
            $vars['new'] = true;
            $vars['helptext'] = true;
        } elseif ($edituser->uid == 0 AND $user->uid > 0) {
            $vars['title'] = "Új felhasználó";
            if (!$user->checkRole('user')) {
                addMessage("Nincs megfelelő jogosultságod!", "danger");
                $vars['accessdenied'] = true;        
            }
            $vars['new'] = true;
        } else {
            $vars['title'] = "Adatok módosítása";
            if (!$user->checkRole('user') AND $user->uid != $edituser->uid) {
                addMessage("Nincs megfelelő jogosultságod!", "danger");
                $vars['accessdenied'] = true;                               
            } elseif($edituser->uid == 0 AND $user->uid == 0 ) {
                addMessage("A személyes adatok módosításához be kell előbb lépni.", "warning");
                $vars['needtologin'] = true;
            }
            $vars['edit'] = true;
        }

        if ($edituser->username == '*vendeg*')
            $edituser->username = false;
        if ($edituser->nickname == '*vendég*')
            $edituser->nickname = false;

        if($user->loggedin)
            $edituser->getRemarks(6);

        if ($user->checkRole('user')) {
            $user->isadmin = true;
        }

        $vars['edituser'] = $edituser;

        foreach ($vars as $key => $value) {
            $this->$key = $value;
        }
        
        if($user->loggedin)
            $this->edituser->processResponsabilities();        
    }

}
