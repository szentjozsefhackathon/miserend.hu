<?php

namespace Html;

use stdClass;

class Remark extends Html {

    public $template;
    public $action;
    public $tid;
    public $church;
    public $disclaimer;
    public $debug;

    public function __construct($path) {
        $this->action = $path[0];
        $this->tid = $rid = $path[1];

        $this->church = \Eloquent\Church::find($this->tid);
        $this->disclaimer = 'Figyelem! Nem állunk közvetlen kapcsolatban a plébániákkal ezért plébániai ügyekben (pl. keresztelési okiratok, stb.) sajnos nem tudunk segíteni.';
        
        switch ($this->action) {
            case 'list':
                $this->pageList();
                $this->template = 'remark_list.twig';                        
                break;

            case 'addform':                
                $this->template = 'remark_form.twig';
                break;

            case 'add':
                $this->pageAdded();
                $this->template = 'remark.twig';
                break;
        }
    }
    
    function pageList() {
        if (\Request::Simpletext('remark') == 'modify') {
            $rid = \Request::IntegerRequired('rid');
            $remark = \Eloquent\Remark::find($rid);
            
            $remark->allapot = \Request::Simpletext('state');
            $remark->admindatum = date('Y-m-d H:i:s');
            
            $remark->appendComment(\Request::Text('adminmegj'));
            $remark->save();
            
            if($this->tid != $remark->church_id) { // Hogy ne lehessen csalni
                $this->tid = $remark->church_id;
                $this->church = \Eloquent\Church::find($this->tid);
            }
        }
       
        global $user;
        if (!$this->church->writeAccess) {
            addMessage("Hiányzó jogosultság. Elnézést.", "danger");
            return;
        }
        
        $this->church->remarks;

        // Split adminmegj by line into adminmegjlist for each remark, and extract meta info if present
        if (isset($this->church->remarks) && is_iterable($this->church->remarks)) {
            foreach ($this->church->remarks as &$remark) {                
                $remark->adminmegjlist = [];                
                if (isset($remark->adminmegj) && is_string($remark->adminmegj)) {
                    $lines = preg_split('/\r?\n/', $remark->adminmegj);
                    foreach ($lines as $line) {
                        $line = trim($line);
                        if ($line === '') continue;
                        $entry = [
                            'raw' => $line,
                            'user' => null,
                            'timestamp' => null,
                            'text' => null
                        ];                             
                        // Try to extract <img ... title='user (timestamp)'>
                        if (preg_match(
                            "#<img[^>]*title='([^']+)\s\(([^)]+)\)'>\s*(.*)#",
                            $line,
                            $m
                        )) {

                            $entry['user']      = $m[1]; // admin
                            $entry['timestamp'] = $m[2]; // 2025-08-08 21:43:59
                            $entry['text']      = $m[3]; // email küldve: remarkfeedback_koszonet (19)
                            
                            if (preg_match('/email küldve:\s*[^\(]*\((\d+)\)/', $entry['text'], $idMatch)) {
                                $entry['email_id'] = $idMatch[1];
                                
                                $entry['email'] = \Eloquent\Email::find($idMatch[1]);
                            }

                        } else {
                            $entry['text'] = $line;
                        }
                        $remark->adminmegjlist[] = $entry;                        
                    }
                }
            }            
        }    
    }
    
       
   
    function pageAdded() {

        $remark = new \Eloquent\Remark;

        $remark->church_id = $this->church->id;
        $remark->allapot = 'u';
        $remark->leiras = \Request::TextRequired('leiras');
        $remark->email = \Request::TextRequired('email');
        $remark->nev = \Request::Text('nev');
        if($remark->nev == '') $remark->nev = $remark->email;
        
       
        // Belépett felhasználónál hidden email és név adat volt, de nem bízunk benne
        global $user;
        if ($user->username != "*vendeg*") {
            $remark->login = $user->username;
            $remark->email = $user->email;
        }
        
        // #755: ugyanaz az észrevétel néha két-háromszor futott be, pár másodperc
        // különbséggel — a beküldő türelmetlenül többször nyomta a gombot, illetve a
        // /remark/add POST-ra nincs átirányítás, tehát egy frissítés is újraküldi.
        //
        // Kiszolgáló oldalon fogjuk meg, mert az fedi le mindhárom okot (dupla
        // kattintás, újraküldés, hálózati ismétlés). Azonos templom + azonos email +
        // BETŰRE azonos szöveg rövid időn belül nem lehet szándékos második
        // észrevétel. A beküldő ugyanazt a visszajelzést kapja, különben azt hinné,
        // hogy nem ment el, és megint próbálkozna.
        if (\Eloquent\Remark::findRecentDuplicate($remark->church_id, $remark->email, $remark->leiras)) {
            global $config;
            $this->debug = $config['debug'];
            return;
        }

        $megbizhato = \Eloquent\Remark::select('megbizhato')->where('email',$remark->email)->orderBy('created_at','desc')->limit(1)->first();
        if($megbizhato)
            $remark->megbizhato = $megbizhato->megbizhato;
        else
            $remark->megbizhato = '?';

        if (!$remark->save())
            addMessage("Nem sikerült elmenteni az észrevételt. Sajánljuk.", "danger");

        if (!$remark->emails())
            addMessage("Nem sikerült elküldeni az értesítő emaileket.", "warning");
                
        global $config;
        $this->debug = $config['debug'];        
        
    }

}
