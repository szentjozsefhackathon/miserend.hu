/*
 * #873: CSRF-token minden böngészőből induló írási kéréshez.
 *
 * A lap <head>-jében ott a token (<meta name="csrf-token">). Innen egyszer kiolvassuk,
 * és a jQuery MINDEN nem-GET ajax-hívásához hozzátesszük X-CSRF-Token fejlécként. Így
 * nem kell végigjárni és külön-külön kiegészíteni a meglévő $.ajax hívásokat, és a
 * később írt hívások is védve lesznek anélkül, hogy bárkinek eszébe kellene jutnia.
 *
 * Csak SAJÁT eredetű kérésre teszünk fejlécet: a tokenünk nem tartozik idegen szerverre.
 * (A jQuery crossDomain jelzője pont ezt mondja meg.)
 *
 * A GET kimarad: az olvasás nem állapotváltás, és a fölös fejléc csak preflightot
 * provokálna ott, ahol eddig nem volt.
 */
(function ($) {
    'use strict';

    if (!$ || !$.ajaxPrefilter) {
        return;
    }

    var meta = document.querySelector('meta[name="csrf-token"]');
    var token = meta ? meta.getAttribute('content') : '';
    if (!token) {
        return;
    }

    // A jQuery-n kívüli (fetch/XHR) hívásoknak is legyen honnan elérni.
    window.miserendCsrfToken = token;

    $.ajaxPrefilter(function (options) {
        var method = (options.type || options.method || 'GET').toUpperCase();
        if (method === 'GET' || method === 'HEAD' || method === 'OPTIONS') {
            return;
        }
        if (options.crossDomain) {
            return;
        }
        options.headers = options.headers || {};
        if (!options.headers['X-CSRF-Token']) {
            options.headers['X-CSRF-Token'] = token;
        }
    });
})(window.jQuery);
