<?php

class GlutenFreeCommunion
{
    public const HOLIDAYS_KEY = 'communion:gluten_free:holidays';
    public const WEEKDAYS_KEY = 'communion:gluten_free:weekdays';

    /**
     * #840: azok a kulcsok, amik NEM OSM-címkék — a mi saját névterünk.
     *
     * A tudás itt van, ahol a kulcs születik. Az `\Eloquent\Attribute::isOsmKey()` innen
     * kérdezi meg, tehát egyetlen helyen kell karbantartani. Enélkül a következő helyi
     * attribútum-író megint azt írná a `fromOSM`-be, hogy „én írtam", és a jelző megint
     * három dolgot jelentene egyszerre.
     */
    public const LOCAL_KEYS = [self::HOLIDAYS_KEY, self::WEEKDAYS_KEY];

    public static function options(): array
    {
        return [
            '' => 'Nincs információ',
            'always' => 'Mindig lehetséges, csak be kell állni a sorba',
            'at_end' => 'Mindig lehetséges, az áldozás végén',
            'at_start' => 'Mindig lehetséges, az áldozás elején vagy külön sorban',
            'ask_sacristy' => 'Előtte szólni kell a sekrestyében',
            'bring_host' => 'Ostyát kell vinni a sekrestyébe',
            'no' => 'Nem lehetséges',
        ];
    }

    public static function osmValue(?string $holidays, ?string $weekdays): string
    {
        $alwaysAvailable = ['always', 'at_end', 'at_start'];
        if (in_array($holidays, $alwaysAvailable, true)) {
            return 'yes';
        }
        if ($holidays === 'no' && $weekdays === 'no') {
            return 'no';
        }
        if ($holidays !== '' && $holidays !== null || $weekdays !== '' && $weekdays !== null) {
            return 'limited';
        }
        return '';
    }

    public static function details(?string $holidays, ?string $weekdays): array
    {
        $options = self::options();
        return [
            'holidays' => $options[$holidays] ?? $options[''],
            'weekdays' => $options[$weekdays] ?? $options[''],
            'hasInformation' => !empty($holidays) || !empty($weekdays),
            'osmValue' => self::osmValue($holidays, $weekdays),
        ];
    }

    /*
     * Az OSM-mel szinkronizált, származtatott címke kulcsa.
     */
    public const OSM_KEY = 'diet:gluten_free';

    /**
     * @return ?string A származtatott OSM-érték, vagy null, ha NINCS MIT FELKÜLDENI —
     *                 vagyis vagy nem jött be beállítás, vagy jött, de az érték nem
     *                 változott.
     */
    public static function save(int $churchId, array $values): ?string
    {
        if (!array_key_exists(self::HOLIDAYS_KEY, $values)
            && !array_key_exists(self::WEEKDAYS_KEY, $values)) {
            return null;
        }

        /*
         * #876: a MENTÉS ELŐTTI érték, hogy tudjuk, van-e mit felküldeni.
         *
         * A /edit űrlap a gluténmentes mezőket MINDIG elküldi (legördülők), tehát a régi
         * feltétel — „jött-e egyáltalán beállítás" — minden mentésnél igaz volt. Ettől a
         * hívó minden mentésnél elindított egy OSM API olvasást, akkor is, ha a
         * felhasználó a templom nevét írta át.
         *
         * borazslo javaslata a #847-ben: „Azt esetleg lehet, hogy csak akkor legyen
         * syncToOsm() a church/:id/edit oldalon, ha van communion:gluten_free változás."
         */
        $korabbiOsmErtek = \Eloquent\Attribute::where('church_id', $churchId)
            ->where('key', self::OSM_KEY)
            ->value('value');

        foreach ([self::HOLIDAYS_KEY, self::WEEKDAYS_KEY] as $key) {
            if (!array_key_exists($key, $values)) {
                continue;
            }
            $value = (string) $values[$key];
            if (!array_key_exists($value, self::options())) {
                throw new \InvalidArgumentException('Érvénytelen csökkentett gluténtartalmú áldozási beállítás.');
            }
            \Eloquent\Attribute::updateOrCreate(
                ['church_id' => $churchId, 'key' => $key],
                // #840: a jelzőt a KULCS dönti el, nem az, hogy mi írjuk épp.
                ['value' => $value, 'fromOSM' => (int) \Eloquent\Attribute::isOsmKey($key)]
            );
        }

        $stored = \Eloquent\Attribute::where('church_id', $churchId)
            ->whereIn('key', [self::HOLIDAYS_KEY, self::WEEKDAYS_KEY])
            ->pluck('value', 'key');
        $osmValue = self::osmValue(
            $stored[self::HOLIDAYS_KEY] ?? '',
            $stored[self::WEEKDAYS_KEY] ?? ''
        );
        /*
         * #840: a `diet:gluten_free` VALÓDI OSM-címke, tehát fromOSM = 1 — akkor is, ha
         * épp mi írjuk. Eddig 0-val írtuk, és ezzel átbillentettük a szinkron által
         * beállított jelzőt: a /josm statisztikájából emiatt tűnt el az a három templom,
         * ahol az érték az OSM-ben is ott van (1515, 889, 1254).
         */
        \Eloquent\Attribute::updateOrCreate(
            ['church_id' => $churchId, 'key' => self::OSM_KEY],
            ['value' => $osmValue, 'fromOSM' => (int) \Eloquent\Attribute::isOsmKey(self::OSM_KEY)]
        );

        /*
         * #876: ha az érték NEM változott, nincs mit felküldeni.
         *
         * A helyi sort ettől függetlenül mentjük (fentebb) — az olcsó. Az OSM-hívás nem:
         * az `OSM::pushTag()` letölti a teljes entitást, mielőtt eldöntené, hogy van-e
         * változás. Azt a kört spóroljuk meg itt.
         *
         * A `null` és az üres string SZÁNDÉKOSAN egyformán viselkedik: a korábbi sor
         * hiánya ugyanaz, mint az „nincs beállítva" érték.
         */
        if ((string) $korabbiOsmErtek === (string) $osmValue) {
            return null;
        }

        return $osmValue;
    }

    /*
     * #484: a származtatott diet:gluten_free azonnali felküldése az OSM-be.
     *
     * Eddig az /editosm-en kellett még egyszer menteni ahhoz, hogy az adat kijusson
     * az OSM-be. Mostantól a /edit mentése rögtön kiviszi — hozzáadva, módosítva
     * vagy (üres értéknél) törölve a címkét. A \OSM::pushTag() maga dönti el, hogy
     * van-e egyáltalán eltérés, tehát fölösleges changeset nem nyílik.
     *
     * Szándékosan NEM dobunk hibát: a misézőhely mentése ne bukjon el azon, hogy az
     * OSM épp nem érhető el. Ilyenkor figyelmeztetést kap a szerkesztő, és az adat
     * a következő mentéskor (vagy az /editosm-en) kimegy.
     */
    public static function syncToOsm($church, string $osmValue): void
    {
        try {
            if (\OSM::pushTag($church, self::OSM_KEY, $osmValue)) {
                addMessage('A csökkentett gluténtartalmú áldozás adatát felküldtük az OpenStreetMapre is.', 'success');
            }
        } catch (\Throwable $e) {
            addMessage('A csökkentett gluténtartalmú áldozás adatát nem sikerült felküldeni az OpenStreetMapre: '
                . htmlspecialchars($e->getMessage()) . ' A miserend.hu-n elmentettük, az OSM-be a következő mentéskor jut ki.', 'warning');
            error_log('[#484] OSM push hiba a(z) ' . ($church->id ?? '?') . ' templomnál: ' . $e->getMessage());
        }
    }
}
