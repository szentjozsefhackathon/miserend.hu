<?php

namespace Html\Email;

class Email extends \Html\Html {

    public function __construct($path) {
        if(!$this->checkPermission()) {
            throw new \Exception("Nincs jogod levélküldéshez.");
        }

        $this->mail = new \Eloquent\Email();        
        if (\Request::text('send') == 'Küldés') {
            // #873: levélküldés a mi nevünkben, tetszőleges címzettnek és szöveggel —
            // GET-tel is kiadható volt. POST + token.
            \Csrf::guard();
            $this->send();
            $this->template = 'layout_simpliest.twig';
            $this->body = "Köszönjük, elküldtük.";
            unset($this->mail);
        } else {            
            $this->preparePage($path);
        }
    }

    public function send() {
        $this->mail->to = \Request::TextRequired('email');
        $this->mail->subject = \Request::TextRequired('subject');
        $this->mail->body = \Request::TextRequired('text');
        $this->mail->type = \Request::Simpletext('type');
        if (!$this->mail->send()) {
            addMessage('Nem sikerült elküldeni az emailt. Bocsánat.', 'danger');
        }
    }

    public function preparePage($path) {
        $id = \Request::Integer('id');
        
        if($id) {
            $this->mail = \Eloquent\Email::find($id);
        }      
    }

    function checkPermission() {
        global $user;
        $this->user = $user;
        if (!$this->user->checkRole('"any"')) {
            return false;   
        }
        return true;
    }
    
}
