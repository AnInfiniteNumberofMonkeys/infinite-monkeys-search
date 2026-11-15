<?php
/**
 * Bricks Builder integration: registers the Search bar and Search results elements.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class IMSearch_Bricks {

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ), 11 );
		add_filter( 'bricks/builder/i18n', array( __CLASS__, 'i18n' ) );
	}

	public static function register() {
		if ( ! class_exists( '\Bricks\Elements' ) ) {
			return;
		}
		\Bricks\Elements::register_element( IMSEARCH_DIR . 'elements/class-imsearch-element-bar.php', 'imsearch-bar', 'IMSearch_Element_Bar' );
		\Bricks\Elements::register_element( IMSEARCH_DIR . 'elements/class-imsearch-element-results.php', 'imsearch-results', 'IMSearch_Element_Results' );
	}

	public static function i18n( $i18n ) {
		$i18n['imsearch'] = esc_html__( 'Infinite Monkeys Search', 'imsearch' );
		return $i18n;
	}

	/**
	 * Is this render happening inside the Bricks builder?
	 *
	 * @return bool
	 */
	public static function in_builder() {
		return ( function_exists( 'bricks_is_builder_call' ) && bricks_is_builder_call() )
			|| ( function_exists( 'bricks_is_builder_iframe' ) && bricks_is_builder_iframe() )
			|| ( function_exists( 'bricks_is_builder' ) && bricks_is_builder() );
	}

	/**
	 * Post type options (slug => label) excluding some.
	 *
	 * @param string[] $exclude Slugs to leave out.
	 * @return array
	 */
	public static function post_type_options( array $exclude = array() ) {
		$out = array();
		foreach ( IMSearch_Settings::indexed_post_types() as $pt ) {
			if ( in_array( $pt, $exclude, true ) ) {
				continue;
			}
			$obj = get_post_type_object( $pt );
			if ( $obj ) {
				$out[ $pt ] = $obj->labels->name;
			}
		}
		return $out;
	}

	/**
	 * Taxonomy options (slug => label) excluding some.
	 *
	 * @param string[] $exclude Slugs to leave out.
	 * @return array
	 */
	public static function taxonomy_options( array $exclude = array() ) {
		$out = array();
		foreach ( IMSearch_Settings::indexed_taxonomies() as $tax ) {
			if ( in_array( $tax, $exclude, true ) ) {
				continue;
			}
			$obj = get_taxonomy( $tax );
			if ( $obj ) {
				$out[ $tax ] = $obj->labels->name . ' (' . $tax . ')';
			}
		}
		return $out;
	}
}
