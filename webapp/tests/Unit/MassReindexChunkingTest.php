<?php

use PHPUnit\Framework\TestCase;

/**
 * Egyetlen hibás templom ne vigye el a teljes mise-újraindexelést.
 *
 * A `\ExternalApi\ElasticsearchApi::updateMasses()` 100-as darabokban dolgozik. Ha egy
 * darab elhasalt, a benne lévő MIND A 100 templom kimaradt az indexből, a hibaüzenet
 * pedig csak az első öt azonosítót mondta — abból nem derült ki, melyik a hibás. Mivel
 * egyetlen hiba is kivételt dob a teljes futásból, a cron soha nem lett sikeres, és
 * minden körben elölről kezdte az egészet (élesben: „soha nem futott le sikeresen",
 * miközben óránként újraindult).
 */
class MassReindexChunkingTest extends TestCase
{
    /** @var string[] */
    private array $naplo = [];

    private function log(): callable
    {
        return function (string $uzenet): void { $this->naplo[] = $uzenet; };
    }

    /** Hibátlan futásnál darabonként egy hívás, semmi újrapróbálkozás. */
    public function testHealthyRunCallsTheRunnerOncePerChunk(): void
    {
        $hivasok = [];
        $hibas = \ExternalApi\ElasticsearchApi::reindexChunks(
            range(1, 250), 100,
            function (array $group) use (&$hivasok): void { $hivasok[] = count($group); },
            $this->log()
        );

        self::assertSame([], $hibas);
        self::assertSame([100, 100, 50], $hivasok, 'Ép futásnál nincs egyesével újrapróbálás.');
        self::assertSame([], $this->naplo);
    }

    /** A hibás darab többi temploma bekerül; csak a hibás marad ki. */
    public function testOnlyTheBrokenChurchIsSkipped(): void
    {
        $sikeres = [];
        $hibas = \ExternalApi\ElasticsearchApi::reindexChunks(
            range(1, 100), 100,
            function (array $group) use (&$sikeres): void {
                if (in_array(42, $group, true)) {
                    throw new \RuntimeException('templom 42 elhasalt');
                }
                foreach ($group as $tid) { $sikeres[] = $tid; }
            },
            $this->log()
        );

        self::assertSame([42 => 'templom 42 elhasalt'], $hibas, 'Pontosan a hibás templomot kell megnevezni.');
        self::assertCount(99, $sikeres, 'A darab többi templomának be kell kerülnie.');
        self::assertNotContains(42, $sikeres);
    }

    /** Több hibás templom is külön-külön megnevezhető. */
    public function testEveryBrokenChurchIsNamed(): void
    {
        $hibas = \ExternalApi\ElasticsearchApi::reindexChunks(
            range(1, 10), 10,
            function (array $group): void {
                foreach ($group as $tid) {
                    if ($tid === 3 || $tid === 7) {
                        throw new \RuntimeException('hiba #' . $tid);
                    }
                }
            },
            $this->log()
        );

        self::assertSame([3 => 'hiba #3', 7 => 'hiba #7'], $hibas);
    }

    /** Az ép darabot nem futtatjuk újra egyesével — az feleslegesen sok kérés lenne. */
    public function testHealthyChunksAreNotRetried(): void
    {
        $hivasszam = 0;
        \ExternalApi\ElasticsearchApi::reindexChunks(
            range(1, 200), 100,
            function (array $group) use (&$hivasszam): void {
                $hivasszam++;
                if (in_array(150, $group, true)) {
                    throw new \RuntimeException('a második darab rossz');
                }
            },
            $this->log()
        );

        // 1. darab: 1 hívás. 2. darab: 1 bukó + 100 egyesével.
        self::assertSame(102, $hivasszam);
    }

    /** A napló mondja meg, melyik templom hibázott — enélkül élesben nem kereshető. */
    public function testTheLogNamesTheBrokenChurch(): void
    {
        \ExternalApi\ElasticsearchApi::reindexChunks(
            [5, 6], 2,
            function (array $group): void {
                if ($group === [5, 6] || $group === [6]) {
                    throw new \RuntimeException('ES időtúllépés');
                }
            },
            $this->log()
        );

        $egyben = implode("\n", $this->naplo);
        self::assertStringContainsString('Templom #6', $egyben);
        self::assertStringContainsString('ES időtúllépés', $egyben);
    }

    /** Üres bemenetnél nincs se hívás, se hiba. */
    public function testEmptyInputDoesNothing(): void
    {
        $hivasszam = 0;
        $hibas = \ExternalApi\ElasticsearchApi::reindexChunks(
            [], 100,
            function () use (&$hivasszam): void { $hivasszam++; },
            $this->log()
        );

        self::assertSame([], $hibas);
        self::assertSame(0, $hivasszam);
    }
}
