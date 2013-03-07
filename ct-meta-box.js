/**
 * CT Meta Box JavaScript
 */

jQuery(document).ready(function($) {

	/**************************************
	 * Media Uploader for Custom Field
	 **************************************/

	// Open media uploader on button click
	$('body').on('click', '.ctmb-upload-file', function(event) {

		// Stop click to URL
		event.preventDefault();

		// Input element
		$input_element = $(this).prev('input');

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