<?php
namespace Html\Ajax\Calendar;

use ExternalApi\ElasticsearchApi;
use Eloquent\CalMass;
use Eloquent\CalModel;
use Html\Ajax\Calendar\Http\ChangeRequest;
use RRule\RRule;

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

class Masses extends \Html\Ajax\Calendar\CalendarApi {

    protected $elastic;

    public function __construct($path) {
        // #392: váratlan kivétel -> tiszta JSON hiba (nem HTML).
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
        }

        $this->tid = $path[0];

        $this->church = \Eloquent\Church::find($this->tid);
        if (!$this->church) {
            $this->sendJsonError('Nincs ilyen templom.', 404);
        }

        $this->elastic = new ElasticsearchApi();


        switch ($_SERVER['REQUEST_METHOD']) {
            case 'OPTIONS':
                http_response_code(200);
                exit();
            case 'POST':
                $this->church->append(['writeAccess']);

                if (!$this->church->writeAccess) {
                    $this->sendJsonError('Hiányzó jogosultság!', 403);
                }

                $input = json_decode(file_get_contents('php://input'), true);
                $changeRequest = new ChangeRequest($input['masses'], $input['deletedMasses']);

                // #592: eddig a külső naptár LÉTE tiltotta le az egész szerkesztőt. Ezért
                // egy templom nem tudta a saját, kézzel felvitt miséit szerkeszteni, ha
                // egyszer beállított egy importot. Mostantól csak maguk az importált misék
                // védettek — azokat úgyis felülírná a következő szinkron.
                $blocked = self::importedIdsIn($changeRequest);
                if ($blocked !== []) {
                    $this->sendJsonError(
                        'A külső naptárból importált liturgiák itt nem szerkeszthetők, azokat a napi szinkron kezeli. '
                        . 'A többi liturgia szerkesztése változatlanul működik.',
                        403
                    );
                }

                $this->save($changeRequest);
                $this->optimizeExperiods();
                // Ha frissítettünk egy miserendet, akkor mindig és automatikusan a dátuma is legyen friss!                
                $this->church->frissites = date('Y-m-d');
                $this->church->save();
                $this->content = json_encode($this->getByChurchId($this->tid));
                break;

            default:
                $this->sendJsonError('Method not allowed', 405);
        }
    }

    public function getByChurchId(int $churchId): array {
        return CalMass::where('church_id', $churchId)->get()->toArray();
    }

    /**
     * #592: a beküldött változtatás mely miséi importáltak? A törlésre jelölteket és a
     * frissítendőket egyaránt nézzük; az új (negatív/hiányzó ID-jű) misék nem lehetnek azok.
     *
     * @return int[]
     */
    public static function importedIdsIn(ChangeRequest $changeRequest): array
    {
        $ids = array_map('intval', $changeRequest->deletedMasses ?? []);

        foreach ($changeRequest->masses as $mass) {
            $massData = is_array($mass) ? $mass : (array) $mass;
            if (isset($massData['id']) && (int) $massData['id'] > 0) {
                $ids[] = (int) $massData['id'];
            }
        }

        return CalMass::importedIdsAmong($ids);
    }

    public function save(ChangeRequest $changeRequest): void
    {
        // Törlendő misék
        if (!empty($changeRequest->deletedMasses)) {
            CalMass::whereIn('id', $changeRequest->deletedMasses)->delete();
        }

        // Új vagy frissítendő misék
        foreach ($changeRequest->masses as $mass) {
            $massData = is_array($mass) ? $mass : (array) $mass;
            $massData = CalModel::arrayKeysToSnakeCase($massData);

            // #592: az `imported` származtatott mező, nincs mögötte oszlop — a frontend
            // visszaküldi a modellel együtt, itt viszont nyers UPDATE-be kerülne.
            unset($massData['imported']);

            // Ha negatív az ID, töröljük
            if (isset($massData['id']) && $massData['id'] < 0) {
                unset($massData['id']);
            }

            if (isset($massData['id']) && CalMass::find($massData['id'])) {
                // Frissítés
                CalMass::where('id', $massData['id'])->update($massData);
            } else {
                // Új rekord – automatikus ID generálás
                CalMass::create($massData);
            }
        }
    }

    // Az experiod azaz kizárt időszak azonosítók között 
    // időnként maradhat olyan, amilyen időszak már nincs is, ezért nem kéne kizárni
    // Ezeket lapátoljuk el az útból
    private function optimizeExperiods() {

        // Build list of currently used period_ids for this church
        $periodIds = CalMass::where('church_id', $this->tid)
            ->get()
            ->pluck('period_id')
            ->filter()    // remove null/empty
            ->unique()    // keep only unique ids
            ->values()    // reindex the collection
            ->toArray();

        // Find masses that have experiod set
        $experiods = CalMass::where('church_id', $this->tid)
            ->whereNotNull('experiod')
            ->groupBy('experiod')
            ->get();
                            
        foreach($experiods as $current) {            
            $cleanedExperiods = [];
            $toChange = CalMass::where('church_id', $this->tid);
            foreach($current->experiod as $k => $experiodId) {
                $toChange = $toChange->whereJsonContains('experiod',$experiodId);                
                if(in_array($experiodId, $periodIds)) {
                    $cleanedExperiods[] = $experiodId;
                }
            }
            $cleanedExperiodString = !empty($cleanedExperiods) ? json_encode($cleanedExperiods) : null;
        
            $toChange = $toChange->whereRaw('JSON_LENGTH(experiod) = ?', [count($current->experiod)]);
                
            //printr($toChange->get()->toArray());
            if($cleanedExperiodString === $current->experiod) {
                // no change
                continue;
            }
            $toChange->update(['experiod' => $cleanedExperiodString]);
        }

        return true;
    }

}
