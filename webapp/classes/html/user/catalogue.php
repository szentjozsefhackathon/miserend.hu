<?php

namespace Html\User;

use Illuminate\Database\Capsule\Manager as DB;

class Catalogue extends \Html\Html {

    public function __construct() {
		parent::__construct();
        global $user;

        if (!$user->checkRole('user')) {
            throw new \Exception('Nincs jogosultságod megnézni a felhasználók listáját.');
        }

        $this->input['kulcsszo'] = \Request::Text('kulcsszo');
        $this->input['sort'] = \Request::TextwDefault('sort', 'lastactive desc');
        $this->input['adminok'] = \Request::SimpleTextwDefault('adminok', '');
        
		$this->pagination->take = \Request::IntegerwDefault('take', 50);		
		$offset = $this->pagination->take * $this->pagination->active;
        $take = $this->pagination->take;
				
        $this->buildForm();
        $this->buildQuery();
	
		$maxResults = count($this->query->get());

		//Data for pagination
        $params = [
            'q' => 'user/catalogue',
            'kulcsszo' => $this->input['kulcsszo'],
            'sort' => $this->input['sort'],
            'adminok' => $this->input['adminok']
        ];

        $url = \Pagination::qe($params, '/?' );
        $this->pagination->set($maxResults, $url );
		        
        $this->query->orderByRaw($this->input['sort']);        
        $results = $this->query->offset($offset)->limit($take)->get();

        foreach ($results as $result) {
            if (preg_match('/^(lastlogin|lastactive|regdatum)/i', $this->input['sort'], $match))
                $field = preg_replace(array('/ /i', '/-/i'), array('&nbsp;', '&#8209;'), $match[1]);
            else
                $field = 'lastlogin';
            
            $this->users[$result->uid] = new \User($result->uid);
        }
        $this->field = $field;

        /*
         * #315: az önkéntesség szerinti rendezésnél külön oszlop és összesítés jár.
         * A darabszám a lényeg — az egész heti hét templom kampányról addig nem
         * lehetett megmondani, hányan élnek vele.
         */
        $this->showVolunteer = ($this->input['sort'] === 'volunteer desc');
        if ($this->showVolunteer) {
            $this->volunteerCount = DB::table('user')->where('volunteer', 1)->count();
        }
    }

    function buildForm() {

        $sortOptions = [
            'login' => 'felhasználó név',
            'becenev' => 'becenév',
            'nev' => 'név',
            'lastlogin desc' => 'utolsó belépés',
            'lastactive desc' => 'utolsó aktivitás',
            'regdatum desc' => 'regisztracio',
            'templomok desc' => 'ellátott templomok',
			'favorites desc' => 'kedvenc templomok',
			// #315: enélkül sehol nem látszott, hányan vállalják a heti hét templomot.
			'volunteer desc' => 'önkéntesség'
        ];

        $this->form = array(
            'kulcsszo' => array(
                'name' => 'kulcsszo',
                'value' => $this->input['kulcsszo'],
                'size' => 20,
            ),
            'sort' => array(
                'label' => 'Rendezés:',
                'name' => 'sort',
                'options' => $sortOptions,
                'selected' => $this->input['sort'],
            ),
            'adminok' => array(
                'label' => 'Jogkör:',
                'name' => 'adminok',
                'options' => array(
                    '' => 'Mindenki'),
                'selected' => $this->input['adminok']
            )
        );

        $roles = unserialize(ROLES);
        foreach ($roles as $role) {
            $this->form['adminok']['options'][$role] = $role;
        }

        if (!key_exists($this->input['sort'], $sortOptions)) {
            throw new \Exception("Sajnos '" . $this->input['sort'] . "' alapján nem lehet rendezni a felhasználókat.");
        }
    }

    function buildQuery() {

        $query = DB::table('user')
                ->select('user.uid');

        if (!empty($this->input['adminok'])) {
            $query->where('jogok', 'like', "%" . $this->input['adminok'] . "%");
        }

        if($this->input['sort'] == 'templomok desc')
            $query->addSelect(DB::raw('count(church_holders.church_id) as templomok'))->leftJoin('church_holders', 'church_holders.user_id','=','user.uid')->where('church_holders.status','allowed')->groupBy('uid');
        
		if($this->input['sort'] == 'favorites desc')
            $query->addSelect(DB::raw('count(favorites.tid) as favorites'))->leftJoin('favorites', 'favorites.uid','=','user.uid')->groupBy('user.uid');
			
			
        if (!empty($this->input['kulcsszo'])) {
            $input = $this->input;
            $query->where(function ($q) use ($input) {
                $q->where('user.login', 'like', "%" . $input['kulcsszo'] . "%")
                        ->orWhere('user.nev', 'like', "%" . $input['kulcsszo'] . "%")
                        ->orWhere('user.email', 'like', "%" . $input['kulcsszo'] . "%");
            });
        }
        $this->query = $query;
    }

}
