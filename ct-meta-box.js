/**
 * CT Meta Box JavaScript
 */
 
jQuery(document).ready(function($) {

	/**************************************
	 * Media Uploader for Custom Field
	 **************************************/

	// Open media uploader on button click
	var input_element;
	var file_dialog_interval;
	var meta_uploader_open = false;
	$('.ctmb-upload-file').on('click', function() {
	
		// Open uploader dialog
		meta_uploader_open = true;
		var post_id = jQuery('#post_ID').val();
		tb_show('', 'media-upload.php?post_id=' + post_id + '&amp;TB_iframe=true');

		// Input to insert URL into
		input_element = $(this).prev('input');
		
		// Change "Insert into Post" button
		var insert_button_text = $(this).attr('data-ctmb-insert-button');
		if (insert_button_text) {
		
			file_dialog_interval = setInterval(function() {

				// Media uploader dialog was closed
				// This is in case someone closes it some way other than "Use This File" button
				if (!$('#TB_iframeContent').is(':visible')) {

					// Flag it as closed for meta purposes so we can tell main editor to not use it
					// Otherwise images inserted into main editor can go to last meta input
					meta_uploader_open = false;

					// Stop this interval
					// Reset "Insert into Post" button
					clearInterval(file_dialog_interval);
					
				}

				// Change "Insert into Post" button to "Use This File"
				$('#TB_iframeContent').contents().find('.savesend .button').val(insert_button_text);
				
			}, 500); // faster than anyone is likely to navigate

		}
		
		return false;
		
	});
	 
	// "Insert Into Post" button clicked
	window.original_send_to_editor = window.send_to_editor;
	window.send_to_editor = function(html) {

		// Media uploader is open for meta input purposes
		if (meta_uploader_open && input_element.length) {

			// Set URL on input
			var url = $(html).attr('href');
			$(input_element).val(url);			
			
			// Close dialog
			tb_remove();
			
			// Stop the interval and reset "Insert into Post" button
			clearInterval(file_dialog_interval);
			meta_uploader_open = false;
			
		}
		
		// Using uploader for main editor, act normally
		else {
			window.original_send_to_editor(html);
		}
	
	}
	
});