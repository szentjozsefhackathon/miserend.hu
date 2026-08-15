(function () {
    function initialize() {
        var form = document.getElementById('kereses');
        var latitude = document.getElementById('nearby_lat');
        var longitude = document.getElementById('nearby_lon');
        var radius = document.getElementById('nearby_radius');
        var status = document.getElementById('location_status');
        if (!form || !latitude || !longitude) return;

        var query = new URLSearchParams(window.location.search);
        latitude.value = query.get('nearby_lat') || latitude.value;
        longitude.value = query.get('nearby_lon') || longitude.value;

        function pad(value) { return String(value).padStart(2, '0'); }
        function setDateTime(prefix, date) {
            var dateInput = document.getElementById(prefix + '_date');
            var timeInput = document.getElementById(prefix + '_time');
            if (dateInput) dateInput.value = date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate());
            if (timeInput) timeInput.value = pad(date.getHours()) + ':' + pad(date.getMinutes());
            var dateDisplay = document.getElementById(prefix + '_date_display');
            var timeDisplay = document.getElementById(prefix + '_time_display');
            if (dateDisplay) dateDisplay.textContent = dateInput.value.replace(/-/g, '.');
            if (timeDisplay) timeDisplay.textContent = timeInput.value;
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
            radius.value = '';
            setNextTwoHours();
            submitMassSearch();
        });

        var walking = document.getElementById('walking_masses');
        function requestLocation(onSuccess, trigger) {
            if (!navigator.geolocation) {
                status.textContent = 'A böngésző nem támogatja a helymeghatározást.';
                return;
            }
            trigger.disabled = true;
            status.textContent = 'Helyzet meghatározása…';
            navigator.geolocation.getCurrentPosition(function (position) {
                latitude.value = position.coords.latitude.toFixed(6);
                longitude.value = position.coords.longitude.toFixed(6);
                trigger.disabled = false;
                onSuccess();
            }, function () {
                status.textContent = 'A helyzet nem érhető el. Engedélyezd a helymeghatározást, majd próbáld újra.';
                trigger.disabled = false;
            }, { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 });
        }

        if (walking) walking.addEventListener('click', function () {
            requestLocation(function () {
                radius.value = '3';
                setNextTwoHours();
                status.textContent = '3 km-es légvonalbeli körben, a következő két órában keresek.';
                submitMassSearch();
            }, walking);
        });

        var useCurrentLocation = document.getElementById('use_current_location');
        if (useCurrentLocation) useCurrentLocation.addEventListener('click', function () {
            requestLocation(function () {
                status.textContent = 'A kiindulópontot beállítottam. Válassz sugarat, majd indíts eseménykeresést.';
                document.querySelector('.nearby-search-options').open = true;
            }, useCurrentLocation);
        });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize);
    else initialize();
}());
