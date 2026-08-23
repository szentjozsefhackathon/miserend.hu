<?php

namespace Html\Ajax;


class OSMKapcsolat extends Ajax {

    

    public function __construct() {
    
        

        try {
            // #873: az OSM-összeköttetés bontása törli az összes fromOSM attribútumot is
            // — POST + token. (A hibát a lenti catch csomagolja JSON-ba.)
            \Csrf::guard();

            $action = \Request::InArrayRequired('action', ['delete', 'add']);
            $tid = \Request::IntegerRequired('tid');

            header("Content-Type: text/plain");
            switch ($action) {
            case 'delete':
                
                $church = \Eloquent\Church::find($tid);
                if (!$church) {
                    throw new \Exception("Church with ID $tid not found.");
                }

                global $user;
                if(!$user->isadmin) {
                    throw new \Exception("You do not have permission to delete this connection.");
                }

                $church->osmid = null;
                $church->osmtype = null;             
                $church->save();
                \Eloquent\Attribute::where('church_id', $church->id)
                    ->where('fromOSM', 1)
                    ->delete();
                
                $this->content = json_encode(["ok" => "Templom OSM összeköttetése sikeresen törölve."]);
                return;    
                break;

            case 'add':
                break;
            }
            $this->content = json_encode(["ok" => "Action completed successfully."]);

        } catch (\Exception $e) {
            // Handle the error
            //header("Content-Type: text/plain", true, 500);
            $this->content = json_encode(["error" => $e->getMessage()]);
        }


    }

}
