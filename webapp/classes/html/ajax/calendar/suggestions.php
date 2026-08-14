<?php

namespace Html\Ajax\Calendar;

use Eloquent\CalMass;
use Eloquent\CalSuggestion;
use Eloquent\CalSuggestionPackage;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Facades\Log;


// A ?? azért kell, mert CLI-ből (teszt, cron) nincs REQUEST_METHOD, és a fájl
// betöltése önmagában figyelmeztetést dobott.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: POST, GET, OPTIONS, PUT, DELETE");
    header("Access-Control-Allow-Headers: Content-Type");
    http_response_code(200);
    exit();
}

header("Access-Control-Allow-Origin: *");

class Suggestions extends \Html\Ajax\Calendar\CalendarApi
{
    private bool $modify;

    public function __construct($path)
    {
        // #392: váratlan kivétel -> tiszta JSON hiba (nem HTML).
        try {
            $this->handle($path);
        } catch (\Throwable $e) {
            error_log('[calendar] ' . static::class . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            $this->sendJsonError('Váratlan hiba a naptár-műveletben.', 500);
        }
    }

    private function handle($path)
    {
        if (empty($path[0])) {
            $this->sendJsonError('Nem megfelelő URL!', 400);
        }

        //ekkor konkrét javaslat elfogadás/elutasítás érkezik
        $this->modify = in_array($path[0], ['accept', 'reject']);

        if (empty($path[1])) {
            $this->sendJsonError('Hiányzó templom azonosító.', 400);
        }

        if (!$this->modify) {
            $this->tid = $path[1];

            $this->church = \Eloquent\Church::find($this->tid);
            if (!$this->church) {
                $this->sendJsonError('Nincs ilyen templom.', 404);
            }
        }

        switch ($_SERVER['REQUEST_METHOD']) {
            case 'GET':
                if ($this->modify) {
                    $this->sendJsonError('Method not allowed', 405);
                }
                // #592: a külső naptár léte önmagában nem tilthatja le a javaslatokat sem —
                // a templom kézzel felvitt miséihez továbbra is lehet javaslatot tenni.
                // Csak a konkrétan importált misékre vonatkozó műveleteket zárjuk ki (lásd lentebb).
                $this->church->append(['writeAccess']);

                if (!$this->church->writeAccess) {
                    $this->sendJsonError('Hiányzó jogosultság!', 403);
                }

                $churchId = $this->tid;

                $query = CalSuggestionPackage::where('church_id', $churchId);

                $filtered = $query->with('suggestions')->get()
                    ->map(fn($mass) => $mass->toArray())
                    ->values();

                $this->content = json_encode($filtered);
                break;
                
            case 'POST':

                if ($this->modify) {
                    //$path[0]: accept/reject, $path[1]: suggestion package ID
                    $input = json_decode(file_get_contents('php://input'), true);
                    $this->handleModifiedPost($path[0], $path[1], $input);
                } else {
                    $this->handleNewSuggestionPackage();
                }
                break;
                
            default:
                $this->sendJsonError('Method not allowed', 405);
        }
    }

    private function handleModifiedPost($operation, $id, $input): void {

        $package = CalSuggestionPackage::with('suggestions')->findOrFail($id);
        $churchId = $package->church_id;
        
        
        // Check if church has external calendar - $path[1] is package ID, get church ID from package
        $modifyChurch = \Eloquent\Church::find($churchId);
        if(!$modifyChurch) {
            $this->sendJsonError('Nincs ilyen templom: '.$churchId, 404);
        }

        // Az elfogadás/elutasítás ágán EDDIG SEMMILYEN jogosultság-ellenőrzés nem volt:
        // a `handle()` csak a GET-nél nézte a writeAccess-t. Bárki, bejelentkezés nélkül,
        // egyetlen POST-tal elfogadhatta vagy elutasíthatta bármelyik templom bármelyik
        // javaslatát — kipróbálva: sima curl, süti nélkül, HTTP 200, az állapot átállt.
        //
        // Ez egyben a megfigyelt tünetet is magyarázza: a kezelő „vendégként" jelent meg,
        // mert tényleg vendégként ment át a művelet.
        $modifyChurch->append(['writeAccess']);
        if (!$modifyChurch->writeAccess) {
            $this->sendJsonError('Hiányzó jogosultság!', 403);
        }

        // #592: nem a külső naptár léte a tiltó ok, hanem az, ha a javaslat épp egy
        // importált misét módosítana — azt a következő szinkron úgyis felülírná.
        $importedTargets = \Eloquent\CalMass::importedIdsAmong(
            $package->suggestions->pluck('mass_id')->filter()->all()
        );
        if ($importedTargets !== []) {
            $this->sendJsonError(
                'Ez a javaslat a külső naptárból importált liturgiát érint, amit itt nem lehet módosítani.',
                403
            );
        }


        // Ki döntött róla, és mikor. Eddig CSAK az állapot tárolódott, a kezelő nem —
        // az adminfelületen ezért nem lehetett látni a nevét. A munkamenetből vesszük,
        // nem a kliens szavából.
        global $user;
        $kezelo = !empty($user->uid) ? (int) $user->uid : null;
        $kezelesIdeje = date('Y-m-d H:i:s');

        $package->state = $input['state'];
        $package->handled_by_user_id = $kezelo;
        $package->handled_at = $kezelesIdeje;
        $package->save();


        //Azonos paraméterű javaslatok kezelése
        $identicalIds = $this->findIdenticalSuggestions($package);
        
        CalSuggestionPackage::whereIn('id', $identicalIds)
            ->update([
                'state' => $input['state'],
                'handled_by_user_id' => $kezelo,
                'handled_at' => $kezelesIdeje,
            ]);

        if ($input['state'] === 'ACCEPTED') {
            //Ugyanarra a misére vonatkozó javaslatok kezelése
            $massIds = $this->findSuggestionsForMass($package);
            
            CalSuggestionPackage::whereIn('id', $massIds)
                ->update([
                    'state' => 'ACCEPTED',
                    'handled_by_user_id' => $kezelo,
                    'handled_at' => $kezelesIdeje,
                ]);

            Capsule::connection()->beginTransaction();

            try {
                foreach ($package->suggestions as $sug) {
                    $massId = $sug->mass_id;
                    $changes = $sug->changes ?? [];

                    if ($sug->mass_state === 'NEW') {
                        CalMass::create($changes);
                    } elseif ($sug->mass_state === 'MODIFIED') {
                        $mass = CalMass::findOrFail($massId);
                        $mass->update($changes);
                    } elseif ($sug->mass_state === 'DELETED') {
                        CalMass::where('id', $massId)->delete();
                    }
                }

                // Update church frissites field when suggestion is accepted
                $modifyChurch->update(['frissites' => date('Y-m-d')]);

                Capsule::connection()->commit();
            } catch (\Throwable $e) {
                Capsule::connection()->rollBack();                
                $this->sendJsonError('Hiba történt a javaslatok alkalmazása során: ' . $e->getMessage(), 500);
            }
        }

        // #543: a beküldő értesítése (ha adott meg emailt). Elfogadáskor automatikus
        // köszönő-levél; elutasításkor csak ha a kezelő kéri (notify_sender flag — ezt
        // az Angular felület küldheti; default: nem küldünk, mert néha látszik, hogy
        // valaki véletlen többet küldött be). Ide csak a state-save + a sikeres ACCEPTED-
        // apply UTÁN jutunk el (az apply hibája fentebb sendJsonError-rel kilép). Az
        // email-hiba NE buktassa a már elmentett javaslat-státuszt.
        if (!empty($package->sender_email)) {
            try {
                if ($input['state'] === 'ACCEPTED') {
                    $package->sendMail('accepted_sender', $package->sender_email);
                } elseif ($input['state'] === 'REJECTED' && !empty($input['notify_sender'])) {
                    $package->sendMail('rejected_sender', $package->sender_email);
                }
            } catch (\Throwable $e) {
                // csendben elnyeljük — a státusz már mentve, az email másodlagos
            }
        }

        $query = CalSuggestionPackage::where('church_id', $churchId);

        $filtered = $query->with('suggestions')->get()
            ->map(fn($mass) => $mass->toArray())
            ->values();

        $calendarMasses = CalMass::where('church_id', $churchId)->get();

        $this->content = json_encode([
            'suggestionPackages' => $filtered,
            'calendarMasses' => $calendarMasses->map(fn($mass) => $mass->toArray())->values(),
        ]);

    }

    private function findIdenticalSuggestions($package)
    {
        $suggestions = $package->suggestions;

        if ($suggestions->isEmpty()) {
            return collect();
        }

        // For each suggestion, find candidate packages with matching suggestions
        $allowedPackageIds = null;

        foreach ($suggestions as $index => $suggestion) {
            $baseNormalizedChanges = $this->normalizeChanges($suggestion->changes);

            $query = CalSuggestion::where('id', '!=', $suggestion->id)
                ->where('mass_state', $suggestion->mass_state)
                ->where('mass_id', $suggestion->mass_id)
                ->whereHas('package', function ($q) use ($package) {
                    $q->where('church_id', $package->church_id)
                        ->where('state', 'PENDING');
                });

            if ($index === 0) {
                $query->where('package_id', '!=', $suggestion->package_id);
            } else {
                if ($allowedPackageIds && $allowedPackageIds->isNotEmpty()) {
                    $query->whereIn('package_id', $allowedPackageIds);
                } else {
                    // No packages matched the previous suggestion, so no candidates remain
                    return collect();
                }
            }
            $candidates = $query->get();

            $found = $candidates->filter(function ($cand) use ($baseNormalizedChanges) {
                return $this->normalizeChanges($cand->changes) === $baseNormalizedChanges;
            });

            // Only keep packages that matched this suggestion
            $allowedPackageIds = $found->pluck('package_id')->unique();
        }

        // Return only package IDs that matched ALL suggestions
        return $allowedPackageIds ?? collect();
    }

    private function normalizeChanges($changes): string
    {
        if (is_string($changes)) {
            $decoded = json_decode($changes, true);
        } else {
            $decoded = $changes;
        }

        if (!is_array($decoded)) {
            return '';
        }

        ksort($decoded);
        return json_encode($decoded);
    }


    private function findSuggestionsForMass($package)
    {
        $originalSuggestions = $package->suggestions;

        if ($originalSuggestions->isEmpty() || $originalSuggestions->pluck('mass_id')->filter()->isEmpty()) {
            return collect();
        }

        $originalMassIds = $originalSuggestions->pluck('mass_id')->unique();
        $originalChurchId = $package->church_id;
        $originalPackageId = $package->id;

        $candidatePackages = CalSuggestionPackage::with('suggestions')
            ->where('id', '!=', $originalPackageId)
            ->where('church_id', $originalChurchId)
            ->where('state', 'PENDING')
            ->get();

        $validPackageIds = collect();

        foreach ($candidatePackages as $candidate) {
            $suggestions = $candidate->suggestions;

            if ($suggestions->isEmpty()) {
                continue;
            }

            $allValid = $suggestions->every(function ($suggestion) use ($originalMassIds, $originalPackageId) {
                return $originalMassIds->contains($suggestion->mass_id) &&
                    $suggestion->package_id !== $originalPackageId;
            });

            if ($allValid) {
                $validPackageIds->push($candidate->id);
            }
        }

        return $validPackageIds->unique();
    }

    private function handleNewSuggestionPackage(): void {
        $input = json_decode(file_get_contents('php://input'), true);

        if (!$input || !isset($input['churchId']) || !isset($input['suggestions']) || !isset($input['state'])) {
            $this->sendJsonError("Érvénytelen adat", 400);
        }

        // #592: importált misére ne lehessen javaslatot tenni — a szinkron felülírná.
        $targetMassIds = array_filter(array_column($input['suggestions'] ?? [], 'massId'));
        if (\Eloquent\CalMass::importedIdsAmong($targetMassIds) !== []) {
            $this->sendJsonError(
                'A külső naptárból importált liturgiákat itt nem lehet módosítani, azokat a napi szinkron kezeli.',
                403
            );
        }

        // A beküldő kilétét a MUNKAMENETBŐL vesszük, nem a kliens szavából. A naptár-
        // alkalmazás a `senderUserId`-t sosem küldte el (a FormControl deklarálva volt,
        // de soha nem kapott értéket), a `senderName`-be pedig a `*vendeg*` belső jelölő
        // is bekerülhetett — így a felület nem tudta a beküldőt felhasználóhoz kötni.
        // Bejelentkezett felhasználónál a szerver tudja a választ; a kliens értéke csak
        // a valódi vendégeknél számít.
        global $user;
        $bejelentkezett = !empty($user->uid);

        Capsule::connection()->transaction(function () use ($input, $user, $bejelentkezett) {
            $package = CalSuggestionPackage::create([
                'church_id' => $input['churchId'] ?? null,
                'sender_name' => $bejelentkezett
                    ? self::displayName($user)
                    : self::cleanSenderName($input['senderName'] ?? null),
                'sender_email' => $bejelentkezett ? ($user->email ?? null) : ($input['senderEmail'] ?? null),
                'sender_user_id' => $bejelentkezett ? (int) $user->uid : null,
                'sender_message' => $input['senderMessage'] ?? null,
                'state' => $input['state'] ?? 'PENDING',
                'created_at' => $input['created_at'] ?? null,
            ]);

            if (!empty($input['suggestions']) && is_array($input['suggestions'])) {
                foreach ($input['suggestions'] as $suggestion) {
                    $package->suggestions()->create([
                        'period_id' => $suggestion['periodId'] ?? null,
                        'mass_id' => $suggestion['massId'] ?? null,
                        'mass_state' => $suggestion['massState'],
                        'changes' => $suggestion['changes'] ?? null,
                    ]);
                }
            }

            // #307: értesítjük az adminokat / egyházmegyei felelőst / templom-gazdákat.
            // A küldés a tranzakción belül van, de a try/catch elnyeli az SMTP- vagy
            // template-hibákat (csak error_log-ba kerül) — a tranzakció EZÉRT NEM
            // görgetődik vissza. A javaslat akkor is legitim, ha az értesítő email
            // valamiért nem ment ki; a felhasználói flow nem akadhat el SMTP-fennakadáson.
            try {
                $package->emails();
            } catch (\Throwable $emailError) {
                error_log("CalSuggestionPackage #{$package->id} email error: " . $emailError->getMessage());
            }

            $this->content = json_encode(["success" => true, "id" => $package->id]);
        });
    }

    /**
     * Emberi név a megjelenítéshez. A `username` a bejelentkezési azonosító, és
     * vendégnél a `*vendeg*` belső jelölő — egyik sem való adatmezőbe.
     */
    public static function displayName($user): ?string {
        foreach ([$user->name ?? null, $user->nickname ?? null, $user->username ?? null] as $jelolt) {
            $jelolt = self::cleanSenderName($jelolt);
            if ($jelolt !== null) {
                return $jelolt;
            }
        }
        return null;
    }

    /**
     * A `*vendeg*` / `*vendég*` belső jelölő SOHA nem kerülhet a beküldő nevébe: az nem
     * név, hanem a "nincs bejelentkezve" állapot jelölése. Eddig így lett a felületen
     * minden ilyen beküldésből „vendég".
     */
    public static function cleanSenderName($nev): ?string {
        $nev = trim((string) $nev);
        if ($nev === '' || preg_match('/^\*vend[eé]g\*$/u', $nev)) {
            return null;
        }
        return $nev;
    }

}