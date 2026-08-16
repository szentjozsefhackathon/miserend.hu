/**
 * Nearby churches geolocation widget
 *
 * Expects the following elements in the DOM:
 *   #nearby-trigger  – button that starts geolocation
 *   #nearby-hint     – <small> element showing permission hint
 *   #nearby-status   – container for status messages
 *   #nearby-results  – container for church list results
 */
(function ($) {
	$(function () {
		var $trigger = $('#nearby-trigger');
		var $status  = $('#nearby-status');
		var $results = $('#nearby-results');
		var $hint    = $('#nearby-hint');

		function setStatus(msg, level) {
			var cls = level === 'error' ? 'alert-warning' : (level === 'info' ? 'alert-info' : '');
			$status.html(cls ? '<div class="alert ' + cls + '" role="alert">' + msg + '</div>' : msg);
		}

		function fetchNearby(position) {
			setStatus('Templomokat keresünk a környéken...', 'info');
			$.ajax({
				url: '/api/v4/nearby',
				type: 'POST',
				dataType: 'json',
				data: JSON.stringify({'lat': position.coords.latitude, 'lon': position.coords.longitude}),
				contentType: 'application/json',
				success: function (data) {
					setStatus('', null);
					if (data.error != 0) {
						setStatus('Hiba a keresőben: ' + (data.text || 'ismeretlen hiba'), 'error');
						return;
					}
					if (!data.templomok || data.templomok.length === 0) {
						$results.html('<p><i>Nem találtunk közeli templomokat.</i></p>');
						return;
					}
					var html = '<ul>';
					for (var i = 0; i < data.templomok.length; i++) {
						var t = data.templomok[i];
						var distance = t.tavolsag < 1000
							? (Math.round(t.tavolsag / 100) * 100) + ' m'
							: (Math.round(t.tavolsag / 100) / 10) + ' km';
						// #257: a `nev` és az `ismertnev` az API-ból már az OSM-ből gyűjtött
						// névhalmazból jön (Church::toAPIArray → names[0] / alternative_names[0]),
						// tehát itt nincs mit átállítani.
						html += '<li><a href="/templom/' + t.id + '">' + t.nev + (t.ismertnev ? ' (' + t.ismertnev + ')' : '') + '</a>, ' + t.varos + ' - ' + distance + '</li>';
					}
					html += '</ul>';
					$results.html(html);
				},
				error: function () {
					setStatus('A keresés szerverhibára futott. Próbáld meg újra később.', 'error');
				}
			});
		}

		function onGeoError(err) {
			$trigger.prop('disabled', false);
			var msg;
			switch (err.code) {
				case err.PERMISSION_DENIED:
					msg = 'Helymeghatározás letiltva. Engedélyezd a böngésződ beállításaiban, vagy keress településnévvel.';
					$hint.text('Legközelebb, ha újra ide kattintasz, majd újrakérdezem.');
					break;
				case err.POSITION_UNAVAILABLE:
					msg = 'Nem sikerült meghatározni a helyzeted. Próbáld meg újra, vagy keress településnévvel.';
					break;
				case err.TIMEOUT:
					msg = 'A helymeghatározás túl sokáig tartott. Próbáld meg újra.';
					break;
				default:
					msg = 'Ismeretlen hiba a helymeghatározás során.';
			}
			setStatus(msg, 'error');
		}

		function startGeolocation(silent) {
			if (!navigator.geolocation) {
				if (!silent) {
					setStatus('Sajnos a böngésződ nem támogatja a helyalapú keresést. Próbáld meg másik böngészővel, vagy keress településnévvel.', 'error');
				}
				return;
			}
			if (!silent) {
				setStatus('Helymeghatározás folyamatban...', 'info');
				$trigger.prop('disabled', true);
			}
			navigator.geolocation.getCurrentPosition(
				function (position) {
					$trigger.hide();
					// Re-query permission state to detect "once" vs "always"
					if (navigator.permissions) {
						navigator.permissions.query({name: 'geolocation'}).then(function (result) {
							if (result.state === 'granted') {
								$hint.text('Engedélyezted a böngésződben a helymeghatározást, ezért automatikusan megmutatjuk.');
							} else {
								$hint.text('Egyszeri engedéllyel futtattuk – legközelebb újra megkérdezzük.');
							}
						});
					} else {
						$hint.text('Engedélyezted a helymeghatározást.');
					}
					fetchNearby(position);
				},
				function (err) {
					if (!silent) {
						onGeoError(err);
					}
				},
				{enableHighAccuracy: false, timeout: 10000, maximumAge: 60000}
			);
		}

		// Ha a felhasználó már korábban engedélyezte a geolokációt,
		// automatikusan futtatjuk – nem kell újra rákattintani a gombra.
		if (navigator.permissions) {
			navigator.permissions.query({name: 'geolocation'}).then(function (result) {
				if (result.state === 'granted') {
					$trigger.hide();
					$hint.text('Engedélyezted, ezért automatikusan megmutatjuk a közeli templomokat.');
					startGeolocation(true);
				} else if (result.state === 'denied') {
					$hint.text('Korábban elutasítottad – engedélyezd a böngésző beállításaiban, vagy keress településnévvel.');
				}
				// Ha 'prompt', a gomb és az alapszöveg marad látható
			});
		}

		$trigger.on('click', function () {
			$results.empty();
			startGeolocation(false);
		});
	});
})(jQuery);
