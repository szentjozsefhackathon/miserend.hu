<?php

/**
 * #393: egyetlen hely, ami tudja, mi számít egésznek, tizedestörtnek, szövegnek,
 * dátumnak — és teljesíti-e a megkötéseit.
 *
 * Eddig ez a tudás kétszer élt: az \Api validate* metódusaiban (JSON-törzsből érkező
 * érték + séma) és a \Request metódusaiban ($_GET/$_POST-ból olvasott érték). A kettő
 * el is csúszott egymástól: az API dátumellenőrzése puszta reguláris kifejezés volt,
 * ezért elfogadta a 2023-02-29-et, a 2023-02-31-et és a 2026-04-31-et is, miközben a
 * \Request ugyanezeket helyesen visszautasította.
 *
 * A metódusok szándékosan NEM dobnak, hanem a hibaüzenet-töredéket adják vissza (vagy
 * null-t, ha rendben van). Így mindkét hívó a saját, megszokott szövegével dobhat:
 * az \Api "Field 'x' should be an integer.", a \Request "Required 'x' is not an
 * Integer." — a kliensek felé látható üzenetek nem változnak.
 *
 * Se szuperglobális, se adatbázis: tiszta, közvetlenül unit-tesztelhető.
 */
class Validate {

    /** @return string|null null = rendben */
    public static function integerError($value, array $rules = []): ?string {
        if (!is_numeric($value) || intval($value) != $value) {
            return 'should be an integer.';
        }
        return self::rangeError($value, $rules);
    }

    /** @return string|null null = rendben */
    public static function floatError($value, array $rules = []): ?string {
        if (!is_numeric($value) || floatval($value) != $value) {
            return 'should be a float.';
        }
        return self::rangeError($value, $rules);
    }

    /** @return string|null null = rendben */
    public static function stringError($value, array $rules = []): ?string {
        if (!is_string($value)) {
            return 'should be a string.';
        }
        if (isset($rules['minLength']) && strlen($value) < $rules['minLength']) {
            return 'should be at least ' . $rules['minLength'] . ' characters long.';
        }
        if (isset($rules['maxLength']) && strlen($value) > $rules['maxLength']) {
            return 'should be at most ' . $rules['maxLength'] . ' characters long.';
        }
        if (isset($rules['pattern']) && !preg_match('/' . $rules['pattern'] . '/', $value)) {
            return 'does not match the required pattern.';
        }
        return null;
    }

    /** @return string|null null = rendben */
    public static function booleanError($value): ?string {
        return is_bool($value) ? null : 'should be a boolean.';
    }

    /** @return string|null null = rendben */
    public static function dateError($value): ?string {
        return self::isDate($value) ? null : 'should be a date (yyyy-mm-dd).';
    }

    /**
     * Naptár-tudatos dátumellenőrzés: nem elég a 4-2-2 alak, a napnak léteznie is kell.
     * A DateTime::createFromFormat magától "átfordítja" a túlcsorduló napot (2023-02-31
     * → 2023-03-03), ezért vetjük össze a visszaformázott értéket az eredetivel.
     */
    public static function isDate($value): bool {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }
        $date = \DateTime::createFromFormat('Y-m-d', $value);
        return $date && $date->format('Y-m-d') === $value;
    }

    private static function rangeError($value, array $rules): ?string {
        if (isset($rules['minimum']) && $value < $rules['minimum']) {
            return 'should be at least ' . $rules['minimum'] . '.';
        }
        if (isset($rules['maximum']) && $value > $rules['maximum']) {
            return 'should be at most ' . $rules['maximum'] . '.';
        }
        return null;
    }
}
