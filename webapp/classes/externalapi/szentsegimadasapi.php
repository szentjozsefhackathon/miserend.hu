<?php

namespace ExternalApi;

use Illuminate\Database\Capsule\Manager as DB;


class szentsegimadasApi extends \ExternalApi\ExternalApi {

    public $name = 'szentsegimadas';
    public $apiUrl = "https://szentsegimadas.hu" ;    
	public $format = 'html';
	public $cache = "1 day"; //false or any time in strtotime() format
	public $testQuery = '';
    public $solrError = [ 'null' => [], 'multiple' => []];
    public $postfields = [
        'telepules' => '',
        'datum' => '',
        'tipus' => '0',
        'gomb' => 'Keresés'
    ];
    private $foundChurches = [];
    
    function buildQuery() {
        global $config;
        $this->rawQuery = $this->query;        
    }
	
    function run() {
        $this->runQuery();

        $this->error = [];
        $this->error['null'] = [];
        $this->error['multiple'] = [];  

        if (preg_match('/<ul class=\"talalatok\">(.*)<\/ul>/s', $this->rawData, $match)) {

            $c=0;
            $html = $match[1];
            unset($this->rawData);
            set_time_limit(300); // 300 seconds
            // Előbb végigmegyünk mindenen, és CSAK a végén cseréljük le a táblát. Korábban
            // a truncate itt, a feldolgozás ELŐTT futott: ha közben bármi elszállt (pl. az
            // Elasticsearch nem válaszolt), a szentségimádások eltűntek az oldalról, és a
            // következő sikeres futásig ott is maradtak.
            $rows = [];
            while (preg_match('/<li>(.*?)<\/li>/s', $html, $match)) {


                if(preg_match('/<b>(.*?)<\/b> \((.*)\)<br>(<a.*<\/a>|) ((\d{4}\.\d{2}\.\d{2}\.) (\d{2}:\d{2})) - (| )((\d{4}\.\d{2}\.\d{2}\. |)(\d{2}:\d{2}))( |)(<img.*?title="(.*?)".*? \/>| )((<div id="info.*?<b>Információk:<\/b><br \/>(.*?)<div.*becsuk<\/a><\/div>)| )/s',$match[1],$matchLi)) {

                    $eventDate = strtotime(rtrim(str_replace('.', '-', $matchLi[5]), '-'));
                    if ($eventDate >= strtotime('today')) {
                        $data = [
                            'varos' => $matchLi[1],
                            'templom' => $matchLi[2],
                            'nap' => $matchLi[5],
                            'kezdes' => $matchLi[6],
                            'veg' => $matchLi[10],
                            'allapot' => $matchLi[13],
                            'info' => isset($matchLi[16]) ? $matchLi[16] : ''
                        ];
                        $data['church_id'] = $this->findChurch($data);

                        if ( $data['church_id'] > 0 ) {
                            $rows[] = $this->toDatabaseRow($data);
                        }
                        $c++;


                    }
                } else {
                    echo "jaj\n";
                    echo $match[1]."\n";
                }

                if($c > 20000) break;
                $html = preg_replace('/<li>(.*?)<\/li>/s','', $html, 1);
            }

            $this->replaceAll($rows);
        }

        if(count($this->error['null']) > 0) {
            echo "Vigyázat! Nincs találat: \n";
            sort($this->error['null']);
            printr($this->error['null']);
        }
        if(count($this->error['multiple']) > 0) {
            echo "Vigyázat! Több találat van: \n";
            sort($this->error['multiple']);
            printr($this->error['multiple']);
        }
        

    }

    /**
     * A központi tesztelőt nem tudjuk használni, mert ez fura html scraper. Ezért van egyedi teszelőnk.
     */
    function test() {
        try {
            $this->runQuery();
        } catch (\Throwable $th) {
            throw new \Exception("Could not run query!\n".$th->getMessage());
        }
        
        if (!preg_match('/<ul class=\"talalatok\">(.*)<\/ul>/s', $this->rawData, $match)) {
            throw new \Exception("A szentsegmiadasok.hu html forrásával gond van. Nincs <ul class=\"talalatok\"> elem a válaszban!");
        }
        // Felszabadítunk némi memóriát
        unset($this->rawData);

        // Van-e legalább egy találat.
        if(!preg_match('/<li>(.*?)<\/li>/s', $match[1], $match)) {
            throw new \Exception("A szentsegmiadasok.hu html forrásával gond van. Nincs <li> elem a válaszban!");
        }

        if(!preg_match('/<b>(.*?)<\/b> \((.*)\)<br>(<a.*<\/a>|) ((\d{4}\.\d{2}\.\d{2}\.) (\d{2}:\d{2})) - (| )((\d{4}\.\d{2}\.\d{2}\. |)(\d{2}:\d{2}))( |)(<img.*?title="(.*?)".*? \/>| )((<div id="info.*?<b>Információk:<\/b><br \/>(.*?)<div.*becsuk<\/a><\/div>)| )/s',$match[1],$matchLi)) {
            throw new \Exception("A szentsegmiadasok.hu html forrásával gond van. Van szentségimádás, de nem a megszokott formátumban.");
        }

        return true;
    }

    function findChurch($data) {
        $keyword = $data['templom'] . ", " . $data['varos'];

        // keyword tisztítása
        $keyword = str_ireplace(
            [
                'téli', 'nyári', 'nyár',
                '3. hétfő', '4. hétfő', 
                'virrasztás első fele', 'virrasztás második fele', 'p.'
            ],
            '',
            $keyword
        );
        $keyword = trim(preg_replace('/\s+/', ' ', $keyword));
        $keyword = preg_replace('/\s+,/', ',', $keyword); // szünetek eltávolítása a vesszők előtt
        $keyword = preg_replace('/,+/', ',', $keyword);   // dupla vesszők eltávolítása
        
        if(isset($this->foundChurches[$keyword])) {
            return $this->foundChurches[$keyword];
        }
        
        // 
        // Van, hogy elsőre mégsem a jó templomot dombja ki a kereső, akkor itt kap fix értéket
        // Van hogy nem találjuk meg sehogy sem, akkor vagy kap itt egy értéket vagy false-al kimerülünk
        $array = [
            'Ferences Mária Misszionárius nővérek temploma, Budapest XIV. kerület' => false,
            'Isteni Szeretet Közösség, Törökbálint' => false,
            'Kápolna, Bernecebaráti' => false,
            'Shalom Közösség Kápolnájában, Bakáts tér 13., Budapest VIII. kerület' => false,            
            'Kisboldogasszony templom szo, Szabadka' => false,
            'Kisboldogasszony templom, Szabadka' => false,                        
            'Szent István Király, Makranc (Szeps)' => false,            
            'Szent István-bazilika, Budapest V. kerület' => 37,
            'Szent István-bazilika, Szent Jobb kápolna, Budapest V. kerület' => 37,
            'Szent László templom (Pestszentlőrinc-Havannatelep plébániatemplom), Budapest XVIII. kerület' => 2499,
            'Szent Rókus templom, Novi Sad - Újvidék, Szerbia' => false,
            'Szentháromság templom, Jászárokszállás' => 1682,
            'Szentháromság templom, Patak' => 922,
            'Szentháromság templom, Szigetmonostor' => 2136,
            'Szeplőtelen Fogantatás és Szent István király-templom (Karmelitatemplom), Győr' => false,
            'Szeretetláng kápolna, Törökbálint' => false            
        ];
        if(isset($array[$keyword])) {
            if($array[$keyword] === false) {
                $this->error['null'][] = $keyword;
            } 
            $this->foundChurches[$keyword] = $array[$keyword];
            return $array[$keyword];
        }

        try {
            // A #309 óta a templomkeresés a \Search osztályon át megy; az
            // ElasticsearchApi::search() akkor tűnt el, ez a hívás viszont itt maradt,
            // és azóta minden futás az első tételnél elhasalt
            // ("Call to undefined method ExternalApi\ElasticsearchApi::search()").
            $hits = $this->searchChurches($keyword);

            // Ha semmilyen találatunk nincs, az nem jó.
            if(count($hits) == 0) {
                $text = $data['templom']." ".$data['varos'];
                $this->error['null'][] = $text;
                $this->foundChurches[$keyword] = false;
                return false;
            }

            // Ha pontosan egy találatunk van, akkor boldogok vagyunk.
            // Bár, vigyázat, lehet hogy csak nagyon gyenge találatunk van és azt jól elfogadtunk.
            if(count($hits) == 1) {
                $this->foundChurches[$keyword] = $hits[0]->id;
                return $hits[0]->id;
            }

            // Ha több találatunk van, akkor tovább gondolkodunk.
            // Új logika: ha az első találat legalább 20%-kal jobb, mint a második, visszaadjuk az első ID-t
            $score0 = $hits[0]->score ?? 0;
            $score1 = $hits[1]->score ?? 0;
            // Itt lehet 10%-ot beállítani, és akkor a kiírt maradékot megnézzük egyesével
            if ($score1 > 0 && $score0 >= 1.001 * $score1) {
                $this->foundChurches[$keyword] = $hits[0]->id;
                return $hits[0]->id;
            }

            // Holtverseny. Ilyenkor szűkítsük a mezőnyt azokra a
            // templomokra, amikhez ebben a futásban még nem osztottunk szentségimádást.
            // Csak MÁSODIK körben tesszük, mert hard filterként rontana: ha egy templom
            // két, eltérően írt néven szerepel a forrásban, az elsőnél kiesne a mezőnyből,
            // és a másodikat egy hasonló nevű, rossz templomra húznánk rá.
            $narrowed = $this->searchChurches($keyword, array_values(array_filter($this->foundChurches)));
            if (count($narrowed) === 1
                || (count($narrowed) > 1 && ($narrowed[1]->score ?? 0) > 0
                    && ($narrowed[0]->score ?? 0) >= 1.001 * ($narrowed[1]->score ?? 0))) {
                $this->foundChurches[$keyword] = $narrowed[0]->id;
                return $narrowed[0]->id;
            }

            $this->error['multiple'][] = $keyword;
            $this->foundChurches[$keyword] = false;
            return false;

        } catch (\Throwable $th) {
            throw new \Exception("Could not search churches!\n".$th->getMessage());
        }

    }

    /**
     * @param int[] $excludeChurchIds Ezeket a templomokat hagyja ki a mezőnyből.
     * @return object[] Legfeljebb 2 találat, `id` és `score` mezővel.
     */
    private function searchChurches(string $keyword, array $excludeChurchIds = []): array {

        $search = new \Search('church');
        $search->keyword($keyword);
        if ($excludeChurchIds !== []) {
            $search->addMustNot(['terms' => ['id' => array_map('intval', $excludeChurchIds)]]);
        }
        $hits = $search->getResults(0, 2);

        // #575: az elérhetetlen kereső NEM "0 találat". Ha ezt elnyelnénk, minden
        // templom "nincs találat"-ot kapna, és a nap szentségimádásai elvesznének.
        if ($search->searchFailed) {
            throw new \Exception('Az Elasticsearch nem adott érvényes találati listát.');
        }

        return $hits;

    }

    function toDatabaseRow($data) {

        return [
            'church_id' => $data['church_id'],
            'date' => $data['nap'],
            'starttime' => $data['kezdes'],
            'endtime' => $data['veg'],
            'type' => $data['allapot'],
            'info' => $data['info']
        ];

    }

    /**
     * A teljes napi állományt egyetlen tranzakcióban cseréli le. Amíg ez le nem fut,
     * a régi adat marad az oldalon — így egy félbeszakadt scrape nem üríti ki a táblát.
     */
    function replaceAll(array $rows) {

        DB::connection()->transaction(function () use ($rows) {
            DB::table('szentsegimadasok')->delete();
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('szentsegimadasok')->insert($chunk);
            }
        });

        return count($rows);

    }

    static function cron() {
        $api = new \ExternalApi\szentsegimadasApi();
        //$api->query = "/kereses";
        $api->run();
    }
    
}

