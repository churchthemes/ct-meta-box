/**
 * CT Meta Box JavaScript
 */

jQuery(document).ready(function($) {

	/**************************************
	 * MEDIA UPLOADER
	 **************************************/

	// Open media uploader on button click
	$('body').on('click', '.ctmb-upload-file', function(event) {

		// Stop click to URL
		event.preventDefault();

		// Input element
		$input_element = $(this).prev('input, textarea');

		// Media frame
		var frame = wp.media({
			title : $(this).attr('data-ctmb-upload-title'),
			library : { type : $(this).attr('data-ctmb-upload-type') },
			multiple : false
		});

		// Open media frame
		// To Do: Set current attachment after opening
		// (How with only URL? For doing with ID, see this: http://bit.ly/Zut80f)
		frame.open();

		// Set attachment URL on click of button
		// (don't do on 'close' so user can cancel)
		frame.on('select', function() {

			// Get attachment data
			var attachments = frame.state().get('selection').toJSON();
			var attachment = attachments[0];

			// An attachment is selected
			if (typeof attachment != 'undefined') {

				// Set attachment URL on input
				if (attachment.url) {
					$input_element.val(attachment.url); // input is directly before button
				}

			}

		});

	});

});

/**************************************
 * PAGE TEMPLATES
 **************************************/

function ctmb_page_template_field_visibility( field, page_templates ) {

	// Get current page template
	var page_template = jQuery( '#page_template' ).val();

	// Get field element to show/hide
	var $field_container = jQuery( '#ctmb-field-' + field );

	// Check if template is one of the required
	if ( jQuery.inArray( page_template, page_templates ) !== -1 ) { // valid template
		$field_container.show();
	} else { // invalid template
		$field_container.hide();
	}

}
