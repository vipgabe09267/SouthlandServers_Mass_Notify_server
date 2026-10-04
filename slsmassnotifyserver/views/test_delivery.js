(() => {
  'use strict';
  const runs = new Map();
  const element = (tag, text, className = '') => {
    const node = document.createElement(tag);
    node.textContent = text;
    node.className = className;
    return node;
  };
  window.SlsTestDeliveryReports = {
    start(data, form, statusId) {
      const status = document.getElementById(statusId);
      if (!status || !form) return;
      const previous = runs.get(statusId);
      if (previous) { clearTimeout(previous.timer); previous.abort.abort(); }
      let box = document.getElementById(statusId + '-receipts');
      if (!box) {
        box = element('section', '', 'sls-test-receipts');
        box.id = statusId + '-receipts';
        status.after(box);
      }
      box.replaceChildren();
      if (!data.delivery_ticket || !data.delivery) { box.hidden = true; return; }
      box.hidden = false;
      const run = {abort: new AbortController(), timer: null, until: Date.now() + 300000};
      runs.set(statusId, run);
      const summary = element('p', '', 'sls-test-receipt-summary');
      summary.setAttribute('role', 'status');
      const details = element('details', '');
      details.append(element('summary', 'Delivery details'));
      const list = element('ul', '');
      details.append(list);
      const note = element('p', 'Desktop receipts confirm receipt by the app. Phone answer and conference evidence do not confirm full playback or SIP popup display.', 'help-block');
      const refresh = element('button', 'Refresh receipts', 'btn btn-default btn-sm');
      refresh.type = 'button';
      const error = element('p', '', 'help-block');
      error.setAttribute('role', 'status');
      box.append(summary, details, note, refresh, error);
      const render = delivery => {
        const rows = Array.isArray(delivery.receipts) ? delivery.receipts : [];
        const desktops = rows.filter(row => row.channel === 'desktop');
        const phones = rows.filter(row => row.channel === 'audio');
        summary.textContent = [desktops.length ? 'Received by desktop app: ' + desktops.filter(row => row.state === 'received').length + '/' + desktops.length : '',
          phones.length ? 'Phones answered: ' + phones.filter(row => row.answered).length + '/' + phones.length : ''].filter(Boolean).join(' · ') || 'No delivery receipts are available.';
        list.replaceChildren();
        rows.slice(0, 100).forEach(row => {
          const item = element('li', '', row.state === 'received' || row.answered ? 'sls-receipt-confirmed' : '');
          const icon = element('i', '', row.state === 'received' || row.answered ? 'fa fa-check-circle' : 'fa fa-clock-o');
          icon.setAttribute('aria-hidden', 'true');
          item.append(icon, document.createTextNode(' ' + (row.channel === 'desktop' ? 'Desktop' : 'Phone') + ' · ' + row.target + ' — ' + row.detail));
          list.append(item);
        });
        if (rows.length > 100) list.append(element('li', 'Showing the first 100 destinations. The totals include all receipts.'));
        error.textContent = delivery.receipt_status_error || '';
      };
      const check = async () => {
        clearTimeout(run.timer);
        refresh.disabled = true;
        try {
          const csrf = form.querySelector('[name="slsmassnotifyserver_csrf"]')?.value;
          const response = await fetch(form.action, {method: 'POST', credentials: 'same-origin', cache: 'no-store', signal: run.abort.signal,
            body: new URLSearchParams({slsmassnotifyserver_action: 'test_delivery_status', slsmassnotifyserver_csrf: csrf || '', delivery_ticket: data.delivery_ticket, ajax: '1'})});
          const answer = await response.json();
          if (!response.ok || !answer.success || !answer.delivery) throw new Error(answer.message || 'Receipt status could not be read.');
          render(answer.delivery);
          if (answer.delivery.pending && Date.now() < run.until) run.timer = setTimeout(check, 5000);
        } catch (failure) {
          if (failure.name !== 'AbortError') error.textContent = failure.message + ' No notification was resent.';
        } finally { refresh.disabled = false; }
      };
      refresh.addEventListener('click', check);
      render(data.delivery);
      if (data.delivery.pending) run.timer = setTimeout(check, 3000);
    }
  };
})();
