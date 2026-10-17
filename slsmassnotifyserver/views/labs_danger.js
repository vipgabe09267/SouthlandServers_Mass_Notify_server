(() => {
  'use strict';
  const danger = document.getElementById('sls-labs-danger');
  if (!danger) return;
  const data = JSON.parse(document.getElementById('sls-labs-danger-data').textContent);
  const find = id => danger.querySelector(`#sls-labs-danger-${id}`);
  const agree = find('agree'), accept = find('accept'), cancel = find('cancel');
  const pageAccess = allowed => {
    if (data.selectors) document.querySelectorAll(data.selectors).forEach(panel => { panel.inert = !allowed; });
  };
  pageAccess(data.acknowledged || !data.automatic);
  const post = async (action, input) => {
    const body = new URLSearchParams({ slsmassnotifyserver_action: 'enterprise_labs', slsmassnotifyserver_csrf: data.csrf,
      payload: JSON.stringify({ action, input }) });
    const signal = AbortSignal.timeout(20000);
    try {
      const response = await fetch(data.endpoint, { method: 'POST', body, credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' }, signal });
      const result = await response.json();
      if (!result || typeof result !== 'object' || Array.isArray(result)) throw new Error('The PBX did not return a valid warning response. Reload before enabling the feature.');
      if (!response.ok || result.success !== true) throw new Error(result.message || 'The warning could not be saved. Reload before enabling this feature.');
      return result;
    } catch (error) {
      if (signal.aborted) throw new Error('Warning acceptance was not confirmed within 20 seconds. Reload this page before enabling the feature.');
      throw error;
    }
  };
  const waitForBetaNotice = () => {
    if (window.SlsBetaNoticeReady) return window.SlsBetaNoticeReady;
    if (!document.getElementById('sls-beta-notice-template')) return Promise.resolve();
    return new Promise(resolve => document.addEventListener('sls-beta-notice-acknowledged', resolve, { once: true }));
  };
  let pending;
  const acknowledge = () => {
    if (data.acknowledged) return Promise.resolve(true);
    if (!pending) pending = waitForBetaNotice().then(showWarning).finally(() => { pending = null; });
    return pending;
  };
  async function showWarning() {
    const feature = data.feature, challenge = await post('danger_begin', { feature });
    if (challenge.acknowledged) { data.acknowledged = true; pageAccess(true); return true; }
    find('title').textContent = challenge.title;
    find('body').textContent = challenge.body;
    find('agreement').textContent = challenge.agreement;
    const error = find('error'), countdown = find('countdown');
    error.hidden = true; agree.checked = false; agree.disabled = true; accept.disabled = true;
    countdown.textContent = '(5s)';
    danger.showModal();
    const deadline = performance.now() + challenge.delay_seconds * 1000;
    const timer = setInterval(() => {
      const left = Math.max(0, Math.ceil((deadline - performance.now()) / 1000));
      countdown.textContent = left ? `(${left}s)` : '';
      if (performance.now() >= deadline) { agree.disabled = false; clearInterval(timer); }
    }, 100);
    return await new Promise(resolve => {
      const cleanup = result => {
        clearInterval(timer); agree.onchange = null; accept.onclick = null; cancel.onclick = null;
        danger.oncancel = null; danger.onclose = null;
        pageAccess(result || !data.automatic); danger.close(); resolve(result);
        if (!result) window.location.replace('config.php?display=index');
      };
      agree.onchange = () => { accept.disabled = !agree.checked || performance.now() < deadline; };
      cancel.onclick = () => cleanup(false);
      danger.oncancel = event => { event.preventDefault(); cleanup(false); };
      danger.onclose = () => cleanup(false);
      accept.onclick = async () => {
        if (!agree.checked || agree.disabled || performance.now() < deadline) return;
        accept.disabled = true; cancel.disabled = true;
        try { await post('danger_accept', { feature, challenge: challenge.challenge, agree: true }); data.acknowledged = true; cleanup(true); }
        catch (failure) { error.textContent = failure.message; error.hidden = false; accept.disabled = false; }
        finally { cancel.disabled = false; }
      };
    });
  }
  window.SlsLabsSafety = { acknowledge };
  if (data.automatic) acknowledge().catch(() => { window.location.replace('config.php?display=index'); });
})();
