<?php

namespace Html;

use Illuminate\Database\Capsule\Manager as DB;

class DioceseCatalogue extends Html {
    public $ehms;
    public $form;
    public $espkers;
    public $churchesGroupByEspker;

    public function __construct($path) {
        global $user;

        if (!$user->checkRole('miserend')) {
            throw new \Exception('Nincs jogosultságod megnézni a templomok listáját.');
        }

        $this->title = "Templomok listája egyházmegyénként";
        $ehm = \Request::Integer('ehm');
        
        $ehmsDB = DB::table('egyhazmegye')->where('ok','i')->orderBy('sorrend')->get();
        $this->ehms = $options = array();        
        foreach($ehmsDB as $tmp) {
            $this->ehms[$tmp->id] = $tmp;
			$options[$tmp->id] = $tmp->nev;
        }
               
		$this->form['diocese'] = array(
            'type' => 'select',
            'name' => 'ehm',
            'options' => $options,
        );

        if (is_numeric($ehm) AND $ehm > 0) {
            $this->ehms[$ehm]->selected = "selected";
			$this->form['diocese']['selected'] = $ehm;
                                                          
            $this->title = "Templomok listája: ".$this->ehms[$ehm]->nev. " egyházmegye";
            
            $espkersDB = DB::table('espereskerulet')->where('ehm',$ehm)->orderBy('nev')->get();
            
            $this->espkers = array();
            foreach($espkersDB as $espker) {
                $this->espkers[$espker->id] = $espker->nev;
            }            
          
            $this->churchesGroupByEspker = \Eloquent\Church::where('ok','i')
                    ->where('egyhazmegye',$ehm)
                    ->orderByCity()->orderBy('nev')
                    ->get()->groupBy('espereskerulet');
            }
            
            return;
        }


}
