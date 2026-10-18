/**
 * FixReport Guided Report Builder.
 *
 * Progressive enhancement only: every Draft field is rendered by the server in
 * one form. This script shows one panel at a time (hidden, never disabled),
 * mirrors server validation limits, keeps an approximate cover preview, and
 * blocks preview/PDF/finalize while unsaved edits exist. The server remains the
 * authority for every rule.
 */
(function () {
	'use strict';

	var root = document.querySelector('.fixreport-builder');
	var form = document.getElementById('frb-form');
	if (!root || !form) { return; }

	var l10n = window.fixreportBuilderL10n || {};
	var stepNames = parseJson(root.getAttribute('data-step-names')) || {};
	var panels = toArray(root.querySelectorAll('.frb-panel'));
	var stepButtons = toArray(root.querySelectorAll('.frb-step'));
	var stepField = form.querySelector('input[name="builder_step"]');
	var total = panels.length;
	var current = parseInt(root.getAttribute('data-active-step'), 10) || 1;
	var dirty = false;
	var submitting = false;

	function toArray(list) { return Array.prototype.slice.call(list || []); }
	function parseJson(text) { try { return JSON.parse(text); } catch (e) { return null; } }
	function format(template, values) {
		var index = 0;
		return String(template || '').replace(/%(\d+\$)?([sd])/g, function (match, position) {
			var value = position ? values[parseInt(position, 10) - 1] : values[index++];
			return value === undefined ? '' : String(value);
		});
	}
	function bytes(value) {
		if (window.TextEncoder) { return new window.TextEncoder().encode(value).length; }
		return unescape(encodeURIComponent(value)).length;
	}
	function stepOf(element) {
		var panel = element.closest ? element.closest('.frb-panel') : null;
		var declared = parseInt(element.getAttribute('data-frb-step'), 10);
		return declared || (panel ? parseInt(panel.getAttribute('data-step'), 10) : 1);
	}

	/* ---------- Step navigation ---------- */
	var prevButton = root.querySelector('.frb-prev');
	var nextButton = root.querySelector('.frb-next');
	var counter = root.querySelector('.frb-current-step');

	function show(step, focusHeading) {
		step = Math.min(Math.max(step, 1), total);
		current = step;
		panels.forEach(function (panel) { panel.hidden = parseInt(panel.getAttribute('data-step'), 10) !== step; });
		stepButtons.forEach(function (button) {
			var active = parseInt(button.getAttribute('data-step'), 10) === step;
			button.classList.toggle('is-active', active);
			if (active) { button.setAttribute('aria-current', 'step'); } else { button.removeAttribute('aria-current'); }
		});
		stepField.value = step;
		if (counter) { counter.textContent = step; }
		prevButton.hidden = step <= 1;
		nextButton.hidden = step >= total;
		prevButton.querySelector('.frb-prev-label').textContent = format(l10n.previous, [stepNames[step - 1] || '']);
		nextButton.querySelector('.frb-next-label').textContent = format(l10n.next, [stepNames[step + 1] || '']);
		try {
			var url = new URL(window.location.href);
			url.searchParams.set('step', step);
			url.searchParams.delete('report_notice');
			window.history.replaceState(null, '', url.toString());
		} catch (e) { /* URL updates are cosmetic. */ }
		if (focusHeading) {
			var heading = document.getElementById('frb-heading-' + step);
			if (heading) { heading.focus(); }
			var top = root.getBoundingClientRect().top + window.pageYOffset - 40;
			if (window.pageYOffset > top) { window.scrollTo(0, top); }
		}
	}

	stepButtons.forEach(function (button, index) {
		button.addEventListener('click', function () { show(parseInt(button.getAttribute('data-step'), 10), true); });
		button.addEventListener('keydown', function (event) {
			var target = null;
			if (event.key === 'ArrowDown' || event.key === 'ArrowRight') { target = stepButtons[Math.min(index + 1, stepButtons.length - 1)]; }
			if (event.key === 'ArrowUp' || event.key === 'ArrowLeft') { target = stepButtons[Math.max(index - 1, 0)]; }
			if (event.key === 'Home') { target = stepButtons[0]; }
			if (event.key === 'End') { target = stepButtons[stepButtons.length - 1]; }
			if (target) { event.preventDefault(); target.focus(); }
		});
	});
	prevButton.addEventListener('click', function () { show(current - 1, true); });
	nextButton.addEventListener('click', function () { show(current + 1, true); });

	/* ---------- Unsaved changes ---------- */
	var unsavedNotice = root.querySelector('.frb-unsaved');
	var savedOnly = toArray(root.querySelectorAll('[data-requires-saved]'));

	function markDirty() {
		if (dirty) { return; }
		dirty = true;
		unsavedNotice.querySelector('p').textContent = l10n.unsaved || '';
		unsavedNotice.hidden = false;
		savedOnly.forEach(function (element) {
			if (element.tagName === 'BUTTON') { element.disabled = true; } else { element.setAttribute('aria-disabled', 'true'); }
		});
	}
	savedOnly.forEach(function (element) {
		if (element.tagName === 'A') {
			element.addEventListener('click', function (event) {
				if (dirty) { event.preventDefault(); unsavedNotice.hidden = false; unsavedNotice.scrollIntoView({ block: 'center' }); }
			});
		}
	});
	form.addEventListener('input', markDirty);
	form.addEventListener('change', markDirty);
	window.addEventListener('beforeunload', function (event) {
		if (dirty && !submitting) { event.preventDefault(); event.returnValue = l10n.leave || ''; return l10n.leave || ''; }
	});

	var finalize = root.querySelector('.frb-finalize');
	var finalizeForm = document.getElementById('frb-finalize-form');
	if (finalizeForm) {
		finalizeForm.addEventListener('submit', function (event) {
			if (dirty || (finalize && finalize.getAttribute('data-gate') !== '1') || !window.confirm(l10n.confirmFinalize || '')) { event.preventDefault(); return; }
			submitting = true;
		});
	}
	var pdfForm = document.getElementById('frb-pdf-form');
	if (pdfForm) {
		pdfForm.addEventListener('submit', function (event) { if (dirty) { event.preventDefault(); } });
	}

	/* ---------- Title mirrors ---------- */
	var title = document.getElementById('frb-title');
	var titleMirror = root.querySelector('[data-frb-title-mirror]');
	if (titleMirror) { titleMirror.addEventListener('click', function () { title.focus(); title.select(); }); }

	/* ---------- Branding fields and live cover ---------- */
	var cover = root.querySelector('.frb-cover');
	var coverConfig = cover ? parseJson(cover.getAttribute('data-cover-config')) : null;
	var logoUrls = coverConfig && coverConfig.logoUrls ? coverConfig.logoUrls : {};
	var pendingLogo = {};
	var brandFields = toArray(root.querySelectorAll('.frb-brand-field'));

	function brandState(key) {
		var field = root.querySelector('.frb-brand-field[data-brand-key="' + key + '"]');
		if (!field) { return { mode: 'inherit', value: '' }; }
		var mode = field.querySelector('.frb-brand-mode');
		var value = field.querySelector('.frb-brand-value, .fixreport-logo-id');
		return { mode: mode ? mode.value : 'inherit', value: value ? value.value : '' };
	}
	function effective(key) {
		var state = brandState(key);
		var fallback = coverConfig && coverConfig.defaults ? coverConfig.defaults[key] : '';
		if (state.mode === 'blank') { return key === 'logo_attachment_id' ? 0 : ''; }
		if (state.mode === 'override') { return key === 'logo_attachment_id' ? (parseInt(state.value, 10) || 0) : state.value; }
		return key === 'logo_attachment_id' ? (parseInt(fallback, 10) || 0) : (fallback || '');
	}
	function logoUrl(id, onReady) {
		if (!id) { return ''; }
		if (logoUrls[id]) { return logoUrls[id]; }
		if (!pendingLogo[id] && window.wp && window.wp.media && window.wp.media.attachment) {
			pendingLogo[id] = true;
			var attachment = window.wp.media.attachment(id);
			attachment.fetch().then(function () {
				var sizes = attachment.get('sizes');
				logoUrls[id] = sizes && sizes.medium ? sizes.medium.url : attachment.get('url');
				onReady();
			});
		}
		return '';
	}
	function updateCover() {
		if (!cover || !coverConfig) { return; }
		var checked = form.querySelector('input[name="report_locale"]:checked');
		var locale = checked ? checked.value : 'en_US';
		var words = coverConfig.vocabulary[locale] || coverConfig.vocabulary.en_US || {};
		cover.setAttribute('dir', locale === 'ar' ? 'rtl' : 'ltr');
		cover.setAttribute('lang', locale === 'ar' ? 'ar' : 'en');
		toArray(cover.querySelectorAll('[data-cover-word]')).forEach(function (node) {
			node.textContent = words[node.getAttribute('data-cover-word')] || '';
		});
		['company_name', 'website', 'email', 'phone', 'address', 'footer_text'].forEach(function (key) {
			var node = cover.querySelector('[data-cover="' + key + '"]');
			if (node) { node.textContent = effective(key); }
		});
		var coverTitle = cover.querySelector('[data-cover="title"]');
		if (coverTitle) { coverTitle.textContent = title.value; }
		var logoId = effective('logo_attachment_id');
		var url = logoUrl(logoId, updateCover);
		var image = cover.querySelector('.frb-cover-logo');
		var thumb = root.querySelector('.frb-logo-thumb img');
		[image, thumb].forEach(function (img) {
			if (!img) { return; }
			if (url) { img.src = url; img.hidden = false; } else { img.removeAttribute('src'); img.hidden = true; }
		});
	}

	brandFields.forEach(function (field) {
		var mode = field.querySelector('.frb-brand-mode');
		var value = field.querySelector('.frb-brand-value');
		if (mode && value) {
			// Typing a value means the user wants it in this report, as the existing logo picker already assumes.
			value.addEventListener('input', function () {
				if (value.value !== '' && mode.value !== 'override') { mode.value = 'override'; }
			});
		}
	});
	if (title) {
		title.addEventListener('input', function () {
			if (titleMirror) { titleMirror.textContent = title.value; }
		});
	}
	form.addEventListener('input', updateCover);
	form.addEventListener('change', updateCover);

	// The existing media picker (fixreport-branding.js) sets values without firing events.
	var logoInput = root.querySelector('.fixreport-logo-id');
	var logoMode = root.querySelector('.frb-logo-field .frb-brand-mode');
	var lastLogo = logoInput ? logoInput.value + '|' + (logoMode ? logoMode.value : '') : '';
	if (logoInput) {
		window.setInterval(function () {
			var now = logoInput.value + '|' + (logoMode ? logoMode.value : '');
			if (now !== lastLogo) { lastLogo = now; markDirty(); updateCover(); }
		}, 400);
	}

	/* ---------- Maintenance work counts and checklist gate ---------- */
	var activityToggles = toArray(root.querySelectorAll('.frb-activity-toggle'));
	var countTargets = toArray(root.querySelectorAll('[data-activity-counts], [data-step-summary="3"]'));
	var gate = root.querySelector('[data-checklist-gate]');
	var checkRows = toArray(root.querySelectorAll('[data-check-row]'));

	function updateWork() {
		if (activityToggles.length) {
			var included = activityToggles.filter(function (box) { return box.checked; }).length;
			var text = format(l10n.activityCounts, [included, activityToggles.length - included]);
			countTargets.forEach(function (node) { node.textContent = text; });
		}
		if (gate) {
			var includedChecks = 0;
			var pending = 0;
			checkRows.forEach(function (row) {
				var include = row.querySelector('.frb-check-included');
				var status = row.querySelector('.frb-check-status');
				if (include && include.checked) {
					includedChecks++;
					if (status && status.value === 'pending') { pending++; }
				}
			});
			var ready = includedChecks > 0 && pending === 0;
			gate.textContent = ready ? (l10n.gateReady || '') : format(l10n.gateBlocked, [includedChecks, pending]);
			gate.classList.toggle('is-ready', ready);
			gate.classList.toggle('is-blocked', !ready);
		}
	}
	form.addEventListener('change', updateWork);

	/* ---------- Snapshot pair changes ---------- */
	var snapshotSelects = toArray(root.querySelectorAll('.frb-snapshot'));
	var pairChanged = root.querySelector('.frb-pair-changed');
	function updatePair() {
		if (!pairChanged) { return; }
		var changed = snapshotSelects.some(function (select) { return select.value !== select.getAttribute('data-saved'); });
		pairChanged.textContent = changed ? (l10n.pairChanged || '') : '';
		pairChanged.hidden = !changed;
	}
	snapshotSelects.forEach(function (select) { select.addEventListener('change', updatePair); });

	/* ---------- Validation (mirrors server limits; the server stays authoritative) ---------- */
	var errorBox = root.querySelector('.frb-errors');

	function brandModeFor(element) {
		var field = element.closest ? element.closest('.frb-brand-field') : null;
		var mode = field ? field.querySelector('.frb-brand-mode') : null;
		return mode ? mode.value : null;
	}
	function validate() {
		var errors = [];
		toArray(form.querySelectorAll('[aria-invalid="true"]')).forEach(function (element) { element.removeAttribute('aria-invalid'); });
		if (!title.value.trim()) { errors.push({ element: title, message: l10n.titleRequired }); }
		else if (bytes(title.value) > 255) { errors.push({ element: title, message: format(l10n.tooLong, [title.getAttribute('data-frb-label'), 255]) }); }
		toArray(form.querySelectorAll('[data-frb-max]')).forEach(function (element) {
			var mode = brandModeFor(element);
			if (mode !== null && mode !== 'override') { return; }
			var label = element.getAttribute('data-frb-label');
			var max = parseInt(element.getAttribute('data-frb-max'), 10);
			var value = element.value;
			if (bytes(value) > max) { errors.push({ element: element, message: format(l10n.tooLong, [label, max]) }); return; }
			var kind = element.getAttribute('data-frb-format');
			if (kind === 'email' && value !== '' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) { errors.push({ element: element, message: format(l10n.emailInvalid, [label]) }); }
			if (kind === 'website' && value !== '' && !/^https?:\/\/[^\s/]+/i.test(value)) { errors.push({ element: element, message: format(l10n.websiteInvalid, [label]) }); }
		});
		if (logoInput && logoMode && logoMode.value === 'override' && !(parseInt(logoInput.value, 10) > 0)) {
			errors.push({ element: logoInput, message: l10n.logoRequired });
		}
		return errors;
	}
	form.addEventListener('submit', function (event) {
		var errors = validate();
		if (!errors.length) {
			errorBox.hidden = true;
			submitting = true;
			return;
		}
		event.preventDefault();
		errorBox.textContent = '';
		var heading = document.createElement('p');
		heading.textContent = l10n.errorsHeading || '';
		var list = document.createElement('ul');
		errors.forEach(function (error) {
			error.element.setAttribute('aria-invalid', 'true');
			var item = document.createElement('li');
			item.textContent = error.message;
			list.appendChild(item);
		});
		errorBox.appendChild(heading);
		errorBox.appendChild(list);
		errorBox.hidden = false;
		show(stepOf(errors[0].element), false);
		errors[0].element.focus();
	});

	/* ---------- Start ---------- */
	root.classList.add('is-enhanced');
	show(current, false);
	updateCover();
	updateWork();
	updatePair();
}());
