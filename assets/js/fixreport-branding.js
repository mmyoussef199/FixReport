(function ($) {
	'use strict';
	$(document).on('click', '.fixreport-select-logo', function () {
		var input = $(this).closest('p').find('.fixreport-logo-id');
		var frame = wp.media({ title: 'Select report logo', button: { text: 'Use logo' }, multiple: false, library: { type: 'image' } });
		frame.on('select', function () {
			var item = frame.state().get('selection').first().toJSON();
			if (item.mime === 'image/png' || item.mime === 'image/jpeg') { input.val(item.id); input.closest('fieldset').find('select[name^="branding_mode"]').val('override'); }
			else { window.alert('Choose a PNG or JPEG image.'); }
		});
		frame.open();
	});
	$(document).on('click', '.fixreport-clear-logo', function () { $(this).closest('p').find('.fixreport-logo-id').val('0').closest('fieldset').find('select[name^="branding_mode"]').val('blank'); });
}(jQuery));
