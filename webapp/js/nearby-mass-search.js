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
           különben a beírt új hely helyett a régi pont körül keresnénk. */
        var geocodedFor = null;
        function clearStaleCoordinates() {
            if (geocodedFor !== null && place && place.value.trim() !== geocodedFor) {
                latitude.value = '';
                longitude.value = '';
                geocodedFor = null;
                setStatus('');
            }
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
                latitude.value = data.lat;
                longitude.value = data.lon;
                geocodedFor = value;
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
            latitude.value = '';
            longitude.value = '';
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
                latitude.value = position.coords.latitude.toFixed(6);
                longitude.value = position.coords.longitude.toFixed(6);
                /* A saját helyzet felülírja a beírt helynevet, tehát az már nem érvényes
                   kiindulópont — különben a szerver azt geokódolná vissza. */
                if (place) place.value = '';
                geocodedFor = null;
                trigger.disabled = false;
                onSuccess();
            }, function () {
                setStatus('A helyzet nem érhető el. Engedélyezd a helymeghatározást, majd próbáld újra.');
                trigger.disabled = false;
            }, { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 });
        }

        if (walking) walking.addEventListener('click', function () {
            requestLocation(function () {
                if (radius) radius.value = '2';
                setNextTwoHours();
                setStatus('2 km-es légvonalbeli körben, a következő két órában keresek.');
                submitMassSearch();
            }, walking);
        });

        var useCurrentLocation = document.getElementById('use_current_location');
        if (useCurrentLocation) useCurrentLocation.addEventListener('click', function () {
            requestLocation(function () {
                setStatus('A kiindulópontot beállítottam. Válassz távolságot, majd indíts keresést.');
                openCoordinates();
            }, useCurrentLocation);
        });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize);
    else initialize();
}());
