<?php

use Illuminate\Database\Capsule\Manager as DB;

class OSM {
   
     /*
     * Az OSM-el rendelkező templomoknál letöltjük, hogy milyen területekhez
     * tartozik.
     */

    function checkBoundaries($limit = 50) {
        $this->deleteOrphanBoundaries();

        // Egyetlen unified query: rendezés boundaries_checked_at szerint (NULL-ok = soha nem ellenőrzöttek - előre),
        // majd legrégebben ellenőrzöttek kerülnek sorra. A kétfázisú doesntHave+RAND() logika hibás volt,
        // mert koordináta nélküli / API-hibás templomok örökös hurokba ragadtak, és a régi adatú templomok soha nem frissültek.
        $churches = \Eloquent\Church::where('ok', 'i')
            ->whereNotNull('lat')
            ->where('lat', '!=', 0)
            ->whereNotNull('lon')
            ->where('lon', '!=', 0)
            ->orderByRaw('ISNULL(boundaries_checked_at) DESC, boundaries_checked_at ASC')
            ->take($limit)
            ->get();

        if ($churches->isEmpty()) return;

        // A referencia táblákat EGYSZER töltjük be az összes templomhoz – nem minden templomnál külön.
        // MmigrateBoundaries() korábban minden templomnál 5x lekérdezte ezeket = 250 query/batch.
        $referenceData = [
            'egyhazmegyek'     => collect(DB::table('egyhazmegye')->get())->keyBy('id'),
            'espereskeruletek' => collect(DB::table('espereskerulet')->get())->keyBy('id'),
            'orszagok'         => collect(DB::table('orszagok')->get())->keyBy('id'),
            'megyek'           => collect(DB::table('megye')->select('*', 'megyenev as nev')->get())->keyBy('id'),
        ];

        foreach ($churches as $church) {
            $this->checkBoundariesForOne($church, $referenceData);
        }
    }

    function checkBoundariesForOne($church, array $referenceData = []) {
        $boundaries = $this->downloadBoundaries($church->lat, $church->lon);

        // Mindig jelöljük, hogy megpróbáltuk – akár sikeres volt, akár nem (pl. Overpass 503).
        // Ez megakadályozza az örökös hurkot: a következő futásban más templomok kerülnek sorra.
        \Eloquent\Church::where('id', $church->id)
            ->update(['boundaries_checked_at' => date('Y-m-d H:i:s')]);

        if ($boundaries === null || count($boundaries) < 1) return;

        $church->boundaries()->sync($boundaries);
        $church->MmigrateBoundaries($referenceData);
    }
    
    function deleteOrphanBoundaries() {
        // Bulk DELETE egyszerre, ahelyett hogy egyenként töröljük Eloquent-tel.
        // Az eredeti kód: doesntHave()->get() majd loop + delete() = N query.
        // Ez egyetlen subquery-s DELETE.
        DB::table('boundaries')
            ->whereNotIn('id', function($query) {
                $query->select('boundary_id')->from('lookup_boundary_church');
            })
            ->delete();
    }

    static function downloadChurchesWithinBoundary($osmtype, $osmid) {
        $overpass = new \ExternalApi\OverpassApi();

        try {
            $overpass->downloadChurchesWithinBoundary($osmtype, $osmid);
        } catch (\Exception $e) {
            // ExternalApi::runQuery() should catch internally, but just in case
            return null;
        }
        
        if ($overpass->hasError()) {
            return null;
        }

        if (!isset($overpass->jsonData->elements) || empty($overpass->jsonData->elements)) {
            return null;
        }

        return $overpass->jsonData->elements;
    }

    // #ci: instance-metódus (nem static), hogy az OsmBoundariesTest partial-mockja
    // (onlyMethods(['downloadBoundaries'])) tudja stubbolni - statikust nem lehet
    // mockolni. A törzse nem használ $this-t, így a váltás biztonságos; a checkBoundaries
    // már $this->downloadBoundaries()-ként hívja.
    function downloadBoundaries($lat, $lon) {
        $return = [];

        $overpass = new \ExternalApi\OverpassApi();

        try {
            $overpass->downloadEnclosingBoundaries($lat, $lon);
        } catch (\Exception $e) {
            // ExternalApi::runQuery() should catch internally, but just in case
            return null;
        }
        
        if ($overpass->hasError()) {
            return null;
        }

        if (!isset($overpass->jsonData->elements) || empty($overpass->jsonData->elements)) {
            return null;
        }
         
        foreach($overpass->jsonData->elements as $element) {            
            $boundary = \Eloquent\Boundary::firstOrNew(['osmtype' => $element->type, 'osmid' => $element->id]);
            
            $changed = false;
            foreach ( array('boundary','admin_level','name','alt_name','denomination') as $key ) {
                if(isset($element->tags->$key) AND $element->tags->$key != $boundary->$key ) {
                    $boundary->$key = $element->tags->$key;
                    $changed = true;
                }                
            }
            if(isset($element->tags->{'name:hu'}) AND $element->tags->{'name:hu'} != $boundary->name) {
                $boundary->name = $element->tags->{'name:hu'};
                $changed = true;
            }

            // Ensure name is set - use boundary type as default if no name is provided
            if(empty($boundary->name)) {
                $boundary->name = $boundary->boundary ?: 'Unnamed Boundary';
                $changed = true;
            }

            if ($changed) {
                $boundary->save();
            } else {
                // Az OSM adat nem változott, de jelezzük, hogy most is ellenőriztük.
                // Ez biztosítja, hogy a boundaries.updated_at tükrözze az utolsó ellenőrzés idejét.
                $boundary->touch();
            }

            $return[] = $boundary->id;
        }
        
        return $return;
    }

    /**
     * Szinkronizálja az OSM adatokat az url:miserend tag alapján.
     *
     * Ez a függvény az OpenStreetMap-ról letölti az összes olyan elemet,
     * amely rendelkezik az "url:miserend" keyel. Az megadott URL-ből kivonja a
     * templom ID-ját és megtalálja a megfelelő templom rekordot az adatbázisban.
     *
     * Minden egyes templom esetén:
     * - Letölti az összes OSM key-value párost azaz tag-et az elemről
     * - Elmenti az összes tag-et az attributes táblába (fromOSM=1 jelöléssel)
     * - Frissíti a templom koordinátáit és OSM azonosítóit (osmtype, osmid)
     *
     * FIGYELMEZTETÉSEK ÉS TELJESÍTMÉNYI KOCKÁZATOK:
     *
     * 1. NAGY ADATMENNYISÉG: Az Overpass API-tól az ÖSSZES url:miserend elemet
     *    letölti, amely nagy adatmennyiség lehet (többezer elem). 
     * 2. TELJES SZINKRONIZÁCIÓ: A függvény az összes OSM tagot (nem csak az
     *    url:miserend-et) elmenti az attributes táblába.
     * 3. TÖRLÉS ÉS ÚJRAÍRÁS: Minden futásnál az összes korábbi OSM attribútum
     *    (fromOSM=1) törlődik és újra létrehozódik. 
     * 4. NINCS HIBAKEZELÉS: Ha az OSM azonosító változik egy templomnál (más
     *    elemre kerül az url:miserend tag), az átkötés automatikusan átkerül az
     *    új elemre, ami nem feltétlenül szándékos.
     *
     * AJÁNLÁSOK:
     * - Futtatás sávon kívüli időben, csúcsidőn kívül
     * - Figyelemmel kísérendő az adatbázis terhelése futás közben
     * - Érdemes lehet az OSM attributumok száma alapján szűrni (nem az összes tagot tárolni)     
     *
     * @throws Exception Ha az Overpass API lekérdezésből hiányoznak az elemek
     */
    function syncUrlMiserendFromOSM() {
        
        $overpass = new \ExternalApi\OverpassApi();
        $overpass->downloadUrlMiserend();
        
         if (!$overpass->jsonData->elements) {
            throw new Exception("Missing Json Elements from OverpassApi Query");
        }
        $c = 0;
        foreach ($overpass->jsonData->elements as $element) {
            $c++;
            if($c > 10000) exit;
            // #410: robusztusabb match. A mintát nem horgonyozzuk, így a
            // http/https/www prefix nem számít; kezeli az opcionális `?`-et és
            // a path-suffixeket (pl. /templom/5/calendar). Mindkét útvonal-
            // formát elfogadja: templom/N és (?)templom=N. A korábbi {1,5}
            // helyett \d+ (nincs 5-jegyű felső korlát az ID-n).
            // #510: az uj.miserend.hu-t szándékosan NEM matcheljük (negatív
            // lookbehind) - hibás adat, így a "van url:miserend, de nem
            // használható" ágba esik, amit borazslo kézzel javít.
            preg_match('#(?<!uj\.)miserend\.hu/?\??templom(?:=|/)(\d+)#i', $element->tags->{'url:miserend'} ?? '', $match);
            if(!isset($match[1])) {
                /*
                 * TODO: Van url:miserend, de az értéke vacak.
                 */
                //printr($element);

            } else {
                $church = \Eloquent\Church::find($match[1]);
                if($church)
                    $this->saveOSM2Church($church,$element);
            }
        }
    }
    
    /**
     * #484: a címkecsere TISZTA része — a meglévő OSM-tagokból és az új értékből
     * előállítja a mentendő taglistát. Szándékosan hálózat- és DB-mentes, hogy
     * unit-tesztelhető legyen, mi számít egyáltalán változásnak.
     *
     * @param  array  $tags   a jelenlegi OSM-tagok (kulcs => érték)
     * @param  string $value  új érték; üres string = a címke törlése
     * @return ?array a mentendő taglista, vagy null, ha nincs változás
     */
    static function applyTagChange(array $tags, string $key, string $value): ?array {
        // Nincs változás: se eddig, se most nincs érték, vagy pont ugyanaz van kint.
        if (($tags[$key] ?? '') === $value) {
            return null;
        }

        if ($value === '') {
            unset($tags[$key]);
        } else {
            $tags[$key] = $value;
        }

        return $tags;
    }

    /**
     * #484: EGYETLEN OSM-címke felküldése a misézőhely OSM-entitására.
     *
     * Az /edit oldalon szerkesztett, származtatott adatokat (ma: diet:gluten_free)
     * mentéskor rögtön kiírjuk az OSM-be is, hogy ne kelljen utána az /editosm-en
     * még egyszer elmenteni. Ehhez végig kell járni a teljes changeset-kört:
     * entitás letöltése -> van-e egyáltalán változás -> changeset nyitás ->
     * entitás PUT -> changeset zárás.
     *
     * A címke hozzáadását, módosítását ÉS törlését is kezeli: üres $value esetén
     * kivesszük a tagot az entitásból.
     *
     * FONTOS: olvasni is a config szerinti (írásra használt) API-ról olvasunk, mert
     * a PUT-nak az ottani entitás-verziót kell látnia — élőről olvasva + devre írva
     * verzióütközés lenne.
     *
     * @param  \Eloquent\Church $church
     * @param  string           $key    OSM tag kulcs (pl. 'diet:gluten_free')
     * @param  ?string          $value  új érték; üres/null = a címke törlése
     * @return bool  true, ha tényleg módosítottuk az OSM-et; false, ha nem volt mit
     * @throws \Exception  ha az OSM nem érhető el vagy a mentés nem sikerült
     */
    static function pushTag($church, string $key, ?string $value): bool {

        if (empty($church->osmtype) OR empty($church->osmid)) {
            return false;
        }

        $value = $value === null ? '' : trim($value);
        $osmtype = $church->osmtype;

        $osmapi = new \ExternalApi\OpenstreetmapApi();
        $osmapi->query = '/api/0.6/'.$osmtype.'/'.$church->osmid;
        $osmapi->run();

        if (!isset($osmapi->xmlData->{$osmtype}[0])) {
            throw new \Exception('Az OSM entitás ('.$osmtype.':'.$church->osmid.') nem érhető el.');
        }
        $entity = $osmapi->xmlData;

        $tags = [];
        foreach ($entity->{$osmtype}[0]->tag as $tag) {
            $tags[(string) $tag['k']] = (string) $tag['v'];
        }

        $tags = self::applyTagChange($tags, $key, $value);
        if ($tags === null) {
            return false; // nincs eltérés, ne nyissunk fölösleges changesetet
        }

        // SimpleXML-ből egyetlen gyereket nem lehet célzottan törölni, ezért az
        // egész taglistát eldobjuk és újraírjuk (ugyanaz, mint az /editosm-en).
        unset($entity->{$osmtype}->tag);
        foreach ($tags as $k => $v) {
            $newTag = $entity->{$osmtype}->addChild('tag');
            $newTag->addAttribute('k', $k);
            $newTag->addAttribute('v', $v);
        }

        global $user;
        $writeApi = new \ExternalApi\OpenstreetmapApi();
        $changesetID = $writeApi->changesetCreate([
            'created_by' => 'miserend.hu',
            'comment' => 'Changes by a user of miserend.hu called '.($user->login ?? 'unknown'),
        ]);
        if ($changesetID <= 0) {
            throw new \Exception('Nem sikerült OSM changesetet nyitni.');
        }

        $versionID = $writeApi->putEntity($changesetID, $osmtype, $entity);
        $writeApi->changesetClose($changesetID);

        if (!$versionID) {
            throw new \Exception('Az OSM entitás mentése nem sikerült ('.$osmtype.':'.$church->osmid.').');
        }

        return true;
    }

    function saveOSM2Church($church, $element) {
			
			// Ha valamiért nincs church.id, akkor inkább elszállunk, minthogy mindent töröljünk és megkavarjunk.
			if(!isset($church->id)) {
				return false;
			}
	
			// Először töröljük az OSM-ből vett adatot, hogy ne maradjon benne olyan ami az újban már nincs
			\Eloquent\Attribute::where('church_id', $church->id)
				->where('fromOSM', 1)
				->delete();
			// Az OSM tags elmentése az Attribute táblába.
			foreach($element->tags as $key => $value) {
				\Eloquent\Attribute::updateOrCreate(
					[
						'church_id' => $church->id,
						'key' => $key
					],			
					[
						'value' => $value,
						'fromOSM' => 1
					]
				);
			}
			
			// Az osm azonosítók és koordináták elmentése 
            if (isset($element->center->lat)) {
            $element->lat = $element->center->lat;
            }
            if (isset($element->center->lon)) {
                $element->lon = $element->center->lon;
            }
            
            $changed = false;
            
			
			// Ha a templomnál még nincs megadva az OSM azonosító, akkor jól megadjuk. És a koordinátákat is jól felülírjuk.
			if( $church->osmid == '' OR $church->osmtype == '' ) {				
                
				$church->osmtype = $element->type;           
                $church->osmid = $element->id;    
				$church->lon = $element->lon;           
                $church->lat = $element->lat;           
				$changed = true;
            }
            /* TODO: biztosan fejetlenül átkütjük? Ha az OSM-ben az url:miserend máshova kerül, máris változik az átkötés. */			
            if( (int) $element->id != (int) $church->osmid OR $element->type != $church->osmtype ) {
				/*
				echo "Változás van az OSM azonosítóban!<br/>\n".
					"'".$church->osmtype.":".$church->osmid."' megváltozik erre: '".$element->type.":".$element->id."'";
                $changed = true;
				$church->osmtype = $element->type;           
                $church->osmid = $element->id;    
				*/
            }
            
            /* Ha biztosan ugyan az az OSM azonosító, de mégis más a koordináta akkor átmentjük aokat az adaokat */
            if(
				( $element->id == $church->osmid AND $element->type == $church->osmtype )
				AND 
				( $element->lat != $church->lat OR $element->lon != $church->lon )
			) {
                $church->lon = $element->lon;           
                $church->lat = $element->lat;           
                $changed = true;
            }            
            if($changed) {
				// Mivel mindenféle egyedi attribútumot adtunk hozzá a $church objecthez az attributes táblából, ezért mentéshez és törléshez el kell távolítani a fura cuccokat.
				foreach ($church->getAttributes() as $key => $value) {
				if(!in_array($key, array_keys($church->getOriginal())))
					unset($church->$key);
				}
				$church->save();
			} else {
				false;                    
			}
    }

}
