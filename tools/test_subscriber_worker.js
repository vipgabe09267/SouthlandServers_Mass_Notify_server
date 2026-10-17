'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const fixtureTime = Date.now();
class FixtureDate extends Date { static now() { return fixtureTime; } }
const handlers = new Map(), stored = new Map(), displayed = [], opened = [];
let disappearOnMatch = '';
const ledger = { async match(request) { if (request.url === disappearOnMatch) { disappearOnMatch = ''; stored.delete(request.url); return undefined; } return stored.get(request.url)?.clone(); }, async put(request, response) { stored.set(request.url, response.clone()); }, async keys() { return [...stored.keys()].map(url => new Request(url)); }, async delete(request) { return stored.delete(request.url); } };
const self = { location: { origin: 'https://pbx.example.test' }, addEventListener(type, handler) { handlers.set(type, handler); }, registration: { async showNotification(title, options) { displayed.push({ title, options }); } } };
vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../slsmassnotifyserver/portal/subscriber-worker.js'), 'utf8'), { self, caches: { async open(name) { assert.equal(name, 'sls-browser-push-v1'); return ledger; } }, clients: { async openWindow(url) { opened.push(url); } }, Date: FixtureDate, Request, Response, URL, Number });
const now = Math.floor(fixtureTime / 1000), event = { event_id: 'fixture_1', title: 'Fixture', message: 'Message', is_test: true, expires_at: now + 300 };
async function push(value) { let work; handlers.get('push')({ data: { json() { return value; } }, waitUntil(promise) { work = promise; } }); await work; }
(async () => {
  await push(event); await push(event); assert.equal(displayed.length, 1); assert.equal(displayed[0].title, 'SLS · TEST · Fixture');
  const show = self.registration.showNotification;
  let attempts = 0;
  self.registration.showNotification = async (...args) => { attempts++; if (attempts === 1) throw Error('Temporary display failure'); return show(...args); };
  const retry = { ...event, event_id: 'display-retry' };
  await assert.rejects(push(retry), /Temporary display failure/);
  assert(!stored.has('https://pbx.example.test/mass-notify/subscriber.php?event=display-retry'));
  await Promise.all([push(retry), push(retry)]);
  assert.equal(attempts, 2); assert.equal(displayed.length, 2);
  self.registration.showNotification = show;
  for (const bad of [{ ...event, event_id: 'expired', expires_at: now - 1 }, { ...event, event_id: 'too-far', expires_at: now + 601 }, { ...event, event_id: '../path' }, { ...event, event_id: 'string-test', is_test: 'true' }, null]) await push(bad);
  assert.equal(displayed.length, 2);
  let closed = false, work; handlers.get('notificationclick')({ notification: { data: { expires_at: now + 300 }, close() { closed = true; } }, waitUntil(promise) { work = promise; } }); await work;
  assert(closed); assert.deepEqual(opened, ['/mass-notify/subscriber.php']);
  handlers.get('notificationclick')({ notification: { data: { expires_at: now - 1 }, close() {} }, waitUntil() { throw Error('Expired notification opened a page'); } });
  disappearOnMatch = 'https://pbx.example.test/mass-notify/subscriber.php?event=fixture_1';
  await push({ ...event, event_id: 'cleanup-race' });
  for (let i = 0; i < 105; i++) await push({ ...event, event_id: `bounded_${i}` }); assert(stored.size <= 100);
  assert(!handlers.has('fetch'), 'Subscriber worker must never intercept operator authentication or API requests');
  console.log('Subscriber worker: expiry, duplicate isolation, explicit tests, bounded cache and fixed-origin navigation passed.');
})().catch(error => { console.error(error); process.exitCode = 1; });
