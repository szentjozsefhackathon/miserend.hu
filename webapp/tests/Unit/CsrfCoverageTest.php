<?php

use PHPUnit\Framework\TestCase;

/**
 * #873: az állapotváltó belépési pontokon OTT KELL LENNIE az őrnek.
 *
 * Ez nem stílus-ellenőrzés. A CSRF-védelem pont attól ér valamit, hogy egy végpont sem
 * marad ki: elég egyetlen őrizetlen írási út, és a támadás megint megvan. Ha valaki
 * jóhiszeműen kiszedi a `\Csrf::guard()`-ot (mert „nem működik a tesztem"), erről itt
 * kap hírt, nem élesben.
 *
 * A vizsgálat a PHP TOKENJEIT nézi, nem a nyers szöveget: egy kommentben említett
 * `Csrf::guard()` nem számít teljesítésnek.
 */
final class CsrfCoverageTest extends TestCase {

    /** Fájl => mit ír, ha nem őrizzük. */
    private const ALLAPOTVALTOK = [
        'classes/html/remark.php' => 'észrevétel állapota és admin-megjegyzése',
        'classes/html/ajax/switchreliable.php' => 'észrevétel megbízhatósági jelzője',
        'classes/html/ajax/churchlink.php' => 'templom-hivatkozás felvétele/törlése',
        'classes/html/ajax/osmkapcsolat.php' => 'OSM-összeköttetés bontása',
        'classes/html/church/changeholders.php' => 'gondnoki jogosultság kiosztása/visszavonása',
        'classes/html/church/delete.php' => 'templom törlése',
        'classes/html/church/edit.php' => 'templom adatainak mentése',
        'classes/html/church/editosm.php' => 'templom adatainak visszaírása OSM-be',
        'classes/html/church/editphotos.php' => 'fotó átnevezése/elrejtése/törlése',
        'classes/html/church/editschedule.php' => 'miserend naprakészre jelölése',
        'classes/html/church/create.php' => 'új templom felvétele',
        'classes/html/user/delete.php' => 'felhasználó törlése',
        'classes/html/user/edit.php' => 'felhasználói adatok mentése',
        'classes/html/uploadimage.php' => 'kép feltöltése',
        'classes/html/email/email.php' => 'levélküldés a nevünkben',
        'classes/html/ajax/favorite.php' => 'kedvenc templom felvétele/törlése',
        'classes/html/ajax/chat.php' => 'chat-üzenet beírása a nevünkben',
    ];

    public static function allapotvaltokProvider(): array {
        $sorok = [];
        foreach (self::ALLAPOTVALTOK as $fajl => $mit) {
            $sorok[$fajl] = [$fajl, $mit];
        }
        return $sorok;
    }

    /**
     * @dataProvider allapotvaltokProvider
     */
    public function testTheStateChangingEndpointIsGuarded(string $fajl, string $mit): void {
        $ut = PATH . $fajl;
        self::assertFileExists($ut, "Elmozdult a fájl? Ha átnevezted, a lista is frissítendő.");

        self::assertTrue(
            self::hivjaAzOrt(file_get_contents($ut)),
            "Nincs \\Csrf::guard() ebben: $fajl — pedig ez írja: $mit. "
            . "Enélkül egy idegen oldal a bejelentkezett felhasználónk nevében kiadhatja."
        );
    }

    /** A load.php nem `guard()`-ol (kivétel nem fér el ott), de POST-ot és tokent követel. */
    public function testTheLogoutRequiresPostAndAToken(): void {
        $forras = file_get_contents(PATH . 'load.php');
        self::assertStringContainsString("\\Csrf::valid()", $forras);
        self::assertStringContainsString("REQUEST_METHOD", $forras);
    }

    /** A tokennek ott kell lennie a lapon, különben az ajax-hívások nem tudják mellékelni. */
    public function testTheLayoutPublishesTheToken(): void {
        $layout = file_get_contents(PATH . 'templates/layout.twig');
        self::assertStringContainsString('name="csrf-token"', $layout);
        self::assertStringContainsString('{{ csrf_token }}', $layout);
        self::assertStringContainsString('/js/csrf.js', $layout);
    }

    /**
     * Tokenekre bontva keressük a `Csrf::guard` hívást, hogy a kommentek és a
     * sztringliterálok ne számítsanak bele.
     */
    private static function hivjaAzOrt(string $forras): bool {
        $tokenek = token_get_all($forras);
        $db = count($tokenek);

        // PHP 8-ban a `\Csrf` EGY token (T_NAME_FULLY_QUALIFIED), a sima `Csrf` T_STRING.
        // Mindkettőt el kell fogadni, különben a `\Csrf::guard()` alakot nem látjuk meg.
        $osztalyNevek = [T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED];

        for ($i = 0; $i < $db; $i++) {
            $t = $tokenek[$i];
            if (!is_array($t) || !in_array($t[0], $osztalyNevek, true)) {
                continue;
            }
            if (strcasecmp(ltrim($t[1], '\\'), 'Csrf') !== 0) {
                continue;
            }
            // Csrf :: guard  — a köztes whitespace-eket átlépve.
            $j = self::kovetkezoErdemi($tokenek, $i + 1);
            if ($j === null || !is_array($tokenek[$j]) || $tokenek[$j][0] !== T_DOUBLE_COLON) {
                continue;
            }
            $k = self::kovetkezoErdemi($tokenek, $j + 1);
            if ($k !== null && is_array($tokenek[$k]) && strcasecmp($tokenek[$k][1], 'guard') === 0) {
                return true;
            }
        }

        return false;
    }

    private static function kovetkezoErdemi(array $tokenek, int $tol): ?int {
        for ($i = $tol; $i < count($tokenek); $i++) {
            $t = $tokenek[$i];
            if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            return $i;
        }
        return null;
    }
}
