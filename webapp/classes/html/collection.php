<?php

namespace Html;

use Illuminate\Database\Capsule\Manager as DB;

class Collection extends Html {

    public function __construct() {
        parent::__construct();

        // #391: a `q`-t eddig nyersen olvastuk a $this->input-ból, és a preg_match
        // eredményét ellenőrzés nélkül használtuk. Hibás azonosítónál (pl. /collection)
        // ez „Undefined array key 1/2" figyelmeztetéseket szórt, majd egy null
        // boundary-n próbált tulajdonságot olvasni.
        $q = (string) \Request::Text('q');
        if (!preg_match('/(node|way|relation):([0-9]{1,8})$/i', $q, $match)) {
            throw new \Exception('Hibás gyűjtemény-azonosító.');
        }

        $osm = \Eloquent\Boundary::where('osmtype',$match[1])
                ->where('osmid',$match[2])->first();
        if (!$osm) {
            throw new \Exception('Nincs ilyen gyűjtemény.');
        }
        $this->setTitle($osm->name);
        
        $this->boundary = $match[1].':'.$match[2];
        
        $churchIds = DB::table('boundaries')
                ->join('lookup_boundary_church','boundaries.id','=','lookup_boundary_church.boundary_id')
                ->where('boundaries.osmtype',$match[1])
                ->where('boundaries.osmid',$match[2])
                ->select('church_id')
                ->pluck('church_id');
        
        // FIXME for Issue #257
        $churches = \Eloquent\Church::whereIn('id',$churchIds)
                ->where('ok','i')
                ->orderBy('nev')
                ;

        $this->pagination->set($churches->count());
        $this->churches = $churches->skip($this->pagination->skip)->take($this->pagination->take)->get();
        foreach ($this->churches as &$church) {
            $church->photos;
        }
        
        $data = \Html\Map::getGeoJsonDioceses();                
        $this->dioceseslayer = [];
        $this->dioceseslayer['geoJson'] = json_encode($data);        
    }

}
