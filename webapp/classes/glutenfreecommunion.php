<?php

class GlutenFreeCommunion
{
    public const HOLIDAYS_KEY = 'communion:gluten_free:holidays';
    public const WEEKDAYS_KEY = 'communion:gluten_free:weekdays';

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
     * @return ?string A származtatott OSM-érték, vagy null, ha nem jött be beállítás
     *                 (tehát nincs mit az OSM-be felküldeni sem).
     */
    public static function save(int $churchId, array $values): ?string
    {
        if (!array_key_exists(self::HOLIDAYS_KEY, $values)
            && !array_key_exists(self::WEEKDAYS_KEY, $values)) {
            return null;
        }

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
                ['value' => $value, 'fromOSM' => 0]
            );
        }

        $stored = \Eloquent\Attribute::where('church_id', $churchId)
            ->whereIn('key', [self::HOLIDAYS_KEY, self::WEEKDAYS_KEY])
            ->pluck('value', 'key');
        $osmValue = self::osmValue(
            $stored[self::HOLIDAYS_KEY] ?? '',
            $stored[self::WEEKDAYS_KEY] ?? ''
        );
        \Eloquent\Attribute::updateOrCreate(
            ['church_id' => $churchId, 'key' => self::OSM_KEY],
            ['value' => $osmValue, 'fromOSM' => 0]
        );

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
