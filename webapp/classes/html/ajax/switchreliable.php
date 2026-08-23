<?php

namespace Html\Ajax;

class SwitchReliable extends Ajax {

    public function __construct() {
        // #873: az észrevétel megbízhatósági jelzőjének átállítása írás — POST + token.
        \Csrf::guard();

        $rid = \Request::IntegerRequired('rid');
        $reliable = \Request::InArrayRequired('reliable', array('i', 'n', '?', 'e'));
        
        $remark = \Eloquent\Remark::find($rid);
        global $user;
        $holding = $user->getHoldingData($remark->church->id);
        if(!$holding) $holding = 'denied';
        else $holding = $holding->status;
        if (!$user->checkRole('miserend') and $holding != 'allowed' and ! $user->checkRole('ehm:' . $remark->church->egyhazmegye)) {
            throw new \Exception("Hiányzó jogosultság.");            
        }
        $remark->megbizhato = $reliable;
        $remark->save();
        
        header("Content-Type: text/plain"); 
        echo 'ok';
    }

}
