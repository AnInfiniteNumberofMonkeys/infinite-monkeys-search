<?php
/**
 * Shared markup for the search bar and results (used by the Bricks
 * elements and the shortcodes). Every class is prefixed "imsearch-".
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class IMSearch_Render {

	/** @var int */
	private static $counter = 0;

	public static function uid( $prefix = 'imsearch' ) {
		++self::$counter;
		return $prefix . '-' . self::$counter . '-' . wp_rand( 100, 999 );
	}

	/* ------------------------------------------------------------------ */
	/* Default icons (inline SVG, inherit currentColor)                     */
	/* ------------------------------------------------------------------ */

	public static function icon_search() {
		return '<svg class="imsearch-svg" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false"><circle cx="10.5" cy="10.5" r="6.5" fill="none" stroke="currentColor" stroke-width="1.8"/><line x1="15.3" y1="15.3" x2="21" y2="21" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>';
	}

	public static function icon_clear() {
		return '<svg class="imsearch-svg" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false"><line x1="5" y1="5" x2="19" y2="19" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><line x1="19" y1="5" x2="5" y2="19" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>';
	}

	/* ------------------------------------------------------------------ */
	/* Search bar                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * Normalise bar arguments.
	 *
	 * @param array $a Args.
	 * @return array
	 */
	public static function bar_args( array $a ) {
		$a = array_merge(
			array(
				'id'               => '',
				'mode'             => 'autocomplete',
				'placeholder'      => __( 'Search…', 'imsearch' ),
				'scope'            => array(
					'description' => true,
					'sku'         => true,
					'categories'  => true,
					'tags'        => true,
					'brands'      => true,
				),
				'submit_type'      => 'text',
				'submit_text'      => __( 'SEARCH', 'imsearch' ),
				'submit_icon_html' => '',
				'search_icon_html' => '',
				'clear_icon_html'  => '',
				'spinner'          => 1,
				'overlay'          => false,
				'more_type'        => 'product',
				'preview_spinner'  => false,
			),
			$a
		);
		$a['mode']    = 'submit' === $a['mode'] ? 'submit' : 'autocomplete';
		$a['spinner'] = max( 1, min( 6, (int) $a['spinner'] ) );
		return $a;
	}

	/**
	 * Classes for the bar root.
	 *
	 * @param array $a Bar args.
	 * @return string[]
	 */
	public static function bar_classes( array $a ) {
		$classes = array( 'imsearch-bar', 'imsearch-bar--' . $a['mode'] );
		if ( $a['preview_spinner'] ) {
			$classes[] = 'imsearch-bar--preview-spinner';
		}
		return $classes;
	}

	/**
	 * data-imsearch attribute value for the bar.
	 *
	 * @param array $a Bar args.
	 * @return string JSON.
	 */
	public static function bar_config( array $a ) {
		$fields = IMSearch_Settings::scope_to_fields( (array) $a['scope'] );
		return wp_json_encode(
			array(
				'mode'     => $a['mode'],
				'fields'   => $fields,
				'overlay'  => (bool) $a['overlay'],
				'moreType' => $a['more_type'],
			)
		);
	}

	/**
	 * Inner markup of the bar.
	 *
	 * @param array $a Bar args.
	 * @return string
	 */
	public static function bar_inner( array $a ) {
		$input_id = ( $a['id'] ? $a['id'] : self::uid() ) . '-input';
		$fields   = IMSearch_Settings::scope_to_fields( (array) $a['scope'] );

		$html  = '<form class="imsearch-form" role="search" method="get" action="' . esc_url( home_url( '/' ) ) . '">';
		$html .= '<div class="imsearch-field">';
		if ( 'autocomplete' === $a['mode'] ) {
			$html .= '<span class="imsearch-search-icon" aria-hidden="true">' . ( $a['search_icon_html'] ? $a['search_icon_html'] : self::icon_search() ) . '</span>';
		}
		$html .= '<label class="imsearch-sr-only" for="' . esc_attr( $input_id ) . '">' . esc_html__( 'Search', 'imsearch' ) . '</label>';
		$html .= '<input id="' . esc_attr( $input_id ) . '" class="imsearch-input" type="search" name="s" value="" placeholder="' . esc_attr( $a['placeholder'] ) . '" autocomplete="off" autocapitalize="off" spellcheck="false" role="combobox" aria-autocomplete="list" aria-expanded="false" maxlength="100">';
		if ( $a['more_type'] ) {
			$html .= '<input type="hidden" name="post_type" value="' . esc_attr( $a['more_type'] ) . '">';
		}
		$html .= '<input type="hidden" name="ims" value="' . esc_attr( implode( ',', $fields ) ) . '">';
		$html .= '<span class="imsearch-spinner" aria-hidden="true"><span class="imsearch-loader imsearch-loader--' . (int) $a['spinner'] . '"></span></span>';
		$html .= '<button type="button" class="imsearch-clear" aria-label="' . esc_attr__( 'Clear search', 'imsearch' ) . '" hidden>' . ( $a['clear_icon_html'] ? $a['clear_icon_html'] : self::icon_clear() ) . '</button>';
		$html .= '</div>';
		if ( 'submit' === $a['mode'] ) {
			$label = 'icon' === $a['submit_type']
				? ( $a['submit_icon_html'] ? $a['submit_icon_html'] : self::icon_search() )
				: '<span class="imsearch-submit-text">' . esc_html( $a['submit_text'] ) . '</span>';
			$html .= '<button type="submit" class="imsearch-submit imsearch-submit--' . esc_attr( 'icon' === $a['submit_type'] ? 'icon' : 'text' ) . '" aria-label="' . esc_attr__( 'Search', 'imsearch' ) . '">' . $label . '</button>';
		}
		$html .= '</form>';
		return $html;
	}

	/* ------------------------------------------------------------------ */
	/* Results                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Normalise results arguments.
	 *
	 * @param array $a Args.
	 * @return array
	 */
	public static function results_args( array $a ) {
		$a = array_merge(
			array(
				'id'           => '',
				'for'          => '',
				'types'        => array( 'product' ),
				'show_image'   => true,
				'show_price'   => true,
				'show_excerpt' => false,
				'show_sku'     => false,
				'show_headings' => true,
				'show_button'  => false,
				'button_text'  => __( 'View', 'imsearch' ),
				'orderby'      => 'relevance',
				'order'        => 'desc',
				'sample'       => false,
			),
			$a
		);
		$a['for']     = ltrim( (string) $a['for'], '#' );
		$a['orderby'] = in_array( $a['orderby'], IMSearch_Engine::ORDERBY, true ) ? $a['orderby'] : 'relevance';
		$a['order']   = 'asc' === strtolower( (string) $a['order'] ) ? 'asc' : 'desc';
		return $a;
	}

	public static function results_classes( array $a ) {
		$classes = array( 'imsearch-results' );
		if ( $a['sample'] ) {
			$classes[] = 'imsearch-results--sample';
		} else {
			$classes[] = 'imsearch-results--idle';
		}
		return $classes;
	}

	public static function results_config( array $a ) {
		$with = array();
		foreach ( array(
			'show_image'   => 'image',
			'show_price'   => 'price',
			'show_excerpt' => 'excerpt',
			'show_sku'     => 'sku',
		) as $key => $flag ) {
			if ( ! empty( $a[ $key ] ) ) {
				$with[] = $flag;
			}
		}
		return wp_json_encode(
			array(
				'types'   => array_values( (array) $a['types'] ),
				'with'     => $with,
				'headings' => (bool) $a['show_headings'],
				'button'   => $a['show_button'] ? (string) $a['button_text'] : '',
				'orderby' => $a['orderby'],
				'order'   => $a['order'],
				'for'     => $a['for'],
			)
		);
	}

	/**
	 * Inner markup of the results container. Live results are rendered by JS;
	 * in the Bricks builder sample results are shown for styling.
	 *
	 * @param array $a Results args.
	 * @return string
	 */
	public static function results_inner( array $a ) {
		$list_id = ( $a['id'] ? $a['id'] : self::uid() ) . '-list';
		$html    = '<div class="imsearch-results-inner" id="' . esc_attr( $list_id ) . '" role="listbox" aria-label="' . esc_attr__( 'Search results', 'imsearch' ) . '">';
		if ( $a['sample'] ) {
			$html .= self::sample( $a );
		}
		$html .= '</div>';
		return $html;
	}

	/**
	 * Builder preview.
	 *
	 * @param array $a Results args.
	 * @return string
	 */
	private static function sample( array $a ) {
		$img = function_exists( 'wc_placeholder_img_src' ) ? wc_placeholder_img_src( 'thumbnail' ) : '';
		$out = '<div class="imsearch-group imsearch-group--product" role="group" aria-label="' . esc_attr__( 'Products', 'imsearch' ) . '">';
		if ( $a['show_headings'] ) {
			$out .= '<div class="imsearch-group-heading">' . esc_html__( 'Products', 'imsearch' ) . '</div>';
		}
		for ( $i = 1; $i <= 3; $i++ ) {
			$out .= '<a class="imsearch-result imsearch-result--product" href="#" role="option" onclick="return false;">';
			if ( $a['show_image'] ) {
				$out .= '<span class="imsearch-result-image">' . ( $img ? '<img src="' . esc_url( $img ) . '" alt="" loading="lazy">' : '' ) . '</span>';
			}
			$out .= '<div class="imsearch-result-content">';
			$out .= '<span class="imsearch-result-body"><span class="imsearch-result-title-row"><span class="imsearch-result-title">' . esc_html__( 'Sample product', 'imsearch' ) . ' <strong class="imsearch-highlight">' . esc_html__( 'name', 'imsearch' ) . '</strong></span>';
			if ( $a['show_sku'] ) {
				$out .= '<span class="imsearch-result-sku">(' . esc_html__( 'SKU', 'imsearch' ) . ': 000' . $i . ')</span>';
			}
			$out .= '</span>';
			if ( $a['show_price'] ) {
				$out .= '<span class="imsearch-result-price"><span class="woocommerce-Price-amount amount"><bdi><span class="woocommerce-Price-currencySymbol">$</span>99.00</bdi></span></span>';
			}
			if ( $a['show_excerpt'] ) {
				$out .= '<span class="imsearch-result-excerpt">Lorem <strong class="imsearch-highlight">ipsum</strong> dolor sit amet, consectetur adipiscing elit. Quisque gravida lacus nec diam porttitor pharetra.</span>';
			}
			$out .= '</span>';
			if ( $a['show_button'] ) {
				$out .= '<span class="imsearch-result-aside"><span class="imsearch-result-button" aria-hidden="true">' . esc_html( $a['button_text'] ) . '</span></span>';
			}
			$out .= '</div></a>';
		}
		$out .= '<a class="imsearch-more" href="#" onclick="return false;">' . esc_html( self::more_label( 73 ) ) . '</a></div>';
		return $out;
	}

	/**
	 * "More results" label with count.
	 *
	 * @param int $count Count.
	 * @return string
	 */
	public static function more_label( $count ) {
		$label = (string) IMSearch_Settings::get( 'more_results_text' );
		if ( false !== strpos( $label, '{count}' ) ) {
			return str_replace( '{count}', (string) $count, $label );
		}
		return $label . ' (' . $count . ')';
	}
}
