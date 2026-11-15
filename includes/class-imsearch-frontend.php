<?php
/**
 * Front end: assets, config, REST fallback endpoint, shortcodes and the
 * integration that makes the site's search results page (the Bricks search
 * template) use the index.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class IMSearch_Frontend {

	/** @var array<string,int[]> */
	private static $ids_cache = array();

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ), 5 );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_shortcode( 'imsearch', array( __CLASS__, 'shortcode_combined' ) );
		add_shortcode( 'imsearch_bar', array( __CLASS__, 'shortcode_bar' ) );
		add_shortcode( 'imsearch_results', array( __CLASS__, 'shortcode_results' ) );
		add_filter( 'posts_search', array( __CLASS__, 'posts_search' ), 20, 2 );
		add_filter( 'posts_search_orderby', array( __CLASS__, 'posts_search_orderby' ), 20, 2 );
		add_filter( 'perfmatters_delay_js_exclusions', array( __CLASS__, 'delay_exclusions' ) );
		add_filter( 'rocket_delay_js_exclusions', array( __CLASS__, 'delay_exclusions' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Assets                                                               */
	/* ------------------------------------------------------------------ */

	public static function register_assets() {
		wp_register_style( 'imsearch', IMSEARCH_URL . 'assets/css/imsearch.css', array(), IMSEARCH_VERSION );
		wp_register_script( 'imsearch', IMSEARCH_URL . 'assets/js/imsearch.js', array( 'jquery' ), IMSEARCH_VERSION, true );
		wp_add_inline_script( 'imsearch', 'window.imsearchConfig = ' . wp_json_encode( self::config() ) . ';', 'before' );
	}

	/**
	 * Enqueue (called by elements and shortcodes when they render).
	 */
	public static function enqueue() {
		if ( ! wp_script_is( 'imsearch', 'registered' ) ) {
			self::register_assets();
		}
		wp_enqueue_style( 'imsearch' );
		wp_enqueue_script( 'imsearch' );
	}

	public static function delay_exclusions( $exclusions ) {
		$exclusions   = is_array( $exclusions ) ? $exclusions : array();
		$exclusions[] = 'imsearch';
		return $exclusions;
	}

	/**
	 * Fast endpoint URL.
	 *
	 * @return string
	 */
	public static function fast_url() {
		return IMSEARCH_URL . 'endpoint/search.php';
	}

	/**
	 * Check that the fast endpoint answers (some hosts block PHP files in plugin folders).
	 *
	 * @return bool
	 */
	public static function test_fast_endpoint() {
		$response = wp_remote_get(
			add_query_arg( 'ping', '1', self::fast_url() ),
			array(
				'timeout'   => 8,
				'sslverify' => false,
			)
		);
		return ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) && 'pong' === trim( wp_remote_retrieve_body( $response ) );
	}

	/**
	 * Base store currency format (raw options, so currency converters can
	 * re-render prices from data-imcc-base attributes).
	 *
	 * @return array|null
	 */
	private static function currency() {
		if ( ! function_exists( 'get_woocommerce_currency_symbol' ) ) {
			return null;
		}
		$code = (string) get_option( 'woocommerce_currency', 'USD' );
		return array(
			'code'   => $code,
			'symbol' => html_entity_decode( get_woocommerce_currency_symbol( $code ), ENT_QUOTES, 'UTF-8' ),
			'pos'    => (string) get_option( 'woocommerce_currency_pos', 'left' ),
			'dec'    => (int) get_option( 'woocommerce_price_num_decimals', 2 ),
			'ts'     => (string) get_option( 'woocommerce_price_thousand_sep', ',' ),
			'ds'     => (string) get_option( 'woocommerce_price_decimal_sep', '.' ),
		);
	}

	public static function config() {
		$s = IMSearch_Settings::all();
		return array(
			'endpoint'  => get_option( 'imsearch_endpoint_ok' ) ? self::fast_url() : '',
			'rest'      => esc_url_raw( rest_url( 'imsearch/v1/search' ) ),
			'v'         => IMSearch_Settings::index_version(),
			'minChars'  => (int) $s['min_chars'],
			'limit'     => (int) $s['max_suggestions'],
			'noResults' => $s['no_results_text'],
			'more'      => $s['more_results_text'],
			'searchUrl' => home_url( '/' ),
			'currency'  => self::currency(),
			'i18n'      => array(
				'sku'     => __( 'SKU', 'imsearch' ),
				'results' => __( '%d results', 'imsearch' ),
			),
		);
	}

	/* ------------------------------------------------------------------ */
	/* REST fallback                                                        */
	/* ------------------------------------------------------------------ */

	public static function register_routes() {
		register_rest_route(
			'imsearch/v1',
			'/search',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => '__return_true',
				'callback'            => array( __CLASS__, 'rest_search' ),
				'args'                => array(
					's' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	public static function rest_search( WP_REST_Request $request ) {
		global $wpdb;
		$engine = new IMSearch_Engine( $wpdb, IMSearch_Settings::runtime() );
		$result = $engine->search( (string) $request->get_param( 's' ), self::request_args( $request->get_params() ) );
		$response = new WP_REST_Response( $result );
		$response->header( 'Cache-Control', 'public, max-age=300' );
		$response->header( 'X-Robots-Tag', 'noindex' );
		return $response;
	}

	/**
	 * Parse search args from request params (shared with the fast endpoint).
	 *
	 * @param array $p Params.
	 * @return array
	 */
	public static function request_args( array $p ) {
		$with = array();
		foreach ( explode( ',', isset( $p['with'] ) ? (string) $p['with'] : '' ) as $flag ) {
			if ( in_array( $flag, array( 'image', 'price', 'excerpt', 'sku' ), true ) ) {
				$with[] = $flag;
			}
		}
		return array(
			'types'   => isset( $p['types'] ) ? (string) $p['types'] : 'product',
			'fields'  => isset( $p['fields'] ) ? (string) $p['fields'] : '',
			'limit'   => isset( $p['limit'] ) ? (int) $p['limit'] : 10,
			'orderby' => isset( $p['orderby'] ) ? (string) $p['orderby'] : 'relevance',
			'order'   => isset( $p['order'] ) ? (string) $p['order'] : 'desc',
			'with'    => $with,
		);
	}

	/* ------------------------------------------------------------------ */
	/* Search results page                                                  */
	/* ------------------------------------------------------------------ */

	/**
	 * Should this query be answered from the index?
	 *
	 * @param WP_Query $query Query.
	 * @return bool
	 */
	private static function applies( $query ) {
		if ( ! $query instanceof WP_Query || is_admin() ) {
			return false;
		}
		// Leave REST searches alone (block editor link search, etc.), except
		// Bricks' own endpoints (AJAX pagination / query filters on the results page).
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			$route = isset( $GLOBALS['wp']->query_vars['rest_route'] ) ? (string) $GLOBALS['wp']->query_vars['rest_route'] : '';
			if ( 0 !== strpos( $route, '/bricks/' ) ) {
				return false;
			}
		}
		if ( ! IMSearch_Settings::get( 'replace_search_page' ) ) {
			return false;
		}
		if ( ! $query->is_search() || '' === trim( (string) $query->get( 's' ) ) || $query->get( 'imsearch_skip' ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Matching post ids for a query, in relevance order.
	 *
	 * @param WP_Query $query Query.
	 * @return int[]
	 */
	private static function ids_for( $query ) {
		global $wpdb;
		$indexed = IMSearch_Settings::indexed_post_types();
		$types   = $query->get( 'post_type' );
		if ( empty( $types ) || 'any' === $types ) {
			$types = $indexed;
		}
		$types = array_values( array_intersect( (array) $types, $indexed ) );

		// Scope from the search bar that sent the visitor here; default all fields.
		$fields = array_keys( IMSearch_Settings::field_map() );
		if ( isset( $_GET['ims'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$fields = array_map( 'sanitize_text_field', explode( ',', wp_unslash( (string) $_GET['ims'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		$s   = (string) $query->get( 's' );
		$key = md5( $s . '|' . implode( ',', $types ) . '|' . implode( ',', $fields ) );
		if ( ! isset( self::$ids_cache[ $key ] ) ) {
			$engine                  = new IMSearch_Engine( $wpdb, IMSearch_Settings::runtime() );
			self::$ids_cache[ $key ] = $engine->search_ids( $s, $types, $fields );
		}
		return self::$ids_cache[ $key ];
	}

	public static function posts_search( $search, $query ) {
		if ( ! self::applies( $query ) ) {
			return $search;
		}
		global $wpdb;
		$ids = self::ids_for( $query );
		if ( ! $ids ) {
			return ' AND 0 = 1 ';
		}
		return " AND {$wpdb->posts}.ID IN (" . implode( ',', array_map( 'intval', $ids ) ) . ') ';
	}

	public static function posts_search_orderby( $orderby, $query ) {
		if ( ! self::applies( $query ) ) {
			return $orderby;
		}
		global $wpdb;
		$ids = self::ids_for( $query );
		return $ids ? "FIELD({$wpdb->posts}.ID, " . implode( ',', array_map( 'intval', $ids ) ) . ')' : $orderby;
	}

	/* ------------------------------------------------------------------ */
	/* Shortcodes                                                           */
	/* ------------------------------------------------------------------ */

	private static function bool( $value ) {
		return in_array( strtolower( (string) $value ), array( '1', 'true', 'yes', 'on' ), true );
	}

	private static function list_attr( $value ) {
		return array_values( array_filter( array_map( 'trim', explode( ',', (string) $value ) ) ) );
	}

	/**
	 * Bar args from shortcode attributes.
	 *
	 * @param array $atts Attributes.
	 * @return array
	 */
	private static function bar_from_atts( array $atts ) {
		$scope_list = self::list_attr( $atts['scope'] );
		return IMSearch_Render::bar_args(
			array(
				'id'          => $atts['id'] ? sanitize_html_class( $atts['id'] ) : IMSearch_Render::uid(),
				'mode'        => self::bool( $atts['submit'] ) ? 'submit' : 'autocomplete',
				'placeholder' => $atts['placeholder'],
				'scope'       => array(
					'description' => in_array( 'description', $scope_list, true ),
					'sku'         => in_array( 'sku', $scope_list, true ),
					'categories'  => in_array( 'categories', $scope_list, true ),
					'tags'        => in_array( 'tags', $scope_list, true ),
					'brands'      => in_array( 'brands', $scope_list, true ),
					'meta'        => self::list_attr( $atts['custom_fields'] ),
					'taxonomies'  => self::list_attr( $atts['taxonomies'] ),
				),
				'submit_type' => 'icon' === $atts['button'] ? 'icon' : 'text',
				'submit_text' => $atts['button_text'],
				'spinner'     => (int) $atts['spinner'],
				'overlay'     => self::bool( $atts['overlay'] ),
			)
		);
	}

	/**
	 * Results args from shortcode attributes.
	 *
	 * @param array $atts Attributes.
	 * @return array
	 */
	private static function results_from_atts( array $atts ) {
		$types = self::list_attr( $atts['types'] );
		foreach ( self::list_attr( $atts['show_taxonomies'] ) as $tax ) {
			$types[] = 'tax:' . $tax;
		}
		return IMSearch_Render::results_args(
			array(
				'id'           => IMSearch_Render::uid( 'imsearch-results' ),
				'for'          => $atts['for'],
				'types'        => $types,
				'show_image'   => self::bool( $atts['image'] ),
				'show_price'   => self::bool( $atts['price'] ),
				'show_excerpt' => self::bool( $atts['excerpt'] ),
				'show_sku'     => self::bool( $atts['sku'] ),
				'show_headings' => self::bool( $atts['headings'] ),
				'show_button'  => self::bool( $atts['view_button'] ),
				'button_text'  => $atts['view_text'],
				'orderby'      => $atts['orderby'],
				'order'        => $atts['order'],
			)
		);
	}

	private static function defaults() {
		return array(
			'id'              => '',
			'for'             => '',
			'placeholder'     => __( 'Search…', 'imsearch' ),
			'scope'           => 'description,sku,categories,tags,brands',
			'custom_fields'   => '',
			'taxonomies'      => '',
			'submit'          => 'no',
			'button'          => 'text',
			'button_text'     => __( 'SEARCH', 'imsearch' ),
			'spinner'         => '1',
			'overlay'         => 'no',
			'types'           => 'product',
			'show_taxonomies' => '',
			'image'           => 'yes',
			'price'           => 'yes',
			'excerpt'         => 'no',
			'sku'             => 'no',
			'headings'        => 'yes',
			'view_button'     => 'no',
			'view_text'       => __( 'View', 'imsearch' ),
			'orderby'         => 'relevance',
			'order'           => 'desc',
		);
	}

	private static function bar_html( array $a, $extra_class = '' ) {
		return '<div id="' . esc_attr( $a['id'] ) . '" class="' . esc_attr( implode( ' ', IMSearch_Render::bar_classes( $a ) ) . ( $extra_class ? ' ' . $extra_class : '' ) ) . '" data-imsearch="' . esc_attr( IMSearch_Render::bar_config( $a ) ) . '">' . IMSearch_Render::bar_inner( $a ) . '</div>';
	}

	private static function results_html( array $a, $extra_class = '' ) {
		return '<div id="' . esc_attr( $a['id'] ) . '" class="' . esc_attr( implode( ' ', IMSearch_Render::results_classes( $a ) ) . ( $extra_class ? ' ' . $extra_class : '' ) ) . '" data-imsearch-results="' . esc_attr( IMSearch_Render::results_config( $a ) ) . '"' . ( $a['for'] ? ' data-for="' . esc_attr( $a['for'] ) . '"' : '' ) . '>' . IMSearch_Render::results_inner( $a ) . '</div>';
	}

	/**
	 * [imsearch] - bar with a dropdown of results under it.
	 *
	 * @param array|string $atts Attributes.
	 * @return string
	 */
	public static function shortcode_combined( $atts ) {
		$atts = shortcode_atts( self::defaults(), (array) $atts, 'imsearch' );
		self::enqueue();
		$bar         = self::bar_from_atts( $atts );
		$atts['for'] = $bar['id'];
		$results     = self::results_from_atts( $atts );
		return '<div class="imsearch-combined">' . self::bar_html( $bar ) . self::results_html( $results, 'imsearch-results--dropdown' ) . '</div>';
	}

	public static function shortcode_bar( $atts ) {
		$atts = shortcode_atts( self::defaults(), (array) $atts, 'imsearch_bar' );
		self::enqueue();
		return self::bar_html( self::bar_from_atts( $atts ) );
	}

	public static function shortcode_results( $atts ) {
		$atts = shortcode_atts( self::defaults(), (array) $atts, 'imsearch_results' );
		self::enqueue();
		return self::results_html( self::results_from_atts( $atts ) );
	}
}
