(function () {
  'use strict';
  var root = document.querySelector('.fixreport-checklist');
  if (!root) return;
  var lastTrigger = null;
  function open(dialog, trigger) {
    if (!dialog) return;
    lastTrigger = trigger || null;
    if (typeof dialog.showModal === 'function') dialog.showModal();
    else dialog.setAttribute('open', '');
  }
  root.querySelectorAll('.frc-open').forEach(function (button) {
    button.addEventListener('click', function () { open(document.getElementById(button.dataset.dialog), button); });
  });
  root.querySelectorAll('.frc-transition-trigger').forEach(function (button) {
    button.addEventListener('click', function () {
      var dialog = document.getElementById('frc-transition-dialog');
      var form = dialog.querySelector('form');
      var complete = button.dataset.transitionAction === 'completed';
      ['task_key', 'transition_action', 'expected_event_id', 'request_token', 'transition_nonce'].forEach(function (name) {
        var field = form.elements[name];
        field.value = button.dataset[{task_key:'taskKey', transition_action:'transitionAction', expected_event_id:'expectedEventId', request_token:'requestToken', transition_nonce:'nonce'}[name]];
      });
      form.elements.transition_note.value = '';
      dialog.querySelector('.frc-transition-title').textContent = (complete ? fixreportChecklistLabels.completeTitle : fixreportChecklistLabels.reopenTitle) + button.dataset.taskTitle;
      dialog.querySelector('.frc-transition-submit').textContent = complete ? fixreportChecklistLabels.completeButton : fixreportChecklistLabels.reopenButton;
      open(dialog, button);
    });
  });
  root.querySelectorAll('.frc-close').forEach(function (button) {
    button.addEventListener('click', function () {
      var dialog = button.closest('dialog');
      if (typeof dialog.close === 'function') dialog.close(); else dialog.removeAttribute('open');
    });
  });
  root.querySelectorAll('dialog').forEach(function (dialog) {
    dialog.addEventListener('close', function () { if (lastTrigger) lastTrigger.focus(); });
    dialog.addEventListener('click', function (event) { if (event.target === dialog && typeof dialog.close === 'function') dialog.close(); });
    if (dialog.dataset.autoOpen === '1') open(dialog, null);
  });
}());
