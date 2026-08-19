<?php

/**
 * #157: mit tudunk kiolvasni egy naptár-bejegyzésből azon túl, hogy mikor van.
 *
 * Az import eddig három dolgot vett át (cím, időpont, ismétlődés), a többit eldobta:
 * a nyelv MINDIG magyar volt, a rítus MINDIG római katolikus, a helyszín, a leírás és
 * a mise típusa pedig sehova nem került. borazslo listája a #157-ben pontosan ezt
 * sorolja fel.
 *
 * A döntések nem elméletiek: a #157-ben megadott szegedi minta-naptár 949 eseményén
 * mértem őket. Abban van 11 „Régi rítusú mise" (ma mind római katolikusként jönne be),
 * több tucat „Angol nyelvű szentmise" (ma mind magyarként), 30+ „kisgyermekes
 * családoknak" (ma típus nélkül), és 16 olyan bejegyzés, ami épp azt mondja, hogy
 * NINCS mise — azokat ma miseként vesszük fel.
 *
 * Külön osztály, mert tiszta függvényekből áll: se adatbázis, se hálózat, tehát
 * önmagában tesztelhető, és az importer marad az, ami — összerakó.
 */
class IcalEventProperties {

    /** Az alapértelmezések, ha a bejegyzés nem mond semmit. */
    public const DEFAULT_LANG = 'hu';
    public const DEFAULT_RITE = 'ROMAN_CATHOLIC';

    /**
     * Nyelvek felismerése a címből.
     *
     * A kulcs a `calendar/src/app/enum/language-code.ts` kódja. Az érték három alak:
     * a magyar melléknév töve, a magyar határozó, és az angol név.
     *
     * A puszta nemzetiségnév NEM elég, és ez nem elméleti óvatosság: a minta-naptárban
     * van „Gyász szentmise Lengyel Györgyért" és „Gyász szentmise Horváth Andreáért" —
     * a `lengyel` és a `horvát` itt VEZETÉKNÉV. Ezért a felismerés nyelvi kontextust
     * követel: „X nyelvű", „X nyelven", „Xül", „Mass in X", „X mass".
     *
     * @var array<string, array{0:string,1:string,2:string}>
     */
    private const LANG_WORDS = [
        'hu'  => ['magyar', 'magyarul', 'hungarian'],
        'en'  => ['angol', 'angolul', 'english'],
        'de'  => ['német', 'németül', 'german'],
        'va'  => ['latin', 'latinul', 'latin'],
        'sk'  => ['szlovák', 'szlovákul', 'slovak'],
        'hr'  => ['horvát', 'horvátul', 'croatian'],
        'ro'  => ['román', 'románul', 'romanian'],
        'pl'  => ['lengyel', 'lengyelül', 'polish'],
        'it'  => ['olasz', 'olaszul', 'italian'],
        'fr'  => ['francia', 'franciául', 'french'],
        'es'  => ['spanyol', 'spanyolul', 'spanish'],
        'ua'  => ['ukrán', 'ukránul', 'ukrainian'],
        'ru'  => ['orosz', 'oroszul', 'russian'],
        'gr'  => ['görög', 'görögül', 'greek'],
        'cu'  => ['ószláv', 'ószlávul', 'church slavonic'],
        'rue' => ['ruszin', 'ruszinul', 'rusyn'],
        'si'  => ['szlovén', 'szlovénul', 'slovenian'],
        'pt'  => ['portugál', 'portugálul', 'portuguese'],
        'tl'  => ['tagalog', 'tagalogul', 'tagalog'],
    ];

    /**
     * A ténylegesen keresett minták, nyelvenként.
     *
     * @return array<string, string[]>
     */
    private static function langPatterns(): array {
        $mintak = [];
        foreach (self::LANG_WORDS as $kod => [$mellek, $hatarozo, $angol]) {
            $mintak[$kod] = [
                $mellek . ' nyelv',      // „angol nyelvű", „angol nyelven"
                $hatarozo,               // „angolul"
                'in ' . $angol,          // „Mass in English"
                $angol . ' mass',        // „English mass"
                $angol . ' language',
            ];
        }

        return $mintak;
    }

    /**
     * Rítusok felismerése a címből.
     *
     * A sorrend SZÁMÍT: a „Régi rítusú liturgikus hétvége" a „liturgi" mintára is
     * illeszkedne, pedig az a régi rítus, nem a görög. Ezért a szűkebb minta van elöl.
     *
     * @var array<string, string[]>
     */
    private const RITE_PATTERNS = [
        'TRADITIONAL'    => ['régi rítus', 'regi ritus', 'tridenti', 'usus antiquior', 'régi rítusú'],
        'GREEK_CATHOLIC' => ['görögkatolikus', 'gorogkatolikus', 'görög katolikus', 'szent liturgia', 'liturgia'],
    ];

    /**
     * Mise-típusok felismerése a címből. A kulcs a `calendar/src/app/enum/types.ts`-ből.
     *
     * @var array<string, string[]>
     */
    private const TYPE_PATTERNS = [
        'FAMILY'           => ['család', 'csalad', 'kisgyermekes'],
        'UNIVERSITY_YOUTH' => ['egyetemi', 'egyetemista', 'főiskolás', 'foiskolas'],
        'STUDENT'          => ['diák', 'diak', 'ifjúsági', 'ifjusagi', 'iskolás', 'iskolas'],
        'GUITAR'           => ['gitáros', 'gitaros'],
        'ORGAN'            => ['orgonás', 'orgonas', 'orgonakísérettel'],
        'SILENT'           => ['csendes', 'néma', 'nema'],
        'SINGER'           => ['énekes', 'enekes', 'kántált', 'kantalt'],
    ];

    /**
     * Ami épp azt mondja, hogy NINCS alkalom.
     *
     * A mintában 16 ilyen van („NINCS Szentmise", „ELMARAD! Szentségimádás…"). Ezeket
     * ma miseként vesszük fel, tehát a miserend pont az ellenkezőjét állítja annak,
     * amit a naptár gazdája ki akart írni.
     */
    private const CANCELLATION_PATTERNS = [
        'nincs', 'elmarad', 'szünetel', 'szunetel', 'törölve', 'torolve', 'cancelled', 'canceled',
    ];

    /** Kis- és nagybetűtől, ékezettől független összehasonlításhoz. */
    private static function normalize(string $text): string {
        return mb_strtolower(trim($text), 'UTF-8');
    }

    /**
     * Elmarad-e az alkalom?
     *
     * Két forrásból: az RFC 5545 `STATUS:CANCELLED`-jéből, és a címből. Az utóbbi
     * azért kell, mert a Google-naptárakban a lemondást jellemzően átírt címmel
     * jelzik, nem a STATUS mezővel.
     */
    public static function isCancelled(string $summary, ?string $status = null): bool {
        if ($status !== null && self::normalize($status) === 'cancelled') {
            return true;
        }

        $cim = self::normalize($summary);
        foreach (self::CANCELLATION_PATTERNS as $minta) {
            // Szóhatáron, hogy a „nincstelen" vagy a „Szentmise a NINCSTELENEKÉRT"
            // ne essen áldozatul. A `nincs` külön is gyakori önálló szóként.
            if (preg_match('/(?:^|[\s\(\[!\.,;:])' . preg_quote($minta, '/') . '(?:[\s\)\]!\.,;:]|$)/u', $cim)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A bejegyzés nyelve.
     *
     * Elsőként az RFC 5545 `LANGUAGE=` paramétere számít — az a szabvány helye, és ha
     * a naptár gazdája kitöltötte, akkor azt akarta. Ha nincs, a címből következtetünk.
     *
     * A „X helyett Y" eset külön kezelendő: a „Mass in HUNGARIAN - Angol helyett magyar
     * nyelvű mise" cím MINDKÉT nyelvet említi, de az egyik épp az, ami ELMARAD. Ezért a
     * „helyett" szó előtti említéseket eldobjuk.
     *
     * @param string|null $languageParam a SUMMARY `LANGUAGE=` paramétere, ha volt
     */
    public static function detectLanguage(string $summary, ?string $languageParam = null): string {
        if ($languageParam !== null && $languageParam !== '') {
            $kod = self::mapLanguageTag($languageParam);
            if ($kod !== null) {
                return $kod;
            }
        }

        $cim = self::normalize($summary);

        // „Angol helyett magyar": ami a „helyett" ELŐTT van, az nem lesz.
        $helyettPos = mb_strpos($cim, 'helyett');
        $vizsgalando = $helyettPos === false ? $cim : mb_substr($cim, $helyettPos);

        $talalt = self::firstMatch($vizsgalando, self::langPatterns());
        if ($talalt !== null) {
            return $talalt;
        }

        // Ha a „helyett" utáni rész nem mondott semmit, essünk vissza a teljes címre.
        if ($helyettPos !== false) {
            $talalt = self::firstMatch($cim, self::langPatterns());
            if ($talalt !== null) {
                return $talalt;
            }
        }

        return self::DEFAULT_LANG;
    }

    /**
     * Az RFC 5646 nyelvi címke (`en-US`, `hu`) leképezése a saját kódjainkra.
     *
     * A saját listánk nem mindenhol ISO 639-1: a latin nálunk `va`, a görög `gr`, az
     * ukrán `ua`. Ezeket külön kell fordítani, különben a szabványos címke épp a
     * szabványkövető naptáraknál veszne el.
     */
    public static function mapLanguageTag(string $tag): ?string {
        $alap = self::normalize(explode('-', str_replace('_', '-', $tag))[0]);

        $kivetelek = ['la' => 'va', 'el' => 'gr', 'uk' => 'ua', 'sl' => 'si', 'cs' => 'cu'];
        if (isset($kivetelek[$alap])) {
            return $kivetelek[$alap];
        }

        return isset(self::LANG_WORDS[$alap]) ? $alap : null;
    }

    /** A bejegyzés rítusa a címből. */
    public static function detectRite(string $summary): string {
        return self::firstMatch(self::normalize($summary), self::RITE_PATTERNS) ?? self::DEFAULT_RITE;
    }

    /**
     * A mise típusai a címből.
     *
     * Több is lehet egyszerre („Szentmise kisgyermekes családoknak, orgonás"), ezért
     * itt nem az első találat nyer.
     *
     * @return string[]
     */
    public static function detectTypes(string $summary): array {
        $cim = self::normalize($summary);
        $talalatok = [];

        foreach (self::TYPE_PATTERNS as $tipus => $mintak) {
            foreach ($mintak as $minta) {
                if (mb_strpos($cim, $minta) !== false) {
                    $talalatok[] = $tipus;
                    break;
                }
            }
        }

        return $talalatok;
    }

    /**
     * A `GEO` mező szélesség/hosszúság párja.
     *
     * RFC 5545: `GEO:46.2530;20.1414` — pontosvesszővel, ebben a sorrendben.
     *
     * @return array{lat: float, lon: float}|null
     */
    public static function parseGeo(?string $geo): ?array {
        if ($geo === null || trim($geo) === '') {
            return null;
        }

        $reszek = explode(';', trim($geo));
        if (count($reszek) !== 2 || !is_numeric($reszek[0]) || !is_numeric($reszek[1])) {
            return null;
        }

        $lat = (float) $reszek[0];
        $lon = (float) $reszek[1];
        if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
            return null;
        }

        return ['lat' => $lat, 'lon' => $lon];
    }

    /**
     * Az iCal szövegmezők escape-elésének visszabontása (RFC 5545 3.3.11).
     *
     * A `\n`, `\,`, `\;` és `\\` a FÁJLBAN escape-elt alak; ha nyersen tennénk az
     * adatbázisba, a leírásban `\n` maradna sortörés helyett — a mintában több
     * DESCRIPTION is ilyen („AA\nKék?").
     */
    public static function unescapeText(?string $value): ?string {
        if ($value === null) {
            return null;
        }

        $value = str_replace(['\\n', '\\N'], "\n", $value);
        $value = preg_replace('/\\\\([,;\\\\])/', '$1', $value);

        return trim($value ?? '');
    }

    /**
     * @param array<string, string[]> $patterns
     */
    private static function firstMatch(string $haystack, array $patterns): ?string {
        foreach ($patterns as $ertek => $mintak) {
            foreach ($mintak as $minta) {
                if (mb_strpos($haystack, $minta) !== false) {
                    return $ertek;
                }
            }
        }

        return null;
    }
}
