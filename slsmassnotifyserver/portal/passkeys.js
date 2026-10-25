'use strict';
(() => {
    const root = document.getElementById('sls-passkeys');
    if (!root) return;
    const status = document.getElementById('passkey-status'), manage = root.dataset.mode === 'manage';
    const endpoint = '/mass-notify/' + (manage ? '?view=security' : '');
    const buttons = root.querySelectorAll('button');
    const decode = value => Uint8Array.from(atob(value.replace(/-/g, '+').replace(/_/g, '/') + '='.repeat((4 - value.length % 4) % 4)), c => c.charCodeAt(0));
    const encode = value => btoa(String.fromCharCode(...new Uint8Array(value))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    const options = value => {
        const key = structuredClone(value); key.challenge = decode(key.challenge);
        if (key.user) key.user.id = decode(key.user.id);
        for (const field of ['allowCredentials', 'excludeCredentials']) for (const row of key[field] || []) row.id = decode(row.id);
        return key;
    };
    const wire = credential => {
        const response = {clientDataJSON: encode(credential.response.clientDataJSON)};
        if (credential.response.attestationObject) {
            response.attestationObject = encode(credential.response.attestationObject);
            response.transports = credential.response.getTransports?.() || [];
        } else {
            response.authenticatorData = encode(credential.response.authenticatorData);
            response.signature = encode(credential.response.signature);
            response.userHandle = credential.response.userHandle ? encode(credential.response.userHandle) : null;
        }
        return JSON.stringify({id: credential.id, rawId: encode(credential.rawId), type: credential.type,
            response, clientExtensionResults: credential.getClientExtensionResults()});
    };
    async function call(action, values = {}) {
        const controller = new AbortController(), timer = setTimeout(() => controller.abort(), 12000);
        try {
            const response = await fetch(endpoint, {method: 'POST', credentials: 'same-origin', cache: 'no-store', redirect: 'error',
                signal: controller.signal, headers: {Accept: 'application/json'},
                body: new URLSearchParams({portal_action: action, portal_csrf: root.dataset.csrf, ...values})});
            const result = await response.json();
            if (!response.ok || result.success !== true) throw Error(result.message || 'The passkey request was not accepted.');
            return result;
        } finally { clearTimeout(timer); }
    }
    async function perform(work) {
        buttons.forEach(button => button.disabled = true); status.textContent = 'Waiting for your authenticator…';
        try {
            await work();
        } catch (error) {
            status.textContent = error.name === 'NotAllowedError' ? 'The passkey request was canceled or timed out. Start again or use your authenticator code.' : error.message;
            buttons.forEach(button => button.disabled = false);
        }
    }
    if (manage) {
        const code = () => document.getElementById('passkey-code').value.trim();
        document.getElementById('passkey-add').addEventListener('click', () => perform(async () => {
            if (!window.PublicKeyCredential || !navigator.credentials) throw Error('This browser does not support passkeys. Use your authenticator code.');
            const name = document.getElementById('passkey-name').value.trim();
            if (!name || name.length > 60) throw Error('Enter a name of at most 60 characters.');
            const begin = await call('passkey_register_begin', {code: code()});
            const credential = await navigator.credentials.create({publicKey: options(begin.public_key)});
            if (!credential) throw Error('No passkey was created.');
            await call('passkey_register_finish', {credential: wire(credential), name}); location.assign(endpoint);
        }));
        root.querySelectorAll('[data-passkey-revoke]').forEach(button => button.addEventListener('click', () => perform(async () => {
            await call('passkey_revoke', {code: code(), credential_id: button.dataset.passkeyRevoke}); location.assign(endpoint);
        })));
    } else {
        document.getElementById('passkey-authenticate').addEventListener('click', () => perform(async () => {
            if (!window.PublicKeyCredential || !navigator.credentials) throw Error('This browser does not support passkeys. Use your authenticator code.');
            const begin = await call('passkey_authenticate_begin');
            const credential = await navigator.credentials.get({publicKey: options(begin.public_key)});
            if (!credential) throw Error('No passkey was selected.');
            await call('passkey_authenticate_finish', {credential: wire(credential)}); location.assign('/mass-notify/');
        }));
    }
})();
