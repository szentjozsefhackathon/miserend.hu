<?php

namespace Html\Church;

class EditPhotos extends \Html\Html {
    public $input;
    public $tid;
    public $church;
    public $title;

    public function __construct($path) {
        global $user;
   
        // #545: kép-szerkesztő nyers inputja. A mezőnkénti \Request:: átírás
        // staging-tesztet igényel (kép-feltöltés/-törlés), ezért marad.
        $this->input = \Request::all();
        $this->tid = $path[0];
        $this->church = \Eloquent\Church::find($this->tid);
        if (!$this->church) {
            throw new \Exception('Nincs ilyen templom.');
        }
        $this->church = $this->church->append(['writeAccess']);

        if (!$this->church->writeAccess) {
            throw new \Exception('Hiányzó jogosultság!');
            return;
        }

	
        $isForm = \Request::Text('submit');
        if ($isForm) {
            $this->modify();
        }
        
		$this->church->photos;
        $this->title = $this->church->fullName;
		
    }

    function modify() {
        // #391: a mezőcsoportok a \Request::Fields()-en át jönnek — ellenőrzött másolat,
        // hiányzó vagy nem-tömb bemenetnél false, tehát nincs „Undefined array key".
        $churchFields = \Request::Fields('church');
        if ($churchFields === false || !isset($churchFields['id']) || $churchFields['id'] != $this->tid) {
            throw new \Exception("Gond van a módosítandó templom azonosítójával.");
        }

        $photos = \Request::Fields('photos');
        if ($photos !== false) {
            foreach ($photos as $modPhoto) {
                /*
                 * #870: a fotót a SZERKESZTETT TEMPLOMHOZ kötjük.
                 *
                 * Itt korábban `Photo::find($modPhoto['id'])` állt, szűkítés nélkül. A
                 * fenti őr (`church[id] == $this->tid`) csak azt nézi, hogy a saját
                 * templomát szerkeszti-e valaki — a FOTÓ azonosítója viszont tetszőleges
                 * lehetett. Egy tetszőleges templom gondnoka tehát a saját templomára
                 * POST-olva idegen fotót nevezhetett át, rejthetett el, sorrendezhetett
                 * át, és a `delete` jelzővel VÉGLEG törölhetett — a `photos` táblán nincs
                 * SoftDeletes, tehát a törlés visszaállíthatatlan.
                 *
                 * A `where()` a `find()` ELŐTT: így egy idegen azonosító nem sort ad
                 * vissza, hanem semmit, és a ciklus csendben továbblép.
                 */
                $origPhoto = \Eloquent\Photo::where('church_id', $this->tid)->find($modPhoto['id']);
                if ($origPhoto) {
                    if ($modPhoto['flag'] == 'i')
                        $origPhoto->flag = 'i';
                    else
                        $origPhoto->flag = "n";
                    if ($modPhoto['weight'] == '' OR is_numeric((int) $modPhoto['weight']))
                        $origPhoto->weight = $modPhoto['weight'];
                    else
                        $origPhoto->order = 0;
                    $origPhoto->title = $modPhoto['title'];
                    $origPhoto->save();
                    if (isset($modPhoto['delete'])) {
                        $origPhoto->delete();
                    }
                }
            }
        }

        global $user;
        $this->church->log .= "\nFotók: " . $user->login . " (" . date('Y-m-d H:i:s') . ")";
               
        switch (\Request::Simpletext('modosit')) {
            case 'n':
                $this->redirect("/church/catalogue");
                break;

            case 't':
                $this->redirect("/church/" . $this->church->id);
                break;

            case 'm':
                $this->redirect("/church/" . $this->church->id . "/editschedule");
                break;

            default:
                break;
        }
    }

  

    
}
