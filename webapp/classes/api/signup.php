<?php

namespace Api;

class Signup extends Api {

    public $title = 'Új felhasználó regisztrálása';
    public $requiredVersion = ['>=',4]; // API v4-től érhető el

    public $fields = [
        'username' => [
            'required' => true,
            'validation' => [
                'string' => [
                    'maxLength' => 20,
                    'pattern' => '^[a-zA-Z0-9._-]+$'
                ]
            ],
            'description' => 'a felhasználó regisztrált neve.',
            'example' => 'ujfelhasznalo'
        ],
        'email' => [
            'required' => true,
            'validation' => 'string',
            'description' => 'a felhasználó email címe. (A regisztrációról értesítést kap.)',
            'example' => 'ujfelhasznalo@no-reply.nomail'
        ],
        'password' => [
            'required' => true,
            'validation' => 'string',
            'description' => 'Ebben az API verzióban ezt a mezőt a rendszer figyelmen kívül hagyja. A regisztráció után a rendszer egy véletlenszerű jelszót generál és az email címre küld.'
        ],
        'nickname' => [             
            'validation' => 'string',
            'description' => 'Becenév vagy megszólítás.',
            'example' => 'Józsi'
        ],
        'name' => [             
            'validation' => 'string',
            'description' => 'Teljes név.',
            'example' => 'Kovács József'
        ]
    ];  



     public function docs() {

        $docs = [];
        
        $docs['description'] = <<<HTML
        <p>Az API-n keresztül lehetséges új miserend felhasználó regisztrálása is. A megfelelő url-re JSON formátumban küldött adatok esetén JSON választ ad a rendszer.</p>
        HTML;

        $docs['response'] = <<<HTML
        <ul>
            <li>„error”: <strong>0</strong>, ha nincs hiba. <strong>1</strong>, ha van valami hiba.</li>
                <li>„text” (opcionális): „error:1” esetén a hiba szöveges leírása. Ez olyan fontos üzeneteket tartalmazhat, mint hogy már foglalt a felhasználónév.</li>            <li>Sikeres regisztráció esetén a rendszer egy véletlenszerű jelszót generál és az email címre küld az új felhasználónak.</li>        </ul>
        HTML;

        return $docs;
    }

    public function run() {
        parent::run();
        $this->getInputJson();
        $newuser = new \User();
        $validFields = array('username', 'email', 'password', 'nickname', 'name');
        $fieldsToSubmit = array();
        foreach ($validFields as $field) {
            if ($this->input[$field] AND $this->input[$field] != '') {
                $fieldsToSubmit[$field] = $this->input[$field];
            }
        }

        $success = $newuser->submit($fieldsToSubmit);

        $messages = \Message::getToShow();
        if (!$success) {
            $exceptionTexts = array();            
            foreach ($messages as $message) {
                $exceptionTexts[] = $message['text'];
            }
            throw new \Exception(implode("\n", $exceptionTexts));
        }
    }

}
