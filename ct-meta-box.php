<?php
/**
 * CT Meta Box
 *
 * This class can be used by themes and plugins to generate meta boxes and custom fields.
 *
 * The CTMB_URL constant must be defined in order for JS/CSS to enqueue.
 * See Church Content plugin for example usage.
 *
 * @package   CT_Meta_Box
 * @copyright Copyright (c) 2013 - 2020, ChurchThemes.com, LLC
 * @link      https://github.com/churchthemes/ct-meta-box
 * @license   GPLv2 or later
 */

// No direct access.
if (! defined( 'ABSPATH' )) {
	exit;
}

// Class may be used in both theme and plugin(s).
if (! class_exists( 'CT_Meta_Box' )) {

	/**
	 * Main class
	 *
	 * @since 0.8.5
	 */
	class CT_Meta_Box {

		/**
		 * Plugin version
		 *
		 * @since 1.0.4
		 * @var string
		 */
		public $version;

		/**
		 * Meta box configuration
		 *
		 * @since 1.0.4
		 * @var array
		 */
		public $meta_box;

		/**
		 * Has date field?
		 *
		 * @since 2.2.3
		 * @var bool
		 */
		public $has_date_field;

		/**
		 * Constructor
		 *
		 * @since 0.8.5
		 * @access public
		 * @param array $meta_box Configuration for meta box and its fields.
		 */
		public function __construct( $meta_box ) {

			// Version - used in cache busting.
			$this->version = '2.2.4';

			// Prepare config.
			$this->prepare( $meta_box );

			// Setup meta box.
			add_action( 'load-post-new.php', array( &$this, 'setup' ) ); // setup meta boxes on add.
			add_action( 'load-post.php', array( &$this, 'setup' ) ); // setup meta boxes on edit.

			// Localize dates via AJAX.
			add_action( 'wp_ajax_localize_dates_ajax', array( &$this, 'localize_dates_ajax' ) );

		}

		/**
		 * Prepare config
		 *
		 * This sets $this->meta_box and adds filtering for the enablement and overriding of fields.
		 *
		 * @since 0.8.5
		 * @access public
		 * @param array $meta_box Configuration for meta box and its fields.
		 */
		public function prepare( $meta_box ) {

			// Filter meta box data (before anything).
			$meta_box = apply_filters( 'ctmb_meta_box', $meta_box );
			if (! empty( $meta_box['id'] )) { // filter meta box by its ID.
				$meta_box = apply_filters( 'ctmb_meta_box-' . $meta_box['id'], $meta_box );
			}

			// Filter fields by meta box ID.
			if (! empty( $meta_box['id'] )) { // filter meta box by its ID.
				$meta_box['fields'] = apply_filters( 'ctmb_fields-' . $meta_box['id'], $meta_box['fields'] );
			}

			// Get fields for looping.
			$fields = $meta_box['fields'];

			// Fill array of visible fields with all by default.
			$visible_fields = array();
			foreach ($fields as $key => $field) {
				$visible_fields[] = $key;
			}

			// Let themes/plugins set explicit visibility for fields of specific post type.
			$visible_fields = apply_filters( 'ctmb_visible_fields-' . $meta_box['post_type'], $visible_fields, $meta_box['post_type'] );

			// Let themes/plugins override specific data for field of specific post type.
			$field_overrides = apply_filters( 'ctmb_field_overrides-' . $meta_box['post_type'], array(), $meta_box['post_type'] ); // by default no overrides.

			// Loop fields to modify them with filtered data.
			foreach ($fields as $key => $field) {

				// Selectively override field data based on filtered array.
				if (! empty( $field_overrides[ $key ] ) && is_array( $field_overrides[ $key ] )) {
					$meta_box['fields'][ $key ] = array_merge( $field, $field_overrides[ $key ] ); // merge filtered in data over top existing data.
				}

				// Set visibility of fields based on filtered or unfiltered array.
				$meta_box['fields'][ $key ]['hidden'] = ! in_array( $key, (array) $visible_fields, true ) ? true : false; // set hidden true if not in array.

				// Allow filtering of individual fields after all other manipulations.
				$meta_box['fields'][ $key ] = apply_filters( 'ctmb_field-' . $key, $meta_box['fields'][ $key ] );

				// Has a date field?
				if (isset( $field['type'] ) && 'date' === $field['type']) {
					$this->has_date_field = true;
				}

			}

			// Make config accessible.
			$this->meta_box = $meta_box;

		}

		/**
		 * Setup meta box
		 *
		 * @since 0.8.5
		 * @access public
		 */
		public function setup() {

			$screen = get_current_screen();

			// Specified post type only.
			if ($screen->post_type === $this->meta_box['post_type']) {

				// Add meta boxes.
				add_action( 'add_meta_boxes', array( &$this, 'add' ) );

				// Save meta boxes.
				add_action( 'save_post', array( &$this, 'save' ), 10, 2 ); // note: this always runs twice (once for revision, once for post).

				// Enqueue styles.
				add_action( 'admin_enqueue_scripts', array( &$this, 'enqueue_styles' ) );

				// Enqueue scripts.
				add_action( 'admin_enqueue_scripts', array( &$this, 'enqueue_scripts' ) );

				// Localize scripts.
				add_action( 'admin_enqueue_scripts', array( &$this, 'localize_scripts' ) );

			}

		}

		/**
		 * Add meta box
		 *
		 * @since 0.8.5
		 * @access public
		 */
		public function add() {

			// Add meta box.
			add_meta_box(
				$this->meta_box['id'],
				esc_html( $this->meta_box['title'] ),
				array( &$this, 'output' ),
				$this->meta_box['post_type'],
				$this->meta_box['context'],
				$this->meta_box['priority'],
				isset( $this->meta_box['callback_args'] ) ? $this->meta_box['callback_args'] : array()
			);

			// Hide if no visible fields.
			add_action( 'admin_head', array( &$this, 'hide' ) );

			// Dynamically show/hide fields based on page template selection.
			add_action( 'admin_head', array( &$this, 'page_template_fields' ) );

		}

		/**
		 * Hide meta box
		 *
		 * If no visible fields in meta box, hide it.
		 *
		 * @since 0.8.5
		 * @access public
		 */
		public function hide() {

			$visible = false;

			// Loop fields to find at least one that is visible.
			$fields = $this->meta_box['fields'];
			foreach ($fields as $field) {

				// Visible?
				if (empty( $field['hidden'] )) {
					$visible = true;
					break;
				}

			}

			// Output CSS to <head> to hide meta box and Screen Options control.
			if (! $visible) {

				?>
				<style type="text/css">
				#<?php echo esc_html( $this->meta_box['id'] ); ?>,
				label[for="<?php echo esc_html( $this->meta_box['id'] ); ?>-hide"] {
					display: none;
				}
				</style>
				<?php

			}

		}

		/**
		 * Page template fields
		 *
		 * If page_templates is specified, that field will dynamically show/hide depending on user's page template selection
		 *
		 * @since 0.8.5
		 * @access public
		 */
		public function page_template_fields() {

			// Only on pages.
			$screen = get_current_screen();
			if ('page' !== $screen->post_type) {
				return;
			}

			// Loop fields.
			$fields = $this->meta_box['fields'];
			foreach ($fields as $key => $field) {

				// Only if has page_templates and not a field that is always hidden.
				if (! empty( $field['page_templates'] ) && empty( $field['hidden'] )) {

					$json_page_templates = wp_json_encode( $field['page_templates'] );

					// Output JavaScript to <head> to handle field visibility based on page template selection.
					?>
					<script type="text/javascript">
					jQuery(document).ready(function($) {
						ctmb_page_template_field_visibility( '<?php echo esc_js( $key ); ?>', <?php echo esc_js( $json_page_templates ); ?> ); // First load.
						$( '#page_template' ).on('change', function() { // Changed page template
							ctmb_page_template_field_visibility( '<?php echo esc_js( $key ); ?>', <?php echo esc_js( $json_page_templates ); ?> );
						});
					});
					</script>
					<?php

				}

			}

		}

		/**
		 * Meta box output
		 *
		 * @since 0.8.5
		 * @access public
		 * @param object $post Post object.
		 * @param array  $args Arguments.
		 */
		public function output( $post, $args ) {

			// Before fields are output.
			do_action( 'ctmb_before_fields', $this );

			// Nonce security.
			wp_nonce_field( $this->meta_box['id'] . '_save', $this->meta_box['id'] . '_nonce' );

			// Loop fields.
			$fields = $this->meta_box['fields'];
			foreach ($fields as $key => $field) {

				// Output field.
				$this->field_output( $key, $field );

			}

			// After fields are output.
			do_action( 'ctmb_after_fields', $this );

		}

		/**
		 * Field output
		 *
		 * @since 0.8.5
		 * @access public
		 * @global object $wp_locale
		 * @param string $key Field key.
		 * @param array  $field Field configuration.
		 */
		public function field_output( $key, $field ) {

			global $wp_locale;

			/**
			 * Field Data
			 */

			// Store data in array so custom output callback can use it.
			$data = array();

			// Get field config.
			$data['key'] = $key;
			$data['field'] = $field;

			// Prepare strings.
			$data['value'] = $this->field_value( $data['key'] ); // saved value or default value if is first add or value not allowed to be empty.
			$data['esc_value'] = esc_attr( $data['value'] );
			$data['esc_element_id'] = 'ctmb-input-' . esc_attr( $data['key'] );

			// Prepare styles for elements.
			// regular-text and small-text are core WP styling.
			$default_classes = array(
				'text'              => 'regular-text',
				'url'               => 'regular-text',
				'upload'            => 'regular-text',
				'upload_textarea'   => '',
				'textarea'          => '',
				'checkbox'          => '',
				'checkbox_multiple' => '',
				'radio'             => '',
				'select'            => '',
				'number'            => 'small-text',
				'range'             => '',
				'date'              => '',
				'time'              => 'regular-text',

			);
			$classes = array();
			$classes[] = 'ctmb-' . $data['field']['type'];
			if (! empty( $default_classes[ $data['field']['type'] ] )) {
				$classes[] = $default_classes[ $data['field']['type'] ];
			}
			if (! empty( $data['field']['class'] )) {
				$classes[] = $data['field']['class'];
			}
			$data['classes'] = implode( ' ', $classes );

			// Name.
			$name = $data['key'];
			if ('checkbox_multiple' === $data['field']['type']) {
				$name .= '[]';
			}

			// Common attributes.
			$data['common_atts'] = 'name="' . esc_attr( $name ) . '" class="' . esc_attr( $data['classes'] ) . '"';
			if (! empty( $data['field']['attributes'] )) { // add custom attributes.
				foreach ($data['field']['attributes'] as $attr_name => $attr_value) {
					$data['common_atts'] .= ' ' . $attr_name . '="' . esc_attr( $attr_value ) . '"';
				}
			}

			// Field container classes.
			$data['field_class'] = array();
			$data['field_class'][] = 'ctmb-field';
			$data['field_class'][] = 'ctmb-field-type-' . $data['field']['type'];
			if (! empty( $data['field']['hidden'] )) { // Hidden (for internal use only, via prepare() filter).
				$data['field_class'][] = 'ctmb-hidden';
			}
			if (! empty( $data['field']['field_class'] )) {
				$data['field_class'][] = $data['field']['field_class']; // append custom classes.
			}
			$data['field_class'] = implode( ' ', $data['field_class'] );

			// Field container styles.
			$data['field_attributes'] = '';
			if (! empty( $data['field']['field_attributes'] )) { // add custom attributes.
				foreach ($data['field']['field_attributes'] as $attr_name => $attr_value) {
					$data['field_attributes'] .= ' ' . $attr_name . '="' . esc_attr( $attr_value ) . '"';
				}
			}

			/**
			 * Form Input
			 */

			// Use custom function to render custom field content.
			if (! empty( $data['field']['custom_field'] )) {
				$input = call_user_func( $data['field']['custom_field'], $data );
			}

			// Standard output based on type.
			else {

				// Switch thru types to render differently.
				$input = '';
				switch ($data['field']['type']) {

					// Text.
					// URL.
					case 'text':
					case 'url': // same as text.

						$input = '<input type="text" ' . $data['common_atts'] . ' id="' . $data['esc_element_id'] . '" value="' . $data['esc_value'] . '" />';

						break;

					// Textarea.
					case 'textarea':

						$input = '<textarea ' . $data['common_atts'] . ' id="' . $data['esc_element_id'] . '">' . esc_textarea( $data['value'] ) . '</textarea>';

						// special esc func for textarea.

						break;

					// Checkbox.
					case 'checkbox':

						$input  = '<input type="hidden" ' . $data['common_atts'] . ' value="" />'; // causes unchecked box to post empty value (helps with default handling)
						$input .= '<label for="' . $data['esc_element_id'] . '">';
						$input .= '	<input type="checkbox" ' . $data['common_atts'] . ' id="' . $data['esc_element_id'] . '" value="1"' . checked( '1', $data['value'], false ) . '/>';
						if (! empty( $data['field']['checkbox_label'] )) {
							$input .= ' ';
							$input .= wp_kses(
								$data['field']['checkbox_label'],
								array(
									'b' => array(),
									'strong' => array(),
									'a' => array(
										'href' => array(),
										'target' => array(),
									),
								)
							);
						}
						$input .= '</label>';

						break;

					// Checkbox Multiple.
					case 'checkbox_multiple':

						if (! empty( $data['field']['options'] )) {

							// Get saved values.
							$values = explode( ',', $data['value'] ); // convert from comma-separated list to array.

							// Make list of checkboxes.
							foreach ($data['field']['options'] as $option_value => $option_text) {

								$esc_checkbox_id = $data['esc_element_id'] . '-' . $option_value;

								// Value checked?
								$checked = '';
								if (in_array( $option_value, $values )) {
									$checked = ' checked="checked"';
								}

								// Make checkbox with label.
								$input .= '<div class="ctmb-checkbox-multiple-container">';
								$input .= '	<label for="' . $esc_checkbox_id . '">';
								$input .= '		<input type="checkbox" ' . $data['common_atts'] . ' id="' . $esc_checkbox_id . '" value="' . esc_attr( $option_value ) . '"' . $checked . '/> ' . esc_html( $option_text );
								$input .= '	</label>';
								$input .= '</div>';

							}

						}

						break;

					// Radio.
					case 'radio':

						if (! empty( $data['field']['options'] )) {

							foreach ($data['field']['options'] as $option_value => $option_text) {

								$esc_radio_id = $data['esc_element_id'] . '-' . $option_value;

								$input .= '<div class="ctmb-radio-container">';
								$input .= '	<label for="' . $esc_radio_id . '">';
								$input .= '		<input type="radio" ' . $data['common_atts'] . ' id="' . $esc_radio_id . '" value="' . esc_attr( $option_value ) . '"' . checked( $option_value, $data['value'], false ) . '/> ' . esc_html( $option_text );
								$input .= '	</label>';
								$input .= '</div>';

							}

						}

						break;

					// Select.
					case 'select':

						if (! empty( $data['field']['options'] )) {

							$input .= '<select ' . $data['common_atts'] . ' id="' . $data['esc_element_id'] . '">';
							foreach ($data['field']['options'] as $option_value => $option_text) {
								$input .= '<option value="' . esc_attr( $option_value ) . '" ' . selected( $option_value, $data['value'], false ) . '> ' . esc_html( $option_text ) . '</option>';
							}
							$input .= '</select>';

						}

						break;

					// Number.
					case 'number':

						$input = '<input type="number" ' . $data['common_atts'] . ' id="' . $data['esc_element_id'] . '" value="' . $data['esc_value'] . '" />';

						break;

					// Range.
					case 'range':

						$input = '<input type="range" ' . $data['common_atts'] . ' id="' . $data['esc_element_id'] . '" value="' . $data['esc_value'] . '" />';

						break;


					// Upload - Regular (URL).
					case 'upload':

						$upload_button = isset( $data['field']['upload_button'] ) ? $data['field']['upload_button'] : '';
						$upload_title = isset( $data['field']['upload_title'] ) ? $data['field']['upload_title'] : '';
						$upload_type = isset( $data['field']['upload_type'] ) ? $data['field']['upload_type'] : '';

						$input  = '<input type="text" ' . $data['common_atts'] . ' id="' . $data['esc_element_id'] . '" value="' . $data['esc_value'] . '" />';
						$input .= ' <input type="button" value="' . esc_attr( $upload_button ) . '" class="upload_button button ctmb-upload-file" data-ctmb-upload-type="' . esc_attr( $upload_type ) . '" data-ctmb-upload-title="' . esc_attr( $upload_title ) . '" /> ';

						break;

					// Upload - Textarea (URL or Embed Code).
					case 'upload_textarea':

						$upload_button = isset( $data['field']['upload_button'] ) ? $data['field']['upload_button'] : '';
						$upload_title = isset( $data['field']['upload_title'] ) ? $data['field']['upload_title'] : '';
						$upload_type = isset( $data['field']['upload_type'] ) ? $data['field']['upload_type'] : '';

						// Textarea (URL or Embed Code).
						$input = '<textarea ' . $data['common_atts'] . ' id="' . $data['esc_element_id'] . '">' . esc_textarea( $data['value'] ) . '</textarea>';

						$input .= ' <input type="button" value="' . esc_attr( $upload_button ) . '" class="upload_button button ctmb-upload-file" data-ctmb-upload-type="' . esc_attr( $upload_type ) . '" data-ctmb-upload-title="' . esc_attr( $upload_title ) . '" /> ';

						break;

					// Date(s).
					case 'date':

						// Single or multiple?
						$multiple = ! empty( $data['field']['date_multiple'] ) ? true : false;

						// How many dates showing?
						// Show button after if 0 or 1; below if 2+.
						$dates_array = explode( ',', $data['value'] );
						$count = count( $dates_array );

						// Open container.
						$input = '<div class="ctmb-date-container' . ( $multiple ? ' ctmb-date-multiple' : '' ) . ( $count < 2 ? ' ctmb-date-button-after' : '' ) . '">';

						// Element to show localized dates in.
						$localized_dates = $this->localize_dates( $data['value'] );
						$input .= '<div id="' . $data['esc_element_id'] . '-formatted" class="ctmb-' . esc_attr( $data['field']['type'] ) . '-formatted"> ' . $localized_dates . ' </div>'; // JavaScript will fill this on load/change.

						// Button for selecting dates.
						$button = isset( $data['field']['date_button'] ) ? $data['field']['date_button'] : '';
						$input .= '<div id="' . $data['esc_element_id'] . '-button-container" class="ctmb-' . esc_attr( $data['field']['type'] ) . '-button-container">';
						$input .= '	<a href="#" id="' . $data['esc_element_id'] . '-button" class="ctmb-' . esc_attr( $data['field']['type'] ) . '-button button">' . esc_html( $button ) . '</a>';
						$input .= '</div>';

						// Input to store comma-separated list of dates in YYYY-mm-dd format.
						// JavaScript hides this since element above shows friendly date list.
						$input .= '<input type="text" ' . $data['common_atts'] . ' id="' . $data['esc_element_id'] . '" value="' . $data['esc_value'] . '" data-date-multiple="' . esc_attr( $multiple ) . '" />';

						// Close container.
						$input .= '</div>';

						break;

					// Time.
					// HTML5 <time> not supported by major browsers. Or, is it yet?
					// Using this instead (like Google Calendar): https://github.com/jonthornton/jquery-timepicker.
					case 'time':

						$input = '<input type="text" ' . $data['common_atts'] . ' id="' . $data['esc_element_id'] . '" value="' . $data['esc_value'] . '" />';

						break;

				}

			}

			/**
			 * Field Container
			 */

			// Output field.
			if (! empty( $input )) { // don't render if type invalid.

				?>
				<div id="ctmb-field-<?php echo esc_attr( $data['key'] ); ?>" class="<?php echo esc_attr( $data['field_class'] ); ?>"<?php echo $data['field_attributes']; ?>>

					<div class="ctmb-name">

						<?php if (! empty( $data['field']['name'] )) : ?>

							<?php echo esc_html( $data['field']['name'] ); ?>

							<?php if (! empty( $data['field']['after_name'] )) : ?>
								<span><?php echo esc_html( $data['field']['after_name'] ); ?></span>
							<?php endif; ?>

						<?php endif; ?>

					</div>

					<div class="ctmb-value">

						<?php echo $input; ?>

						<?php
						if (
							! empty( $data['field']['after_input'] )
							&& in_array( $data['field']['type'], array( 'text', 'select', 'number', 'range', 'upload', 'url', 'date', 'time' ), true ) // specific fields only.
						) :
						?>
							<span class="ctmb-after-input"><?php echo esc_html( $data['field']['after_input'] ); ?></span>
						<?php endif; ?>

						<?php if (! empty( $data['field']['desc'] )) : ?>

							<p class="description">
								<?php
								echo wp_kses(
									$data['field']['desc'],
									array(
										'b' => array(),
										'strong' => array(),
										'a' => array(
											'href' => array(),
											'target' => array(),
										),
										'br' => array(),
									)
								)
								?>
							</p>

						<?php endif; ?>

					</div>

				</div>
				<?php

			}

		}

		/**
		 * Get field value
		 *
		 * Gets saved value or a default value if is first add or if value not allowed to be empty.
		 * This assists with showing and saving/validating fields.
		 *
		 * @since 0.8.5
		 * @access public
		 * @param string $key Field key.
		 * @param bool   $default_only True if only want to check if default should be used.
		 * @return string Saved or default value
		 */
		public function field_value( $key, $default_only = false ) {

			global $post;

			$screen = get_current_screen();

			$value = '';

			// Get saved value, if any.
			if (empty( $default_only )) { // sometimes only want to check if default should be used.
				$value = get_post_meta( $post->ID, $key, true );
			}

			// No saved value.
			if (empty( $value )) {

				// Get field data.
				$field = $this->meta_box['fields'][ $key ];

				// Default is not empty.
				if (! empty( $field['default'] )) {

					// Field cannot be empty, use default.
					if (! empty( $field['no_empty'] )) {
						$value = $field['default'];
					}

					// Field can be empty but this is first add, use default.
					elseif ('post' === $screen->base && 'add' === $screen->action) {
						$value = $field['default'];
					}

				}

			}

			return $value;

		}

		/**
		 * Save meta boxes
		 *
		 * @since 0.8.5
		 * @access public
		 * @param int    $post_id Post ID.
		 * @param object $post Data for post being saved.
		 */
		public function save( $post_id, $post ) {

			// Is a POST occurring?
			if (empty( $_POST )) {
				return false;
			}

			// Not an auto-save (meta values not submitted).
			if (defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE) {
				return false;
			}

			// Verify the nonce.
			$nonce_key = $this->meta_box['id'] . '_nonce';
			$nonce_action = $this->meta_box['id'] . '_save';
			if (empty( $_POST[ $nonce_key ] ) || ! wp_verify_nonce( $_POST[ $nonce_key ], $nonce_action )) {
				return false;
			}

			// Make sure user has permission to edit.
			$post_type = get_post_type_object( $post->post_type );
			if (! current_user_can( $post_type->cap->edit_post, $post_id )) {
				return false;
			}

			// Get fields.
			$fields = $this->meta_box['fields'];

			// Sanitize fields.
			$sanitized_values = array();
			foreach ($fields as $key => $field) {

				// Sanitize value.
				// General sanitization and sanitization based on field type, options, no_empty, etc.
				$sanitized_values[ $key ] = $this->sanitize_field_value( $key );

			}

			// Run additional custom sanitization function if config requires it.
			if (! empty( $this->meta_box['custom_sanitize'] )) {
				$sanitized_values = call_user_func( $this->meta_box['custom_sanitize'], $sanitized_values );
			}

			// Save fields.
			foreach ($sanitized_values as $key => $value) {

				// Add value if it key does not exist.
				// Or upate value it key does exist.
				// Note: see old code below which deleted key if empty value.
				// This is no longer done because it causes posts with empty values to disappear when sorted on that key!
				update_post_meta( $post_id, $key, $value );

				/* Old way causes sorting problems (see note above)

				// Update value or add if key does not exist
				if ( ! empty( $value ) ) {
					update_post_meta( $post_id, $key, $value );
				}

				// Delete key if value is empty and key exists in database
				else if ( get_post_meta( $post_id, $key, true ) ) {
					delete_post_meta( $post_id, $key );
				}

				*/

			}

		}

		/**
		 * Sanitize field value
		 *
		 * Sanitize field's POST value before saving.
		 * Provides General sanitization and sanitization based on field type, options, no_empty, etc.
		 *
		 * @since 0.8.5
		 * @access public
		 * @param string $key Field key.
		 * @return mixed Sanitized value
		 */
		public function sanitize_field_value( $key ) {

			global $post_id, $post, $allowedposttags;

			// Get posted value.
			$input = isset( $_POST[ $key ] ) ? $_POST[ $key ] : '';

			// General sanitization.
			if (is_array( $input )) {
				$output = array_map( 'stripslashes', $input );
				$output = array_map( 'trim', $output );
			} else {
				$output = stripslashes( $input );
			}

			// Empty value if specific page templates required but not used.
			if (! empty( $output ) && ! empty( $this->meta_box['fields'][ $key ]['page_templates'] ) && ( ! isset( $_POST['page_template'] ) || ! in_array( $_POST['page_template'], $this->meta_box['fields'][ $key ]['page_templates'] ) )) {
				$output = '';
			}

			// Sanitize based on type.
			switch ($this->meta_box['fields'][ $key ]['type']) {

				// Text.
				// Textarea.
				case 'text':
				case 'textarea':

					// Strip tags if config does not allow HTML.
					if (empty( $this->meta_box['fields'][ $key ]['allow_html'] )) {
						$output = trim( strip_tags( $output ) );
					}

					// Sanitize HTML in case used (remove evil tags like script, iframe) - same as post content
					if (! current_user_can( 'unfiltered_html' )) { // Admin only.
						$output = stripslashes( wp_filter_post_kses( addslashes( $output ), $allowedposttags ) );
					}

					break;

				// Checkbox.
				case 'checkbox':

					$output = ! empty( $output ) ? '1' : '';

					break;

				// Checkbox Multiple.
				case 'checkbox_multiple':

					// Get posted values and ensure array format.
					$posted_values = (array) $output;

					// Loop array of valid option values to build sanitized array in correct order.
					$output = array(); // start fresh.
					foreach ($this->meta_box['fields'][ $key ]['options'] as $option_value => $option_text) {

						// Posted value is valid, add to new array.
						// Note: this fails if add true flag for strict comparison.
						if (in_array( $option_value, $posted_values )) {
							$output[] = $option_value;
						}

					}

					// Create comma-separated list.
					if (! empty( $output )) {
						$output = implode( ',', $output );
					} else {
						$output = ''; // empty string if no data.
					}

					break;

				// Radio.
				// Select.
				case 'radio':
				case 'select':

					// If option invalid, blank it so default will be used.
					if (! isset( $this->meta_box['fields'][ $key ]['options'][ $output ] )) {
						$output = '';
					}

					break;

				// Number.
				case 'number':

					$output = (int) $output; // force number.

					break;

				// Range.
				case 'range':

					$output = (int) $output; // force number.

					break;

				// URL.
				// Upload (value is URL).
				case 'url':
				case 'upload': // Regular (URL).

					$output = esc_url_raw( $output ); // force valid URL or use nothing.

					break;

				// Upload Textarea (URL or Embed Code).
				case 'upload_textarea':

					// URL?
					if (preg_match( '/^(http(s*)):\/\//i', $output )) { // if begins with http:// or https://, must be URL.
						$output = esc_url_raw( $output ); // force valid URL or use nothing.
					}

					// Otherwise it must be embed code, so HTML is always allowed.
					// <script>, <iframe>, etc. will be allowed for all users.

					break;

				// Date(s).
				// This could be one or multiple dates in YYYY-mm-dd format.
				// If multiple, save as comma-separated list.
				case 'date':

					// Make array out of list of dates.
					$dates = explode( ',', $output );

					// Make validated list of dates.
					$valid_dates = array();
					foreach ($dates as $date) {

						// Trim, just in case.
						$date = trim( $date );

						// Have date value with proper format?
						if ($date && preg_match( '/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $date )) {

							// Extract month, day, year from YYYY-MM-DD date.
							list( $y, $m, $d ) = explode( '-', $date );

							// Validate year, month and date (e.g. no February 31).
							if (checkdate( $m, $d, $y )) {

								// Add valid date to array to be made into list.
								$valid_dates[] = "$y-$m-$d";

							}

						}

					}

					// Make cleaned list of dates.
					// Comma-separated list without spaces, having valid dates in YYYY-mm-dd format.
					if ($valid_dates) {
						$valid_dates = array_unique( $valid_dates ); // no duplicate dates.
						$output = implode( ',', $valid_dates ); // array to list.
					} else { // if no valid dates, save empty.
						$output = '';
					}

					break;

				// Time.
				case 'time':

					// Is time valid?
					// If it's not valid 12 or 24 hour format, date will return 1970 instead of current year.
					$ts = strtotime( $output );
					if (date( 'Y', $ts ) == date( 'Y' )) {

						// Convert to 24 hour time.
						// Easier sorting and comparison.
						$output  = date( 'H:i', $ts );

					} else {
						$output = ''; // return empty if invalid.
					}

					break;

			}

			// Run additional custom sanitization function if config requires it.
			if (! empty( $this->meta_box['fields'][ $key ]['custom_sanitize'] )) {
				$output = call_user_func( $this->meta_box['fields'][ $key ]['custom_sanitize'], $output );
			}

			// Sanitization left value empty but empty is not allowed, use default.
			$output = trim( $output );
			if (empty( $output )) {
				$output = $this->field_value( $key, 'default_only' ); // won't try to get saved value.
			}

			// Return sanitized value.
			return $output;

		}

		/**
		 * Enqueue stylesheets.
		 *
		 * @since 0.8.5
		 * @access public
		 */
		public function enqueue_styles() {

			global $ctmb_styles_enqueued;

			// Styles need not be enqueued for every meta box on a page.
			if (! empty( $ctmb_styles_enqueued )) {
				return;
			} else {
				$ctmb_styles_enqueued = true;
			}

			// Get current screen.
			$screen = get_current_screen();

			// Add/edit any post type.
			if ('post' === $screen->base) {

				// Always enable thickbox on add/edit post for custom meta upload fields.
				wp_enqueue_style( 'thickbox' );

				// Air Datepicker.
				if (! empty( $this->has_date_field )) {  // only if have a date field, to prevent conflicts with jQuery UI Datepicker.
					wp_enqueue_style( 'air-datepicker', trailingslashit( CTMB_URL ) . 'css/datepicker.min.css', false, $this->version ); // bust cache on update.
				}

				// jQuery Timepicker.
				// https://github.com/jonthornton/jquery-timepicker
				wp_enqueue_style( 'jquery-timepicker', trailingslashit( CTMB_URL ) . 'css/jquery.timepicker.css', false, $this->version ); // bust cache on update.

				// Meta boxes stylesheet.
				wp_enqueue_style( 'ctmb-meta-boxes', trailingslashit( CTMB_URL ) . 'css/ct-meta-box.css', false, $this->version ); // bust cache on update.

			}

		}

		/**
		 * Enqueue scripts
		 *
		 * These are scripts used by all meta boxes on a page.
		 * This is enqueued once per page in constructor.
		 *
		 * @since 0.8.5
		 * @access public
		 */
		public function enqueue_scripts() {

			global $ctmb_scripts_enqueued;

			// Scripts need not be enqueued for every meta box on a page.
			if (! empty( $ctmb_scripts_enqueued )) {
				return;
			} else {
				$ctmb_scripts_enqueued = true;
			}

			// Get current screen.
			$screen = get_current_screen();

			// Add/edit any post type.
			if ('post' === $screen->base) {

				// Always enable media-upload and thickbox on add/edit post for custom meta upload fields.
				wp_enqueue_script( 'media-upload' );
				wp_enqueue_script( 'thickbox' );

				// Air Datepicker.
				if (! empty( $this->has_date_field )) { // only if have a date field, to prevent conflicts with jQuery UI Datepicker.

					// Enqueue Air Datepicker.
					wp_enqueue_script( 'air-datepicker', trailingslashit( CTMB_URL ) . 'js/datepicker.min.js', false, $this->version ); // bust cache on update.

					// Deregister jQuery UI Datepicker to prevent conflicts with other plugins since Air Datepicker uses same name.
					// This is a workaround until Air Datepicker uses distinct name. https://github.com/t1m0n/air-datepicker/issues/170.
					wp_deregister_script( 'jquery-ui-datepicker' );

				}

				// jQuery Timepicker.
				// https://github.com/jonthornton/jquery-timepicker
				wp_enqueue_script( 'jquery-timepicker', trailingslashit( CTMB_URL ) . 'js/jquery.timepicker.min.js', false, $this->version ); // bust cache on update.

				// Meta boxes JavaScript.
				wp_enqueue_script( 'ctmb-meta-boxes', trailingslashit( CTMB_URL ) . 'js/ct-meta-box.js', false, $this->version ); // bust cache on update.

			}

		}

		/**
		 * Localize scripts
		 *
		 * @since 2.0
		 * @access public
		 */
		public function localize_scripts() {

			global $ctmb_scripts_localized_globally, $ctmb_fields_localized;

			// Get current screen.
			$screen = get_current_screen();

			// Add/edit any post type.
			if ('post' === $screen->base) {

				// Global localization.
				// This is data all meta boxes will use.
				// Run this localization once per page, not on every meta box instantiation.
				if (empty( $ctmb_scripts_localized_globally )) {

					// Time format 12- or 24-hour format.
					$time_format = get_option( 'time_format' ); // from General Settings.
					if (! in_array( $time_format, array( 'g:i a', 'g:ia', 'g:i A', 'g:iA', 'H:i' ) )) {

						// If user enters a custom format then default this to 12-hour format.
						// It is most common in English-speaking countries and most others are able to recognize it.
						// The reason for this is that a custom format may be invalid, causing the timepicker to fail
						// converting it to the 24-hour format for saving.
						$time_format = 'g:i a'; // default WordPress time format.

					}

					// Data to pass.
					wp_localize_script( 'ctmb-meta-boxes', 'ctmb', array(
						'ajax_url'             => admin_url( 'admin-ajax.php' ),
						'localize_dates_nonce' => wp_create_nonce( 'ctmb_localize_dates' ),
						'datepicker_language'  => $this->datepicker_language(),
						'time_format'          => $time_format, // time format from Settings > General.
						'week_days'            => $this->week_days(), // to show translated week day date fields.
					) );

					// Make sure this is done only once (on first meta box).
					$ctmb_scripts_localized_globally = true;

				}

				// Localization per meta box
				// This will output a ctmb_meta_boxes var having data box merged in for each, the latest having all
				// It is not ideal to output multiple vars of same name, so see if there is a better way
				// (maybe WordPress will in the future cause duplicate names to override instead)
				$data[ $this->meta_box['id'] ] = $this->js_meta_box(); // pass in only as much meta box / field data as necessary.
				$ctmb_fields_localized = empty( $ctmb_fields_localized ) ? array() : $ctmb_fields_localized;
				if (! empty( $data[ $this->meta_box['id'] ] )) { // if there is anything to add.
					$ctmb_fields_localized = array_merge( $ctmb_fields_localized, $data );
					wp_localize_script( 'ctmb-meta-boxes', 'ctmb_meta_boxes', $ctmb_fields_localized );
				}

			}

		}

		/**
		 * Localize dates AJAX
		 *
		 * date and date_multiple fields send YYYY-mm-dd format to this method via AJAX.
		 * This method outputs the localized dates using the site's date_format setting.
		 * ct-meta-box.js then shows the localized/formatted dates for the user to see.
		 *
		 * @since 2.2
		 * @access public
		 */
		public function localize_dates_ajax() {

			// Only if is AJAX request.
			if (! ( defined( 'DOING_AJAX' ) && DOING_AJAX )) {
				exit;
			}

			// Check nonce.
			check_ajax_referer( 'ctmb_localize_dates', 'nonce' );

			// Check user capabilities add/edit.
			if (! current_user_can( 'edit_posts' )) {
				exit;
			}

			// Get selected dates.
			$dates = sanitize_text_field( $_POST['dates'] );

			// Convert list of YYYY-mm-dd formatted dates into list of localize dates using date_format setting.
			$dates_localized = $this->localize_dates( $dates );

			// Send friendly dates to client-side for adding to element.
			echo apply_filters( 'localize_dates_ajax', $dates_localized );

			// Done.
			exit;

		}

		/**
		 * Localize dates
		 *
		 * Convert a comma-separated list of dates in YYYY-mm-dd into localized list of dates
		 * using the website's date_format setting.
		 *
		 * @since 2.2
		 * @access public
		 * @param string $dates Comma-separated list of dates in YYYY-mm-dd format.
		 */
		public function localize_dates( $dates ) {

			// Empty if no valid dates.
			$dates_localized = '';

			// Get date format.
			$date_format = get_option( 'date_format' );

			// Have dates.
			if ($dates) {

				// Convert to array.
				$dates = explode( ',', $dates );

				// Sort low to high.
				asort( $dates );

				// Count dates.
				$count = count( $dates );

				// Array to add dates to.
				$dates_localized = array();

				// Add formatted dates to array.
				foreach ($dates as $date) {

					// Trim just in case.
					$date = trim( $date );

					// Make sure in YYYY-mm-dd format and date is valid.
					// Keep strotime from creating a wrong timestamp from an invalid date.
					if (! $this->valid_date( $date )) {
						continue;
					}

					// Convert to timestamp.
					$ts = strtotime( $date );

					// Localized date.
					$date_localized = esc_html( date_i18n( $date_format, $ts ) );

					// Add day of week to end if only showing one date and day of week not already present in the date format.
					if (1 === $count && ! preg_match( '/l|N|w/', $date_format )) {
						$date_localized .= ' &ndash; <span class="ctmb-date-day-of-week">' . esc_html( date_i18n( 'l', $ts ) ) . '</span>';
					}

					// Add icon for removing date.
					$date_localized .= '<a href="#" class="ctmb-remove-date dashicons dashicons-no-alt" data-ctmb-date="' . esc_attr( $date ) . '"></a>';

					// Format/localize and add to array.
					$dates_localized[] = '<span class="ctmb-localized-date">' . $date_localized . '</span>';

				}

				// Concatenate into comma-separated list of dates.
				$dates_localized = implode( '', $dates_localized );

			}

			// Send friendly dates to client-side for adding to element.
			return apply_filters( 'ctmb_localize_dates', $dates_localized, $dates );

		}

		/**
		 * Datepicker language
		 *
		 * Dynamically provide language to Air Datepicker based on WordPress settings.
		 * This uses native WordPress functions for localizing calendar (same as calendar widget).
		 *
		 * @since 2.2
		 * @access public
		 * @global object $wp_locale
		 * @return array Localized text strings for Air Datepicker.
		 */
		public function datepicker_language() {

			global $wp_locale;

			$language = array();

			// Days of week.
			for ($day = 0; $day < 7; $day++) {

				// Sunday - Saturday
				//$language['days']        = array( 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday' );
				$day_name = $wp_locale->get_weekday( $day );
				$language['days'][] = $day_name;

				// Sun - Sat
				//$language['daysShort']   = array( 'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat' );
				$language['daysShort'][] = $wp_locale->get_weekday_abbrev( $day_name );

				// S - S
				// Air Datepicker uses SU - SA but WordPress provides single-letter abbreviation; same as Calendar widget.
				//$language['daysMin']     = array( 'SU', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa' );
				$language['daysMin'][] = $wp_locale->get_weekday_initial( $day_name );

			}

			// Months of year.
			for ($month = 1; $month <= 12; $month++) {

				// Pad with leading zero.
				$month_padded = str_pad( $month, 2, '0', STR_PAD_LEFT );

				// January - December.
				//$language['months']      = array( 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December' );
				$month_name = $wp_locale->get_month( $month_padded );
				$language['months'][] = $month_name;

				// Jan - Dec.
				//$language['monthsShort'] = array( 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec' );
				$language['monthsShort'][] = $wp_locale->get_month_abbrev( $month_name );

			}

			// Sunday as first day of week.
			$language['firstDay'] = absint( get_option( 'start_of_week' ) );

			// Return filtered.
			return apply_filters( 'datepicker_language', $language );

		}

		/**
		 * Days of week, localized
		 *
		 * @since 2.0
		 * @access public
		 * @return array Array of days of week with 0 - 6 as keys and Sunday - Saturday translated as values
		 */
		public function week_days() {

			global $wp_locale;

			$week_days = array();

			for ($day = 0; $day < 7; $day++) {
				$week_days[ $day ] = $wp_locale->get_weekday( $day );
			}

			return $week_days;

		}

		/**
		 * Meta box and field data for JavaScript
		 *
		 * Provide only as much data as is needed
		 *
		 * @since 2.0
		 * @access public
		 * @return array Array of meta box and field settings
		 */
		public function js_meta_box() {

			$js_meta_box = array();

			// Loop fields.
			$fields = $this->meta_box['fields'];
			foreach ($fields as $key => $field) {

				// For now only visibility data is needed for each field.
				if (empty( $field['visibility'] )) {
					continue;
				}

				$js_meta_box['fields'][ $key ]['visibility'] = $field['visibility'];

			}

			return $js_meta_box;

		}

		/**
		 * Validate YYYY-mm-dd date format.
		 *
		 * This is a helpful check to run before using strtotime.
		 *
		 * @since 2.2
		 * @access public
		 * @param string $date Date in YYYY-mm-dd format (ie. 2017-01-20).
		 * @return bool True if date is valid and in YYYY-mm-dd format
		 */
		public function valid_date( $date ) {

			$valid = false;

			// Have date value with proper format of YYYY-mm-dd.
			if ($date && preg_match( '/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $date )) {

				// Extract month, day, year from YYYY-mm-dd date.
				list( $y, $m, $d ) = explode( '-', $date );

				// Validate year, month and date (e.g. no February 31).
				if (checkdate( $m, $d, $y )) {
					$valid = true;
				}

			}

			return $valid;

		}

	}

}
