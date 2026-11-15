<?php
/**
 * Settings storage, defaults and the compiled runtime config the search
 * engine reads (also from the SHORTINIT endpoint, which cannot call
 * get_option(), so the runtime config is stored as JSON).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class IMSearch_Settings {

	const OPTION  = 'imsearch_settings';
	const RUNTIME = 'imsearch_runtime';
	const FIELDS  = 'imsearch_fields';

	/**
	 * FiboSearch-compatible fuzzy levels: prefix length, max candidate words, max edit distance.
	 */
	const FUZZY_LEVELS = array(
		'soft'   => array( 2, 50, 1 ),
		'normal' => array( 2, 200, 2 ),
		'hard'   => array( 2, 400, 3 ),
	);

	public static function defaults() {
		return array(
			'exclude_out_of_stock' => 0,
			'exclude_terms'        => array(),
			'replace_search_page'  => 1,
			'fuzzy'                => 'normal',
			'min_chars'            => 2,
			'stopwords'            => implode( ', ', IMSearch_Text::default_stopwords() ),
			'synonyms'             => '',
			'index_meta_keys'      => array(),
			'max_suggestions'      => 10,
			'no_results_text'      => __( 'No results found', 'imsearch' ),
			'more_results_text'    => __( 'See all products…', 'imsearch' ),
			'schedule'             => 'off',
		);
	}

	/**
	 * All settings merged with defaults.
	 *
	 * @return array
	 */
	public static function all() {
		$saved = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
	}

	/**
	 * One setting.
	 *
	 * @param string $key Key.
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Sanitise settings submitted from the admin page.
	 *
	 * @param mixed $input Raw input.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();
		$d     = self::defaults();
		$out   = array();

		$out['exclude_out_of_stock'] = empty( $input['exclude_out_of_stock'] ) ? 0 : 1;
		$out['replace_search_page']  = empty( $input['replace_search_page'] ) ? 0 : 1;

		$terms = isset( $input['exclude_terms'] ) ? (array) $input['exclude_terms'] : array();
		$out['exclude_terms'] = array_values( array_unique( array_filter( array_map( 'absint', $terms ) ) ) );

		$fuzzy        = isset( $input['fuzzy'] ) ? sanitize_key( $input['fuzzy'] ) : $d['fuzzy'];
		$out['fuzzy'] = ( 'off' === $fuzzy || isset( self::FUZZY_LEVELS[ $fuzzy ] ) ) ? $fuzzy : $d['fuzzy'];

		$out['min_chars']       = isset( $input['min_chars'] ) ? max( 1, min( 5, absint( $input['min_chars'] ) ) ) : $d['min_chars'];
		$out['max_suggestions'] = isset( $input['max_suggestions'] ) ? max( 1, min( 50, absint( $input['max_suggestions'] ) ) ) : $d['max_suggestions'];

		$out['stopwords'] = isset( $input['stopwords'] ) ? sanitize_textarea_field( wp_unslash( $input['stopwords'] ) ) : $d['stopwords'];
		$out['synonyms']  = isset( $input['synonyms'] ) ? sanitize_textarea_field( wp_unslash( $input['synonyms'] ) ) : '';

		$out['no_results_text']   = isset( $input['no_results_text'] ) ? sanitize_text_field( wp_unslash( $input['no_results_text'] ) ) : $d['no_results_text'];
		$out['more_results_text'] = isset( $input['more_results_text'] ) ? sanitize_text_field( wp_unslash( $input['more_results_text'] ) ) : $d['more_results_text'];
		if ( '' === $out['no_results_text'] ) {
			$out['no_results_text'] = $d['no_results_text'];
		}
		if ( '' === $out['more_results_text'] ) {
			$out['more_results_text'] = $d['more_results_text'];
		}

		$keys = isset( $input['index_meta_keys'] ) ? (array) $input['index_meta_keys'] : array();
		if ( ! empty( $input['index_meta_keys_extra'] ) ) {
			$keys = array_merge( $keys, explode( ',', wp_unslash( $input['index_meta_keys_extra'] ) ) );
		}
		$clean = array();
		foreach ( $keys as $key ) {
			$key = trim( (string) $key );
			if ( '' !== $key && preg_match( '/^[A-Za-z0-9_\-]{1,100}$/', $key ) ) {
				$clean[] = $key;
			}
		}
		$out['index_meta_keys'] = array_values( array_unique( $clean ) );

		$schedule        = isset( $input['schedule'] ) ? sanitize_key( $input['schedule'] ) : 'off';
		$out['schedule'] = in_array( $schedule, array( 'off', 'daily', 'weekly', 'semimonthly', 'monthly' ), true ) ? $schedule : 'off';

		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Fields                                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * Map of field code => numeric id used in the postings table.
	 *
	 * @return array<string,int>
	 */
	public static function field_map() {
		$map = get_option( self::FIELDS, array() );
		return is_array( $map ) ? $map : array();
	}

	/**
	 * Numeric id for a field code, creating it if needed.
	 *
	 * @param string $code Field code (title, content, excerpt, sku, tax:x, meta:x).
	 * @return int
	 */
	public static function field_id( $code ) {
		static $map = null;
		if ( null === $map ) {
			$map = self::field_map();
		}
		if ( ! isset( $map[ $code ] ) ) {
			$fresh = self::field_map(); // Another request may have added fields.
			$map   = $fresh;
			if ( ! isset( $map[ $code ] ) ) {
				$map[ $code ] = $map ? max( $map ) + 1 : 1;
				update_option( self::FIELDS, $map, false );
			}
		}
		return (int) $map[ $code ];
	}

	/**
	 * Relevance weight for a field code.
	 *
	 * @param string $code Field code.
	 * @return float
	 */
	public static function field_weight( $code ) {
		if ( 'title' === $code ) {
			return 10.0;
		}
		if ( 'sku' === $code ) {
			return 9.0;
		}
		if ( 0 === strpos( $code, 'tax:' ) ) {
			return 5.0;
		}
		if ( 'excerpt' === $code || 0 === strpos( $code, 'meta:' ) ) {
			return 3.0;
		}
		return 2.0; // content.
	}

	/* ------------------------------------------------------------------ */
	/* What is indexed                                                      */
	/* ------------------------------------------------------------------ */

	/**
	 * Post types that are indexed.
	 *
	 * @return string[]
	 */
	public static function indexed_post_types() {
		$types = get_post_types( array( 'public' => true ), 'names' );
		unset( $types['attachment'], $types['bricks_template'], $types['e-landing-page'] );
		return array_values( apply_filters( 'imsearch_indexed_post_types', array_values( $types ) ) );
	}

	/**
	 * Taxonomies whose terms are indexed (as results and as fields of their posts).
	 *
	 * @return string[]
	 */
	public static function indexed_taxonomies() {
		$out = array();
		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $tax ) {
			if ( in_array( $tax->name, array( 'post_format', 'template_tag', 'template_bundle', 'product_shipping_class', 'product_visibility', 'product_type' ), true ) ) {
				continue;
			}
			if ( ! array_intersect( (array) $tax->object_type, self::indexed_post_types() ) ) {
				continue;
			}
			$out[] = $tax->name;
		}
		return array_values( apply_filters( 'imsearch_indexed_taxonomies', $out ) );
	}

	/**
	 * Non-protected custom field keys used by indexed content (cached for an hour).
	 *
	 * @return string[]
	 */
	public static function discover_meta_keys() {
		$cached = get_transient( 'imsearch_meta_keys' );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		global $wpdb;
		$types = self::indexed_post_types();
		$keys  = array();
		if ( $types ) {
			$ph   = implode( ',', array_fill( 0, count( $types ), '%s' ) );
			$keys = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT pm.meta_key FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.post_type IN ($ph) AND pm.meta_key NOT LIKE %s ORDER BY pm.meta_key LIMIT 300", array_merge( $types, array( $wpdb->esc_like( '_' ) . '%' ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		$keys = array_values( array_filter( (array) $keys, array( __CLASS__, 'valid_meta_key' ) ) );
		set_transient( 'imsearch_meta_keys', $keys, HOUR_IN_SECONDS );
		return $keys;
	}

	/**
	 * @param string $key Meta key.
	 * @return bool
	 */
	public static function valid_meta_key( $key ) {
		return is_string( $key ) && (bool) preg_match( '/^[A-Za-z0-9_\-]{1,100}$/', $key );
	}

	/**
	 * Add custom field keys to the index list (triggers a rebuild when anything is added).
	 *
	 * @param string[] $keys Meta keys.
	 * @return bool Whether the list changed.
	 */
	public static function add_index_meta_keys( array $keys ) {
		$saved   = get_option( self::OPTION, array() );
		$saved   = is_array( $saved ) ? array_merge( self::defaults(), $saved ) : self::defaults();
		$current = (array) $saved['index_meta_keys'];
		$new     = array_values( array_diff( array_filter( $keys, array( __CLASS__, 'valid_meta_key' ) ), $current ) );
		if ( ! $new ) {
			return false;
		}
		$saved['index_meta_keys'] = array_values( array_unique( array_merge( $current, $new ) ) );
		update_option( self::OPTION, $saved );
		return true;
	}

	/**
	 * Brand taxonomies present on the site.
	 *
	 * @return string[]
	 */
	public static function brand_taxonomies() {
		return array_values( array_filter( array( 'product_brand', 'pwb-brand', 'yith_product_brand', 'berocket_brand' ), 'taxonomy_exists' ) );
	}

	/**
	 * Scope keys (from the Bricks element / shortcode) mapped to field codes.
	 *
	 * @param array $scope Keys: description, sku, categories, tags, brands, meta (array), taxonomies (array).
	 * @return string[]
	 */
	public static function scope_to_fields( array $scope ) {
		$fields = array( 'title' );
		if ( ! empty( $scope['description'] ) ) {
			$fields[] = 'content';
			$fields[] = 'excerpt';
		}
		if ( ! empty( $scope['sku'] ) ) {
			$fields[] = 'sku';
		}
		if ( ! empty( $scope['categories'] ) ) {
			$fields[] = 'tax:product_cat';
			$fields[] = 'tax:category';
		}
		if ( ! empty( $scope['tags'] ) ) {
			$fields[] = 'tax:product_tag';
			$fields[] = 'tax:post_tag';
		}
		if ( ! empty( $scope['brands'] ) ) {
			foreach ( self::brand_taxonomies() as $tax ) {
				$fields[] = 'tax:' . $tax;
			}
		}
		foreach ( (array) ( isset( $scope['meta'] ) ? $scope['meta'] : array() ) as $key ) {
			$fields[] = 'meta:' . $key;
		}
		foreach ( (array) ( isset( $scope['taxonomies'] ) ? $scope['taxonomies'] : array() ) as $tax ) {
			$fields[] = 'tax:' . $tax;
		}
		// Drop taxonomies that do not exist on this site.
		$fields = array_filter(
			$fields,
			static function ( $code ) {
				return 0 !== strpos( $code, 'tax:' ) || taxonomy_exists( substr( $code, 4 ) );
			}
		);
		return array_values( array_unique( $fields ) );
	}

	/* ------------------------------------------------------------------ */
	/* Runtime config                                                       */
	/* ------------------------------------------------------------------ */

	/**
	 * Compile and store the config the engine needs.
	 */
	public static function build_runtime() {
		$s = self::all();

		// Stopwords.
		$stop = array();
		foreach ( preg_split( '/[\s,]+/', (string) $s['stopwords'] ) as $w ) {
			$w = IMSearch_Text::normalize( $w );
			if ( '' !== $w ) {
				$stop[] = $w;
			}
		}

		// Synonyms: one group per line, phrases separated by commas.
		$groups = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $s['synonyms'] ) as $line ) {
			$group = array();
			foreach ( explode( ',', $line ) as $phrase ) {
				$words = IMSearch_Text::words( $phrase );
				if ( $words ) {
					$group[] = $words;
				}
			}
			if ( count( $group ) > 1 ) {
				$groups[] = $group;
			}
		}

		// Field weights keyed by id.
		$weights = array();
		foreach ( self::field_map() as $code => $id ) {
			$weights[ (string) $id ] = self::field_weight( $code );
		}

		// Group labels.
		$groups_labels = array();
		foreach ( self::indexed_post_types() as $pt ) {
			$obj = get_post_type_object( $pt );
			if ( $obj ) {
				$groups_labels[ $pt ] = $obj->labels->name;
			}
		}
		foreach ( self::indexed_taxonomies() as $tax ) {
			$obj = get_taxonomy( $tax );
			if ( $obj ) {
				$groups_labels[ 'tax:' . $tax ] = $obj->labels->name;
			}
		}

		$fuzzy = isset( self::FUZZY_LEVELS[ $s['fuzzy'] ] ) ? self::FUZZY_LEVELS[ $s['fuzzy'] ] : null;

		$runtime = array(
			'fields'    => self::field_map(),
			'weights'   => $weights,
			'stopwords' => array_values( array_unique( $stop ) ),
			'synonyms'  => $groups,
			'fuzzy'     => $fuzzy ? array(
				'prefix' => $fuzzy[0],
				'max'    => $fuzzy[1],
				'dist'   => $fuzzy[2],
			) : null,
			'min_chars' => (int) $s['min_chars'],
			'groups'    => $groups_labels,
		);

		update_option( self::RUNTIME, wp_json_encode( $runtime ), false );
		return $runtime;
	}

	/**
	 * Runtime config (builds it if missing).
	 *
	 * @return array
	 */
	public static function runtime() {
		$raw = get_option( self::RUNTIME );
		$cfg = is_string( $raw ) ? json_decode( $raw, true ) : null;
		return is_array( $cfg ) ? $cfg : self::build_runtime();
	}

	/**
	 * Index version used to bust browser caches of search responses.
	 *
	 * @return int
	 */
	public static function index_version() {
		return (int) get_option( 'imsearch_index_version', 1 );
	}

	public static function bump_version() {
		update_option( 'imsearch_index_version', self::index_version() + 1, true );
	}
}
