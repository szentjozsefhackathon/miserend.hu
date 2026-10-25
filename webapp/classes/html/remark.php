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
    
    /** #868: az észrevétel érvényes állapotai (l. `remarks.allapot` enum). */
    const ALLAPOTOK = ['u', 'f', 'j'];

    function pageList() {
        if (\Request::Simpletext('remark') == 'modify') {
            // #873: POST + token. A #869-ben a jogosultság-ellenőrzést hoztam előre, de a
            // művelet GET-en is indítható maradt — egy beágyazott kép a bejelentkezett
            // gondnok nevében állította volna „feldolgozottra" a bejelentést.
            \Csrf::guard();

            $rid = \Request::IntegerRequired('rid');
            $remark = \Eloquent\Remark::find($rid);

            /*
             * #868: ELŐBB a jogosultság, AZTÁN a mentés.
             *
             * Itt korábban a `save()` a `writeAccess` őr ELŐTT futott le — az őr csak
             * lentebb, a lista megjelenítése előtt állt. Következmény, mérve, sima
             * GET-tel, bejelentkezés NÉLKÜL:
             *
             *   /index.php?q=remark/list/1&remark=modify&rid=3&state=f&adminmegj=…
             *   -> HTTP 200, és az adatbázisban: allapot u -> f, admindatum frissült,
             *      az adminmegj-be bekerült a támadó szövege.
             *
             * Vagyis bárki elrejthette a bejelentéseket a gondnokok elől („f" =
             * feldolgozva), és tetszőleges tartalmat írhatott az admin-megjegyzésbe,
             * amit a lista a gondnok böngészőjében jelenít meg.
             *
             * A jogot a MEGTALÁLT észrevétel templomához nézzük, nem az URL-ből jövő
             * `tid`-hez: az utóbbi a támadó paramétere. (A régi kód ezt a mentés UTÁN
             * javította ki — „hogy ne lehessen csalni" —, csak épp későn.)
             */
            if (!$remark) {
                addMessage('Nincs ilyen észrevétel.', 'danger');
                return;
            }

            if ($this->tid != $remark->church_id) {
                $this->tid = $remark->church_id;
                $this->church = \Eloquent\Church::find($this->tid);
            }

            if (!$this->church || !$this->church->writeAccess) {
                addMessage('Hiányzó jogosultság. Elnézést.', 'danger');
                return;
            }

            /*
             * #868: az állapot FEHÉRLISTÁS. A `Simpletext` bármit átenged, az oszlop
             * viszont enum — egy ismeretlen érték némán üres stringgé válna, és az
             * észrevétel besorolhatatlan állapotba kerülne.
             */
            $ujAllapot = \Request::Simpletext('state');
            if (!in_array($ujAllapot, self::ALLAPOTOK, true)) {
                addMessage('Érvénytelen állapot.', 'danger');
                return;
            }

            $remark->allapot = $ujAllapot;
            $remark->admindatum = date('Y-m-d H:i:s');

            $remark->appendComment(\Request::Text('adminmegj'));
            $remark->save();
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

        /*
         * #890: az `admindatum`-ot a PHP órája írja, ne a MySQL-é.
         *
         * Az oszlopon `DEFAULT current_timestamp()` van, és eddig egyik beszúró út sem
         * adta meg — tehát a MySQL órája töltötte, az pedig a `+05:00`-s session-zóna
         * miatt három órával a budapesti falióra előtt jár. Ugyanennek a sornak a
         * `created_at`-jét viszont az Eloquent írja, PHP-ből: egyetlen beküldésből két,
         * egymáshoz képest elcsúszott időbélyeg lett. Kimérve: 1437 „u" állapotú sorból
         * 1435-nél pontosan 10800 másodperc a különbség.
         *
         * A mező jelentése a kódból: az utolsó `?remark=modify` beküldés ideje (l. lentebb
         * a modify-ágat). Új észrevételnél ilyen még nem volt — a mai viselkedést tartom
         * meg (a keletkezés ideje kerül bele), csak helyes órával. Hogy az „érintetlen"
         * állapotnak inkább üresnek kellene-e látszania, az tartalmi kérdés, és séma-
         * módosítás lenne: a #890-ben rákérdeztem.
         *
         * A típus itt DATETIME, nem TIMESTAMP: a MySQL nem váltja át, tehát a beírt
         * karakterlánc marad. A PHP-ból írt érték emiatt akkor is helyes marad, ha a
         * kapcsolat zónája egyszer UTC-re vált.
         */
        $remark->admindatum = date('Y-m-d H:i:s');
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
