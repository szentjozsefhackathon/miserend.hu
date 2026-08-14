<?php

namespace Html\Church;

use Illuminate\Database\Capsule\Manager as DB;

class Catalogue extends \Html\Html {
    public $form;
    public $search;
    public $churches;

    private $filterDiocese;
    private $filterDeanery;
    private $filterKeyword;
    private $filterStatus;
    private $orderBy;

    public function __construct() {
        parent::__construct();

        global $user;
        if (!$user->checkRole('miserend')) {
            throw new \Exception('Nincs jogosultságod megnézni a templomok listáját.');
        }
               
        // #391: az `isset(...) ? ... : false` páros pontosan az, amit a \Request::get()
        // ad — csak épp nyersen olvasta a $this->input-ot.
        //
        // Az azonosítókat számként ellenőrizzük, de NEM \Request::Integer()-rel: az
        // kivételt dob nem-számra, vagyis egy elgépelt `?egyhazmegye=abc` hibaoldalt
        // adna egy listán. Egy rossz szűrő nem hiba — egyszerűen nem szűr.
        $numericFilter = function (string $key) {
            $value = \Request::get($key);
            return is_numeric($value) ? (int) $value : false;
        };

        $this->filterKeyword = \Request::Text('keyword');
        $this->filterDiocese = $numericFilter('egyhazmegye');
        $deanery = $numericFilter('espereskerulet');
        $this->filterDeanery = ($deanery !== false AND $deanery != 0) ? $deanery : false;
        $this->filterStatus = \Request::SimpleText('status');
        $this->orderBy = \Request::TextwDefault('orderBy', 'updated_at DESC');
        
        $params = [
            'keyword' => $this->filterKeyword,
            'egyhazmegye' => $this->filterDiocese,
            'espereskerulet' => $this->filterDeanery,
            'status' => $this->filterStatus,
            'orderBy' => $this->orderBy
        ];
        foreach ($params as $key => &$param) {
            if ($param == '' or $param == 0)
                unset($params[$key]);
        }

        $this->loadForm();
        $this->buildQuery();

        $url = \Pagination::qe($params);
        $this->pagination->set($this->search->count(), $url);

        $this->churches = $this->search->skip($this->pagination->skip)->take($this->pagination->take)->get();
		
		$accessibilityOSMTags = ['wheelchair', 'wheelchair:description','toilets:wheelchair','hearing_loop','disabled:description'];
		
		foreach($this->churches as $church) {
			if($church->osm) {
					foreach($accessibilityOSMTags as $tag) {
						if(array_key_exists($tag,$church->osm->tagList) AND $church->osm->tagList[$tag] != '' ) {
								$church->hasAccessibilityTag = true;
								break;
						}
					}			
			}			
		}
		
        
    }

    function loadForm() {
        // FIXME for Issue #257
        $this->form = \Form::religiousAdministrationSelection(['diocese' => $this->filterDiocese, 'deanery' => $this->filterDeanery]);

        
        $this->form['dioceses']['name'] = 'egyhazmegye';
        foreach ($this->form['deaneries'] as $key => $input) {
            if (isset($input['name']) AND $input['name'] == 'church[espereskerulet]') {
                $this->form['deaneries'][$key]['name'] = 'espereskerulet';               
            }               
        }
                        
        $this->form['keyword'] = $this->filterKeyword;
        $this->form['status'] = [
            'name' => 'status',
            'options' => [
                0 => 'Mind',
                'i' => 'csak engedélyezett templomok',
                'f' => 'áttekintésre várók',
                'n' => 'letiltott templomok',
                'Rnj' => 'templomok nem jóváhagyott észrevétellel',
                'Ru' => 'templomok új észrevétellel',
                'Rf' => 'templomok folyamatban lévő észrevétellel',
                'Sp' => 'javaslatokkal rendelkező templomok',
                'Hn' => 'gondnok nélküli templomok (nincs aktív gondnok)'
            ],
            'selected' => $this->filterStatus
        ];

        $this->form['orderBy'] = [
            'name' => 'orderBy',
            'options' => [
                'updated_at DESC' => 'utolsó módosítás',
                'frissites DESC' => 'utolsó frissítés',
                'varos' => 'település',
                'nev' => 'név',
                'remarks.created_at' => 'utolsó észrevétel',
            ],
            'selected' => $this->orderBy
        ];
    }

    function buildQuery() {
        // FIXME for Issue #257
        $search = \Eloquent\Church::where('templomok.id', '>', 1);

        if ($this->filterKeyword) {
            $filterKeyword = '%' . $this->filterKeyword . '%';
            $search = $search->where(function($query) use ($filterKeyword) {
                $query->where('nev', 'LIKE', $filterKeyword)->
                        orWhere('varos', 'LIKE', $filterKeyword)->
                        orWhere('ismertnev', 'LIKE', $filterKeyword);
            });

        }

        if ($this->filterDiocese) {
            $search = $search->where('egyhazmegye', $this->filterDiocese);
            if ($this->filterDeanery) {
                $search = $search->where('espereskerulet', $this->filterDeanery);
            }
        }

        if ($this->filterStatus) {
            if ($this->filterStatus == 'Ru') {
                $search = $search->whereHas('remarks', function ($query) {
                    $query->where('allapot', 'u');
                });
            } else if ($this->filterStatus == 'Rf') {
                $search = $search->whereHas('remarks', function ($query) {
                    $query->where('allapot', 'f');
                });
            } else if ($this->filterStatus == 'Rnj') {
                $search = $search->whereHas('remarks', function ($query) {
                    $query->where('allapot', '!=', 'j');
                });
            }

            if( $this->filterStatus == 'Sp') {
                $search = $search->whereHas('suggestionPackages', function ($query) {
                    $query->where('state','PENDING');
                });
            }

            if (in_array($this->filterStatus, ['i', 'f', 'n'])) {
                $search = $search->where('ok', $this->filterStatus);
            }

            // #504: gondnok nélküli templomok — nincs 'allowed' (aktív) church_holder soruk.
            // (whereNotExists, mert a Church modellen nincs holders() reláció, csak accessor.)
            if ($this->filterStatus === 'Hn') {
                $search = $search->whereNotExists(function ($q) {
                    $q->from('church_holders')
                      ->whereColumn('church_holders.church_id', 'templomok.id')
                      ->where('church_holders.status', 'allowed')
                      ->whereNull('church_holders.deleted_at');
                });
            }

        }

        if ($this->orderBy) {
            if (in_array($this->orderBy, [
                        'updated_at DESC', 'updated_at', 'frissites DESC', 'frissites',
                        'nev', 'ismertnev', 'varos'])) {
                $search = $search->orderByRaw($this->orderBy);
            } elseif ($this->orderBy == 'remarks.created_at') {
                $search = $search->leftJoin(
                                DB::raw("(" .
                                        DB::table('remarks')
                                        ->select(['created_at as remark_created_at', 'church_id as remark_church_id'])
                                        ->groupBy('remark_church_id')
                                        ->orderBy('remark_created_at', 'DESC')->toSql()
                                        . ") first_remark ")
                                , 'remark_church_id', '=', 'templomok.id')
                        ->orderBy('remark_created_at', 'DESC');
            }
        }
        $this->search = $search;
    }

}
