/**
 * #817: a projekt kanonikus csempeforrása, EGY helyen.
 *
 * MIÉRT NEM A DIREKT OSM. Az OpenStreetMap önkéntesekből fenntartott csempeszervere
 * produkciós forgalomra tiltott (Tile Usage Policy), és a blokkolást NEM hibakóddal
 * jelzi: HTTP 200-at ad és egy valódi 256×256-os PNG-t, amire az van írva, hogy
 * „403 — Access blocked". Mérve (2026-08-20):
 *
 *   curl https://a.tile.openstreetmap.org/16/36000/22800.png
 *     -> HTTP 200, 6987 B, fejléc: x-blocked: Access denied
 *   ugyanaz böngésző-UA-val és Refererrel
 *     -> HTTP 200, 1473 B, nincs x-blocked
 *
 * A Leaflet ezt nem tudja megkülönböztetni egy csempétől: kirakja. A térkép ilyenkor
 * nem hibázik, hanem FOLTOS lesz — és se konzolhiba, se `tileerror` nem szól róla.
 * A #376 ezt a döntést a főtérképnél már meghozta, csak nem mindenhol futott át rajta.
 *
 * SIMA ADATOBJEKTUM, nem Leaflet-hívás: a `nearby-map-search.js` szándékosan lustán
 * tölti be a Leafletet, tehát egy Leaflet-függő factory itt betöltési sorrend-csapda
 * lenne. Így mindegy, hol szerepel a `<script>`.
 *
 * PÁRJA AZ ANGULAR-OLDALON: `calendar/src/app/map-tiles.ts`. A kettőt KÉZZEL kell
 * szinkronban tartani — a naptár külön npm-csomag, nem tud innen importálni, és
 * futásidejű globálisra sem támaszkodhat (a szerkesztő-oldal a Leafletet magát is csak
 * menet közben tölti be). Ha itt módosítasz, módosítsd ott is.
 */
window.MISEREND_CSEMPE = {
    /** A `{r}` a retina-változatot kéri; a Leaflet 1.9 magától kezeli. */
    url: 'https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png',
    beallitas: {
        subdomains: 'abcd',
        maxZoom: 19,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors &copy; <a href="https://carto.com/attributions">CARTO</a>'
    }
};
