'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../slsmassnotifyserver/views/dashboard_assets.js'), 'utf8');
const origin = 'https://pbx.example.org:8443';
let filter;
const context = {URL, document: {currentScript: {src: origin + '/admin/modules/slsmassnotifyserver/views/dashboard_assets.js?v=fixture'}},
    window: {location: {origin, href: origin + '/admin/config.php?display=index'}, jQuery: {ajaxPrefilter(type, callback) {
        assert.equal(type, 'script'); filter = callback;
    }}}};
vm.runInNewContext(source, context);
assert.equal(typeof filter, 'function');
for (const url of ['https://cdnjs.cloudflare.com/ajax/libs/Chart.js/2.6.0/Chart.bundle.min.js',
    '//cdnjs.cloudflare.com/ajax/libs/Chart.js/2.6.0/Chart.bundle.min.js?cache=1']) {
    const options = {url, async: false, crossDomain: true}; filter(options);
    assert.equal(options.url, origin + '/admin/modules/slsmassnotifyserver/assets/vendor/chartjs/Chart.bundle-2.9.4.min.js');
    assert.equal(options.crossDomain, false); assert.equal(options.cache, true); assert.equal(options.async, false);
}
for (const url of ['http://cdnjs.cloudflare.com/ajax/libs/Chart.js/2.6.0/Chart.bundle.min.js',
    'https://cdnjs.cloudflare.com.evil.example/ajax/libs/Chart.js/2.6.0/Chart.bundle.min.js',
    'https://user:pass@cdnjs.cloudflare.com/ajax/libs/Chart.js/2.6.0/Chart.bundle.min.js',
    'https://cdnjs.cloudflare.com:8443/ajax/libs/Chart.js/2.6.0/Chart.bundle.min.js',
    'https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.0.0/Chart.min.js', '/admin/ajax.php']) {
    const options = {url}; filter(options); assert.deepEqual(options, {url});
}
filter = null; context.document.currentScript.src = 'https://other.example/dashboard_assets.js';
vm.runInNewContext(source, context); assert.equal(filter, null);
context.document.currentScript = null; vm.runInNewContext(source, context);
console.log('Dashboard script routing: exact legacy CDN URL only, forwarded port retained, same-origin asset required, unrelated requests unchanged.');
