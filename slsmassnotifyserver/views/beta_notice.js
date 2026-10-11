(function () {
    'use strict';
    if (window.SlsBetaNoticeReady) return;
    let acknowledgeReady;
    window.SlsBetaNoticeReady = new Promise(resolve => { acknowledgeReady = resolve; });
    async function showNotice() {
        const template = document.getElementById('sls-beta-notice-template');
        if (!template) { acknowledgeReady(); return; }
        if (document.querySelector('dialog.sls-beta-notice')) return;
        const dialog = template.content.firstElementChild.cloneNode(true);
        const button = dialog.querySelector('button');
        const status = dialog.querySelector('[role="status"]');
        document.body.appendChild(dialog);
        document.documentElement.classList.add('sls-beta-notice-open');
        dialog.addEventListener('cancel', event => event.preventDefault());
        dialog.setAttribute('closedby', 'none');
        const preventDismissal = event => {
            if (dialog.open && event.key === 'Escape') {
                event.preventDefault();
                event.stopImmediatePropagation();
            }
        };
        document.addEventListener('keydown', preventDismissal, true);
        dialog.showModal();
        dialog.tabIndex = -1;
        dialog.focus();

        async function request(action, values) {
            const body = new URLSearchParams({slsmassnotifyserver_action: action,
                slsmassnotifyserver_csrf: template.dataset.csrf, ...values});
            const response = await fetch('config.php?display=slsmassnotifyserver', {
                method: 'POST', credentials: 'same-origin', cache: 'no-store',
                headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'},
                body, signal: AbortSignal.timeout(10000)
            });
            const result = await response.json();
            if (!response.ok || result.success !== true) {
                throw new Error(result.message || 'The acknowledgment could not be saved. Reload this page and try again.');
            }
            return result;
        }
        function finish() {
            document.removeEventListener('keydown', preventDismissal, true);
            dialog.close();
            dialog.remove();
            template.remove();
            document.documentElement.classList.remove('sls-beta-notice-open');
            acknowledgeReady();
            document.dispatchEvent(new Event('sls-beta-notice-acknowledged'));
        }
        let challenge;
        try {
            const result = await request('beta_notice_begin', {});
            if (result.acknowledged === true) { finish(); return; }
            if (!/^[a-f0-9]{64}$/.test(result.challenge) || result.delay_seconds !== 5) {
                throw new Error('Warning verification failed. Reload this page and try again.');
            }
            challenge = result.challenge;
        } catch (error) { status.textContent = error.message; return; }
        let visibleMilliseconds = 0, lastTick = performance.now();
        const resetTick = () => { lastTick = performance.now(); };
        document.addEventListener('visibilitychange', resetTick);
        window.addEventListener('focus', resetTick);
        window.addEventListener('blur', resetTick);
        status.textContent = 'Read this warning before agreeing. SLS is for noncritical testing only.';
        const timer = setInterval(() => {
            const now = performance.now();
            if (document.visibilityState === 'visible' && document.hasFocus() && dialog.open) {
                visibleMilliseconds += Math.max(0, now - lastTick);
            }
            lastTick = now;
            const remaining = Math.max(0, Math.ceil((5000 - visibleMilliseconds) / 1000));
            button.textContent = remaining ? `Agree and continue (${remaining}s)` : 'Agree and continue';
            if (!remaining) {
                clearInterval(timer);
                document.removeEventListener('visibilitychange', resetTick);
                window.removeEventListener('focus', resetTick);
                window.removeEventListener('blur', resetTick);
                button.disabled = false;
            }
        }, 100);
        button.addEventListener('click', async () => {
            if (button.disabled || visibleMilliseconds < 5000) return;
            button.disabled = true;
            try {
                await request('beta_notice_accept', {challenge, agree: '1'});
                finish();
            } catch (error) {
                status.textContent = `${error.message} Reload this page before trying again.`;
                button.textContent = 'Reload required';
            }
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', showNotice, {once: true});
    else showNotice();
}());
