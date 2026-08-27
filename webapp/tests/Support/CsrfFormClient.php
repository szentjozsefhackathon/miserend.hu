<?php

/**
 * #873: űrlap-beküldő teszt-ügyfél, ami úgy viselkedik, mint egy böngésző.
 *
 * A CSRF-védelem bevezetése után egy nyers POST-tól már nem történik semmi — és ez így
 * helyes. A böngésző viszont nem nyers POST-ot küld: előbb betölti az űrlap oldalát,
 * megkapja a `csrf` sütit, kiolvassa a lapból a tokent, és a beküldéskor MINDKETTŐT
 * mellékeli. A teszteknek pontosan ezt kell utánozniuk, különben nem a mentést mérnék,
 * hanem az őrt.
 *
 * A sütiket kérésről kérésre visszük tovább, mert a token a `csrf` süti értékéből
 * számolódik: ha elhagynánk, minden beküldés más tokent várna.
 */
final class CsrfFormClient {

    private string $baseUrl;

    /** name => value */
    private array $sutik = [];

    /** Az utolsó válasz státuszsora — hibakereséshez. */
    public string $utolsoStatusz = '';

    public function __construct(?string $baseUrl = null) {
        $this->baseUrl = rtrim($baseUrl ?: (getenv('PANTHER_EXTERNAL_BASE_URI') ?: 'http://127.0.0.1:8000'), '/');
    }

    /** Bejelentkezés; a kapott sütiket (token, csrf) megjegyezzük. */
    public function login(string $nev, string $jelszo): bool {
        $this->post('/', ['login' => $nev, 'passw' => $jelszo, 'logout' => 'false'], false);
        return isset($this->sutik['token']);
    }

    public function beVagyunkLepve(): bool {
        return isset($this->sutik['token']);
    }

    public function get(string $path): string {
        return $this->keres($path, null);
    }

    /**
     * Beküldés. Alapból előbb LEKÉRI az űrlap oldalát ($tokenLapja vagy maga a $path),
     * hogy legyen érvényes tokenünk — pont úgy, ahogy a felhasználó is látja az űrlapot.
     */
    public function post(string $path, array $mezok, bool $tokennel = true, ?string $tokenLapja = null): string {
        if ($tokennel) {
            $mezok['csrf_token'] = $this->token($tokenLapja ?? $path);
        }
        return $this->keres($path, http_build_query($mezok));
    }

    /** A lapon lévő token. Mellékhatás: ha még nincs `csrf` sütink, itt keletkezik. */
    public function token(string $path): string {
        $html = $this->get($path);
        if (preg_match('/<meta name="csrf-token" content="([0-9a-f]{64})">/', $html, $m)) {
            return $m[1];
        }
        // Az oldal nem layout.twig-et használ (pl. layout_empty) — ilyenkor a rejtett mező segít.
        if (preg_match('/name="csrf_token" value="([0-9a-f]{64})"/', $html, $m)) {
            return $m[1];
        }
        throw new \RuntimeException("Nincs CSRF-token ezen a lapon: $path");
    }

    private function keres(string $path, ?string $torzs): string {
        $fejlec = '';
        if ($this->sutik) {
            $parok = [];
            foreach ($this->sutik as $nev => $ertek) {
                $parok[] = $nev . '=' . $ertek;
            }
            $fejlec .= 'Cookie: ' . implode('; ', $parok) . "\r\n";
        }

        $opciok = ['timeout' => 60, 'ignore_errors' => true];
        if ($torzs === null) {
            $opciok['method'] = 'GET';
        } else {
            $opciok['method'] = 'POST';
            $fejlec .= "Content-Type: application/x-www-form-urlencoded\r\n";
            $opciok['content'] = $torzs;
        }
        $opciok['header'] = $fejlec;

        $valasz = @file_get_contents($this->baseUrl . $path, false, stream_context_create(['http' => $opciok]));
        $this->sutiketEltesz($http_response_header ?? []);
        $this->utolsoStatusz = ($http_response_header ?? [''])[0];

        return $valasz === false ? '' : $valasz;
    }

    private function sutiketEltesz(array $fejlecek): void {
        foreach ($fejlecek as $sor) {
            if (stripos($sor, 'Set-Cookie:') !== 0) {
                continue;
            }
            $elso = trim(explode(';', substr($sor, 11))[0]);
            if (!str_contains($elso, '=')) {
                continue;
            }
            [$nev, $ertek] = explode('=', $elso, 2);
            $nev = trim($nev);
            if ($ertek === '' || $ertek === '""') {
                unset($this->sutik[$nev]);   // törlő süti (pl. kilépés)
            } else {
                $this->sutik[$nev] = $ertek;
            }
        }
    }
}
