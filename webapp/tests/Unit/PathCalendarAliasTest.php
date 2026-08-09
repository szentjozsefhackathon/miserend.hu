<?php

use PHPUnit\Framework\TestCase;

/**
 * #316/#374: a naptár-API osztályokat a html/calendar/ → html/ajax/calendar/
 * alá helyeztük (namespace Html\Ajax\Calendar). Hogy az Angular frontend
 * (`environment.apiUrl = '/calendar/'`) ne törjön, a Path::convertAliases egy
 * backward-compat aliast tesz be: `/calendar/X` → `ajax/calendar/X`.
 *
 * Ez a teszt rögzíti, hogy az Angular valódi hívásai (event.service.ts) a helyes
 * Html\Ajax\Calendar\* osztályokra oldódnak fel, a helyes argumentumokkal — egy
 * jövőbeli elmozdítás (alias törlése, fájl-áthelyezés) így nem törheti el csendben.
 */
class PathCalendarAliasTest extends TestCase
{
    public static function routingCases(): array
    {
        return [
            // url                              elvárt osztály                        args
            ['calendar/church/5',             '\Html\Ajax\Calendar\Church',        ['5']],
            ['calendar/masses/5',             '\Html\Ajax\Calendar\Masses',        ['5']],
            ['calendar/suggestions/church/5', '\Html\Ajax\Calendar\Suggestions',   ['church', '5']],
            ['calendar/liturgicaldays',       '\Html\Ajax\Calendar\LiturgicalDays', []],
            ['calendar/generate',             '\Html\Ajax\Calendar\Generate',      []],
            ['calendar/caluser',              '\Html\Ajax\Calendar\CalUser',       []],
            ['calendar/periods',              '\Html\Ajax\Calendar\Periods',       []],
            ['calendar/calendarapi',          '\Html\Ajax\Calendar\CalendarApi',   []],
        ];
    }

    /**
     * @dataProvider routingCases
     */
    public function testCalendarAliasRoutesToAjaxCalendar(string $url, string $expectedClass, array $expectedArgs): void
    {
        $path = new \Path($url);

        // A Path a lowercase URL-ből építi a className-t; a PHP osztály-feloldás
        // kis/nagybetű-független, ezért case-insensitive az összevetés.
        $this->assertEqualsIgnoringCase(
            $expectedClass,
            (string) $path->className,
            "A(z) '$url' URL nem a várt osztályra oldódik fel."
        );
        $this->assertSame(
            $expectedArgs,
            $path->arguments,
            "A(z) '$url' URL argumentumai nem a vártak."
        );
    }
}
