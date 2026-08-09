<?php

use PHPUnit\Framework\TestCase;

/**
 * #306: A teljes (top-level, tids nélküli) ES mise-újragenerálás gating-logikája.
 *
 * A döntést a tiszta, DB/ES-mentes ElasticsearchApi::shouldFullReindex() hozza meg,
 * ezért itt közvetlenül, mockok nélkül tesztelhető. A tényleges I/O (index-üresség,
 * cron.lastsuccess_at, generatedPeriods max updated_at) a hívó updateMasses()-ben
 * gyűlik össze — az integrációt az ElasticsearchApiLoggerTest fedi.
 */
class ElasticsearchMassGatingTest extends TestCase
{
    /** Üres/hiányzó index (startup) mindig teljes futást kényszerít. */
    public function testEmptyIndexAlwaysReindexes(): void
    {
        $this->assertTrue(
            \ExternalApi\ElasticsearchApi::shouldFullReindex('2026-07-10 03:00:00', '2026-07-01', true),
            'Üres index esetén mindig teljes újragenerálás kell.'
        );
    }

    /** Korábbi sikeres futás hiánya (null / nulldátum) teljes futást kényszerít. */
    public function testNoPriorSuccessReindexes(): void
    {
        $this->assertTrue(
            \ExternalApi\ElasticsearchApi::shouldFullReindex(null, '2026-07-01', false),
            'Ha nincs korábbi sikeres futás, futni kell.'
        );
        $this->assertTrue(
            \ExternalApi\ElasticsearchApi::shouldFullReindex('0000-00-00 00:00:00', '2026-07-01', false),
            'Nulldátumú lastsuccess_at is korábbi-siker-hiánynak számít.'
        );
    }

    /** Ha a periódusok a legutóbbi sikeres futás UTÁN frissültek -> futás. */
    public function testPeriodsChangedAfterLastSuccessReindexes(): void
    {
        $this->assertTrue(
            \ExternalApi\ElasticsearchApi::shouldFullReindex('2026-07-01 03:00:00', '2026-07-05', false),
            'Frissült periódus (későbbi dátum) -> teljes futás.'
        );
    }

    /** Aznapi periódus-frissítés (date-inkluzív >=) -> futás (adatbiztos irány). */
    public function testSameDayPeriodUpdateReindexes(): void
    {
        $this->assertTrue(
            \ExternalApi\ElasticsearchApi::shouldFullReindex('2026-07-05 03:00:00', '2026-07-05', false),
            'Aznapi (napi granularitású) periódus-frissítés esetén inkább fussunk le.'
        );
    }

    /** Ha semmi nem változott a legutóbbi sikeres futás óta -> SKIP. */
    public function testNoChangeSkips(): void
    {
        $this->assertFalse(
            \ExternalApi\ElasticsearchApi::shouldFullReindex('2026-07-10 03:00:00', '2026-07-01', false),
            'Változatlan periódusok + nem üres index esetén kihagyjuk a teljes futást.'
        );
    }

    public function testMassChangedSinceLastSuccessReindexes(): void
    {
        $this->assertTrue(
            \ExternalApi\ElasticsearchApi::shouldFullReindex(
                '2026-07-10 03:00:00',
                '2026-07-01',
                false,
                '2026-07-11'
            ),
            'Mise módosítása önmagában is teljes újraindexelést kér.'
        );
    }

    public function testIndexCreatedAfterLastSuccessReindexes(): void
    {
        $this->assertTrue(
            \ExternalApi\ElasticsearchApi::shouldFullReindex(
                '2026-01-26 17:58:39',
                '2026-01-06',
                false,
                '2026-01-20',
                '2026-08-06 20:00:00'
            ),
            'A cron utolsó sikere után létrejött indexet újra kell építeni.'
        );
    }

    public function testOlderIndexAndUnchangedMassesSkip(): void
    {
        $this->assertFalse(
            \ExternalApi\ElasticsearchApi::shouldFullReindex(
                '2026-07-10 03:00:00',
                '2026-07-01',
                false,
                '2026-07-01',
                '2026-06-01 12:00:00'
            )
        );
    }

    /*
     * #627: "0-ról docker compose up" — a MySQL-seed egy viszonylag friss cron-sikert hoz,
     * az ES viszont egy mentésből visszatöltött, régi indexet. A DB cron-sora ilyenkor
     * hazudik az index tartalmáról, ezért a hívó az INDEX saját vízjelét (_meta.full_reindex_at)
     * adja át lastSuccess gyanánt. Így a seed óta lévő adat újraindexelést kér.
     */
    public function testRestoredOldIndexWithNewerSeededCronStillReindexes(): void
    {
        $indexWatermark = '2026-02-08 21:01:36'; // a szállított ES-image indexe
        $this->assertTrue(
            \ExternalApi\ElasticsearchApi::shouldFullReindex(
                $indexWatermark,
                '2026-01-06',      // seed: max(cal_generated_periods.updated_at)
                false,
                '2026-04-01',      // a seed óta módosult mise
                '2026-02-08 21:01:36'
            ),
            'A visszaállított index vízjele óta változott adat teljes futást kér.'
        );
    }

    /*
     * #627: ha az index vízjele frissebb minden DB-vízjelnél, ne fussunk fölöslegesen —
     * akkor se, ha a crons.lastsuccess_at ettől eltér.
     */
    public function testIndexWatermarkNewerThanEveryDbWatermarkSkips(): void
    {
        $this->assertFalse(
            \ExternalApi\ElasticsearchApi::shouldFullReindex(
                '2026-08-06 03:00:00',
                '2026-01-06',
                false,
                '2026-01-26',
                '2026-02-08 21:01:36'
            )
        );
    }

    /*
     * #627: évforduló. Semmi nem változik az adatbázisban, de a [Y-1, Y, Y+1] ablak
     * elcsúszik, és az index utolsó éve hiányozni kezd — ezt egyetlen időbélyeg sem veszi észre.
     */
    public function testIndexNotCoveringTheRequiredYearsIsStale(): void
    {
        $this->assertFalse(
            \ExternalApi\ElasticsearchApi::indexCoversYears('2027-12-31T22:00:00.000Z', [2026, 2027, 2028]),
            'A 2028-as évet nem tartalmazó index nem fedi le a kért ablakot.'
        );
        $this->assertTrue(
            \ExternalApi\ElasticsearchApi::indexCoversYears('2027-12-31T22:00:00.000Z', [2025, 2026, 2027]),
            'A kért ablakot lefedő index nem avult el.'
        );
    }

    /** Ha nem tudjuk megállapítani a lefedettséget, ne erre hivatkozva döntsünk. */
    public function testUnknownCoverageDoesNotDecide(): void
    {
        $this->assertTrue(\ExternalApi\ElasticsearchApi::indexCoversYears(null, [2026]));
        $this->assertTrue(\ExternalApi\ElasticsearchApi::indexCoversYears('2020-01-01T00:00:00Z', []));
    }

    /** Ha egyáltalán nincs generatedPeriod -> ne blokkoljunk. */
    public function testNoGeneratedPeriodsReindexes(): void
    {
        $this->assertTrue(
            \ExternalApi\ElasticsearchApi::shouldFullReindex('2026-07-10 03:00:00', null, false),
            'GeneratedPeriod hiányában ne blokkoljunk (adatbiztos irány).'
        );
        $this->assertTrue(
            \ExternalApi\ElasticsearchApi::shouldFullReindex('2026-07-10 03:00:00', '0000-00-00', false),
            'Nulldátumú periódus-updated_at se blokkoljon.'
        );
    }
}
