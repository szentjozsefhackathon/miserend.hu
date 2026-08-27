<?php

use Illuminate\Database\Capsule\Manager as DB;

/**
 * #872: napi/heti összefoglaló az esemény-értesítőkből.
 *
 * A gond, amit borazslo leírt: egyre több és többféle értesítőnk van, és aki több
 * templomot gondnokol — a #819 óta a plébános a fíliáiét is —, azt elönti. Ha kikapcsolja
 * az értesítést, csak rosszabb lesz, mert onnantól semmit nem tud.
 *
 * A számok az éles adatbázisból (borazslo mérése, 30 nap): a legterheltebb címek 157-162
 * levelet kaptak, a legrosszabb napokon 21-et EGYETLEN nap alatt. 41 felhasználó már ki
 * is kapcsolta az értesítést.
 *
 * MI TARTOZIK IDE. Csak a három esemény-értesítő: új észrevétel, új javaslat, új kép.
 * Az emlékeztetők (`user_pleaseupdate`, `holder_bucsu_reminder`,
 * `holder_holiday_reminder`) MÁR egy levél felhasználónként, az összes érintett
 * templommal, és ritkítva is vannak — azokkal nincs teendő.
 *
 * MIT HALASZTUNK. Nem a kész levelet tesszük félre, hanem az ESEMÉNYT. Így a digest
 * egyetlen, templomonként csoportosított levél lesz, nem N kész levél egymás alá fűzve
 * (N köszöntéssel és N aláírással).
 *
 * A `notifications = 0` erősebb mindennél: az továbbra is „ne írj nekem".
 */
class DigestQueue {

    const AZONNAL = 'instant';
    const NAPI = 'daily';
    const HETI = 'weekly';

    const GYAKORISAGOK = [self::AZONNAL, self::NAPI, self::HETI];

    /** A heti összefoglaló napja (1 = hétfő), és a legvégső határidő napokban. */
    const HETI_NAP = 1;
    const HETI_HATARIDO_NAP = 7;

    /**
     * Halasszuk-e ennek a címzettnek az értesítést?
     *
     * @return bool  true, ha a sor bekerült a várólistába — ilyenkor a hívó NE küldjön
     *               azonnali levelet. false esetén marad a régi, azonnali kiküldés.
     */
    public static function halaszt($cimzett, string $tipus, ?int $churchId, string $cim, string $url): bool {
        $uid = (int) ($cimzett->uid ?? 0);
        $email = trim((string) ($cimzett->email ?? ''));

        // Akit nem tudunk azonosítani, azt nem tudjuk összegezni sem: menjen azonnal,
        // ahogy eddig. (Inkább kapjon levelet, mint hogy elvesszen az értesítés.)
        if ($uid <= 0 || $email === '') {
            return false;
        }

        if (self::gyakorisag($cimzett) === self::AZONNAL) {
            return false;
        }

        DB::table('notification_digest_items')->insert([
            'uid' => $uid,
            'email' => $email,
            'type' => $tipus,
            'church_id' => $churchId,
            // A cím a levélben egy sor lesz; a hosszú észrevétel-szövegek levágva.
            'title' => mb_substr(trim(strip_tags($cim)), 0, 250),
            'url' => mb_substr($url, 0, 250),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return true;
    }

    /**
     * A címzett gyakorisága. Ismeretlen érték esetén a napi — az a beállított
     * alapértelmezés, és sosem vezet elveszett értesítéshez, csak késleltetetthez.
     */
    public static function gyakorisag($cimzett): string {
        $ertek = $cimzett->notification_frequency ?? null;

        return in_array($ertek, self::GYAKORISAGOK, true) ? $ertek : self::NAPI;
    }

    /**
     * Cron: kiküldi az esedékes összefoglalókat.
     *
     * Naponta fut. A napi gyakoriságúak minden futásnál sorra kerülnek, ha van mit
     * küldeni; a hetiek a hét megadott napján — VAGY ha a legrégebbi tételük már
     * túllépte a hét napot. Ez utóbbi azért kell, hogy egy kimaradt hétfői futás miatt
     * ne csússzon a következő hétre az egész.
     *
     * @return int a kiküldött összefoglalók száma
     */
    public static function sendDue(): int {
        $varakozok = DB::table('notification_digest_items')
            ->select('uid')
            ->selectRaw('MIN(created_at) AS legregebbi')
            ->whereNull('sent_at')
            ->groupBy('uid')
            ->get();

        $kuldott = 0;
        foreach ($varakozok as $sor) {
            if (self::kikuldheto((int) $sor->uid, $sor->legregebbi)) {
                $kuldott += self::kuldEgynek((int) $sor->uid) ? 1 : 0;
            }
        }

        return $kuldott;
    }

    private static function kikuldheto(int $uid, $legregebbi): bool {
        $user = DB::table('user')->where('uid', $uid)->first();
        if (!$user) {
            return false;
        }

        // Ha időközben kikapcsolta az értesítést, a várólistája nem megy ki.
        if ((int) ($user->notifications ?? 0) !== 1) {
            return false;
        }

        $gyakorisag = self::gyakorisag($user);
        if ($gyakorisag === self::AZONNAL) {
            // Menet közben állt át azonnalira: a felgyűlt tételeket még kiküldjük
            // egyben, mert azok már nem mennek ki külön levélben.
            return true;
        }
        if ($gyakorisag === self::NAPI) {
            return true;
        }

        return self::hetiEsedekes((int) date('N'), $legregebbi);
    }

    /**
     * Esedékes-e a HETI összefoglaló?
     *
     * A hét megadott napján igen. Emellett akkor is, ha a legrégebbi tétel már túllépte
     * a hét napot — enélkül egyetlen kimaradt hétfői futás miatt a következő hétre
     * csúszna az egész, és a gondnok két hétig nem tudna a hozzá érkezett észrevételről.
     *
     * Kívülről is hívható, mert a „ma milyen nap van" nélkül nem lenne tesztelhető.
     */
    public static function hetiEsedekes(int $maiNap, $legregebbi): bool {
        if ($maiNap === self::HETI_NAP) {
            return true;
        }

        return strtotime((string) $legregebbi) < strtotime('-' . self::HETI_HATARIDO_NAP . ' days');
    }

    /** Egy címzett összefoglalója. */
    private static function kuldEgynek(int $uid): bool {
        $tetelek = DB::table('notification_digest_items')
            ->where('uid', $uid)
            ->whereNull('sent_at')
            ->orderBy('created_at')
            ->get();

        if ($tetelek->isEmpty()) {
            return false;
        }

        $user = new \User($uid);
        if (!$user->uid) {
            return false;
        }

        // Templomonként csoportosítva — a gondnok így egyben látja, melyik templomával
        // mi történt, nem eseménytípusonként szétszórva.
        $templomok = [];
        foreach ($tetelek as $tetel) {
            $kulcs = (int) $tetel->church_id;
            if (!isset($templomok[$kulcs])) {
                $templom = $kulcs > 0 ? \Eloquent\Church::find($kulcs) : null;
                $templomok[$kulcs] = [
                    'nev' => $templom ? $templom->fullName : 'Egyéb',
                    'id' => $kulcs,
                    'tetelek' => [],
                ];
            }
            $templomok[$kulcs]['tetelek'][] = $tetel;
        }

        $user->digestChurches = array_values($templomok);
        $user->digestCount = count($tetelek);

        $mail = new \Eloquent\Email();
        $mail->to = $user->email;
        $mail->render('user_digest', $user);
        $mail->addToQueue();

        DB::table('notification_digest_items')
            ->where('uid', $uid)
            ->whereNull('sent_at')
            ->update(['sent_at' => date('Y-m-d H:i:s')]);

        return true;
    }
}
