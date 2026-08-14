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

        function drawCircle() {
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
            state.map.fitBounds(state.circle.getBounds(), { padding: [20, 20] });
        }

        /** Külső forrásból (geokódolás, saját helyzet) érkezett pont. */
        function moveToStoredPoint(zoom) {
            var point = currentPoint();
            updateReadout();
            if (!point || !state.map) return;
            state.marker.setLatLng(point);
            state.map.setView(point, zoom || state.map.getZoom());
            drawCircle();
        }

        function buildMap(L) {
            state.map = L.map(canvas, { scrollWheelZoom: false });
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
            }).addTo(state.map);

            var point = currentPoint();
            state.map.setView(point || DEFAULT_CENTER, point ? 14 : DEFAULT_ZOOM);

            state.marker = L.marker(state.map.getCenter(), { draggable: true }).addTo(state.map);
            state.marker.on('dragend', function () {
                var position = state.marker.getLatLng();
                api.setOrigin(position.lat, position.lng, 'marker');
                updateReadout();
                drawCircle();
            });

            // A vezérlők ÁTHELYEZÉSE a térkép fölé.
            leftBox.appendChild(placeControls.querySelector('#hely'));
            if (api.locateButton) leftBox.appendChild(api.locateButton);
            if (api.radius) rightBox.appendChild(api.radius);
            canvas.appendChild(leftBox);
            canvas.appendChild(rightBox);
            isolate(leftBox);
            isolate(rightBox);

            if (api.radius) api.radius.addEventListener('change', drawCircle);

            updateReadout();
            drawCircle();

            /* A térkép-példányt kitesszük a konténerre. A modul állapota egyébként
               closure-ben van (nincs globális szemét), de a térképhez időnként kívülről
               is hozzá kell férni — hibakereséshez és későbbi bővítéshez. */
            canvas._miserendMapSearch = { map: state.map, marker: state.marker };
        }

        function restoreControls() {
            var placeInput = leftBox.querySelector('#hely');
            if (placeInput) placeControls.insertBefore(placeInput, placeControls.firstChild);
            if (api.radius && api.radius.parentNode === rightBox) placeControls.appendChild(api.radius);
            if (api.locateButton && api.locateButton.parentNode === leftBox && api.locateButtonHome) {
                api.locateButtonHome.appendChild(api.locateButton);
            }
        }

        function openMap() {
            toggle.disabled = true;
            loadLeaflet().then(function (L) {
                toggle.disabled = false;
                state.open = true;
                box.hidden = false;
                if (details) details.hidden = true;
                /* A vezérlők átkerültek a térkép fölé, tehát a hozzájuk tartozó felirat
                   és az üresen maradt soruk itt már csak zavarna. */
                if (placeLabel) placeLabel.hidden = true;
                placeControls.hidden = true;
                toggle.innerHTML = '<span aria-hidden="true">🗺</span> Térkép bezárása';

                if (!state.map) {
                    buildMap(L);
                } else {
                    state.map.invalidateSize();
                    moveToStoredPoint();
                }
            }).catch(function (error) {
                toggle.disabled = false;
                api.setStatus(error.message);
            });
        }

        function closeMap() {
            state.open = false;
            placeControls.hidden = false;
            if (placeLabel) placeLabel.hidden = false;
            restoreControls();
            box.hidden = true;
            if (details) details.hidden = false;
            toggle.innerHTML = '<span aria-hidden="true">🗺</span> Térkép alapú keresés';
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
