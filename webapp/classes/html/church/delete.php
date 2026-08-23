<?php

namespace Html\Church;

class Delete extends \Html\Html {

    public function __construct($path) {
        global $user;
        if (!$user->checkRole('miserend')) {
            throw new \Exception('Nincs jogosultságod a templomot törölni.');
        }

        $this->input['tid'] = $path[0];

        $this->church2delete = \Eloquent\Church::find($this->input['tid']);
        if ($this->church2delete->id == 0) {
            addMessage("Nincs ilyen templom!", danger);
            return;
        }

        $this->input['confirmation'] = \Request::SimpleText('confirmation');
        if (!$this->input['confirmation']) {
            $this->askConfirmation();
            return;
        } else {
            // #873: a templom törlése (a misékkel, képekkel, kedvencekkel együtt)
            // eddig GET-tel is kiadható volt — a megerősítő űrlap POST-ol ugyan, de a
            // `?confirmation=confirmed` egy sima linkből is elég volt hozzá.
            \Csrf::guard();
            $this->delete();
        }
    }

    function delete() {
        global $user;
        
        $comment = \Request::Text('comment');
        if($this->church2delete->adminmegj != '')
            $this->church2delete->adminmegj .= "\n";
        $this->church2delete->adminmegj .= "Törölte ".$user->login;
        if($comment)
            $this->church2delete->adminmegj .= ": ".$comment;        
        $this->church2delete->ok = 'n';
        $this->church2delete->log .= "\nDel: " . $user->login . " (" . date('Y-m-d H:i:s') . ")";
		
		// Mivel mindenféle egyedi attribútumot adtunk hozzá a $church objecthez az attributes táblából, ezért mentéshez és törléshez el kell távolítani a fura cuccokat.
		foreach ($this->church2delete->getAttributes() as $key => $value) {
        if(!in_array($key, array_keys($this->church2delete->getOriginal())))
            unset($this->church2delete->$key);
        }
        
		
        $this->church2delete->save();
                
        $this->church2delete->delete();
        addMessage('A templomot sikeresen töröltük.','info');
        header("Location: /templom/list");
    }

    function askConfirmation() {
        // church/delete.twig
    }

}
