/**
 * CT Meta Box JavaScript
 */

jQuery(document).ready(function ($) {

	/**************************************
	 * VISIBILITY
	 **************************************/

	// Change visibility of fields based on other fields' values
	// This runs on page load and form field change
	ctmb_change_visibility(); // on load
	$('form .ctmb-field :input').on('change', function () { // any form input changes
		ctmb_change_visibility();
	});

	/**************************************
	 * MEDIA UPLOADER
	 **************************************/

	// Open media uploader on button click
	$('body').on('click', '.ctmb-upload-file', function (event) {

		var frame;

		// Stop click to URL
		event.preventDefault();

		// Input element
		$input_element = $(this).prev('input, textarea');

		// Media frame
		frame = wp.media({
			title: $(this).attr('data-ctmb-upload-title'),
			library: { type: $(this).attr('data-ctmb-upload-type') },
			multiple: false
		});

		// Open media frame
		// To Do: Set current attachment after opening
		// ( How with only URL? For doing with ID, see this: http://bit.ly/Zut80f )
		frame.open();

		// Set attachment URL on click of button
		// ( don't do on 'close' so user can cancel )
		frame.on('select', function () {

			var attachments, attachment;

			// Get attachment data
			attachments = frame.state().get('selection').toJSON();
			attachment = attachments[0];

			// An attachment is selected
			if (typeof attachment != 'undefined') {

				// Set attachment URL on input
				if (attachment.url) {
					$input_element.val(attachment.url); // input is directly before button
				}

			}

		});

	});

	/**************************************
	 * DATEPICKER
	 **************************************/

	// Loop elements to use Air Datepicker on.
	$('.ctmb-date').each(function () {

		// Field container.
		var $field_container = $(this).parents('.ctmb-field');

		// Localization.
		$.fn.datepicker.language['dynamic'] = ctmb.datepicker_language;

		// Allow multiple?
		var multiple = $(this).data('date-multiple') ? true : false;

		// Activate Air Datepicker.
		var $datepicker = $(this).datepicker({

			// Options.
			inline: true,
			dateFormat: 'yyyy-mm-dd',
			language: 'dynamic',
			multipleDates: multiple,
			multipleDatesSeparator: ',',

			// Date selected.
			onSelect: function (fd, d, picker) { // date(s) were changed.

				// Trigger change event in hidden input so other scripts can do things on change.
				$('#' + $(picker.el).attr('id')).trigger('change');

				// Continue only if AJAX not disabled such as when pre-selecting dates in picker on first load.
				if (!localize_dates_ajax_disabled) {

					// Get localized dates via AJAX.
					$.post(ctmb.ajax_url, {
						'action': 'localize_dates_ajax',
						'nonce': ctmb.localize_dates_nonce,
						'dates': fd,
					}, function (dates_formatted) {

						// Show date(s) formatted.
						$('#' + $(picker.el).attr('id') + '-formatted')
							.hide()
							.html(dates_formatted) // add formatted dates to element for user-friendly display.
							.show();

						// How many dates showing?
						// Show button after if 0 or 1; below if 2+.
						var count = (dates_formatted.match(/ctmb-localized-date/g) || []).length;
						if (count < 2) {
							$('.ctmb-date-container', $field_container)
								.removeClass('ctmb-date-button-after') // don't let it double up.
								.addClass('ctmb-date-button-after');
						} else {
							$('.ctmb-date-container', $field_container)
								.removeClass('ctmb-date-button-after');
						}

						// Hide datepicker when not multiple.
						if (!multiple) {
							$('.datepicker-inline', $field_container).hide();
						}

					});

				}

			}

		}).data('datepicker');

		// Pre-select initial dates from first load (make calendar reflect input).
		var initial_dates = $(this).val();
		if (initial_dates.length) {

			// Convert comma-separated list into array.
			initial_dates = initial_dates.split(',');

			// Array to add date objects to.
			var initial_date_objects = [];

			// Loop dates to add objects to array.
			$.each(initial_dates, function (index, date) {
				initial_date_objects.push(new Date(date.replace(/-/g, '\/'))); // Replace - with / (e.g. 2017-01-01 to 2017/01/01) to prevent his issue: https://stackoverflow.com/a/31732581
			});

			// Temporarily disable localize_dates_ajax to avoid problems / extra resource usage.
			var localize_dates_ajax_disabled = true;

			// Set the date in the calendar (also re-populates the input).
			$datepicker.selectDate(initial_date_objects);

			// Re-enable localize_dates_ajax.
			localize_dates_ajax_disabled = false;

		}

		// Make button show picker.
		$($field_container).on('click', '.button', function (e) {

			// Prevent click from continuing.
			e.preventDefault();

			// Open if calendar not already open.
			if (!$('.datepicker-inline', $field_container).is(':visible')) {
				$('.datepicker-inline', $field_container).show();
			}

			// Close if calendar is already open.
			else {
				$('.datepicker-inline', $field_container).hide();
			}

		});

		// Remove date when "X" clicked in friendly list of dates.
		$($field_container).on('click', '.ctmb-remove-date', function (e) {

			// Prevent click from continuing.
			e.preventDefault();

			// Get date X clicked for.
			var date = $(this).data('ctmb-date');

			// Remove date.
			$datepicker.removeDate(new Date(date.replace(/-/g, '\/'))); // Replace - with / (e.g. 2017-01-01 to 2017/01/01) to prevent his issue: https://stackoverflow.com/a/31732581

		});

	});

	/**************************************
	 * TIMEPICKER
	 **************************************/

	// jQuery Timepicker for 'time' fields
	// https://github.com/jonthornton/jquery-timepicker
	$('.ctmb-time').timepicker({
		noneOption: true,
		timeFormat: ctmb.time_format, // from 12- or 24-hour format (always saved as 24-hour)
		minTime: '06:00' // works for all formats
	});

	/**************************************
	 * READONLY
	 **************************************/

	// Prevent checkbox/radio changes on readonly inputs.
	// readonly attribute itself does not stop changes to checkbox states.
	$('.ctmb-field input[type=checkbox][readonly], .ctmb-field input[type=radio][readonly]').on('click', function (e) {
		return false;
	});

});

/**************************************
 * FUNCTIONS
 **************************************/

// Change visibility of fields based on other fields' values
function ctmb_change_visibility() {

	// Only if ctmb_meta_boxes is defined
	if (typeof ctmb_meta_boxes === 'undefined') {
		return;
	}

	// Loop meta boxes
	jQuery.each(ctmb_meta_boxes, function (meta_box_id, meta_box_settings) {

		// If fields are present
		if (meta_box_settings['fields'] !== undefined) {

			// Loop fields
			jQuery.each(meta_box_settings['fields'], function (field, settings) {

				var $field, conditions, conditions_required, conditions_met;

				// Visibility conditions
				conditions = settings['visibility'];

				// Field element
				$field = jQuery('#ctmb-field-' + field);

				// Don't affect fields never to be shown to the user
				if ($field.hasClass('ctmb-hidden')) {
					return true; // same as continue
				}

				// How many conditions are to be met?
				conditions_required = 0;
				for (i in conditions) {
					if (conditions.hasOwnProperty(i)) {
						conditions_required++;
					}
				}

				// Loop fields to see if other fields allow it to be shown
				conditions_met = 0;
				jQuery.each(conditions, function (condition_field, condition_value) {

					var compare, value, condition_field_selector;

					// Default is to match equally
					compare = '==';

					// Is array used? Get value and compare
					if (jQuery.isArray(condition_value)) {
						compare = condition_value[1];
						condition_value = condition_value[0];
					}

					// Get field value
					// Note: This may be incomplete
					condition_field_selector = '[name=' + condition_field + ']';
					field_type = jQuery(condition_field_selector).prop('type');
					if ('radio' == field_type) {
						value = jQuery(condition_field_selector + ':checked').val();
					} else {
						value = jQuery(condition_field_selector).val();
					}

					// Does the other field's value meet conditions?
					if (
						'==' == compare && condition_value == value
						|| '!=' == compare && condition_value != value
					) {
						conditions_met++;
					}

				});

				// If all conditions met, show field; otherwise hide
				if (conditions_required == conditions_met) {
					$field.show();
				} else {
					$field.hide();
				}

			});

		}

	});

}

// Page template field visibility
function ctmb_page_template_field_visibility(field, page_templates) {

	var page_template, $field_container;

	// Get current page template
	page_template = jQuery('#page_template').val();

	// Get field element to show/hide
	$field_container = jQuery('#ctmb-field-' + field);

	// Check if template is one of the required
	if (jQuery.inArray(page_template, page_templates) !== -1) { // valid template
		$field_container.show();
	} else { // invalid template
		$field_container.hide();
	}

}
