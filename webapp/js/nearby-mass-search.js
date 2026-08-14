/*
 * #722: a „Hely közelében" és az „Egyéni közeli keresés" egyetlen szűrő lett.
 *
 * A helynév és a koordináta ugyanazt a kérdést teszi föl — „mi van ennek a pontnak a
 * közelében?" —, csak más bemenettel. Ezért a beírt helyet itt fordítjuk koordinátává
 * (ugyanazzal a Nominatimmal, amit a szerver is használ), és beírjuk a szélesség/hosszúság
 * mezőkbe. Így a felhasználó LÁTJA, mire értelmeztük a beírását, és felül is tudja írni.
 *
 * A sugár közös (`tavolsag`): két külön távolság-választó ugyanahhoz a kereséshez csak
 * zavart okozott.
 *
 * JS nélkül is működik: ilyenkor a helynevet a szerver geokódolja beküldés után, ahogy
 * eddig is.
 */
(function () {
    function initialize() {
        var form = document.getElementById('kereses');
        var place = document.getElementById('hely');
        var latitude = document.getElementById('nearby_lat');
        var longitude = document.getElementById('nearby_lon');
        var radius = document.getElementById('tavolsag');
        var status = document.getElementById('location_status');
        if (!form || !latitude || !longitude) return;

        var query = new URLSearchParams(window.location.search);
        latitude.value = query.get('nearby_lat') || latitude.value;
        longitude.value = query.get('nearby_lon') || longitude.value;

        function setStatus(text) { if (status) status.textContent = text; }
        function openCoordinates() {
            var details = document.querySelector('.nearby-search-options');
            if (details) details.open = true;
        }

        /* A koordináta erősebb jelzés, mint a helynév (a szerver is így dönt). Ha a
           felhasználó átírja a helynevet, a korábbi koordináta már NEM hozzá tartozik —
           különben a beírt új hely helyett a régi pont körül keresnénk.

           #733: a pont már nem csak geokódolásból jöhet, hanem a térképen húzott
           jelölőből és a saját helyzetből is. Ezért nem elég a "melyik szöveghez
           tartozik" kérdés: azt tartjuk számon, HONNAN van. Új helynév beírása
           mindhárom forrást érvényteleníti, különben a friss beírás helyett a régi
           pont körül keresnénk. */
        var originSource = null;   // 'geocode' | 'marker' | 'geolocation'
        var geocodedFor = null;
        var originListeners = [];

        function notifyOriginChange() {
            originListeners.forEach(function (fn) { fn(); });
        }

        function setOrigin(lat, lon, source) {
            latitude.value = Number(lat).toFixed(6);
            longitude.value = Number(lon).toFixed(6);
            originSource = source;
            if (source !== 'geocode') geocodedFor = null;
            if (source === 'marker') setStatus('Kiindulópont: a térképen kijelölt pont.');
            notifyOriginChange();
        }

        function clearOrigin() {
            latitude.value = '';
            longitude.value = '';
            originSource = null;
            geocodedFor = null;
            notifyOriginChange();
        }

        function clearStaleCoordinates() {
            if (!place || originSource === null) return;
            /* Geokódolt pontnál csak akkor dobjuk el, ha tényleg MÁS szöveg áll a
               mezőben; a térképről vagy a saját helyzetből jött pontot viszont bármely
               új beírás felülírja, hiszen azok nem ehhez a szöveghez tartoznak. */
            if (originSource === 'geocode' && place.value.trim() === geocodedFor) return;
            clearOrigin();
            setStatus('');
        }

        function geocodePlace() {
            if (!place) return;
            var value = place.value.trim();
            if (value.length < 3 || value === geocodedFor) return;

            setStatus('Hely keresése…');
            fetch('/index.php?q=ajax/Geocode&hely=' + encodeURIComponent(value), {
                headers: { 'Accept': 'application/json' }
            }).then(function (response) {
                return response.ok ? response.json() : null;
            }).then(function (data) {
                if (!data || !data.found) {
                    setStatus(data && data.message ? data.message : 'Ezt a helyet nem találtuk meg.');
                    return;
                }
                geocodedFor = value;
                setOrigin(data.lat, data.lon, 'geocode');
                setStatus('Kiindulópont: ' + data.name);
            }).catch(function () {
                /* Nem baj: beküldéskor a szerver úgyis geokódol. Csak ne áltassuk a
                   felhasználót egy régi koordinátával. */
                setStatus('');
            });
        }

        if (place) {
            place.addEventListener('input', clearStaleCoordinates);
            place.addEventListener('change', geocodePlace);
            place.addEventListener('blur', geocodePlace);
        }

        function pad(value) { return String(value).padStart(2, '0'); }
        function setDateTime(prefix, date) {
            var dateInput = document.getElementById(prefix + '_date');
            var timeInput = document.getElementById(prefix + '_time');
            if (dateInput) dateInput.value = date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate());
            if (timeInput) timeInput.value = pad(date.getHours()) + ':' + pad(date.getMinutes());
            var dateDisplay = document.getElementById(prefix + '_date_display');
            var timeDisplay = document.getElementById(prefix + '_time_display');
            if (dateDisplay && dateInput) dateDisplay.textContent = dateInput.value.replace(/-/g, '.');
            if (timeDisplay && timeInput) timeDisplay.textContent = timeInput.value;
        }

        function setNextTwoHours() {
            var start = new Date();
            var end = new Date(start.getTime() + 2 * 60 * 60 * 1000);
            setDateTime('start', start);
            setDateTime('end', end);
        }

        function submitMassSearch() {
            var action = document.createElement('input');
            action.type = 'hidden';
            action.name = 'q';
            action.value = 'SearchResultsMasses';
            form.appendChild(action);
            form.submit();
        }

        var nextTwoHours = document.getElementById('next_two_hours');
        if (nextTwoHours) nextTwoHours.addEventListener('click', function () {
            clearOrigin();
            if (place) place.value = '';
            if (radius) radius.value = '0';
            setNextTwoHours();
            submitMassSearch();
        });

        var walking = document.getElementById('walking_masses');
        function requestLocation(onSuccess, trigger) {
            if (!navigator.geolocation) {
                setStatus('A böngésző nem támogatja a helymeghatározást.');
                return;
            }
            trigger.disabled = true;
            setStatus('Helyzet meghatározása…');
            navigator.geolocation.getCurrentPosition(function (position) {
                /* A saját helyzet felülírja a beírt helynevet, tehát az már nem érvényes
                   kiindulópont — különben a szerver azt geokódolná vissza. */
                if (place) place.value = '';
                setOrigin(position.coords.latitude, position.coords.longitude, 'geolocation');
                trigger.disabled = false;
                onSuccess();
            }, function () {
                setStatus('A helyzet nem érhető el. Engedélyezd a helymeghatározást, majd próbáld újra.');
                trigger.disabled = false;
            }, { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 });
        }

        if (walking) walking.addEventListener('click', function () {
            requestLocation(function () {
                if (radius) radius.value = '3';
                setNextTwoHours();
                setStatus('3 km-es légvonalbeli körben, a következő két órában keresek.');
                submitMassSearch();
            }, walking);
        });

        var useCurrentLocation = document.getElementById('use_current_location');
        if (useCurrentLocation) useCurrentLocation.addEventListener('click', function () {
            requestLocation(function () {
                setStatus('A kiindulópontot beállítottam. Válassz távolságot, majd indíts keresést.');
                /* Térkép-módban a jelölő már odaugrott, ott nincs mit lenyitni. */
                if (!document.getElementById('map_search_box') ||
                    document.getElementById('map_search_box').hidden) {
                    openCoordinates();
                }
            }, useCurrentLocation);
        });

        /* #733: a térkép külön fájlban él, mert a Leafletet csak megnyitáskor töltjük be.
           Az állapot viszont KÖZÖS — a kiindulópontot egyetlen helyen tartjuk nyilván,
           különben a térkép és az űrlap elcsúszna egymástól. */
        if (window.MiserendMapSearch) {
            window.MiserendMapSearch.install({
                place: place,
                radius: radius,
                latitude: latitude,
                longitude: longitude,
                locateButton: useCurrentLocation,
                locateButtonHome: useCurrentLocation ? useCurrentLocation.parentNode : null,
                setStatus: setStatus,
                setOrigin: setOrigin,
                clearOrigin: clearOrigin,
                onOriginChange: function (fn) { originListeners.push(fn); }
            });
        }
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize);
    else initialize();
}());
