<?php
/**
 * Remove the index tables, options and scheduled events.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

foreach ( array( '', '_tmp', '_old' ) as $imsearch_suffix ) {
	foreach ( array( 'imsearch_docs', 'imsearch_words', 'imsearch_postings' ) as $imsearch_table ) {
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . $imsearch_table . $imsearch_suffix ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
}

foreach ( array( 'imsearch_settings', 'imsearch_runtime', 'imsearch_fields', 'imsearch_index_state', 'imsearch_queue', 'imsearch_dirty', 'imsearch_lock', 'imsearch_db_version', 'imsearch_index_version', 'imsearch_endpoint_ok' ) as $imsearch_option ) {
	delete_option( $imsearch_option );
}

foreach ( array( 'imsearch_build_step', 'imsearch_scheduled_reindex', 'imsearch_process_queue' ) as $imsearch_hook ) {
	wp_clear_scheduled_hook( $imsearch_hook );
}
