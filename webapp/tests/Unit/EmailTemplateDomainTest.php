<?php

use PHPUnit\Framework\TestCase;

/**
 * #315 / borazslo: „a levelekben nem írunk miserend.hu-t, hanem {{ domain }}-t vagy
 * hasonlót, hogy a staging vagy dev vagy bármi megfelelő emailt küldjön."
 *
 * A beégetett hoszt nem szépséghiba: a stagingről és a fejlesztői környezetből kiküldött
 * levél az ÉLES oldalra mutató linkeket tartalmaz. Aki rákattint, a valódi adatokat
 * szerkeszti — pont azt, amit a teszt-környezettel el akartunk kerülni. A
 * jelszó-emlékeztető és a „minden pontos" megerősítő linkek különösen kellemetlenek így.
 *
 * A `domain` globálist a Twig-környezet adja (`$config['path']['domain']`), tehát
 * környezetenként a helyes címet hozza.
 */
class EmailTemplateDomainTest extends TestCase
{
    /** @return string[] */
    private static function sablonok(): array
    {
        $minta = glob(__DIR__ . '/../../templates/emails/*.twig');
        self::assertNotEmpty($minta, 'nem találom az email-sablonokat');
        return $minta;
    }

    public function testNoEmailTemplateHardcodesTheLiveHost(): void
    {
        $vetkesek = [];
        foreach (self::sablonok() as $utvonal) {
            if (preg_match('#https?://(www\.)?miserend\.hu#i', (string) file_get_contents($utvonal))) {
                $vetkesek[] = basename($utvonal);
            }
        }

        self::assertSame([], $vetkesek,
            'beégetett éles hoszt az email-sablonban — használd a {{ domain }} globálist: '
            . implode(', ', $vetkesek));
    }

    /**
     * Fejlesztői cím semmiképp nem kerülhet levélbe.
     *
     * A `staging.miserend.hu` szándékosan NINCS a tiltottak közt: az önkéntes-levél
     * kifejezetten gyakorlóhelynek ajánlja. Az egy külön, megnevezett szolgáltatásra
     * mutató link — nem az oldal saját címe, tehát nem a `{{ domain }}` dolga. A
     * localhost és a 127.0.0.1 viszont sosem helyes: az a fejlesztő gépére mutat.
     */
    public function testNoEmailTemplateLinksToADeveloperHost(): void
    {
        $vetkesek = [];
        foreach (self::sablonok() as $utvonal) {
            if (preg_match('#https?://(localhost|127\.0\.0\.1)#i', (string) file_get_contents($utvonal))) {
                $vetkesek[] = basename($utvonal);
            }
        }

        self::assertSame([], $vetkesek, implode(', ', $vetkesek));
    }

    /**
     * A csere csak akkor ér valamit, ha a hivatkozások tényleg a globálist használják.
     * Enélkül a fenti két állítást úgy is ki lehetne elégíteni, hogy egyszerűen
     * kivesszük a linkeket.
     */
    public function testTheLinkingTemplatesUseTheDomainGlobal(): void
    {
        $hivatkozok = 0;
        foreach (self::sablonok() as $utvonal) {
            if (str_contains((string) file_get_contents($utvonal), '{{ domain }}')) {
                $hivatkozok++;
            }
        }

        self::assertGreaterThan(10, $hivatkozok,
            'gyanúsan kevés sablon hivatkozik a domain globálisra');
    }

    /** A sablonok maradjanak értelmezhetőek — a tömeges csere ne törjön el semmit. */
    public function testEveryEmailTemplateStillParses(): void
    {
        $twig = new \Twig\Environment(
            new \Twig\Loader\FilesystemLoader(dirname(__DIR__, 2) . '/templates')
        );
        foreach ([
            'miserend_date'  => 'twig_hungarian_date_format',
            'trans'          => 'twig_translate',
            'phone_links'    => 'twig_phone_links',
            'strip_protocol' => 'twig_strip_protocol',
            'facebook_path'  => 'twig_facebook_path',
            'readable_rrule' => 'twig_readable_rrule',
        ] as $nev => $fuggveny) {
            $twig->addFilter(new \Twig\TwigFilter($nev, $fuggveny));
        }

        foreach (self::sablonok() as $utvonal) {
            $forras = new \Twig\Source((string) file_get_contents($utvonal), basename($utvonal));
            $twig->parse($twig->tokenize($forras));
        }

        // A parse() kivételt dob hibás sablonnál; ha idáig eljutottunk, mind rendben van.
        self::assertTrue(true);
    }
}
