<?php
/**
 * Bricks element: Search results.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class IMSearch_Element_Results extends \Bricks\Element {

	public $category     = 'imsearch';
	public $name         = 'imsearch-results';
	public $icon         = 'ti-layout-list-thumb';
	public $css_selector = '.imsearch-results-inner';
	public $scripts      = array( 'imsearchBricksInit' );

	public function get_label() {
		return esc_html__( 'Search results', 'imsearch' );
	}

	public function get_keywords() {
		return array( 'search', 'results', 'autocomplete', 'imsearch', 'infinite monkeys' );
	}

	public function enqueue_scripts() {
		IMSearch_Frontend::enqueue();
	}

	public function set_control_groups() {
		$this->control_groups['include'] = array( 'title' => esc_html__( 'Include in results', 'imsearch' ) );
		$this->control_groups['display'] = array( 'title' => esc_html__( 'Display', 'imsearch' ) );
		$this->control_groups['item']    = array( 'title' => esc_html__( 'Result item', 'imsearch' ) );
		$this->control_groups['text']    = array( 'title' => esc_html__( 'Title, SKU, excerpt, price', 'imsearch' ) );
		$this->control_groups['heading'] = array( 'title' => esc_html__( 'Group headings & links', 'imsearch' ) );
	}

	/**
	 * Helper for a style control.
	 *
	 * @param string $group    Group.
	 * @param string $label    Label.
	 * @param string $type     Control type.
	 * @param string $property CSS property.
	 * @param string $selector Selector.
	 * @param array  $extra    Extra keys.
	 * @return array
	 */
	private function style( $group, $label, $type, $property, $selector, array $extra = array() ) {
		return array_merge(
			array(
				'group' => $group,
				'label' => $label,
				'type'  => $type,
				'css'   => array(
					array(
						'property' => $property,
						'selector' => $selector,
					),
				),
			),
			'number' === $type ? array( 'units' => true ) : array(),
			$extra
		);
	}

	public function set_controls() {
		$this->controls['for'] = array(
			'label'          => esc_html__( 'Search bar ID (optional)', 'imsearch' ),
			'type'           => 'text',
			'inline'         => true,
			'hasDynamicData' => false,
			'placeholder'    => 'brxe-abcdef',
			'description'    => esc_html__( 'CSS ID of the Search bar these results belong to. Leave empty to use the nearest Search bar before this element (or the first one on the page).', 'imsearch' ),
		);

		$this->controls['orderby'] = array(
			'label'       => esc_html__( 'Order by', 'imsearch' ),
			'type'        => 'select',
			'inline'      => true,
			'options'     => array(
				'relevance'  => esc_html__( 'Relevance', 'imsearch' ),
				'title'      => esc_html__( 'Name', 'imsearch' ),
				'date'       => esc_html__( 'Date published', 'imsearch' ),
				'modified'   => esc_html__( 'Date modified', 'imsearch' ),
				'price'      => esc_html__( 'Price', 'imsearch' ),
				'menu_order' => esc_html__( 'Menu order', 'imsearch' ),
			),
			'placeholder' => esc_html__( 'Relevance', 'imsearch' ),
		);
		$this->controls['order'] = array(
			'label'       => esc_html__( 'Order', 'imsearch' ),
			'type'        => 'select',
			'inline'      => true,
			'options'     => array(
				'desc' => esc_html__( 'Descending', 'imsearch' ),
				'asc'  => esc_html__( 'Ascending', 'imsearch' ),
			),
			'placeholder' => esc_html__( 'Descending', 'imsearch' ),
		);

		// INCLUDE.
		$this->controls['includeProducts'] = array(
			'group'   => 'include',
			'label'   => esc_html__( 'Products', 'imsearch' ),
			'type'    => 'checkbox',
			'default' => true,
		);
		$this->controls['includePages'] = array(
			'group' => 'include',
			'label' => esc_html__( 'Pages', 'imsearch' ),
			'type'  => 'checkbox',
		);
		$this->controls['includePostTypes'] = array(
			'group'       => 'include',
			'label'       => esc_html__( 'Posts', 'imsearch' ),
			'type'        => 'select',
			'options'     => IMSearch_Bricks::post_type_options( array( 'product', 'page' ) ),
			'multiple'    => true,
			'searchable'  => true,
			'placeholder' => esc_html__( 'None', 'imsearch' ),
			'description' => esc_html__( 'Posts and any custom post types.', 'imsearch' ),
		);
		$this->controls['includeBrands'] = array(
			'group' => 'include',
			'label' => esc_html__( 'Brands', 'imsearch' ),
			'type'  => 'checkbox',
		);
		$this->controls['includeTaxonomies'] = array(
			'group'       => 'include',
			'label'       => esc_html__( 'Taxonomies', 'imsearch' ),
			'type'        => 'select',
			'options'     => IMSearch_Bricks::taxonomy_options( IMSearch_Settings::brand_taxonomies() ),
			'multiple'    => true,
			'searchable'  => true,
			'placeholder' => esc_html__( 'None', 'imsearch' ),
			'description' => esc_html__( 'Show matching categories, tags and custom taxonomy terms as results.', 'imsearch' ),
		);

		// DISPLAY.
		$this->controls['showImage'] = array(
			'group'   => 'display',
			'label'   => esc_html__( 'Show image', 'imsearch' ),
			'type'    => 'checkbox',
			'default' => true,
		);
		$this->controls['showPrice'] = array(
			'group'       => 'display',
			'label'       => esc_html__( 'Show price', 'imsearch' ),
			'type'        => 'checkbox',
			'default'     => true,
			'description' => esc_html__( 'Products only.', 'imsearch' ),
		);
		$this->controls['showExcerpt'] = array(
			'group' => 'display',
			'label' => esc_html__( 'Show excerpt', 'imsearch' ),
			'type'  => 'checkbox',
		);
		$this->controls['showSku'] = array(
			'group'       => 'display',
			'label'       => esc_html__( 'Show SKU', 'imsearch' ),
			'type'        => 'checkbox',
			'description' => esc_html__( 'Products only.', 'imsearch' ),
		);
		$this->controls['hideHeadings'] = array(
			'group'       => 'display',
			'label'       => esc_html__( 'Hide group headings', 'imsearch' ),
			'type'        => 'checkbox',
			'description' => esc_html__( 'Hides the "Products", "Pages" etc. headings above each group of results.', 'imsearch' ),
		);
		$this->controls['showButton'] = array(
			'group'       => 'display',
			'label'       => esc_html__( 'Show "View" button', 'imsearch' ),
			'type'        => 'checkbox',
			'description' => esc_html__( 'A button-styled label at the right of the result (in .imsearch-result-aside). The whole result stays the link.', 'imsearch' ),
		);
		$this->controls['buttonText'] = array(
			'group'          => 'display',
			'label'          => esc_html__( 'Button label', 'imsearch' ),
			'type'           => 'text',
			'inline'         => true,
			'hasDynamicData' => false,
			'placeholder'    => esc_html__( 'View', 'imsearch' ),
			'required'       => array( 'showButton', '=', true ),
		);
		$this->controls['buttonTypography']  = $this->style( 'display', esc_html__( 'Button typography', 'imsearch' ), 'typography', 'font', '.imsearch-result-button', array( 'required' => array( 'showButton', '=', true ) ) );
		$this->controls['buttonBackground']  = $this->style( 'display', esc_html__( 'Button background', 'imsearch' ), 'color', 'background-color', '.imsearch-result-button', array( 'required' => array( 'showButton', '=', true ) ) );
		$this->controls['buttonBorder']      = $this->style( 'display', esc_html__( 'Button border', 'imsearch' ), 'border', 'border', '.imsearch-result-button', array( 'required' => array( 'showButton', '=', true ) ) );
		$this->controls['buttonPadding']     = $this->style( 'display', esc_html__( 'Button padding', 'imsearch' ), 'spacing', 'padding', '.imsearch-result-button', array( 'required' => array( 'showButton', '=', true ) ) );
		$this->controls['buttonHoverBg']     = array(
			'group'    => 'display',
			'label'    => esc_html__( 'Button background (result hovered / selected)', 'imsearch' ),
			'type'     => 'color',
			'css'      => array(
				array(
					'property' => 'background-color',
					'selector' => '.imsearch-result:hover .imsearch-result-button',
				),
				array(
					'property' => 'background-color',
					'selector' => '.imsearch-result.is-active .imsearch-result-button',
				),
			),
			'required' => array( 'showButton', '=', true ),
		);
		$this->controls['buttonHoverColor']  = array(
			'group'    => 'display',
			'label'    => esc_html__( 'Button text color (result hovered / selected)', 'imsearch' ),
			'type'     => 'color',
			'css'      => array(
				array(
					'property' => 'color',
					'selector' => '.imsearch-result:hover .imsearch-result-button',
				),
				array(
					'property' => 'color',
					'selector' => '.imsearch-result.is-active .imsearch-result-button',
				),
			),
			'required' => array( 'showButton', '=', true ),
		);
		$this->controls['maxHeight']   = $this->style( 'display', esc_html__( 'Max height (scrolls)', 'imsearch' ), 'number', 'max-height', '.imsearch-results-inner' );
		$this->controls['imageSize']   = $this->style( 'display', esc_html__( 'Image size', 'imsearch' ), 'number', '--imsearch-image-size', '', array( 'placeholder' => '56px' ) );
		$this->controls['imageBorder'] = $this->style( 'display', esc_html__( 'Image border', 'imsearch' ), 'border', 'border', '.imsearch-result-image' );

		// ITEM.
		$this->controls['itemBackground']      = $this->style( 'item', esc_html__( 'Background color', 'imsearch' ), 'color', 'background-color', '.imsearch-result' );
		$this->controls['itemBackgroundHover'] = $this->style( 'item', esc_html__( 'Background (hover / selected)', 'imsearch' ), 'color', '--imsearch-item-hover', '' );
		$this->controls['itemBorder']          = $this->style( 'item', esc_html__( 'Border', 'imsearch' ), 'border', 'border', '.imsearch-result' );
		$this->controls['itemPadding']         = $this->style( 'item', esc_html__( 'Padding', 'imsearch' ), 'spacing', 'padding', '.imsearch-result' );
		$this->controls['itemMargin']          = $this->style( 'item', esc_html__( 'Margin', 'imsearch' ), 'spacing', 'margin', '.imsearch-result' );
		$this->controls['itemGap']             = $this->style( 'item', esc_html__( 'Gap', 'imsearch' ), 'number', 'gap', '.imsearch-result' );
		$this->controls['itemAlign']           = $this->style(
			'item',
			esc_html__( 'Align items', 'imsearch' ),
			'align-items',
			'align-items',
			'.imsearch-result'
		);

		$this->controls['contentSep'] = array(
			'group'       => 'item',
			'type'        => 'separator',
			'label'       => esc_html__( 'Content (text + button)', 'imsearch' ),
			'description' => esc_html__( 'Styles .imsearch-result-content, which wraps the text (title, price, excerpt) and the View button.', 'imsearch' ),
		);
		$this->controls['contentDirection'] = $this->style( 'item', esc_html__( 'Direction', 'imsearch' ), 'direction', 'flex-direction', '.imsearch-result-content' );
		$this->controls['contentAlign']     = $this->style( 'item', esc_html__( 'Align items', 'imsearch' ), 'align-items', 'align-items', '.imsearch-result-content' );
		$this->controls['contentJustify']   = $this->style( 'item', esc_html__( 'Justify content', 'imsearch' ), 'justify-content', 'justify-content', '.imsearch-result-content' );
		$this->controls['contentGap']       = $this->style( 'item', esc_html__( 'Gap', 'imsearch' ), 'number', 'gap', '.imsearch-result-content' );

		// TEXT.
		$this->controls['titleTypography']     = $this->style( 'text', esc_html__( 'Title', 'imsearch' ), 'typography', 'font', '.imsearch-result-title' );
		$this->controls['highlightTypography'] = $this->style( 'text', esc_html__( 'Matched text', 'imsearch' ), 'typography', 'font', '.imsearch-highlight' );
		$this->controls['skuTypography']       = $this->style( 'text', esc_html__( 'SKU', 'imsearch' ), 'typography', 'font', '.imsearch-result-sku' );
		$this->controls['excerptTypography']   = $this->style( 'text', esc_html__( 'Excerpt', 'imsearch' ), 'typography', 'font', '.imsearch-result-excerpt' );
		$this->controls['priceTypography']     = $this->style( 'text', esc_html__( 'Price', 'imsearch' ), 'typography', 'font', '.imsearch-result-price' );
		$this->controls['priceOldTypography']  = $this->style( 'text', esc_html__( 'Regular price (on sale)', 'imsearch' ), 'typography', 'font', '.imsearch-result-price del' );

		// HEADINGS.
		$this->controls['headingTypography'] = $this->style( 'heading', esc_html__( 'Group heading', 'imsearch' ), 'typography', 'font', '.imsearch-group-heading' );
		$this->controls['headingBorder']     = $this->style( 'heading', esc_html__( 'Group heading border', 'imsearch' ), 'border', 'border', '.imsearch-group-heading' );
		$this->controls['headingPadding']    = $this->style( 'heading', esc_html__( 'Group heading padding', 'imsearch' ), 'spacing', 'padding', '.imsearch-group-heading' );
		$this->controls['groupGap']          = $this->style( 'heading', esc_html__( 'Space between groups', 'imsearch' ), 'number', '--imsearch-group-gap', '' );
		$this->controls['moreTypography']    = $this->style( 'heading', esc_html__( '"More results" link', 'imsearch' ), 'typography', 'font', '.imsearch-more' );
		$this->controls['noneTypography']    = $this->style( 'heading', esc_html__( '"No results" message', 'imsearch' ), 'typography', 'font', '.imsearch-no-results' );
	}

	private function root_id() {
		return ! empty( $this->settings['_cssId'] ) ? sanitize_html_class( $this->settings['_cssId'] ) : 'brxe-' . $this->id;
	}

	public function render() {
		$s     = $this->settings;
		$types = array();
		if ( ! empty( $s['includeProducts'] ) ) {
			$types[] = 'product';
		}
		if ( ! empty( $s['includePages'] ) ) {
			$types[] = 'page';
		}
		foreach ( isset( $s['includePostTypes'] ) ? (array) $s['includePostTypes'] : array() as $pt ) {
			$types[] = $pt;
		}
		if ( ! empty( $s['includeBrands'] ) ) {
			foreach ( IMSearch_Settings::brand_taxonomies() as $tax ) {
				$types[] = 'tax:' . $tax;
			}
		}
		foreach ( isset( $s['includeTaxonomies'] ) ? (array) $s['includeTaxonomies'] : array() as $tax ) {
			$types[] = 'tax:' . $tax;
		}

		$args = IMSearch_Render::results_args(
			array(
				'id'           => $this->root_id(),
				'for'          => isset( $s['for'] ) ? $s['for'] : '',
				'types'        => array_values( array_unique( $types ) ),
				'show_image'   => ! empty( $s['showImage'] ),
				'show_price'   => ! empty( $s['showPrice'] ),
				'show_excerpt' => ! empty( $s['showExcerpt'] ),
				'show_sku'     => ! empty( $s['showSku'] ),
				'show_headings' => empty( $s['hideHeadings'] ),
				'show_button'  => ! empty( $s['showButton'] ),
				'button_text'  => ! empty( $s['buttonText'] ) ? $s['buttonText'] : __( 'View', 'imsearch' ),
				'orderby'      => isset( $s['orderby'] ) ? $s['orderby'] : 'relevance',
				'order'        => isset( $s['order'] ) ? $s['order'] : 'desc',
				'sample'       => IMSearch_Bricks::in_builder(),
			)
		);

		$this->set_attribute( '_root', 'class', IMSearch_Render::results_classes( $args ) );
		$this->set_attribute( '_root', 'data-imsearch-results', IMSearch_Render::results_config( $args ) );
		$this->set_attribute( '_root', 'data-imsearch-id', $args['id'] );
		if ( $args['for'] ) {
			$this->set_attribute( '_root', 'data-for', $args['for'] );
		}

		echo "<div {$this->render_attributes( '_root' )}>" . IMSearch_Render::results_inner( $args ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
