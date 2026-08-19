<?php
namespace Html\Ajax\Calendar;

use ExternalApi\ElasticsearchApi;
use Eloquent\CalMass;
use RRule\RRule;

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

class Church extends \Html\Ajax\Calendar\CalendarApi {

    protected $elastic;
    public $tid;
    public $church;

    public function __construct($path) {
        // #392: minden váratlan kivétel tiszta JSON hibaválasz legyen (ne az index.php
        // globális HTML-handlere, amin a JSON-t váró naptár-kliens elhasal).
        try {
            $this->handle($path);
        } catch (\Throwable $e) {
            error_log('[calendar] ' . static::class . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            $this->sendJsonError('Váratlan hiba a naptár-műveletben.', 500);
        }
    }

    private function handle($path) {

        if (empty($path[0])) {
            $this->sendJsonError('Hiányzó templom azonosító.', 400);
            exit;
        }

        $this->tid = $path[0];

        $this->church = \Eloquent\Church::find($this->tid);
        if (!$this->church) {
            $this->sendJsonError('Nincs ilyen templom.', 404);
            exit;
        }

        $this->elastic = new ElasticsearchApi();

        switch ($_SERVER['REQUEST_METHOD']) {
            case 'OPTIONS':
                http_response_code(200);
                exit();
            case 'GET':
                // Append external calendar info
                $this->church->append(['hasExternalCalendar']);
                $confessions = $this->church->getConfessions('-40 days', '20 hours');
                $c = 1;
                foreach ($confessions as &$confession) {
                    $confession['startDate'] = date('Y-m-d\TH:i:s', strtotime($confession['start'])); // TODO timezone kérdések
                    unset($confession['start']);
                    unset($confession['end']);
                    $confession['churchId'] = $this->church->id;
                    $confession['periodId'] = null;
                    $confession['title'] = 'Gyóntatás';
                    $confession['types'] = [];
                    $confession['rite'] = null;
                    $confession['id'] = 'confession_' . $c;
                    $c++;   
                }
                
                if( isset($this->church->location->country['name']) and $this->church->location->country['name'] == 'Magyarország')                    
                    $country = 'HU';
                else
                    $country = false;

                $response = [
                    'id' => $this->tid,
                    'name' => $this->church->nev,
                    'rite' => strtoupper($this->church->denomination),
                    'country' => $country,
                    'timeZone' => 'Europe/Budapest',
                    // #816: a térképes helyszínválasztó kiindulópontja. A szabadtéri
                    // alkalom jellemzően a templom közelében van.
                    'lat' => (float) $this->church->lat,
                    'lon' => (float) $this->church->lon,
                    'hasExternalCalendar' => $this->church->hasExternalCalendar,
                    'eventsFromSensor' => $confessions,
                    'sensorEvents' => $confessions,
                    'masses' => $this->getEventsByChurchId($this->tid)
                    
                ];

                /*
                 * #506: a plébánia és fíliái egy naptárban.
                 *
                 * OPCIONÁLIS (`?family=1`), hogy a mai válasz bitre ugyanaz maradjon —
                 * a szerkesztő csak akkor kéri, ha tényleg családban dolgozik.
                 *
                 * Minden családtag bekerül, mert a miserend nyilvános adat, és a
                 * plébánia rendjét együtt látni akkor is hasznos, ha nem mindegyikhez
                 * van jogod. Hogy melyikbe LEHET írni, azt a `writable` mondja meg —
                 * és a mentés úgyis templomonként ellenőrzi.
                 */
                if (\Request::Integer('family')) {
                    $response['family'] = $this->familyOf($this->church);
                }

                $this->content = json_encode($response);
                break;
            default:
                $this->sendJsonError('Method not allowed', 405);
        }
    }

    public function getEventsByChurchId(int $churchId): array {
        return CalMass::where('church_id', $churchId)->get()->toArray();
    }

    /**
     * #506: a templom „családja" — az ősök, a leszármazottak, és maga a templom.
     *
     * A `fullNetwork` a teljes hálózatot adja (plébánia → fíliák), a hierarchia-oldal is
     * ezt használja. A saját templom is benne marad, hogy a szerkesztő egyetlen listából
     * tudja felkínálni, melyikhez tartozzon egy esemény.
     *
     * @return array<int, array{id:int, name:string, writable:bool, isCurrent:bool, masses:array}>
     */
    private function familyOf(\Eloquent\Church $church): array {
        $family = [];

        foreach ($church->fullNetwork as $tag) {
            $tagChurch = $tag['church'] ?? null;
            if (!$tagChurch) {
                continue;
            }
            $tagChurch->append(['writeAccess']);

            $family[] = [
                'id'        => (int) $tagChurch->id,
                'name'      => (string) $tagChurch->nev,
                // #496/#497/#498: a település az OSM-határokból, NEM a `templomok.varos`
                // oszlopból — azt a #805 eldobja, és a névképzés némán kiürülne.
                'city'      => $tagChurch->locationCityName(),
                // A rítus templomonként más lehet (görögkatolikus fília római
                // plébánia alatt), és az új esemény alapértelmezését ez adja.
                'rite'      => strtoupper((string) $tagChurch->denomination),
                'writable'  => (bool) $tagChurch->writeAccess,
                'isCurrent' => (int) $tagChurch->id === (int) $church->id,
                'masses'    => $this->getEventsByChurchId((int) $tagChurch->id),
            ];
        }

        return $family;
    }

    

}
