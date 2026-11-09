/**
 * Modul „KI-Übersetzung“: Auswahl und Abarbeiten der Warteschlange im Browser (eine Seite pro Anfrage).
 */
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';

const qs = (selector, root = document) => root.querySelector(selector);

// Auswahlfelder (Website, Sprache, Filter) sofort anwenden
document.querySelectorAll('[data-ait-autosubmit]').forEach((select) => {
  select.addEventListener('change', () => select.form.submit());
});

// Kostenschätzung für die Auswahl
const estimateForm = qs('[data-ait-estimate-form]');
const estimateOutput = estimateForm ? qs('[data-ait-estimate]', estimateForm) : null;
let estimateTimer = null;
let estimateRequest = 0;
const formatNumber = (value) => new Intl.NumberFormat('de-DE').format(value);
const updateEstimate = () => {
  if (!estimateForm || !estimateOutput) return;
  window.clearTimeout(estimateTimer);
  estimateTimer = window.setTimeout(async () => {
    const ids = [...estimateForm.querySelectorAll('[data-ait-page]:checked')].map((checkbox) => checkbox.value);
    const provider = qs('[name="provider"]', estimateForm)?.value || '';
    if (ids.length === 0) {
      estimateOutput.textContent = '';
      return;
    }
    const current = ++estimateRequest;
    estimateOutput.textContent = 'Kosten werden geschätzt …';
    try {
      const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.ai_translator_estimate).post({
        type: estimateForm.dataset.type,
        language: estimateForm.dataset.language,
        provider,
        ids,
      });
      const data = await response.resolve();
      if (current !== estimateRequest) return;
      let text = `Geschätzt: ${formatNumber(data.characters)} Zeichen, ca. ${data.costFormatted} €`;
      if (data.budget > 0) {
        text += ` (diesen Monat bisher ${data.spentFormatted} € von ${data.budgetFormatted} €)`;
      }
      estimateOutput.textContent = text;
      estimateOutput.classList.toggle('text-danger', data.exceeds);
      if (data.exceeds) {
        estimateOutput.textContent += ' – überschreitet das Monatslimit, die Warteschlange hält dann an.';
      }
    } catch (error) {
      if (current === estimateRequest) estimateOutput.textContent = '';
    }
  }, 400);
};
if (estimateForm) {
  estimateForm.addEventListener('change', (event) => {
    if (event.target.matches('[data-ait-page], [data-ait-toggle-all], [name="provider"]')) {
      updateEstimate();
    }
  });
}

// Alle auswählen
const toggleAll = qs('[data-ait-toggle-all]');
if (toggleAll) {
  toggleAll.addEventListener('change', () => {
    document.querySelectorAll('[data-ait-page]').forEach((checkbox) => {
      checkbox.checked = toggleAll.checked;
    });
    updateEstimate();
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
      if (data.item.status === 'budget') {
        addLog(data.item.message, false);
        finish();
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
