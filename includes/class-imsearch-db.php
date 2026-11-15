<?php
/**
 * Custom index tables.
 *
 * docs     - one row per searchable object (post or term) with display data.
 * words    - the vocabulary (unique normalised words).
 * postings - inverted index: which word appears in which field of which doc.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class IMSearch_DB {

	const SCHEMA_VERSION = '1';

	/**
	 * Table names. With $suffix = '_tmp' returns the shadow tables used while rebuilding.
	 *
	 * @param string $suffix Optional suffix.
	 * @return array{docs:string,words:string,postings:string}
	 */
	public static function tables( $suffix = '' ) {
		global $wpdb;
		return array(
			'docs'     => $wpdb->prefix . 'imsearch_docs' . $suffix,
			'words'    => $wpdb->prefix . 'imsearch_words' . $suffix,
			'postings' => $wpdb->prefix . 'imsearch_postings' . $suffix,
		);
	}

	public static function maybe_upgrade() {
		if ( get_option( 'imsearch_db_version' ) !== self::SCHEMA_VERSION ) {
			self::create_tables();
		}
	}

	/**
	 * Create (or update) a set of tables.
	 *
	 * @param string $suffix Optional suffix for shadow tables.
	 */
	public static function create_tables( $suffix = '' ) {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$t       = self::tables( $suffix );
		$charset = $wpdb->get_charset_collate();

		// Words use a binary collation so normalised words compare exactly.
		$sql = "CREATE TABLE {$t['docs']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  object_id bigint(20) unsigned NOT NULL,
  object_type varchar(10) NOT NULL,
  subtype varchar(80) NOT NULL,
  title text NOT NULL,
  title_norm text NOT NULL,
  excerpt text NOT NULL,
  sku varchar(255) NOT NULL DEFAULT '',
  url text NOT NULL,
  image text NOT NULL,
  price decimal(19,4) DEFAULT NULL,
  price_regular decimal(19,4) DEFAULT NULL,
  price_sale decimal(19,4) DEFAULT NULL,
  price_max decimal(19,4) DEFAULT NULL,
  menu_order int(11) NOT NULL DEFAULT 0,
  post_date datetime NOT NULL DEFAULT '1970-01-01 00:00:01',
  modified datetime NOT NULL DEFAULT '1970-01-01 00:00:01',
  PRIMARY KEY  (id),
  KEY object (object_type,object_id),
  KEY subtype (subtype)
) $charset;
CREATE TABLE {$t['words']} (
  id int(10) unsigned NOT NULL AUTO_INCREMENT,
  word varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY word (word)
) $charset;
CREATE TABLE {$t['postings']} (
  word_id int(10) unsigned NOT NULL,
  field_id smallint(5) unsigned NOT NULL,
  doc_id bigint(20) unsigned NOT NULL,
  hits smallint(5) unsigned NOT NULL DEFAULT 1,
  PRIMARY KEY  (word_id,field_id,doc_id),
  KEY doc_id (doc_id)
) $charset;";

		dbDelta( $sql );

		if ( '' === $suffix ) {
			update_option( 'imsearch_db_version', self::SCHEMA_VERSION, true );
		}
	}

	/**
	 * Drop a set of tables.
	 *
	 * @param string $suffix Suffix.
	 */
	public static function drop_tables( $suffix = '' ) {
		global $wpdb;
		foreach ( self::tables( $suffix ) as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}

	/**
	 * Atomically swap the shadow tables in for the live ones.
	 *
	 * @return bool
	 */
	public static function swap_in_shadow() {
		global $wpdb;
		$live = self::tables();
		$tmp  = self::tables( '_tmp' );
		$old  = self::tables( '_old' );
		self::drop_tables( '_old' );
		$parts = array();
		foreach ( $live as $key => $table ) {
			$parts[] = "{$table} TO {$old[ $key ]}";
			$parts[] = "{$tmp[ $key ]} TO {$table}";
		}
		$ok = false !== $wpdb->query( 'RENAME TABLE ' . implode( ', ', $parts ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		self::drop_tables( '_old' );
		return $ok;
	}

	/**
	 * Row counts for the status panel.
	 *
	 * @return array{docs:int,words:int,postings:int}
	 */
	public static function counts() {
		global $wpdb;
		$t   = self::tables();
		$out = array();
		foreach ( $t as $key => $table ) {
			$out[ $key ] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return $out;
	}
}
