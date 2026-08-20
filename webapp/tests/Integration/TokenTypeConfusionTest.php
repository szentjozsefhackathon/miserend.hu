<?php

use Illuminate\Database\Capsule\Manager as DB;
use PHPUnit\Framework\TestCase;

/**
 * #862: azonosítás-megkerülés a token típus-konverziójával.
 *
 * A `tokens.name` `varchar(40)`, a token 32 jegyű hexadecimális szám. Ha az
 * összehasonlítás másik oldalára SZÁM kerül, a MySQL nem a számot alakítja sztringgé,
 * hanem fordítva: a TÁROLT sztringet konvertálja számmá, a vezető számjegyekből.
 *
 *     SELECT '971744af0e83941bffd19c110a5d2b28' = 971744;   ->  1
 *
 * Az API-k JSON-törzsből olvasnak, ahol a `{"token": 971744}` valódi PHP int lesz.
 * Mérve, a `/api/v4/favorites` végponton:
 *
 *     {"token": 971744}    ->  {"favorites":[],"error":0}      <-- BEENGEDTE
 *     {"token": "971744"}  ->  {"error":"1","text":"Invalid token."}
 *
 * Vagyis bárki, token ismerete nélkül, rövid számokat próbálgatva idegen fiók adataihoz
 * fért — kiolvashatta és módosíthatta a kedvenc-templom listáját.
 */
final class TokenTypeConfusionTest extends TestCase {

    private string $tokenNev;

    protected function setUp(): void {
        DB::beginTransaction();

        /*
         * A SZÁM-ELŐTAG a lényeg, nem a konkrét érték: a támadó a `971744`-et küldi, a
         * tárolt token pedig ezzel KEZDŐDIK. A maradékot véletlenből tesszük hozzá, hogy
         * a seedben lévő tokenekkel ne ütközzön (a `name` UNIQUE).
         */
        $this->tokenNev = '971744' . bin2hex(random_bytes(13));

        DB::table('tokens')->insert([
            'name' => $this->tokenNev,
            'type' => 'api',
            'uid' => 2,
            'timeout' => date('Y-m-d H:i:s', strtotime('+1 day')),
        ]);
    }

    protected function tearDown(): void {
        DB::rollBack();
    }

    /**
     * A LÉNYEG: a szám-előtag NE találjon rá a tokenre.
     *
     * Ez a teszt a javítás előtt elbukik — a MySQL a tárolt sztringet konvertálja.
     */
    public function testANumericPrefixDoesNotMatchAToken(): void {
        self::assertNull(\Eloquent\Token::findByName(971744),
            'a 971744 szam nem token; a MySQL tipus-konverzioja miatt eddig ratalalt');
    }

    /**
     * A VÉDTELEN lekérdezés tényleg rátalál — ez a bizonyíték, hogy a védelem kell.
     *
     * Nem a MySQL-t teszteljük elvontan, hanem pontosan azt a hívást, ami eddig négy
     * helyen állt a kódban. Ha ez valaha `null`-t adna, a DB viselkedése változott — a
     * védelem akkor is maradjon.
     */
    public function testTheUnguardedLookupReallyMatchesANumericPrefix(): void {
        $talalt = \Eloquent\Token::where('name', 971744)->first();

        self::assertNotNull($talalt,
            'a vedtelen lekerdezes ratalal a szam-elotagra — ezert kell a findByName()');

        /*
         * A megtalált token nem feltétlenül a MIÉNK: a `first()` azt adja vissza, amelyik
         * előbb van a táblában. Épp ez a támadás lényege — a `971744` szám egy IDEGEN
         * felhasználó tokenjére talált rá.
         */
        self::assertStringStartsWith('971744', (string) $talalt->name);
    }

    /** A valódi token továbbra is működik. */
    public function testAValidTokenStillResolves(): void {
        $token = \Eloquent\Token::findByName($this->tokenNev);

        self::assertNotNull($token);
        self::assertSame(2, (int) $token->uid);
    }

    /** Nem sztring bemenet: elutasítás, nem konverzió. */
    public static function nemSztringek(): array {
        return [
            'int' => [971744],
            'float' => [971744.0],
            'true' => [true],
            'null' => [null],
            'tomb' => [['971744']],
        ];
    }

    /** @dataProvider nemSztringek */
    public function testNonStringInputIsRejected($bemenet): void {
        self::assertNull(\Eloquent\Token::findByName($bemenet));
    }

    /** Az üres sztring sem token. */
    public function testAnEmptyStringIsRejected(): void {
        self::assertNull(\Eloquent\Token::findByName(''));
    }

    /**
     * MINDEN hívóhely a védett metódust használja.
     *
     * Négy helyen állt ugyanez a minta; ha bármelyik visszatér a nyers `where('name', …)`
     * alakra, a hiba is visszatér — csak épp ott, ahol senki nem keresi.
     */
    public function testNoCallerUsesTheRawLookup(): void {
        $talalatok = [];
        $konyvtar = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/classes')
        );

        foreach ($konyvtar as $fajl) {
            if (!$fajl->isFile() || !str_ends_with((string) $fajl->getFilename(), '.php')) {
                continue;
            }
            // A modell maga tartalmazza a lekérdezést — az a védett hely.
            if (str_ends_with((string) $fajl->getPathname(), '/eloquent/token.php')) {
                continue;
            }
            $tartalom = (string) file_get_contents($fajl->getPathname());
            if (preg_match('#Token::where\(\s*[\'"]name[\'"]#', $tartalom)) {
                $talalatok[] = basename((string) $fajl->getPathname());
            }
        }

        self::assertSame([], $talalatok,
            'Ezek nyers Token::where(name, …)-t hasznalnak: ' . implode(', ', $talalatok)
            . '. Hasznald a Token::findByName()-et — kulonben a szam-bemenet ratalal a tokenre.');
    }
}
