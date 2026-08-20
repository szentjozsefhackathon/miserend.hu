/*
 * #733: térkép alapú kiindulópont-választás a kereséshez.
 *
 * A koordináta-mezők önmagukban nem mondtak semmit: a felhasználó nem látta, hova
 * fog keresni, és a beírt helynévről sem tudta eldönteni, hogy jó helyet találtunk-e.
 * A térkép ezt teszi láthatóvá — a jelölő ott áll, ahonnan keresünk, a kör pedig
 * megmutatja, meddig.
 *
 * Két fontos döntés:
 *
 *  - A `hely` és a `tavolsag` vezérlőt ÁTHELYEZZÜK a térkép fölé, nem lemásoljuk.
 *    Másolással két, egymásnak ellentmondó érték keletkezne, és az űrlap-beküldés is
 *    kiszámíthatatlan lenne. Bezáráskor visszakerülnek a helyükre.
 *
 *  - A Leafletet CSAK a megnyitáskor töltjük be. A főoldal a leglátogatottabb lap;
 *    nem terhelhetjük ~150 KB-tal azért, mert a lehetőség létezik. (Ugyanez az elv,
 *    mint a church-map.js-nél.)
 *
 * JS nélkül minden marad a régiben: a kapcsoló meg sem jelenik, a koordináta-blokk
 * kézzel kitölthető, a helynevet pedig beküldés után a szerver geokódolja.
 */
(function () {
    var LEAFLET_CSS = '/node_modules/leaflet/dist/leaflet.css';
    var LEAFLET_JS = '/node_modules/leaflet/dist/leaflet.js';
    var DEFAULT_CENTER = [47.4979, 19.0402]; // Budapest — csak amíg nincs valódi pont
    var DEFAULT_ZOOM = 12;
    var DEFAULT_RADIUS_KM = 3;   // a „gyalogtávolság" gyorskeresés értéke

    var state = {
        map: null,
        marker: null,
        circle: null,
        loading: null,
        open: false
    };

    function loadLeaflet() {
        if (window.L) return Promise.resolve(window.L);
        if (state.loading) return state.loading;

        state.loading = new Promise(function (resolve, reject) {
            if (!document.querySelector('link[href="' + LEAFLET_CSS + '"]')) {
                var css = document.createElement('link');
                css.rel = 'stylesheet';
                css.href = LEAFLET_CSS;
                document.head.appendChild(css);
            }
            var script = document.createElement('script');
            script.src = LEAFLET_JS;
            script.onload = function () { resolve(window.L); };
            script.onerror = function () { reject(new Error('A térképet nem sikerült betölteni.')); };
            document.body.appendChild(script);
        });

        return state.loading;
    }

    /** A lebegő dobozok fölött ne a térkép mozogjon. */
    function isolate(element) {
        if (!window.L || !window.L.DomEvent) return;
        window.L.DomEvent.disableClickPropagation(element);
        window.L.DomEvent.disableScrollPropagation(element);
    }

    function overlayBox(position) {
        var box = document.createElement('div');
        box.className = 'map-search-overlay map-search-overlay-' + position;
        return box;
    }

    function injectStyles() {
        if (document.getElementById('map-search-styles')) return;
        var style = document.createElement('style');
        style.id = 'map-search-styles';
        style.textContent = [
            '#map_search_canvas { position: relative; }',
            '.map-search-overlay { position: absolute; z-index: 1000; background: #fff;',
            '  padding: 6px; border-radius: 6px; box-shadow: 0 1px 5px rgba(0,0,0,.4); }',
            '.map-search-overlay-left { top: 10px; left: 10px; right: 10px; max-width: 22em; }',
            '.map-search-overlay-right { top: 10px; right: 10px; }',
            /* Szűk kijelzőn a kettő egymásra csúszna, ezért ott egymás alá kerülnek. */
            '@media (max-width: 576px) {',
            '  .map-search-overlay-left { right: 10px; max-width: none; }',
            '  .map-search-overlay-right { top: auto; bottom: 10px; right: 10px; }',
            '}',
            '.map-search-toggle { background: none; border: 0; padding: 0; color: #6f3b8f;',
            '  cursor: pointer; text-decoration: underline; }'
        ].join('\n');
        document.head.appendChild(style);
    }

    /**
     * @param {object} api a nearby-mass-search.js által átadott közös állapot:
     *        { place, radius, latitude, longitude, setOrigin, onOriginChange, requestLocation }
     */
    function install(api) {
        var slot = document.getElementById('map_search_toggle_slot');
        var box = document.getElementById('map_search_box');
        var canvas = document.getElementById('map_search_canvas');
        var readout = document.getElementById('map_search_readout');
        var details = document.querySelector('.nearby-search-options');
        var placeGroup = document.getElementById('place_field_group');
        var placeControls = document.getElementById('place_controls');
        var placeLabel = placeGroup ? placeGroup.querySelector('label[for="hely"]') : null;
        if (!slot || !box || !canvas || !placeControls) return;

        injectStyles();

        var toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.className = 'map-search-toggle';
        toggle.id = 'map_search_toggle';
        toggle.innerHTML = '<span aria-hidden="true">🗺</span> Térkép alapú keresés';
        slot.appendChild(toggle);

        /*
         * Alapból CSAK a térkép-kapcsoló látszik.
         *
         * Az „Egyéni közeli keresés" felirat, a helynév+sugár sor és a „Koordináta
         * megadása" blokk együtt három, egymást átfedő módot kínált ugyanarra a
         * kérdésre — ettől lett a sáv félrevezető. A vezérlők megmaradnak (az űrlap
         * ugyanazt küldi), csak nem tolakodnak: a térképen kapnak helyet.
         *
         * JS nélkül minden marad a régiben: ez a rejtés is innen, JS-ből történik.
         */
        if (placeGroup) placeGroup.hidden = true;

        var leftBox = overlayBox('left');
        var rightBox = overlayBox('right');

        function updateReadout() {
            if (!readout) return;
            var lat = api.latitude.value;
            var lon = api.longitude.value;
            readout.textContent = (lat && lon)
                ? Number(lat).toFixed(5) + ', ' + Number(lon).toFixed(5)
                : 'nincs megadva';
        }

        function currentPoint() {
            var lat = parseFloat(api.latitude.value);
            var lon = parseFloat(api.longitude.value);
            return (isFinite(lat) && isFinite(lon)) ? [lat, lon] : null;
        }

        /**
         * @param {boolean} illesszen igazítsa-e a nézetet a körhöz
         *
         * Az illesztés SZÁNDÉKOSAN nem történik minden rajzoláskor. A kört a jelölő
         * mozgatása, a geokódolás és a sugár állítása is újrarajzolja; ha mindegyik
         * nagyítana is, a nézet folyamatosan ugrálna, és a beállítások egymást írnák
         * felül. Illeszteni ott van értelme, ahol a felhasználó épp a TÁVOLSÁGRÓL
         * mond valamit.
         */
        function drawCircle(illesszen) {
            if (!state.map) return;
            var km = parseFloat(api.radius ? api.radius.value : '0');
            var point = state.marker ? state.marker.getLatLng() : null;

            if (state.circle) {
                state.map.removeLayer(state.circle);
                state.circle = null;
            }
            if (!point || !isFinite(km) || km <= 0) return;

            state.circle = window.L.circle(point, {
                radius: km * 1000,
                color: '#6f3b8f',
                weight: 1,
                fillOpacity: 0.08
            }).addTo(state.map);

            /*
             * A kör lássék EGÉSZBEN, különben a felhasználó csak egy ívet lát a szélén,
             * és nem derül ki, mekkora területről beszélünk.
             *
             * A `fitBounds()` itt nem vált be: a doboz széles és alacsony (825×360), és
             * a nagyítás makacsul 14 maradt, pedig a `getBoundsZoom()` 12-t mond. Ezért
             * magunk számoljuk ki a nagyítást, és egy lépésben állítjuk be a nézetet.
             */
            if (!illesszen) return;

            var illoZoom = state.map.getBoundsZoom(state.circle.getBounds(), false, [20, 20]);
            state.map.setView(point, illoZoom, { animate: false });
        }

        /**
         * A tárolt kiindulópontra állunk (geokódolás, saját helyzet, jelölő).
         *
         * A nagyítást a KÖR határozza meg, nem egy fix érték: ha van sugár, a nézet
         * mindig akkora, hogy a kör egészben látszódjon. Korábban ez fix 14 volt, és a
         * jelölő megmozgatása után a 10 km-es kör négyszer akkora lett, mint a doboz —
         * a felhasználó egy ívet sem látott belőle. Rögzített sugárnál az illő nagyítás
         * állandó, tehát húzás közben a nézet nem ugrál, csak követ.
         */
        function moveToStoredPoint(zoom) {
            var point = currentPoint();
            updateReadout();
            if (!point || !state.map) return;
            state.marker.setLatLng(point);
            state.map.setView(point, zoom || state.map.getZoom());
            drawCircle(true);
        }

        function buildMap(L) {
            state.map = L.map(canvas, { scrollWheelZoom: false });
            // #817: a kanonikus csempeforrás (/js/map-tiles.js). A direkt OSM-szerver
            // produkciós forgalomra tiltott, és a blokkolt csempét HTTP 200-zal, valódi
            // PNG-ként adja vissza — a Leaflet kirakja, a térkép foltos lesz.
            L.tileLayer(window.MISEREND_CSEMPE.url, window.MISEREND_CSEMPE.beallitas).addTo(state.map);

            var point = currentPoint();
            state.map.setView(point || DEFAULT_CENTER, point ? 14 : DEFAULT_ZOOM);

            state.marker = L.marker(state.map.getCenter(), { draggable: true }).addTo(state.map);
            state.marker.on('dragend', function () {
                var position = state.marker.getLatLng();
                api.setOrigin(position.lat, position.lng, 'marker');
                updateReadout();
                drawCircle(true);
            });

            if (api.radius) api.radius.addEventListener('change', function () { drawCircle(true); });

            updateReadout();

            /* A térkép-példányt kitesszük a konténerre. A modul állapota egyébként
               closure-ben van (nincs globális szemét), de a térképhez időnként kívülről
               is hozzá kell férni — hibakereséshez és későbbi bővítéshez. */
            canvas._miserendMapSearch = { map: state.map, marker: state.marker };
        }

        /*
         * A vezérlők áthelyezése a térkép fölé.
         *
         * Ez KORÁBBAN a `buildMap()`-ben volt, ami csak az ELSŐ nyitáskor fut le. Aki
         * bezárta és újranyitotta a térképet, annak a kereső- és a sugármező szinte
         * teljesen eltűnt: a bezárás visszatette őket a rejtett sorba, az újranyitás
         * pedig már nem vitte vissza a lebegő dobozokba. Ezért nyitásonként futtatjuk.
         */
        function moveControlsToMap() {
            var placeInput = placeControls.querySelector('#hely') || leftBox.querySelector('#hely');
            if (placeInput) leftBox.appendChild(placeInput);
            if (api.locateButton) leftBox.appendChild(api.locateButton);
            if (api.radius) rightBox.appendChild(api.radius);
            if (leftBox.parentNode !== canvas) canvas.appendChild(leftBox);
            if (rightBox.parentNode !== canvas) canvas.appendChild(rightBox);
            isolate(leftBox);
            isolate(rightBox);
        }

        function restoreControls() {
            var placeInput = leftBox.querySelector('#hely');
            if (placeInput) placeControls.insertBefore(placeInput, placeControls.firstChild);
            if (api.radius && api.radius.parentNode === rightBox) placeControls.appendChild(api.radius);
            if (api.locateButton && api.locateButton.parentNode === leftBox && api.locateButtonHome) {
                api.locateButtonHome.appendChild(api.locateButton);
            }
        }

        /*
         * Nyitáskor a kiindulópont LEGYEN kitöltve.
         *
         * Enélkül aki kinyitotta a térképet és rögtön a keresésre nyomott, üres
         * koordinátával indult — a jelölő ott állt a képernyőn, a mezők mégis üresek
         * voltak. Ami a térképen látszik, az legyen a keresés kiindulópontja is.
         * A sugár ugyanígy: alapból a „gyalogtávolság" 3 km.
         */
        function seedOriginFromMap() {
            if (!state.marker) return;
            if (!currentPoint()) {
                /*
                 * Nincs tárolt kiindulópont — tehát vagy most nyitjuk először, vagy a
                 * bezárás törölte. Ilyenkor a jelölő is menjen vissza az alaphelyzetbe:
                 * enélkül a legutóbbi állás (pl. Bécs) csendben visszajönne, pedig épp
                 * azért zártuk be, hogy ne maradjon meg.
                 */
                state.marker.setLatLng(DEFAULT_CENTER);
                state.map.setView(DEFAULT_CENTER, DEFAULT_ZOOM);
                var kozep = state.marker.getLatLng();
                api.setOrigin(kozep.lat, kozep.lng, 'marker');
            }
            if (api.radius && (api.radius.value === '' || api.radius.value === '0')) {
                api.radius.value = String(DEFAULT_RADIUS_KM);
            }
            updateReadout();
            drawCircle(true);
        }

        function openMap() {
            toggle.disabled = true;
            loadLeaflet().then(function (L) {
                toggle.disabled = false;
                state.open = true;
                // A csoport hordozza a térkép-dobozt és az állapotsort, ezért nyitáskor
                // láthatóvá kell tenni — a benne lévő felirat és sor viszont rejtve marad.
                if (placeGroup) placeGroup.hidden = false;
                if (placeLabel) placeLabel.hidden = true;
                placeControls.hidden = true;
                if (details) details.hidden = true;
                box.hidden = false;
                toggle.innerHTML = '<span aria-hidden="true">🗺</span> Térkép bezárása';

                if (!state.map) {
                    buildMap(L);
                }

                // Minden nyitáskor, nem csak az elsőnél — l. moveControlsToMap().
                moveControlsToMap();
                state.map.invalidateSize();
                moveToStoredPoint();
                seedOriginFromMap();
            }).catch(function (error) {
                toggle.disabled = false;
                api.setStatus(error.message);
            });
        }

        function closeMap() {
            state.open = false;
            restoreControls();
            box.hidden = true;
            if (placeGroup) placeGroup.hidden = true;
            toggle.innerHTML = '<span aria-hidden="true">🗺</span> Térkép alapú keresés';

            /*
             * Bezárás = a térképen beállított kiindulópont visszavonása. Enélkül
             * megmaradna, hogy valaki egyszer Bécset állította középpontnak, és a
             * következő keresés csendben oda szólna — úgy, hogy közben egyetlen
             * látható mező sem mutatja.
             */
            api.clearOrigin();
            if (api.place) api.place.value = '';
            if (api.radius) api.radius.value = '0';
            api.setStatus('');
            updateReadout();
        }

        toggle.addEventListener('click', function () {
            if (state.open) closeMap(); else openMap();
        });

        /* Ha máshonnan (geokódolás, saját helyzet) változott a kiindulópont, a jelölő
           ugorjon oda — enélkül a felhasználó nem látná, hogy megtaláltuk-e a helyet. */
        api.onOriginChange(function () {
            if (state.open && state.map) moveToStoredPoint(14);
            else updateReadout();
        });
    }

    window.MiserendMapSearch = { install: install };
}());
