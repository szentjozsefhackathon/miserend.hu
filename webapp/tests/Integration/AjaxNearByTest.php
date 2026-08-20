<?php

use PHPUnit\Framework\TestCase;

/**
 * #854: a főoldali „mi van a közelemben" doboz saját végpontot kapott.
 *
 * borazslo: „A /home használja az api/v4/nearby-t a közeli templomok kiírására. És ez
 * egyáltalán nem elegáns. A statisztikát is torzítja, és másképp kell így figyelni az
 * API alakításra is."
 *
 * Két külön baj volt vele. A STATISZTIKA: az `index.php` minden `api/` kezdetű útvonalat
 * `kind = 'api'`-ként számol, tehát a saját főoldalunk minden helymeghatározása
 * API-használatnak látszott. És a SZERZŐDÉS: amíg a saját oldalunk a nyilvános,
 * verziózott API-n függ, annak az alakját nem lehet szabadon alakítani.
 *
 * A felület vált szét, a LOGIKA nem: a lekérdezés közös (`Church::nearestQuery()`).
 */
final class AjaxNearByTest extends TestCase {

    /* ---- A közös lekérdezés ---- */

    /**
     * A LÉNYEG: a két felület ugyanazt a lekérdezést használja, tehát nem tud szétcsúszni
     * a távolságszámítás és a kizárás-halmaz.
     */
    public function testBothSurfacesShareTheSameQuery(): void {
        $ajax = file_get_contents(dirname(__DIR__, 2) . '/classes/html/ajax/nearby.php');
        $api = file_get_contents(dirname(__DIR__, 2) . '/classes/api/nearby.php');

        self::assertStringContainsString('nearestQuery(', $ajax);
        self::assertStringContainsString('nearestQuery(', $api);

        // Az API-ban ne maradjon saját, kézzel írt távolság-lekérdezés.
        self::assertStringNotContainsString('ST_distance_sphere', $api,
            'a tavolsagszamitas egy helyen legyen: Church::nearestQuery()');
    }

    /** A (0,0) védelem (#94) is közös — a GPS-fix hiánya nem érvényes helyzet. */
    public function testTheZeroPositionGuardIsShared(): void {
        self::assertFalse(\Eloquent\Church::isUsablePosition(0.0, 0.0));
        self::assertTrue(\Eloquent\Church::isUsablePosition(47.4979, 19.0402));
    }

    /** A koordináta nélküli templomok nem szivároghatnak be találatként. */
    public function testChurchesWithoutCoordinatesAreExcluded(): void {
        $sql = \Eloquent\Church::nearestQuery(47.4979, 19.0402, 5)->toSql();

        self::assertStringContainsString('NOT (lat = 0 AND lon = 0)', $sql);
        self::assertStringContainsString('order by `distance` asc', strtolower($sql));
    }

    /** A limit tényleg korlátoz. */
    public function testTheLimitIsApplied(): void {
        $templomok = \Eloquent\Church::nearestQuery(47.4979, 19.0402, 3)->get();

        self::assertLessThanOrEqual(3, $templomok->count());
    }

    /** ...és növekvő távolság szerint jön. */
    public function testTheResultIsOrderedByDistance(): void {
        $tavok = \Eloquent\Church::nearestQuery(47.4979, 19.0402, 5)
            ->get()->pluck('distance')->map('floatval')->all();

        $rendezett = $tavok;
        sort($rendezett);
        self::assertSame($rendezett, $tavok);
    }

    /* ---- A szétválás ---- */

    /**
     * A saját felületünk NE hívja a nyilvános API-t.
     *
     * Ez az őrzés a jegy lényege: amíg egy JS-ünk az `api/`-t hívja, a statisztika
     * torzít, és az API alakja a saját oldalunkhoz van kötve.
     */
    public function testOurOwnFrontendDoesNotCallThePublicApi(): void {
        $talalatok = [];
        $konyvtar = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/js')
        );

        foreach ($konyvtar as $fajl) {
            if (!$fajl->isFile() || !str_ends_with((string) $fajl->getFilename(), '.js')) {
                continue;
            }
            // A naptár lefordított csomagja nem a mi forrásunk.
            if (str_contains((string) $fajl->getPathname(), '/mcal/')) {
                continue;
            }
            if (preg_match('#[\'"]/api/v[0-9]+/#', (string) file_get_contents($fajl->getPathname()))) {
                $talalatok[] = basename((string) $fajl->getPathname());
            }
        }

        self::assertSame([], $talalatok,
            'Ezek a sajat frontend-fajljaink a nyilvanos API-t hivjak: ' . implode(', ', $talalatok)
            . '. Az api/ utvonalak kind=api-kent szamolodnak a statisztikaban, es a publikus '
            . 'szerzodest is a sajat oldalunkhoz kotik. Hasznalj ajax/ vegpontot.');
    }

    /** A doboz az új végpontot hívja. */
    public function testTheHomepageWidgetUsesTheAjaxEndpoint(): void {
        $js = file_get_contents(dirname(__DIR__, 2) . '/js/nearby-churches.js');

        self::assertStringContainsString("'/ajax/nearby'", $js);
    }
}
