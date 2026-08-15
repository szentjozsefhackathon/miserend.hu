<?php

use PHPUnit\Framework\TestCase;

/**
 * #670: a templom adatai a mise-indexbe is be vannak ágyazva, ezért a templom mentése
 * után a MISE-kereső még a régi adatot látta (pl. az újonnan felvitt gluténmentes vagy
 * akadálymentességi információt nem találta meg a napi cron lefutásáig).
 *
 * A frissítés mérve ~0,5 mp templomonként, ezért SZÁNDÉKOSAN nem a Church::save()-ben
 * fut: azt az OSM-szinkron (több ezer templom) és a boundary-cron (50 templom / 5 perc)
 * is hívja. Csak a felhasználói mentés-útvonalak (/edit, /editosm) indítják.
 */
final class MassSearchIndexRefreshTest extends TestCase
{
    public function testEnabledChurchIsRefreshed(): void
    {
        self::assertTrue(\Eloquent\Church::shouldRefreshMassSearchIndex('i'));
    }

    /* Nem engedélyezett templom miséi nincsenek is az indexben — nincs mit frissíteni. */
    public function testDisabledChurchIsNotRefreshed(): void
    {
        foreach (['n', 'f', '', null] as $ok) {
            self::assertFalse(
                \Eloquent\Church::shouldRefreshMassSearchIndex($ok),
                'A(z) ' . var_export($ok, true) . " státuszú templomnál nem kell újraindexelni."
            );
        }
    }
}
