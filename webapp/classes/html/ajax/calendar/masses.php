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

                $erintettTemplomok = self::affectedChurchIds(
                    $changeRequest->masses, $changeRequest->deletedMasses, (int) $this->tid
                );

                $this->save($changeRequest);
                $this->optimizeExperiods();

                /*
                 * Ha frissítettünk egy miserendet, akkor a dátuma is legyen friss — de
                 * MINDEN érintett templomé, ne csak az útvonalé. #506 óta egy mentés több
                 * templomot is érinthet (plébánia + fíliák), és a régi kód ilyenkor a
                 * fília adatlapján hagyta a régi dátumot, pedig épp akkor frissült.
                 */
                foreach ($erintettTemplomok as $erintettId) {
                    \Eloquent\Church::where('id', $erintettId)->update(['frissites' => date('Y-m-d')]);
                }

                /*
                 * A válasz minden érintett templom miséit hozza. Egy templomnál ez
                 * pontosan a régi tartalom; többnél viszont enélkül a szerkesztő elveszítené
                 * a többi templom miséit, mert a kapott listával írja felül a sajátját.
                 */
                $this->content = json_encode($this->getByChurchIds($erintettTemplomok));
                break;

            default:
                $this->sendJsonError('Method not allowed', 405);
        }
    }

    /**
     * #506: több templom miséi egyben. A sorrend az útvonal templomával kezd, hogy az
     * egy-templomos válasz tartalma és sorrendje se változzon.
     *
     * @param int[] $churchIds
     */
    public function getByChurchIds(array $churchIds): array {
        $sorrend = array_values(array_unique(array_merge([(int) $this->tid], array_map('intval', $churchIds))));

        $misek = [];
        foreach ($sorrend as $churchId) {
            foreach ($this->getByChurchId($churchId) as $mise) {
                $misek[] = $mise;
            }
        }
        return $misek;
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

    /**
     * Írhatja-e a bejelentkezett felhasználó ENNEK a templomnak a miséit?
     *
     * A jogosultság-ellenőrzés eddig kizárólag az ÚTVONALBAN szereplő templomra futott,
     * a mentés viszont a beküldött adat `church_id`-jét írta ki. Aki tehát egyetlen
     * templomhoz kapott jogot, az a saját mentésébe más templom azonosítóját írva bárhova
     * felvihetett misét. A törlés még ennyit sem nézett: `whereIn('id', ...)` alapján
     * bármelyik mise törölhető volt, azonosító alapján.
     *
     * A szabály egyszerű és mindkét irányban véd: egy misét oda írhatsz és onnan
     * törölhetsz, AHOL írásjogod van. Egy templomra ez pontosan a mai viselkedés; a
     * plébánia gondnokának a fíliákra is jár, mert a `checkWriteAccess()` az örökölt
     * gondnokságot is elfogadja.
     */
    private function ensureWritableChurch(int $churchId): void
    {
        static $ellenorzott = [];
        if (isset($ellenorzott[$churchId])) {
            return;
        }

        $church = \Eloquent\Church::find($churchId);
        if (!$church) {
            $this->sendJsonError('Nincs ilyen templom: ' . $churchId, 404);
        }

        $church->append(['writeAccess']);
        if (!$church->writeAccess) {
            $this->sendJsonError('Hiányzó jogosultság a(z) ' . $churchId . ' azonosítójú templomhoz!', 403);
        }

        $ellenorzott[$churchId] = true;
    }

    /**
     * MELY templomokat érinti ez a kérés?
     *
     * Külön, tiszta függvény, mert az `ensureWritableChurch()` hibánál `exit`-tel zár —
     * azt nem lehet tesztelni. Márpedig épp ez a lényegi állítás: egy kérés akkor is a
     * B templomot érinti, ha az A templom útvonalára küldték be.
     *
     * A törlendő misék templomát az adatbázisból nézzük meg, nem a beküldött adatból: a
     * kliens csak azonosítót küld, és a hovatartozást nem is bízhatjuk rá.
     *
     * @param  int[] $deletedMasses törlendő mise-azonosítók
     * @param  array $masses        mentendő misék (tömb vagy objektum elemek)
     * @param  int   $utvonalTemplom az URL-ben szereplő templom — ez a hiányzó church_id alapja
     * @return int[] az érintett templom-azonosítók, ismétlés nélkül
     */
    public static function affectedChurchIds(array $masses, array $deletedMasses, int $utvonalTemplom): array
    {
        $erintett = [];

        foreach ($masses as $mass) {
            $adat = CalModel::arrayKeysToSnakeCase(is_array($mass) ? $mass : (array) $mass);
            $erintett[] = (int) ($adat['church_id'] ?? $utvonalTemplom);
        }

        foreach ($deletedMasses as $torlendoId) {
            $torlendo = CalMass::find($torlendoId);
            if ($torlendo) {
                $erintett[] = (int) $torlendo->church_id;
            }
        }

        return array_values(array_unique($erintett));
    }

    public function save(ChangeRequest $changeRequest): void
    {
        // A mentés eddig ELLENŐRZÉS NÉLKÜL írta ki, amit a frontend küldött. Így került az
        // adatbázisba `2026-01-01TNaN:NaN:NaN` kezdés (a szerkesztőben üresen maradt
        // időpontból), és az ilyen mise NÉMÁN eltűnt: az újraindexelő kihagyja
        // ("Invalid RRULE dtstart, skipping mass ID …"), tehát a keresőben soha nem
        // jelenik meg, a szerkesztőben viszont ott van — a gondnok jogosan hiszi, hogy
        // felvitte. A /health "elastic-misék nélküli misézőhelyek" listáján bukkant elő.
        //
        // Előbb MINDENT ellenőrzünk, és csak utána írunk: fél-mentett miserendet ne
        // hagyjunk magunk után.
        /*
         * Jogosultság MINDEN érintett templomra, még az első írás előtt. A kérés nem
         * csak az útvonal templomát érintheti: a mentendő mise `church_id`-je bármi
         * lehet, a törlendő mise pedig a saját templomához tartozik.
         */
        foreach (self::affectedChurchIds($changeRequest->masses, $changeRequest->deletedMasses, (int) $this->tid) as $erintett) {
            $this->ensureWritableChurch($erintett);
        }

        foreach ($changeRequest->masses as $mass) {
            $ellenorzendo = CalModel::arrayKeysToSnakeCase(is_array($mass) ? $mass : (array) $mass);
            $hiba = CalMass::invalidDateTimeReason($ellenorzendo);
            if ($hiba !== null) {
                $cim = trim((string) ($ellenorzendo['title'] ?? ''));
                $this->sendJsonError(
                    'Hiányzó vagy értelmezhetetlen időpont'
                    . ($cim !== '' ? ' ennél: „' . $cim . '”' : '')
                    . '. ' . $hiba,
                    400
                );
            }
        }

        /*
         * Törlendő misék. Csak azt töröljük, aminek a templomához van jogunk — és csak
         * azt, ami létezik. A nem létező azonosítót szó nélkül átugorjuk: a szerkesztő
         * küldhet olyat, amit közben más már törölt, attól még a mentés menjen végig.
         */
        $torolheto = [];
        foreach ($changeRequest->deletedMasses as $torlendoId) {
            $torlendo = CalMass::find($torlendoId);
            if (!$torlendo) {
                continue;
            }
            $torolheto[] = $torlendo->id;
        }
        if ($torolheto) {
            CalMass::whereIn('id', $torolheto)->delete();
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
