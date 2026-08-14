<?php

use Illuminate\Database\Capsule\Manager as DB;
use PHPUnit\Framework\TestCase;

/**
 * #498: az országkód az OSM-határról jön, nem a régi `templomok.orszag` oszlopról.
 *
 * A tárolt értékek nem kitaláltak — az Overpassból ellenőriztem, hogy az OSM
 * országrelációi tényleg hordozzák az `ISO3166-1` taget:
 *   rel/21335 Magyarország HU | rel/90689 România RO | rel/14296 Slovensko SK
 */
final class CountryCodeTest extends TestCase {

    private array $createdBoundaryIds = [];
    private ?int $churchId = null;

    protected function tearDown(): void {
        if ($this->churchId !== null) {
            DB::table('lookup_boundary_church')->where('church_id', $this->churchId)->delete();
        }
        if ($this->createdBoundaryIds) {
            DB::table('boundaries')->whereIn('id', $this->createdBoundaryIds)->delete();
        }
        $this->createdBoundaryIds = [];
    }

    /** Létrehoz egy határt és rákapcsolja a templomra. */
    private function attach(int $churchId, int $level, string $name, ?string $iso): void {
        $this->churchId = $churchId;

        $id = DB::table('boundaries')->insertGetId([
            'boundary'     => 'administrative',
            'admin_level'  => $level,
            'name'         => $name,
            'iso3166_1'    => $iso,
            'osmtype'      => 'relation',
            'osmid'        => 900000 + $level,
        ]);
        $this->createdBoundaryIds[] = $id;

        DB::table('lookup_boundary_church')->insert([
            'church_id'   => $churchId,
            'boundary_id' => $id,
        ]);
    }

    private function church(int $id): \Eloquent\Church {
        return \Eloquent\Church::find($id);
    }

    public function testReturnsTheIsoCodeOfTheCountryBoundary(): void {
        $this->attach(1, 2, 'Magyarország', 'HU');

        self::assertSame('HU', $this->church(1)->countryCode());
    }

    /* Határon túl is — ez a lényeg, a régi oszlop ott a leggyengébb. */
    public function testWorksForForeignChurches(): void {
        $this->attach(1, 2, 'România', 'RO');

        self::assertSame('RO', $this->church(1)->countryCode());
    }

    /* Csak a level-2 határ számít, a megye/település nem. */
    public function testIgnoresNonCountryBoundaries(): void {
        $this->attach(1, 6, 'Pest vármegye', 'XX');
        $this->attach(1, 8, 'Szentendre', 'YY');

        self::assertNull($this->church(1)->countryCode());
    }

    /* Ha a szinkron még nem töltötte fel, NULL — nem üres string, nem szemét. */
    public function testReturnsNullWhenTheCodeIsNotSyncedYet(): void {
        $this->attach(1, 2, 'Magyarország', null);

        self::assertNull($this->church(1)->countryCode());
    }

    /* Határ nélküli templom (nincs koordináta, vagy a szinkron nem ért oda). */
    public function testReturnsNullWithoutAnyBoundary(): void {
        $this->churchId = 1;
        DB::table('lookup_boundary_church')->where('church_id', 1)->delete();

        self::assertNull($this->church(1)->countryCode());
    }

    /* A kódot nagybetűsítve adjuk vissza, akárhogy is került be. */
    public function testNormalisesTheCaseOfTheStoredCode(): void {
        $this->attach(1, 2, 'Slovensko', 'sk');

        self::assertSame('SK', $this->church(1)->countryCode());
    }
}
