<?php

namespace Html\Ajax;

class ChurchLink extends Ajax {

    public function __construct() {
        // #873: hivatkozás felvétele és törlése is írás — POST + token.
        \Csrf::guard();

        $action = \Request::InArrayRequired('action', ['delete','add']);

        header("Content-Type: text/plain");                         
        switch ($action) {
            case 'delete':
                        
                $id = \Request::IntegerRequired('id');
                $link = \Eloquent\ChurchLink::find($id);        
                if(!$link)
                    throw new \Exception("There is no ChurchLink with id: ".$id);
                if(!$link->church) {
                    $link->delete();
                    throw new \Exception("There is no Church with id: ".$link->church_id);
                }

                if(!$link->church->WriteAccess) 
                    throw new \Exception("Hozzáférés megtagadva.");
                
                if($link->delete()) 
                    echo 'ok';
                break;
            
            case 'add':
                
                $church_id = \Request::IntegerRequired('church_id');
                $church = \Eloquent\Church::find($church_id);
                if(!$church) {
                    throw new \Exception("There is no Church with id: ".$church_id);
                }
                if(!$church->WriteAccess) 
                    throw new \Exception("Hozzáférés megtagadva.");
                
                $link = \Eloquent\ChurchLink::create([
                    'church_id' => $church_id,
                    'href' => \Request::TextRequired('href'),
                    'title' => \Request::Text('title')
                ]);
                $link->save();

                echo '<div class="church-link" data-link-id="'.$link->id.'">';
                echo $link->html;
                echo '</div>';
                
                break;
        }                
    }
}
