<?php

use PHPUnit\Framework\TestCase;

/**
 * #157: a naptár-bejegyzés tulajdonságainak felismerése.
 *
 * A példák NEM kitaláltak: mind a #157-ben megadott szegedi minta-naptárból valók.
 */
final class IcalEventPropertiesTest extends TestCase {

    // ── nyelv ───────────────────────────────────────────────────

    public function testAlapertelmezesbenMagyar(): void {
        self::assertSame('hu', IcalEventProperties::detectLanguage('Szentmise (P. Elek)'));
    }

    public function testAngolNyelvuMiseAngol(): void {
        self::assertSame('en', IcalEventProperties::detectLanguage('Mass in English - Angol nyelvű szentmise (P. Elek)'));
        self::assertSame('en', IcalEventProperties::detectLanguage('Szentmise ANGOL nyelven (P. Elek)'));
        self::assertSame('en', IcalEventProperties::detectLanguage('English mass / Angol nyelvű szentmise (P. Elek)'));
    }

    /**
     * A legcifrább valódi cím: mindkét nyelvet említi, de az egyik épp az, ami ELMARAD.
     */
    public function testAHelyettUtaniNyelvSzamit(): void {
        self::assertSame(
            'hu',
            IcalEventProperties::detectLanguage('Mass in HUNGARIAN - Angol helyett magyar nyelvű mise (P. SZŐCS)')
        );
    }

    public function testASzabvanyosLanguageParameterEroesebb(): void {
        // A cím magyarul van, de a naptár gazdája kitöltötte a szabványos mezőt.
        self::assertSame('de', IcalEventProperties::detectLanguage('Szentmise', 'de-AT'));
    }

    /** A saját kódjaink nem mindenhol ISO 639-1: a latin `va`, a görög `gr`. */
    public function testANyelviCimkeLekepezese(): void {
        self::assertSame('va', IcalEventProperties::mapLanguageTag('la'));
        self::assertSame('gr', IcalEventProperties::mapLanguageTag('el-GR'));
        self::assertSame('ua', IcalEventProperties::mapLanguageTag('uk'));
        self::assertSame('hu', IcalEventProperties::mapLanguageTag('hu-HU'));
        self::assertNull(IcalEventProperties::mapLanguageTag('klingon'));
    }

    /**
     * A puszta nemzetiségnév nem elég — VEZETÉKNÉV is lehet.
     *
     * Mindkettő valódi cím a minta-naptárból. A kontextus-követelmény nélkül ezek
     * lengyel, illetve horvát nyelvű misének látszanának.
     */
    public function testANemzetisegNevuVezeteknevNemNyelv(): void {
        self::assertSame('hu', IcalEventProperties::detectLanguage('Gyász szentmise Lengyel Györgyért (P. Szőcs)'));
        self::assertSame('hu', IcalEventProperties::detectLanguage('Gyász szentmise Horváth Andreáért (P. SZŐCS)'));
    }

    /** Kontextussal viszont felismeri. */
    public function testANyelvKontextussalFelismerheto(): void {
        self::assertSame('pl', IcalEventProperties::detectLanguage('Lengyel nyelvű szentmise'));
        self::assertSame('hr', IcalEventProperties::detectLanguage('Szentmise horvátul'));
        self::assertSame('de', IcalEventProperties::detectLanguage('German mass'));
    }

    // ── rítus ───────────────────────────────────────────────────

    public function testAlapertelmezesbenRomaiKatolikus(): void {
        self::assertSame('ROMAN_CATHOLIC', IcalEventProperties::detectRite('Szentmise (P. Elek)'));
    }

    public function testARegiRitusFelismerese(): void {
        self::assertSame('TRADITIONAL', IcalEventProperties::detectRite('(Régi rítusú mise)'));
        self::assertSame('TRADITIONAL', IcalEventProperties::detectRite('(Régi rítusú katolikus mise)'));
        self::assertSame('TRADITIONAL', IcalEventProperties::detectRite('(Régi rítusú mise: Uhel Péter primíciája)'));
    }

    /**
     * A sorrend számít: ez a cím a „liturgi" mintára is illeszkedne, pedig régi rítus.
     */
    public function testARegiRitusEroesebbMintALiturgia(): void {
        self::assertSame('TRADITIONAL', IcalEventProperties::detectRite('Régi rítusú liturgikus hétvége'));
    }

    public function testAGorogKatolikusFelismerese(): void {
        self::assertSame('GREEK_CATHOLIC', IcalEventProperties::detectRite('Szent Liturgia'));
        self::assertSame('GREEK_CATHOLIC', IcalEventProperties::detectRite('Görögkatolikus szentmise'));
    }

    // ── típus ───────────────────────────────────────────────────

    public function testACsaladosMiseTipusa(): void {
        self::assertSame(['FAMILY'], IcalEventProperties::detectTypes('Szentmise kisgyermekes családoknak (P. SZŐCS)'));
        self::assertSame(['FAMILY'], IcalEventProperties::detectTypes('Családi mise (P. ELEK)'));
    }

    public function testAzEgyetemiMiseTipusa(): void {
        self::assertSame(
            ['UNIVERSITY_YOUTH'],
            IcalEventProperties::detectTypes('Szentmise az Egyetemilelkészség évnyitójával (P. ELEK)')
        );
    }

    /** Egyszerre több típus is lehet. */
    public function testTobbTipusEgyszerre(): void {
        $tipusok = IcalEventProperties::detectTypes('Szentmise kisgyermekes családoknak (P. Szőcs, orgonás)');

        self::assertContains('FAMILY', $tipusok);
        self::assertContains('ORGAN', $tipusok);
    }

    public function testTipusJelzesNelkulUres(): void {
        self::assertSame([], IcalEventProperties::detectTypes('Szentmise (P. Elek)'));
    }

    // ── elmaradás ───────────────────────────────────────────────

    /**
     * A mintában 16 ilyen van. Ma miseként vesszük fel őket, tehát a miserend pont az
     * ellenkezőjét állítja annak, amit a naptár gazdája kiírt.
     */
    public function testANincsMiseNemMise(): void {
        self::assertTrue(IcalEventProperties::isCancelled('NINCS Szentmise'));
        self::assertTrue(IcalEventProperties::isCancelled('NINCS SZENTMISE'));
        self::assertTrue(IcalEventProperties::isCancelled('NINCS MÁR Taizei imaóra'));
        self::assertTrue(IcalEventProperties::isCancelled('ELMARAD! Szentségimádás és gyóntatás'));
    }

    public function testASzabvanyosCancelledStatuszIsSzamit(): void {
        self::assertTrue(IcalEventProperties::isCancelled('Szentmise', 'CANCELLED'));
        self::assertFalse(IcalEventProperties::isCancelled('Szentmise', 'CONFIRMED'));
    }

    /** A szóhatár miatt a szó BELSEJÉBEN lévő egyezés nem számít. */
    public function testASzoBelsejebenNemIllik(): void {
        self::assertFalse(IcalEventProperties::isCancelled('Szentmise a nincstelenekért'));
        self::assertFalse(IcalEventProperties::isCancelled('Szentmise (P. Elek)'));
    }

    // ── GEO ─────────────────────────────────────────────────────

    public function testAGeoMezoFeldolgozasa(): void {
        self::assertSame(['lat' => 46.253, 'lon' => 20.1414], IcalEventProperties::parseGeo('46.2530;20.1414'));
    }

    public function testARosszGeoNemDoblHibat(): void {
        self::assertNull(IcalEventProperties::parseGeo(null));
        self::assertNull(IcalEventProperties::parseGeo(''));
        self::assertNull(IcalEventProperties::parseGeo('46.25'));
        self::assertNull(IcalEventProperties::parseGeo('abc;def'));
        self::assertNull(IcalEventProperties::parseGeo('200;300'), 'a tartományon kívüli koordináta sem jó');
    }

    // ── szöveg-visszafejtés ─────────────────────────────────────

    /** RFC 5545 3.3.11: a `\n` a fájlban escape-elt sortörés. */
    public function testAzEscapeltSzovegVisszabontasa(): void {
        self::assertSame("AA\nKék?", IcalEventProperties::unescapeText('AA\\nKék?'));
        self::assertSame('Elek, gyóntat: -', IcalEventProperties::unescapeText('Elek\\, gyóntat: -'));
        self::assertNull(IcalEventProperties::unescapeText(null));
    }
}
