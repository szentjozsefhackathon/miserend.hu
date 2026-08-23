<?php

namespace Html\Church;

class ChangeHolders extends \Html\Html {
    public $holder;
    
    public function __construct($path) {
        $where = [];
        $data = [];
        
        if (isset($path[0])) {
            $where['church_id'] = $path[0];
        } else {
            $where['church_id'] = \Request::IntegerRequired('tid');
        }
        
        $where['user_id'] = \Request::Integer('uid');
        $confirmation = \Request::Simpletext('confirmation');
        
        if(!$where['user_id']) {
            if($confirmation) {
                // Boldogok vagyunk
                return;
            } else {
                throw new \Exception("Required 'uid' is required.");
            }            
        }
        
        $data['status'] = \Request::InArrayRequired('access', ['allowed','denied','revoked','asked','toDelete']);                
        $description = \Request::Text('description');
        if($description != '') {
            $data['description'] = $description;
        }
               
        global $user;   
        if ( $user->uid == $where['user_id'] AND $data['status'] == 'asked' )  {
                        
            if($confirmation == 'needed') {
            
                $churchHolder = \Eloquent\ChurchHolder::where('user_id',$where['user_id'])->where('church_id',$where['church_id'])->first();
                if(!$churchHolder) {
                    $churchHolder = new \Eloquent\ChurchHolder(array_merge($where,$data));
                }
                $this->holder = $churchHolder;
                           
            } else {
                // #873: itt már írunk — POST + token. A fenti `confirmation == 'needed'`
                // ág CSAK megjelenít (a megerősítő lapot), azt szándékosan nem őrizzük.
                \Csrf::guard();

                $churchHolder = \Eloquent\ChurchHolder::updateOrCreate($where,$data);
                $churchHolder->sendEmails();
                addMessage('A kérést köszönettel elmentettük.', 'info');
                return $this->redirect('/templom/'.$where['church_id']);
            }
        
        } else if($user->checkRole('miserend')) {
            /*
             * #873: ez az ág adott ki és vont vissza szerkesztési jogot — GET-en, sima
             * linkkel. Vagyis egy bejelentkezett miserend-adminnak elég volt megnéznie
             * egy idegen oldalt (vagy egy beküldött észrevételben lévő képet), és a
             * nevében kiosztódott a jog egy tetszőleges felhasználónak egy tetszőleges
             * templomra. A jogosultság-ellenőrzés eközben hibátlanul lefutott.
             */
            \Csrf::guard();

			if ( $data['status'] == 'toDelete') {
				$churchHolder = \Eloquent\ChurchHolder::where('user_id',$where['user_id'])->where('church_id',$where['church_id'])->first()->delete();
			} else {
				$churchHolder = \Eloquent\ChurchHolder::updateOrCreate($where,$data);
				$churchHolder->sendEmails();
			}
           addMessage('A változtatást sikeresen elmentettük.', 'info');
           return $this->redirect('/templom/'.$where['church_id'].'/edit');
            
        } else {
            
                throw new \Exception('Hiányzó jogosultság');
        }
  
    }
            
}

