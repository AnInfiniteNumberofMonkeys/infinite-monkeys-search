<?php
/**
 * Fast search endpoint.
 *
 * Loads WordPress with SHORTINIT (database only: no plugins, theme or
 * hooks) and answers from the index tables, like FiboSearch Pro's endpoint.
 * Read-only: it only ever returns published, publicly visible content that
 * the indexer stored. All input is validated against whitelists and every
 * value reaching SQL goes through $wpdb->prepare() or is cast to int.
 *
 * Query: ?s=term&types=product,page&fields=title,sku&limit=10&orderby=relevance&order=desc&with=image,price
 */

// phpcs:disable WordPress.Security.NonceVerification.Recommended

if ( isset( $_GET['ping'] ) ) {
	header( 'Content-Type: text/plain; charset=utf-8' );
	header( 'X-Robots-Tag: noindex' );
	echo 'pong';
	exit;
}

if ( 'GET' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : 'GET' ) ) {
	http_response_code( 405 );
	exit;
}

define( 'SHORTINIT', true );
define( 'IMSEARCH_DOING_SEARCH', true );

// Find wp-load.php: <wp>/wp-content/plugins/infinite-monkeys-search/endpoint/search.php.
$imsearch_dir  = __DIR__;
$imsearch_load = '';
for ( $imsearch_i = 0; $imsearch_i < 8; $imsearch_i++ ) {
	$imsearch_dir = dirname( $imsearch_dir );
	foreach ( array( '', 'wp/', 'wordpress/' ) as $imsearch_sub ) {
		if ( is_file( $imsearch_dir . '/' . $imsearch_sub . 'wp-load.php' ) ) {
			$imsearch_load = $imsearch_dir . '/' . $imsearch_sub . 'wp-load.php';
			break 2;
		}
	}
}
if ( '' === $imsearch_load ) {
	http_response_code( 500 );
	exit;
}
require_once $imsearch_load;

if ( ! defined( 'ABSPATH' ) ) {
	http_response_code( 500 );
	exit;
}

/**
 * Send JSON and stop.
 *
 * @param mixed $data   Data.
 * @param int   $status HTTP status.
 */
function imsearch_endpoint_send( $data, $status = 200 ) {
	http_response_code( $status );
	header( 'Content-Type: application/json; charset=utf-8' );
	header( 'X-Content-Type-Options: nosniff' );
	header( 'X-Robots-Tag: noindex' );
	header( 'Cache-Control: ' . ( 200 === $status ? 'public, max-age=300' : 'no-store' ) );
	echo json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	exit;
}

global $wpdb;

// Only answer while the plugin is active.
$imsearch_active = $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'active_plugins' LIMIT 1" );
$imsearch_active = is_string( $imsearch_active ) ? @unserialize( $imsearch_active, array( 'allowed_classes' => false ) ) : array(); // phpcs:ignore
$imsearch_base   = basename( dirname( __DIR__ ) ) . '/infinite-monkeys-search.php';
if ( ! is_array( $imsearch_active ) || ! in_array( $imsearch_base, $imsearch_active, true ) ) {
	imsearch_endpoint_send( array( 'error' => 'disabled' ), 404 );
}

$imsearch_runtime = $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'imsearch_runtime' LIMIT 1" );
$imsearch_runtime = is_string( $imsearch_runtime ) ? json_decode( $imsearch_runtime, true ) : null;
if ( ! is_array( $imsearch_runtime ) ) {
	imsearch_endpoint_send( array( 'error' => 'not_ready' ), 503 );
}

require_once dirname( __DIR__ ) . '/includes/class-imsearch-text.php';
require_once dirname( __DIR__ ) . '/includes/class-imsearch-engine.php';

// SHORTINIT runs before WordPress adds magic quotes, so $_GET is raw here.
$imsearch_s    = isset( $_GET['s'] ) && is_string( $_GET['s'] ) ? $_GET['s'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
$imsearch_s    = trim( preg_replace( '/[\x00-\x1F\x7F<>]/u', ' ', (string) $imsearch_s ) );
$imsearch_args = array();
foreach ( array( 'types', 'fields', 'limit', 'orderby', 'order', 'with' ) as $imsearch_key ) {
	if ( isset( $_GET[ $imsearch_key ] ) && is_string( $_GET[ $imsearch_key ] ) ) {
		$imsearch_args[ $imsearch_key ] = substr( $_GET[ $imsearch_key ], 0, 2000 ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	}
}

$imsearch_with = array();
foreach ( explode( ',', isset( $imsearch_args['with'] ) ? $imsearch_args['with'] : '' ) as $imsearch_flag ) {
	if ( in_array( $imsearch_flag, array( 'image', 'price', 'excerpt', 'sku' ), true ) ) {
		$imsearch_with[] = $imsearch_flag;
	}
}

$imsearch_engine = new IMSearch_Engine( $wpdb, $imsearch_runtime );
imsearch_endpoint_send(
	$imsearch_engine->search(
		$imsearch_s,
		array(
			'types'   => isset( $imsearch_args['types'] ) ? $imsearch_args['types'] : 'product',
			'fields'  => isset( $imsearch_args['fields'] ) ? $imsearch_args['fields'] : '',
			'limit'   => isset( $imsearch_args['limit'] ) ? (int) $imsearch_args['limit'] : 10,
			'orderby' => isset( $imsearch_args['orderby'] ) ? $imsearch_args['orderby'] : 'relevance',
			'order'   => isset( $imsearch_args['order'] ) ? $imsearch_args['order'] : 'desc',
			'with'    => $imsearch_with,
		)
	)
);
