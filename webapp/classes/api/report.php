<?php

namespace Api;

class Report extends Api {
    
    public $title = 'Visszajelzés / jelentés';
    public $fields = [
        'tid' => [
            'required' => true, 
            'validation' => 'integer', 
            'description' => 'a templom azonosítója (mint az url-ben)',
            'example' => 7
        ],
        'text' => [
            'validation' => 'string',
            'description' => 'szöveges üzenet; „pid:2” esetén kötelező',
            'example' => 'A mise vasárnap 11-kor van, de a honlapon 10:30 szerepel.'
        ],
        'timestamp' => [
            'validation' => 'timestamp',
            'description' => 'a beküldés időpontja (hiánya esetén aktuális pillanat)',
            'default' => 'current timestamp',
            'example' => '2024-01-16 12:34:56'
        ],
        'email' => [
            'validation' => 'email',
            'description' => 'a beküldő email címe, hogy tudjunk neki válaszolni',
            'example' => 'somebody@no.mail'
        ],
        'token' => [
            'validation' => 'string',
            'description' => 'bejelentkezett felhasználó esetén hasznos. Felülírja az „email” értéket. Csak v4+ esetén használható'
        ],
        'pid' => [
            'validation' => [
                'enum' => [0,1,2]
            ], 
            'description' => 'visszajelzés típusa: 0 - rossz pozíció, 1 - hibás mise adatok, 2 - egyéb / ill. előbbiek részletezve',
            'example' => 2,
        ],
        'dbdate' => [
            'validation' => [
                'string' => [
                    'pattern' => '^\d{4}-\d{2}(-\d{2}( \d{2}:\d{2}(:\d{2})?)?)?$'
                ] 
            ], 
            'description' =>  'a használt adatbázis letöltöttségének ideje, timestamp vagy ÉÉÉÉ-HH-NN ÓÓ:PP:MM vagy ÉÉÉÉ-HH-NN (≤v3 optional, v4+ kötelező)',
            'example' => '2024-01-16'
        ]
    ];
        
    public function docs() {

        $docs = [];
       
        $docs['description'] = <<<HTML
        <p>Fontos, hogy a felhasználók tudják jelezni, ha valami hibát találnak a miserendben vagy a templom adataiban.<br>
        JSON formátumba kell küldeni az adatokat és JSON formátumban válaszol az API.</p>
        HTML;

        $docs['response'] = <<<HTML
        <ul>
            <li>„error”: <strong>0</strong>, ha nincs hiba. <strong>1</strong>, ha van valami hiba.</li>
            <li>„text” (opcionális): „error:1” esetén a hiba szöveges leírása</li>
        </ul>
        HTML;

        return $docs;
    }



    public function validateInput() {
        
        if ($this->input['pid'] === 2 AND ! isset($this->input['text'])) {
            throw new \Exception("In the case of 'pid=2' the 'text' field required in JSON input.");
        }
        if ($this->version > 3 AND ( !isset($this->input['dbdate']) OR strtotime($this->input['dbdate']) == false )) {
            throw new \Exception("Field 'dbdate' is required after API version 3 in JSON input.");
        }
        if (isset($this->input['timestamp']) AND    strtotime($this->input['timestamp']) == false) {
            throw new \Exception("Wrong format of 'timestamps' in JSON input.");
        }
    
        if (isset($this->input['token'])) {                    
            $this->token = \Eloquent\Token::where('name',$this->input['token'])->first();
            if(!$this->token or !$this->token->isValid) {
                throw new \Exception("Invalid token.");
            }            
        }
    }

    public function run() {
        parent::run();
        $this->getInputJson();
        

        if(!isset($this->input['token'])) {
            // Prepare anonymus user
            $this->user = new \User();
            $this->user->name = "Mobil felhasználó";
            if (isset($this->input['email'])) {
                $this->user->email = sanitize($this->input['email']);
            }
        } else {            
            // Prepare logged in user
            $this->user = new \User($this->token->uid);
        }

        $this->prepareRemark();

        // #755: ugyanaz az észrevétel néha két-háromszor futott be. A mobilkliens
        // ismételt küldése (rossz hálózaton a felhasználó újra nyom, vagy a kérés
        // magától fut le kétszer) ugyanúgy termeli a duplikátumot, mint a webes űrlap,
        // ezért itt is szűrünk. A válasz változatlan: a beküldő ne higgye, hogy nem
        // ment el, mert akkor megint próbálkozna.
        $duplicate = \Eloquent\Remark::findRecentDuplicate(
            $this->remark->church_id,
            $this->remark->email,
            $this->remark->leiras
        );
        if ($duplicate) {
            $this->return['text'] = 'Köszönjük. Elmentettük.';
            return;
        }

        try {
            $this->remark->save();
            $this->remark->emails();
            $this->return['text'] = 'Köszönjük. Elmentettük.';
        } catch (Exception $e) {
            throw new \Exception($e->getMessage());
        }
    }

    public function prepareRemark() {        
        $this->remark = new \Eloquent\Remark;

        $this->remark->church_id = $this->input['tid'];
        $this->remark->nev = $this->user->name;
        if(isset($this->user->email))
            $this->remark->email = $this->user->email;
        if (isset($this->input['timestamp'])) {
            $this->remark->created_at = $this->input['timestamp'];
        }
        
        if ($this->user->uid > 0) {
            $this->user->active();
        }
        $this->prepareRemarkText();
    }

    public function prepareRemarkText() {

        if (!isset($this->input['text'])) {
            $this->input['text'] = "";
        } else {
            $this->input['text'] = sanitize($this->input['text'])."<br/>";
        }

        switch ($this->input['pid']) {
            case 0:
                $this->input['text'] .= " Helytelen pozíció.";
                break;
            case 1:
                $this->input['text'] .= " Helytelen miseidőpont.";
                break;
        }

        $this->remark->leiras = "Mobilalkalmazáson keresztül érkezett információ:<br/>\n" . $this->input['text'] . "<br/>\n <i>verzió:" . $this->version . ", pid:" . $this->input['pid'] . "</i>";
        if (isset($this->input['dbdate'])) {
            if (!is_numeric($this->input['dbdate'])) {
                $this->input['dbdate'] = strtotime($this->input['dbdate']);
            }
            $this->remark->leiras .= "<i>, adatbázis: " . date("Y-m-d H:i", $this->input['dbdate']) . "</i>";

            $church = \Eloquent\Church::find($this->remark->church_id)->toArray();
            $updated = strtotime($church['frissites'] ?? ''); // #174-B: frissites nullable
            if ($this->input['dbdate'] < $updated) {
                $this->remark->leiras .= "<br/>\n<br/>\n<strong>Figyelem! Elavult adatok alapján történt a bejelentés!</strong>";
            }
        }
    }

}
