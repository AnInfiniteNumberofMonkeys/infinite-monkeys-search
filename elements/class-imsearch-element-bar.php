<?php
/**
 * Bricks element: Search bar.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class IMSearch_Element_Bar extends \Bricks\Element {

	public $category     = 'imsearch';
	public $name         = 'imsearch-bar';
	public $icon         = 'ti-search';
	public $css_selector = '.imsearch-field';
	public $scripts      = array( 'imsearchBricksInit' );

	public function get_label() {
		return esc_html__( 'Search bar', 'imsearch' );
	}

	public function get_keywords() {
		return array( 'search', 'autocomplete', 'imsearch', 'infinite monkeys' );
	}

	public function enqueue_scripts() {
		IMSearch_Frontend::enqueue();
	}

	public function set_control_groups() {
		$this->control_groups['scope']   = array( 'title' => esc_html__( 'Search scope', 'imsearch' ) );
		$this->control_groups['button']  = array( 'title' => esc_html__( 'Button & icons', 'imsearch' ) );
		$this->control_groups['spinner'] = array( 'title' => esc_html__( 'Spinner', 'imsearch' ) );
		$this->control_groups['field']   = array( 'title' => esc_html__( 'Field', 'imsearch' ) );
		$this->control_groups['overlay'] = array( 'title' => esc_html__( 'Overlay', 'imsearch' ) );
	}

	public function set_controls() {
		$this->controls['placeholder'] = array(
			'label'          => esc_html__( 'Placeholder', 'imsearch' ),
			'type'           => 'text',
			'inline'         => true,
			'hasDynamicData' => false,
			'default'        => esc_html__( 'Search…', 'imsearch' ),
		);

		$this->controls['submitIsButton'] = array(
			'label'       => esc_html__( 'Search icon is submit button', 'imsearch' ),
			'type'        => 'checkbox',
			'description' => esc_html__( 'On: a button at the right of the field runs the search when clicked. Off: an icon sits at the left of the field and results appear as you type.', 'imsearch' ),
		);

		// SCOPE.
		$this->controls['scopeInfo'] = array(
			'group'   => 'scope',
			'type'    => 'info',
			'content' => esc_html__( 'The title is always searched.', 'imsearch' ),
		);
		foreach ( array(
			'searchDescription' => esc_html__( 'Description / content', 'imsearch' ),
			'searchSku'         => esc_html__( 'SKU (including variations)', 'imsearch' ),
			'searchCategories'  => esc_html__( 'Categories', 'imsearch' ),
			'searchTags'        => esc_html__( 'Tags', 'imsearch' ),
			'searchBrands'      => esc_html__( 'Brands', 'imsearch' ),
		) as $key => $label ) {
			$this->controls[ $key ] = array(
				'group'   => 'scope',
				'label'   => $label,
				'type'    => 'checkbox',
				'default' => true,
			);
		}
		$meta = array();
		foreach ( array_unique( array_merge( IMSearch_Settings::discover_meta_keys(), (array) IMSearch_Settings::get( 'index_meta_keys' ) ) ) as $key ) {
			$meta[ $key ] = $key;
		}
		ksort( $meta );
		$this->controls['searchCustomFields'] = array(
			'group'       => 'scope',
			'label'       => esc_html__( 'Custom fields', 'imsearch' ),
			'type'        => 'select',
			'options'     => $meta,
			'multiple'    => true,
			'searchable'  => true,
			'placeholder' => esc_html__( 'None', 'imsearch' ),
			'description' => esc_html__( 'Fields you pick are added to the search index automatically when you save (the index rebuilds in the background).', 'imsearch' ),
		);
		$this->controls['searchTaxonomies'] = array(
			'group'       => 'scope',
			'label'       => esc_html__( 'Other taxonomies', 'imsearch' ),
			'type'        => 'select',
			'options'     => IMSearch_Bricks::taxonomy_options( array_merge( array( 'product_cat', 'product_tag', 'category', 'post_tag' ), IMSearch_Settings::brand_taxonomies() ) ),
			'multiple'    => true,
			'searchable'  => true,
			'placeholder' => esc_html__( 'None', 'imsearch' ),
			'description' => esc_html__( 'Match products whose terms in these taxonomies match the search.', 'imsearch' ),
		);

		// BUTTON & ICONS.
		$this->controls['submitContent'] = array(
			'group'       => 'button',
			'label'       => esc_html__( 'Search/submit button', 'imsearch' ),
			'type'        => 'select',
			'inline'      => true,
			'options'     => array(
				'text' => esc_html__( 'Text', 'imsearch' ),
				'icon' => esc_html__( 'Icon', 'imsearch' ),
			),
			'placeholder' => esc_html__( 'Text', 'imsearch' ),
			'required'    => array( 'submitIsButton', '=', true ),
		);
		$this->controls['submitText'] = array(
			'group'          => 'button',
			'label'          => esc_html__( 'Button text', 'imsearch' ),
			'type'           => 'text',
			'inline'         => true,
			'hasDynamicData' => false,
			'placeholder'    => esc_html__( 'SEARCH', 'imsearch' ),
			'required'       => array(
				array( 'submitIsButton', '=', true ),
				array( 'submitContent', '!=', 'icon' ),
			),
		);
		$this->controls['submitIcon'] = array(
			'group'    => 'button',
			'label'    => esc_html__( 'Button icon', 'imsearch' ),
			'type'     => 'icon',
			'required' => array(
				array( 'submitIsButton', '=', true ),
				array( 'submitContent', '=', 'icon' ),
			),
		);
		$this->controls['searchIcon'] = array(
			'group'    => 'button',
			'label'    => esc_html__( 'Search icon', 'imsearch' ),
			'type'     => 'icon',
			'required' => array( 'submitIsButton', '!=', true ),
		);
		$this->controls['clearIcon'] = array(
			'group'       => 'button',
			'label'       => esc_html__( 'Clear icon', 'imsearch' ),
			'type'        => 'icon',
			'description' => esc_html__( 'Shown at the right of the field once something is typed; clears the field.', 'imsearch' ),
		);
		$this->controls['iconColor'] = array(
			'group' => 'button',
			'label' => esc_html__( 'Search & clear icon color', 'imsearch' ),
			'type'  => 'color',
			'css'   => array(
				array(
					'property' => 'color',
					'selector' => '.imsearch-search-icon',
				),
				array(
					'property' => 'color',
					'selector' => '.imsearch-clear',
				),
			),
		);
		$this->controls['iconSize'] = array(
			'group' => 'button',
			'label' => esc_html__( 'Search & clear icon size', 'imsearch' ),
			'type'  => 'number',
			'units' => true,
			'css'   => array(
				array(
					'property' => '--imsearch-icon-size',
					'selector' => '',
				),
			),
		);
		$this->controls['buttonSep'] = array(
			'group'    => 'button',
			'type'     => 'separator',
			'label'    => esc_html__( 'Button style', 'imsearch' ),
			'required' => array( 'submitIsButton', '=', true ),
		);
		$this->controls['buttonBackground'] = array(
			'group'    => 'button',
			'label'    => esc_html__( 'Background color', 'imsearch' ),
			'type'     => 'color',
			'css'      => array(
				array(
					'property' => '--imsearch-button-bg',
					'selector' => '',
				),
			),
			'required' => array( 'submitIsButton', '=', true ),
		);
		$this->controls['buttonTypography'] = array(
			'group'    => 'button',
			'label'    => esc_html__( 'Typography', 'imsearch' ),
			'type'     => 'typography',
			'css'      => array(
				array(
					'property' => 'font',
					'selector' => '.imsearch-submit',
				),
			),
			'required' => array( 'submitIsButton', '=', true ),
		);
		$this->controls['buttonIconSize'] = array(
			'group'    => 'button',
			'label'    => esc_html__( 'Button icon size', 'imsearch' ),
			'type'     => 'number',
			'units'    => true,
			'css'      => array(
				array(
					'property' => '--imsearch-button-icon-size',
					'selector' => '',
				),
			),
			'required' => array(
				array( 'submitIsButton', '=', true ),
				array( 'submitContent', '=', 'icon' ),
			),
		);
		$this->controls['buttonPadding'] = array(
			'group'    => 'button',
			'label'    => esc_html__( 'Padding', 'imsearch' ),
			'type'     => 'spacing',
			'css'      => array(
				array(
					'property' => 'padding',
					'selector' => '.imsearch-submit',
				),
			),
			'required' => array( 'submitIsButton', '=', true ),
		);
		$this->controls['buttonBorder'] = array(
			'group'    => 'button',
			'label'    => esc_html__( 'Border', 'imsearch' ),
			'type'     => 'border',
			'css'      => array(
				array(
					'property' => 'border',
					'selector' => '.imsearch-submit',
				),
			),
			'required' => array( 'submitIsButton', '=', true ),
		);
		$this->controls['buttonArrow'] = array(
			'group'    => 'button',
			'label'    => esc_html__( 'Arrow size', 'imsearch' ),
			'type'     => 'number',
			'units'    => true,
			'css'      => array(
				array(
					'property' => '--imsearch-arrow-size',
					'selector' => '',
				),
			),
			'placeholder' => '8px',
			'required' => array( 'submitIsButton', '=', true ),
		);

		// SPINNER.
		$this->controls['spinner'] = array(
			'group'       => 'spinner',
			'label'       => esc_html__( 'Design', 'imsearch' ),
			'type'        => 'select',
			'inline'      => true,
			'options'     => array(
				'1' => esc_html__( '1 - Ring', 'imsearch' ),
				'2' => esc_html__( '2 - Pulse', 'imsearch' ),
				'3' => esc_html__( '3 - Dots circle', 'imsearch' ),
				'4' => esc_html__( '4 - Three dots', 'imsearch' ),
				'5' => esc_html__( '5 - Rolling dots', 'imsearch' ),
				'6' => esc_html__( '6 - Falling dots', 'imsearch' ),
			),
			'placeholder' => esc_html__( '1 - Ring', 'imsearch' ),
		);
		$this->controls['spinnerColor'] = array(
			'group' => 'spinner',
			'label' => esc_html__( 'Color', 'imsearch' ),
			'type'  => 'color',
			'css'   => array(
				array(
					'property' => '--imsearch-spinner-color',
					'selector' => '',
				),
			),
		);
		$this->controls['spinnerSize'] = array(
			'group'       => 'spinner',
			'label'       => esc_html__( 'Size', 'imsearch' ),
			'type'        => 'number',
			'units'       => true,
			'placeholder' => '20px',
			'css'         => array(
				array(
					'property' => '--imsearch-spinner-size',
					'selector' => '',
				),
			),
		);
		$this->controls['previewSpinner'] = array(
			'group'       => 'spinner',
			'label'       => esc_html__( 'Preview in builder', 'imsearch' ),
			'type'        => 'checkbox',
			'default'     => true,
			'description' => esc_html__( 'Shows the spinner in the editor canvas so you can style it. Never shown on the live site until a search runs.', 'imsearch' ),
		);

		// FIELD.
		$this->controls['fieldHeight'] = array(
			'group' => 'field',
			'label' => esc_html__( 'Height', 'imsearch' ),
			'type'  => 'number',
			'units' => true,
			'css'   => array(
				array(
					'property' => 'height',
					'selector' => '.imsearch-form',
				),
			),
		);
		$this->controls['fieldBackground'] = array(
			'group' => 'field',
			'label' => esc_html__( 'Background color', 'imsearch' ),
			'type'  => 'color',
			'css'   => array(
				array(
					'property' => 'background-color',
					'selector' => '.imsearch-field',
				),
			),
		);
		$this->controls['fieldBorder'] = array(
			'group' => 'field',
			'label' => esc_html__( 'Border', 'imsearch' ),
			'type'  => 'border',
			'css'   => array(
				array(
					'property' => 'border',
					'selector' => '.imsearch-field',
				),
			),
		);
		$this->controls['fieldBoxShadow'] = array(
			'group' => 'field',
			'label' => esc_html__( 'Box shadow', 'imsearch' ),
			'type'  => 'box-shadow',
			'css'   => array(
				array(
					'property' => 'box-shadow',
					'selector' => '.imsearch-field',
				),
			),
		);
		$this->controls['fieldPadding'] = array(
			'group' => 'field',
			'label' => esc_html__( 'Padding', 'imsearch' ),
			'type'  => 'spacing',
			'css'   => array(
				array(
					'property' => 'padding',
					'selector' => '.imsearch-input',
				),
			),
		);
		$this->controls['fieldTypography'] = array(
			'group' => 'field',
			'label' => esc_html__( 'Typography', 'imsearch' ),
			'type'  => 'typography',
			'css'   => array(
				array(
					'property' => 'font',
					'selector' => '.imsearch-input',
				),
			),
		);
		$this->controls['placeholderColor'] = array(
			'group' => 'field',
			'label' => esc_html__( 'Placeholder color', 'imsearch' ),
			'type'  => 'color',
			'css'   => array(
				array(
					'property' => 'color',
					'selector' => '.imsearch-input::placeholder',
				),
			),
		);

		// OVERLAY.
		$this->controls['useOverlay'] = array(
			'group'       => 'overlay',
			'label'       => esc_html__( 'Use overlay', 'imsearch' ),
			'type'        => 'checkbox',
			'description' => esc_html__( 'While results are showing, cover the rest of the page.', 'imsearch' ),
		);
		$this->controls['overlayBlur'] = array(
			'group'       => 'overlay',
			'label'       => esc_html__( 'Blur amount', 'imsearch' ),
			'type'        => 'number',
			'units'       => true,
			'placeholder' => '0px',
			'css'         => array(
				array(
					'property' => '--imsearch-overlay-blur',
					'selector' => '',
				),
			),
			'required'    => array( 'useOverlay', '=', true ),
		);
		$this->controls['overlayColor'] = array(
			'group'    => 'overlay',
			'label'    => esc_html__( 'Overlay color', 'imsearch' ),
			'type'     => 'color',
			'css'      => array(
				array(
					'property' => '--imsearch-overlay-color',
					'selector' => '',
				),
			),
			'required' => array( 'useOverlay', '=', true ),
		);
	}

	/**
	 * Root id as rendered by Bricks.
	 *
	 * @return string
	 */
	private function root_id() {
		return ! empty( $this->settings['_cssId'] ) ? sanitize_html_class( $this->settings['_cssId'] ) : 'brxe-' . $this->id;
	}

	public function render() {
		$s       = $this->settings;
		$submit  = ! empty( $s['submitIsButton'] );
		$builder = IMSearch_Bricks::in_builder();

		$args = IMSearch_Render::bar_args(
			array(
				'id'               => $this->root_id(),
				'mode'             => $submit ? 'submit' : 'autocomplete',
				'placeholder'      => isset( $s['placeholder'] ) ? $s['placeholder'] : __( 'Search…', 'imsearch' ),
				'scope'            => array(
					'description' => ! empty( $s['searchDescription'] ),
					'sku'         => ! empty( $s['searchSku'] ),
					'categories'  => ! empty( $s['searchCategories'] ),
					'tags'        => ! empty( $s['searchTags'] ),
					'brands'      => ! empty( $s['searchBrands'] ),
					'meta'        => isset( $s['searchCustomFields'] ) ? (array) $s['searchCustomFields'] : array(),
					'taxonomies'  => isset( $s['searchTaxonomies'] ) ? (array) $s['searchTaxonomies'] : array(),
				),
				'submit_type'      => ( isset( $s['submitContent'] ) && 'icon' === $s['submitContent'] ) ? 'icon' : 'text',
				'submit_text'      => ! empty( $s['submitText'] ) ? $s['submitText'] : __( 'SEARCH', 'imsearch' ),
				'submit_icon_html' => ! empty( $s['submitIcon'] ) ? self::render_icon( $s['submitIcon'], array( 'imsearch-icon' ) ) : '',
				'search_icon_html' => ! empty( $s['searchIcon'] ) ? self::render_icon( $s['searchIcon'], array( 'imsearch-icon' ) ) : '',
				'clear_icon_html'  => ! empty( $s['clearIcon'] ) ? self::render_icon( $s['clearIcon'], array( 'imsearch-icon' ) ) : '',
				'spinner'          => isset( $s['spinner'] ) ? (int) $s['spinner'] : 1,
				'overlay'          => ! empty( $s['useOverlay'] ),
				'preview_spinner'  => $builder && ! empty( $s['previewSpinner'] ),
			)
		);

		$this->set_attribute( '_root', 'class', IMSearch_Render::bar_classes( $args ) );
		$this->set_attribute( '_root', 'data-imsearch', IMSearch_Render::bar_config( $args ) );
		$this->set_attribute( '_root', 'data-imsearch-id', $args['id'] );

		echo "<div {$this->render_attributes( '_root' )}>" . IMSearch_Render::bar_inner( $args ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
