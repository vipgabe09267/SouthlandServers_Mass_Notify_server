'use strict';
const fs = require('fs'), vm = require('vm'), assert = require('assert');
const sandbox = {document:{getElementById:()=>null}, TextEncoder};
vm.runInNewContext(fs.readFileSync(__dirname+'/../slsmassnotifyserver/views/phone_contacts.js','utf8'), sandbox);
const {combine, split} = sandbox.SlsPhoneContacts;
const plain = value => JSON.parse(JSON.stringify(value));
const voice = [{id:'voice_'+'a'.repeat(24), name:'Original call name', number:'+15555550123', enabled:'1'},
    {id:'voice_'+'b'.repeat(24), name:'Disabled contact', number:'+15555550124', enabled:'0'}];
const sms = [{id:'sms_'+'c'.repeat(24), name:'Original SMS name', number:'+15555550123', enabled:true,
    consent:true, consent_note:'Original signed consent', consent_at:'2026-10-01T10:00:00+00:00'}];
const rows = combine(voice, sms);
assert.equal(rows.length, 2);
assert.equal(rows[0].voice_enabled, true); assert.equal(rows[0].sms_enabled, true);
let packed = split(rows);
assert.deepStrictEqual(plain(packed.voice), voice);
assert.equal(packed.sms[0].id, sms[0].id);
assert.equal(packed.sms[0].name, sms[0].name);
assert.equal(packed.sms[0].consent_note, sms[0].consent_note);
assert.equal(packed.sms[0].renew_consent, false);
rows[0].sms_enabled = false;
packed = split(rows);
assert.equal(packed.voice[0].enabled, '1'); assert.equal(packed.sms[0].enabled, false);
assert.equal(packed.sms[0].id, sms[0].id);
rows[0].name = 'Renamed contact'; rows[0].sms_enabled = true;
packed = split(rows);
assert.equal(packed.voice[0].name, 'Renamed contact'); assert.equal(packed.sms[0].name, 'Renamed contact');
assert.equal(packed.voice[0].id, voice[0].id); assert.equal(packed.sms[0].id, sms[0].id);
rows[0].renew_consent = true;
assert.throws(()=>split(rows), /Update the consent note/);
rows[0].consent_note = 'Renewed in person'; assert.equal(split(rows).sms[0].renew_consent, true);
rows[0].consent_note = ''; assert.throws(()=>split(rows), /Record when and how/);
rows[0].consent = false; rows[0].renew_consent = false;
assert.equal(split(rows).sms[0].consent, false);
rows[1].number = rows[0].number; assert.throws(()=>split(rows), /unique international/);
rows[1].number = '+15555550124';
const fresh = {name:'New contact', initial_name:'', number:'+15555550125', voice:null, sms:null,
    voice_enabled:false, sms_enabled:true, consent:false, consent_note:'', renew_consent:false};
packed = split([fresh]); assert.equal(packed.voice.length, 0); assert.equal(packed.sms.length, 1); assert.equal(packed.sms[0].id, '');
fresh.sms_enabled = false; assert.throws(()=>split([fresh]), /Enable Calls, SMS or both/);
fresh.voice_enabled = true; fresh.name='<img src=x onerror=alert(1)>';
assert.equal(split([fresh]).voice[0].name, fresh.name); // Stored as text, never HTML.
fresh.name='\u0000bad'; assert.throws(()=>split([fresh]), /control characters/);
console.log('Unified contacts: exact-number grouping, existing identities/names, disabled channels, renewed consent, validation and channel isolation passed.');
