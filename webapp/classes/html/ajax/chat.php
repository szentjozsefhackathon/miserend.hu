<?php

namespace Html\Ajax;

use Illuminate\Database\Capsule\Manager as DB;

class Chat extends Ajax {
    public $content;

    public function __construct() {
        global $user;
        if (!$user->checkRole("'any'")) {
            $this->content = json_encode(array('result' => 'error', 'text' => 'Hiányzó jogosultság'));
            return;
        }
        
        switch (\Request::InArrayRequired('action', ['load','save','getusers'])) {
            
            case 'load':
                    $date = date('Y-m-d H:i:s', strtotime(\Request::Text('date')));
                    $chat = new \Chat;
                    if (!\Request::Text('rev'))
                        $comments = $chat->loadComments(array('last' => $date));
                    else
                        $comments = $chat->loadComments(array('first' => $date));

                    $this->content = json_encode(array('result' => 'loaded', 'comments' => $chat->comments, 'new' => count($chat->comments), 'alert' => $chat->alert, 'hasMore' => $chat->hasMoreComments));

                break;

            case 'save':
                // #873: a chat-üzenet a mi nevünkben kerül be — POST + token. A `load` és
                // a `getusers` marad őrizetlen: azok csak olvasnak.
                \Csrf::guard();

                $text = \Request::TextRequired('text');
                if (preg_match('/^\$(\w+)/si', $text, $match)) {
                    $kinek = $match[1];
                    $text = preg_replace('/^(\$\w+(:*))/si', "", $text);
                } else
                    $kinek = "";
                if (trim(preg_replace('/^((\$|@)\w+(:*))/si', "", $text)) == '') {
                    $this->content = json_encode(array('result' => 'error', 'text' => 'Nem volt igazán üzenet, amit elküldhettünk volna.'));
                    return;
                }

                if( !DB::table('chat')->insert([
                        'datum' => date('Y-m-d H:i:s'),
                        'user' => $user->login,
                        'kinek' => $kinek,
                        'szoveg' => trim($text)
                    ])
                  ) {
                    $this->content = json_encode(array('result' => 'error', 'text' => 'Hiba a mysql küldésben!'));
                } else {
                    // Delete chat messages older than 30 days
                    $chat = new \Chat;
                    $chat->deleteOldMessages();
                    
                    $this->content = json_encode(array('result' => 'saved', 'text' => $text));
                }

                break;

            case 'getusers':

                $chat = new \Chat;        
                $this->content = json_encode(array('result' => 'loaded', 'text' => $chat->getUsers('html')));
                
                break;

        }
        
        
        
    }
}
