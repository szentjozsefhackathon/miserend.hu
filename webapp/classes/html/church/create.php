<?php

namespace Html\Church;

class Create extends \Html\Html {

    public function __construct($path) {
        global $user;
        if (!$user->checkRole('miserend')) {
            throw new \Exception('Nincs jogosultságod a templomot létrehozni.');
        }

        $this->title = 'Új misézőhely létrehozása';

        
        $isForm = \Request::Text('submit');
        if ($isForm) {
            $tid = $this->create();            
            if($tid) {
                $this->redirect("/templom/".$tid."/edit");
                return;
            }
            throw new \Exception('Nem sikerült a templomot létrehozni.');
        }


        return;

    }

    function create() {
        
        $lat = \Request::FloatRequired('church[lat]');
        $lon = \Request::FloatRequired('church[lon]');
        $name = \Request::TextRequired('church[nev]');
        $osm_id = \Request::Integer('church[osmid]');
        $osm_type = \Request::InArray('church[osmtype]', ['node', 'way', 'relation']);

        $church = \Eloquent\Church::create([
            'nev' => $name,
            'ok' => 'n',
            'frissites' => date('Y-m-d'),
            'lat' => $lat,
            'lon' => $lon,
            'osmid' => $osm_id,
            'osmtype' => $osm_type,                                    
        ]);
         
        $church->save();

        return $church->id;
        
    }

}
