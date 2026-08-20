/**
 * #817: a projekt kanonikus csempeforrása.
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
 * A Leaflet ezért nem tud hibát észlelni: a `tileerror` sosem sül el, a blokkoló képet
 * szabályos csempeként rakja ki. Pontosan ez borazslo tünete a #817-ben: a doboz tele
 * van, de „foltos" — hol térkép, hol figyelmeztető négyzet.
 *
 * A projekt ezt a döntést a #376-ban már meghozta (l. `webapp/js/church-map.js`), a
 * #816 PR-je viszont nem vette át. A #816 jegy szövege külön kérte, hogy „ugyanaz a
 * csempeszolgáltató és ugyanaz a licenc-feltüntetés menjen".
 *
 * PÁRJA A WEBAPP-OLDALON: `webapp/js/map-tiles.js`. A kettőt KÉZZEL kell szinkronban
 * tartani — az Angular külön npm-csomag, nem tud a `webapp/js`-ből importálni, és
 * futásidejű globálisra sem támaszkodhat (a szerkesztő-oldal a Leafletet magát is
 * csak menet közben tölti be). Ha itt módosítasz, módosítsd ott is.
 */

/** A `{r}` a retina-változatot kéri; a Leaflet 1.9 magától kezeli. */
export const CSEMPE_URL = 'https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png';

export const CSEMPE_BEALLITAS = {
  subdomains: 'abcd',
  maxZoom: 19,
  attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors &copy; <a href="https://carto.com/attributions">CARTO</a>',
};
