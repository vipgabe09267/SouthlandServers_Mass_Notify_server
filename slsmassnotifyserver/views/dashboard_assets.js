(function () {
    'use strict';
    const script = document.currentScript;
    const $ = window.jQuery;
    if (!script || !$ || !$.ajaxPrefilter) return;
    const localChart = new URL('../assets/vendor/chartjs/Chart.bundle-2.9.4.min.js', script.src);
    if (localChart.origin !== window.location.origin) return;
    $.ajaxPrefilter('script', function (options) {
        let url;
        try { url = new URL(options.url, window.location.href); } catch (_) { return; }
        if (url.origin !== 'https://cdnjs.cloudflare.com' || url.username || url.password ||
            url.pathname !== '/ajax/libs/Chart.js/2.6.0/Chart.bundle.min.js') return;
        // Sysadmin's disk widget uses jQuery's script loader.
        options.url = localChart.href;
        options.crossDomain = false;
        options.cache = true;
    });
}());
