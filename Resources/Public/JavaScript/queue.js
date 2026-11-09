/**
 * Modul „KI-Übersetzung“: Auswahl und Abarbeiten der Warteschlange im Browser (eine Seite pro Anfrage).
 */
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';

const qs = (selector, root = document) => root.querySelector(selector);

// Auswahlfelder (Website, Sprache, Filter) sofort anwenden
document.querySelectorAll('[data-ait-autosubmit]').forEach((select) => {
  select.addEventListener('change', () => select.form.submit());
});

// Alle auswählen
const toggleAll = qs('[data-ait-toggle-all]');
if (toggleAll) {
  toggleAll.addEventListener('change', () => {
    document.querySelectorAll('[data-ait-page]').forEach((checkbox) => {
      checkbox.checked = toggleAll.checked;
    });
  });
}

const box = qs('[data-ait-queue]');
if (box) {
  const startButton = qs('[data-ait-start]', box);
  const stopButton = qs('[data-ait-stop]', box);
  const counts = qs('[data-ait-counts]', box);
  const progress = qs('[data-ait-progress]', box);
  const bar = progress ? qs('.progress-bar', progress) : null;
  const log = qs('[data-ait-log]', box);
  const url = TYPO3.settings.ajaxUrls.ai_translator_queue_process;
  let running = false;
  let total = Number(box.dataset.open || 0);
  let processed = 0;

  const addLog = (text, ok) => {
    const li = document.createElement('li');
    li.textContent = (ok ? '✓ ' : '✗ ') + text;
    li.className = ok ? 'text-success' : 'text-danger';
    log.prepend(li);
  };

  const finish = () => {
    running = false;
    if (startButton) startButton.hidden = false;
    if (stopButton) stopButton.hidden = true;
  };

  const step = async () => {
    if (!running) {
      finish();
      return;
    }
    try {
      const response = await new AjaxRequest(url).post({});
      const data = await response.resolve();
      const c = data.counts;
      counts.textContent = `${c.pending} offen · ${c.running} in Arbeit · ${c.done} erledigt · ${c.error} Fehler`;
      if (!data.item) {
        finish();
        // Fertig: Übersicht neu laden, damit der Status stimmt
        window.location.href = box.dataset.reloadUrl;
        return;
      }
      processed++;
      total = Math.max(total, processed + c.pending + c.running);
      if (bar) {
        const percent = total > 0 ? Math.round((processed / total) * 100) : 100;
        bar.style.width = `${percent}%`;
        bar.textContent = `${processed} / ${total}`;
      }
      addLog(`${data.item.title}: ${data.item.message}`, data.item.status === 'done');
      step();
    } catch (error) {
      addLog('Die Verarbeitung wurde unterbrochen. Bitte erneut auf „Abarbeiten“ klicken.', false);
      finish();
    }
  };

  const start = () => {
    if (running) return;
    running = true;
    if (startButton) startButton.hidden = true;
    if (stopButton) stopButton.hidden = false;
    if (progress) progress.hidden = false;
    step();
  };

  startButton?.addEventListener('click', start);
  stopButton?.addEventListener('click', () => {
    running = false;
    stopButton.hidden = true;
  });
  if (box.dataset.autostart === '1' && startButton) {
    start();
  }
}
