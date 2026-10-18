(function () {
    'use strict';

    var activeDialog = null;
    var returnFocus = null;

    function openDialog(dialog, trigger) {
        if (!dialog) { return; }
        returnFocus = trigger;
        activeDialog = dialog;
        dialog.hidden = false;
        document.body.classList.add('fixreport-dialog-open');
        var first = dialog.querySelector('[data-dialog-initial-focus]') || dialog.querySelector('button, input, select, textarea');
        (first || dialog.querySelector('[role="dialog"]')).focus();
    }

    function closeDialog() {
        if (!activeDialog) { return; }
        activeDialog.hidden = true;
        document.body.classList.remove('fixreport-dialog-open');
        activeDialog = null;
        if (returnFocus && document.contains(returnFocus)) { returnFocus.focus(); }
        returnFocus = null;
    }

    var labels = window.fixreportActivityLabels || {};

    document.addEventListener('click', function (event) {
        var opener = event.target.closest('.fixreport-open-add');
        if (opener) { openDialog(document.getElementById('fixreport-add-modal'), opener); return; }
        var inspect = event.target.closest('.fixreport-inspect');
        if (inspect) {
            var record;
            try { record = JSON.parse(inspect.getAttribute('data-record')); } catch (error) { return; }
            var dialog = document.getElementById('fixreport-inspection');
            dialog.querySelector('[data-record-id]').textContent = labels.id + ' #' + record.id;
            dialog.querySelector('[data-record-title]').textContent = record.title || '';
            var source = dialog.querySelector('[data-record-source]');
            source.textContent = record.source || '';
            source.classList.toggle('is-automatic', !!record.automatic);
            source.classList.toggle('is-manual', !record.automatic);
            dialog.querySelector('[data-record-date]').textContent = record.date || '';
            dialog.querySelector('[data-record-category]').textContent = record.category || '';
            dialog.querySelector('[data-record-status]').textContent = record.status || '';
            dialog.querySelector('[data-record-legacy]').hidden = !record.legacy;
            var description = dialog.querySelector('[data-record-description-wrap]');
            description.hidden = !record.description;
            description.querySelector('[data-record-description]').textContent = record.description || '';
            var facts = dialog.querySelector('.fixreport-inspection-facts');
            facts.replaceChildren();
            ['actor', 'event', 'object', 'previous', 'new', 'hook', 'note', 'visible'].forEach(function (key) {
                if (key === 'visible' && record.automatic) { return; }
                if (record[key] === null || record[key] === undefined || record[key] === '') { return; }
                var term = document.createElement('dt');
                var value = document.createElement('dd');
                term.textContent = labels[key];
                value.textContent = key === 'visible' ? (record[key] ? labels.yes : labels.no) : String(record[key]);
                facts.append(term, value);
            });
            openDialog(dialog, inspect);
            return;
        }
        if (activeDialog && (event.target === activeDialog || event.target.closest('.fixreport-close-dialog, [data-close-dialog]'))) {
            closeDialog();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (!activeDialog) { return; }
        if (event.key === 'Escape') { event.preventDefault(); closeDialog(); return; }
        if (event.key !== 'Tab') { return; }
        var items = Array.prototype.filter.call(activeDialog.querySelectorAll('button, a[href], input, select, textarea, [tabindex="0"]'), function (item) {
            return !item.disabled && item.offsetParent !== null;
        });
        if (!items.length) { return; }
        var first = items[0];
        var last = items[items.length - 1];
        if (!activeDialog.contains(document.activeElement) || document.activeElement === activeDialog.querySelector('[role="dialog"]')) {
            event.preventDefault();
            (event.shiftKey ? last : first).focus();
            return;
        }
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
        else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    });

    document.addEventListener('submit', function (event) {
        var form = event.target.closest('.fixreport-delete-form');
        if (!form) {
            return;
        }

        var message = form.getAttribute('data-confirm');
        if (message && !window.confirm(message)) {
            event.preventDefault();
        }
    });
}());
