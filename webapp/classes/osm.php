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
            if (!$this->checkBoundariesForOne($church, $referenceData)) {
                /*
                 * #570/#700: ha az Overpass elhasalt, a köteg többi templomával
                 * tovább kopogtatni értelmetlen — jellemzően rate-limit (429) az ok,
                 * amit a további kérések csak mélyítenek. Megállunk; a következő
                 * cron-futás ugyanezekkel a templomokkal folytatja, mert bélyeget
                 * nem tettünk rájuk.
                 */
                error_log('[miserend] OSM: a határ-lekérdezés elhasalt (templom #'
                    . $church->id . '), a köteget megszakítom.');
                return;
            }
        }
    }

    /**
     * Egy templom határainak felderítése.
     *
     * #570/#700: eddig MINDEN esetben rákerült a `boundaries_checked_at` bélyeg,
     * akkor is, ha az Overpass elhasalt (429/503/időtúllépés). A batch a legrégebben
     * ellenőrzöttet veszi előre, tehát a sikertelen próbálkozás a templomot a sor
     * VÉGÉRE tette — határok nélkül, örökre. Egyetlen rate-limitelt futás így
     * tömegével tett templomot elérhetetlenné a települési keresés számára, és ez
     * sehol nem látszott hibaként: a felhasználó csak annyit lát, hogy „Pécs" alatt
     * nem jön ki a Szent Ferenc-templom.
     *
     * A sikertelenséget innentől megkülönböztetjük a „lekérdeztük, de nincs határ"
     * esettől: hibánál NEM bélyegzünk, tehát a templom a sor elején marad, és a
     * következő futás újra próbálja.
     *
     * @return bool sikerült-e a lekérdezés (false: a hívó állítsa le a köteget)
     */
    function checkBoundariesForOne($church, array $referenceData = []): bool {
        $boundaries = $this->downloadBoundaries($church->lat, $church->lon);

        // A `null` a régi jelzés volt mindenféle kudarcra; kezeljük ugyanúgy, hogy
        // egy régebbi hívó vagy teszt-dublőr se okozzon hamis „ellenőrizve" bélyeget.
        if ($boundaries === false || $boundaries === null) {
            // Nem tudjuk, van-e határa — ne állítsuk azt, hogy ellenőriztük.
            return false;
        }

        // Idáig eljutva a lekérdezés SIKERES volt. A bélyeg ilyenkor kell: enélkül a
        // valóban határ nélküli templomok (pl. tengerpart, hiányos OSM-adat) minden
        // futásban újra sorra kerülnének, és kiszorítanák a többit.
        \Eloquent\Church::where('id', $church->id)
            ->update(['boundaries_checked_at' => date('Y-m-d H:i:s')]);

        if (count($boundaries) < 1) return true;

        $church->boundaries()->sync($boundaries);
        $church->MmigrateBoundaries($referenceData);

        return true;
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

        // A sikertelenség itt VÁRT kimenet: lentebb null-lal térünk vissza, és a hívó
        // ezt kezeli. Hibakereső üzemmódban se öntsük a verem-kiírást a lapra — a
        // stagingen pontosan ez csúfította el egy templom oldalát.
        $overpass->quiet = true;

        /*
         * #570/#700: a visszatérés HÁROM különböző dolgot jelenthetett, mind `null`-t
         * adott, és a hívó nem tudta megkülönböztetni őket:
         *   - a lekérdezés elhasalt (429/503/időtúllépés)  -> most: false
         *   - lefutott, de nincs itt határ                 -> most: []
         *   - lefutott, van határ                          -> tömb
         * A különbség azért számít, mert csak a második kettőnél szabad
         * „ellenőrizve" bélyeget tenni a templomra.
         */
        try {
            $overpass->downloadEnclosingBoundaries($lat, $lon);
        } catch (\Exception $e) {
            // ExternalApi::runQuery() should catch internally, but just in case
            return false;
        }

        if ($overpass->hasError()) {
            return false;
        }

        if (!isset($overpass->jsonData->elements)) {
            return false;
        }

        if (empty($overpass->jsonData->elements)) {
            return [];
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

            /*
             * #498: az országkód. A fenti hurok nem tudja átvenni, mert az OSM-tag
             * neve kötőjeles (`ISO3166-1`), az oszlopnév viszont nem lehet az.
             * Csak a level-2 határokon van értelme; ott viszont mindig ott van.
             */
            $isoTag = $element->tags->{'ISO3166-1'} ?? $element->tags->{'ISO3166-1:alpha2'} ?? null;
            if($isoTag !== null) {
                $iso = strtoupper(substr(trim((string) $isoTag), 0, 2));
                if($iso !== '' AND $iso != $boundary->iso3166_1) {
                    $boundary->iso3166_1 = $iso;
                    $changed = true;
                }
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
    /** Egy futásban ennyi elemnél többet nem dolgozunk fel. */
    const URL_MISEREND_LIMIT = 10000;

    /**
     * #658: az `url:miserend` értékéből a templom azonosítója.
     *
     * #410: a mintát nem horgonyozzuk, így a http/https/www prefix nem számít; kezeli
     * az opcionális `?`-et és a path-suffixeket (pl. /templom/5/calendar). Mindkét
     * útvonal-formát elfogadja: `templom/N` és `(?)templom=N`.
     *
     * #510: az `uj.miserend.hu`-t szándékosan NEM fogadjuk el (negatív lookbehind):
     * hibás adat, essen a „van url:miserend, de nem használható" ágba.
     *
     * Külön metódus, hogy tesztelhető legyen, MELYIK alakot fogadjuk el — a jegy épp
     * arról szól, hogy az OSM-ben sokféle alak van forgalomban.
     */
    public static function churchIdFromMiserendUrl(?string $url): ?int {
        if ($url === null || trim($url) === '') {
            return null;
        }
        if (preg_match('#(?<!uj\.)miserend\.hu/?\??templom(?:=|/)(\d+)#i', $url, $match) !== 1) {
            return null;
        }
        return (int) $match[1];
    }

    function syncUrlMiserendFromOSM() {
        
        $overpass = new \ExternalApi\OverpassApi();
        $overpass->downloadUrlMiserend();
        
         if (!$overpass->jsonData->elements) {
            throw new Exception("Missing Json Elements from OverpassApi Query");
        }

        /*
         * #658: a hibás értékeket JELENTJÜK, nem dobjuk el némán.
         *
         * Az OSM-ben sokféle alak van forgalomban (http nélkül, `?templom=345`,
         * `uj.miserend.hu`, elgépelt hoszt), és borazslo egyetlen nagy changesetben
         * akarja rendbe tenni. Ahhoz viszont tudnia kell, MELYIK elemről van szó —
         * eddig ezek nyom nélkül elvesztek (a kódban ottfelejtett TODO helyén).
         */
        $hibasErtek = [];
        $ismeretlenTemplom = [];
        $sikeres = 0;

        $c = 0;
        foreach ($overpass->jsonData->elements as $element) {
            $c++;
            if ($c > self::URL_MISEREND_LIMIT) {
                // Itt korábban `exit` állt: az a cron-futtatót is megölte, tehát a munka
                // SOHA nem került sikeres állapotba, és a többi cron sem futott le utána.
                echo "OSM url:miserend: elértem a(z) " . self::URL_MISEREND_LIMIT
                    . " elemes korlátot, a többit ebben a körben kihagyom.\n";
                break;
            }

            $ertek = $element->tags->{'url:miserend'} ?? '';
            $tid = self::churchIdFromMiserendUrl($ertek);

            if ($tid === null) {
                $hibasErtek[] = $this->osmElementLabel($element) . ' → ' . $ertek;
                continue;
            }

            $church = \Eloquent\Church::find($tid);
            if (!$church) {
                $ismeretlenTemplom[] = $this->osmElementLabel($element) . ' → ' . $ertek;
                continue;
            }

            $this->saveOSM2Church($church, $element);
            $sikeres++;
        }

        $this->reportUrlMiserendIssues($sikeres, $hibasErtek, $ismeretlenTemplom);
    }

    /** Az OSM-elem emberi azonosítója, hogy a jelentésből meg lehessen találni. */
    private function osmElementLabel($element): string {
        return ($element->type ?? '?') . '/' . ($element->id ?? '?');
    }

    /**
     * #658: a futás összegzése. A cron-oldalon és a `docker logs`-ban is látszik,
     * tehát egyetlen futásból megvan a javítandó elemek listája.
     */
    private function reportUrlMiserendIssues(int $sikeres, array $hibasErtek, array $ismeretlenTemplom): void {
        printf("OSM url:miserend: %d elem átkötve, %d hibás értékű, %d ismeretlen templomra mutat.\n",
            $sikeres, count($hibasErtek), count($ismeretlenTemplom));

        foreach ([
            'Nem értelmezhető url:miserend érték' => $hibasErtek,
            'Nem létező templomra mutat'          => $ismeretlenTemplom,
        ] as $cim => $lista) {
            if (!$lista) {
                continue;
            }
            echo '  ' . $cim . ":\n";
            foreach ($lista as $sor) {
                echo '    ' . $sor . "\n";
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
