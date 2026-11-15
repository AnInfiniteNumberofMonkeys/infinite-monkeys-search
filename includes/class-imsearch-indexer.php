<?php
/**
 * Builds and maintains the search index.
 *
 * - Full rebuilds write into shadow tables and swap them in atomically, so
 *   search keeps working (with the old index) while a rebuild runs.
 * - Real-time updates listen to save_post, deleted_post, edited_term,
 *   created_term, delete_term and a few WooCommerce/Bricks hooks, collect the
 *   affected objects during the request and re-index them on shutdown.
 * - Scheduled rebuilds run through WP-Cron in time-boxed steps.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class IMSearch_Indexer {

	const STATE          = 'imsearch_index_state';
	const QUEUE          = 'imsearch_queue';
	const DIRTY          = 'imsearch_dirty';
	const LOCK           = 'imsearch_lock';
	const CRON_STEP      = 'imsearch_build_step';
	const CRON_SCHEDULED = 'imsearch_scheduled_reindex';
	const CRON_QUEUE     = 'imsearch_process_queue';
	const BATCH          = 50;
	const INLINE_LIMIT   = 25;

	/** @var array{post:array<int,bool>,term:array<string,bool>} */
	private static $pending = array(
		'post' => array(),
		'term' => array(),
	);

	/** @var array|null Expanded excluded term ids for this request. */
	private static $excluded = null;

	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) );
		add_action( self::CRON_STEP, array( __CLASS__, 'cron_step' ) );
		add_action( self::CRON_SCHEDULED, array( __CLASS__, 'start_full' ) );
		add_action( self::CRON_QUEUE, array( __CLASS__, 'process_queue' ) );

		// Real-time updates.
		add_action( 'save_post', array( __CLASS__, 'on_save_post' ), 99, 2 );
		add_action( 'deleted_post', array( __CLASS__, 'on_post_gone' ) );
		add_action( 'trashed_post', array( __CLASS__, 'on_post_gone' ) );
		add_action( 'set_object_terms', array( __CLASS__, 'queue_post' ) );
		add_action( 'woocommerce_update_product', array( __CLASS__, 'queue_post' ) );
		add_action( 'woocommerce_new_product', array( __CLASS__, 'queue_post' ) );
		add_action( 'woocommerce_product_set_stock_status', array( __CLASS__, 'queue_post' ) );
		add_action( 'woocommerce_variation_set_stock_status', array( __CLASS__, 'queue_post' ) );
		add_action( 'updated_post_meta', array( __CLASS__, 'on_meta' ), 10, 3 );
		add_action( 'added_post_meta', array( __CLASS__, 'on_meta' ), 10, 3 );
		add_action( 'updated_post_meta', array( __CLASS__, 'on_bricks_save' ), 10, 4 );
		add_action( 'added_post_meta', array( __CLASS__, 'on_bricks_save' ), 10, 4 );
		add_action( 'created_term', array( __CLASS__, 'on_term' ), 10, 3 );
		add_action( 'edited_term', array( __CLASS__, 'on_term' ), 10, 3 );
		add_action( 'delete_term', array( __CLASS__, 'on_delete_term' ), 10, 5 );
		add_action( 'shutdown', array( __CLASS__, 'flush_pending' ) );

		add_action( 'update_option_' . IMSearch_Settings::OPTION, array( __CLASS__, 'on_settings_saved' ), 10, 2 );
		add_action(
			'add_option_' . IMSearch_Settings::OPTION,
			static function ( $name, $value ) {
				self::on_settings_saved( array(), $value );
			},
			10,
			2
		);
	}

	/* ------------------------------------------------------------------ */
	/* Lifecycle                                                            */
	/* ------------------------------------------------------------------ */

	public static function activate() {
		IMSearch_DB::create_tables();
		IMSearch_Settings::build_runtime();
		self::reschedule();
		update_option( 'imsearch_endpoint_ok', IMSearch_Frontend::test_fast_endpoint() ? 1 : 0, true );
		$counts = IMSearch_DB::counts();
		if ( 0 === $counts['docs'] ) {
			self::start_full();
		}
	}

	public static function deactivate() {
		foreach ( array( self::CRON_STEP, self::CRON_SCHEDULED, self::CRON_QUEUE ) as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
		delete_option( self::LOCK );
	}

	public static function cron_schedules( $schedules ) {
		if ( ! isset( $schedules['weekly'] ) ) {
			$schedules['weekly'] = array(
				'interval' => WEEK_IN_SECONDS,
				'display'  => __( 'Once Weekly', 'imsearch' ),
			);
		}
		$schedules['imsearch_semimonthly'] = array(
			'interval' => 15 * DAY_IN_SECONDS,
			'display'  => __( 'Twice a month', 'imsearch' ),
		);
		$schedules['imsearch_monthly'] = array(
			'interval' => 30 * DAY_IN_SECONDS,
			'display'  => __( 'Once a month', 'imsearch' ),
		);
		return $schedules;
	}

	/**
	 * (Re)create the scheduled full rebuild according to settings.
	 */
	public static function reschedule() {
		wp_clear_scheduled_hook( self::CRON_SCHEDULED );
		$map = array(
			'daily'       => 'daily',
			'weekly'      => 'weekly',
			'semimonthly' => 'imsearch_semimonthly',
			'monthly'     => 'imsearch_monthly',
		);
		$schedule = IMSearch_Settings::get( 'schedule' );
		if ( isset( $map[ $schedule ] ) ) {
			// First run tonight at 03:00 site time.
			$next = strtotime( 'tomorrow 03:00', current_time( 'timestamp' ) ) - ( (int) get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
			wp_schedule_event( $next, $map[ $schedule ], self::CRON_SCHEDULED );
		}
	}

	/**
	 * Settings changed: rebuild runtime config, reschedule and rebuild the
	 * index if a setting that affects what is indexed changed.
	 *
	 * @param mixed $old Old value.
	 * @param mixed $new New value.
	 */
	public static function on_settings_saved( $old, $new ) {
		IMSearch_Settings::build_runtime();
		self::reschedule();
		$old = is_array( $old ) ? $old : array();
		$new = is_array( $new ) ? $new : array();
		foreach ( array( 'exclude_out_of_stock', 'exclude_terms', 'index_meta_keys' ) as $key ) {
			$a = isset( $old[ $key ] ) ? $old[ $key ] : null;
			$b = isset( $new[ $key ] ) ? $new[ $key ] : null;
			if ( wp_json_encode( $a ) !== wp_json_encode( $b ) ) {
				self::start_full();
				break;
			}
		}
		IMSearch_Settings::bump_version();
		update_option( 'imsearch_endpoint_ok', IMSearch_Frontend::test_fast_endpoint() ? 1 : 0, true );
	}

	/* ------------------------------------------------------------------ */
	/* State & locking                                                      */
	/* ------------------------------------------------------------------ */

	/**
	 * @return array
	 */
	public static function state() {
		$state = get_option( self::STATE, array() );
		return array_merge(
			array(
				'status'      => 'idle',
				'started'     => 0,
				'finished'    => 0,
				'total'       => 0,
				'done'        => 0,
				'phase'       => 'posts',
				'cursor'      => 0,
				'last_change' => 0,
				'error'       => '',
			),
			is_array( $state ) ? $state : array()
		);
	}

	private static function save_state( array $state ) {
		update_option( self::STATE, $state, false );
	}

	private static function lock() {
		$held = (int) get_option( self::LOCK, 0 );
		if ( $held && ( time() - $held ) < 120 ) {
			return false;
		}
		if ( $held ) {
			delete_option( self::LOCK );
		}
		return add_option( self::LOCK, time(), '', false );
	}

	private static function unlock() {
		delete_option( self::LOCK );
	}

	/* ------------------------------------------------------------------ */
	/* Full rebuild                                                         */
	/* ------------------------------------------------------------------ */

	/**
	 * Start a full rebuild into shadow tables.
	 */
	public static function start_full() {
		global $wpdb;
		IMSearch_DB::drop_tables( '_tmp' );
		IMSearch_DB::create_tables( '_tmp' );

		$types = IMSearch_Settings::indexed_post_types();
		$posts = 0;
		if ( $types ) {
			$ph    = implode( ',', array_fill( 0, count( $types ), '%s' ) );
			$posts = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ($ph)", $types ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		$taxes = IMSearch_Settings::indexed_taxonomies();
		$terms = 0;
		if ( $taxes ) {
			$ph    = implode( ',', array_fill( 0, count( $taxes ), '%s' ) );
			$terms = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy IN ($ph)", $taxes ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		delete_option( self::DIRTY );
		self::save_state(
			array_merge(
				self::state(),
				array(
					'status'  => 'building',
					'started' => time(),
					'total'   => $posts + $terms,
					'done'    => 0,
					'phase'   => 'posts',
					'cursor'  => 0,
					'error'   => '',
				)
			)
		);
		self::unlock();
		if ( ! wp_next_scheduled( self::CRON_STEP ) ) {
			wp_schedule_single_event( time(), self::CRON_STEP );
		}
	}

	/**
	 * Cron: run steps for ~20 seconds, then reschedule if not finished.
	 */
	public static function cron_step() {
		$state = self::step( 20 );
		if ( 'building' === $state['status'] && ! wp_next_scheduled( self::CRON_STEP ) ) {
			wp_schedule_single_event( time() + 5, self::CRON_STEP );
		}
	}

	/**
	 * Process batches for up to $budget seconds.
	 *
	 * @param int $budget Seconds.
	 * @return array State.
	 */
	public static function step( $budget = 5 ) {
		global $wpdb;
		$state = self::state();
		if ( 'building' !== $state['status'] ) {
			return $state;
		}
		if ( ! self::lock() ) {
			return $state;
		}

		$end = microtime( true ) + $budget;
		$tmp = IMSearch_DB::tables( '_tmp' );

		try {
			while ( microtime( true ) < $end ) {
				if ( 'posts' === $state['phase'] ) {
					$types = IMSearch_Settings::indexed_post_types();
					$ids   = array();
					if ( $types ) {
						$ph   = implode( ',', array_fill( 0, count( $types ), '%s' ) );
						$args = array_merge( $types, array( (int) $state['cursor'], self::BATCH ) );
						$ids  = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ($ph) AND ID > %d ORDER BY ID ASC LIMIT %d", $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					}
					if ( ! $ids ) {
						$state['phase']  = 'terms';
						$state['cursor'] = 0;
						continue;
					}
					foreach ( $ids as $id ) {
						self::index_post( (int) $id, $tmp, false );
						$state['cursor'] = (int) $id;
						++$state['done'];
					}
				} elseif ( 'terms' === $state['phase'] ) {
					$taxes = IMSearch_Settings::indexed_taxonomies();
					$rows  = array();
					if ( $taxes ) {
						$ph   = implode( ',', array_fill( 0, count( $taxes ), '%s' ) );
						$args = array_merge( $taxes, array( (int) $state['cursor'], self::BATCH ) );
						$rows = $wpdb->get_results( $wpdb->prepare( "SELECT term_taxonomy_id, term_id, taxonomy FROM {$wpdb->term_taxonomy} WHERE taxonomy IN ($ph) AND term_taxonomy_id > %d ORDER BY term_taxonomy_id ASC LIMIT %d", $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					}
					if ( ! $rows ) {
						$state['phase'] = 'swap';
						continue;
					}
					foreach ( $rows as $row ) {
						self::index_term( (int) $row->term_id, $row->taxonomy, $tmp, false );
						$state['cursor'] = (int) $row->term_taxonomy_id;
						++$state['done'];
					}
				} else {
					if ( ! IMSearch_DB::swap_in_shadow() ) {
						throw new Exception( 'Could not swap the new index into place: ' . $wpdb->last_error );
					}
					$state['status']      = 'idle';
					$state['finished']    = time();
					$state['last_change'] = time();
					$state['done']        = $state['total'];
					self::save_state( $state );
					// Changes made while building were written to the old tables.
					$dirty = get_option( self::DIRTY, array() );
					delete_option( self::DIRTY );
					if ( is_array( $dirty ) ) {
						self::apply_changes( $dirty );
					}
					IMSearch_Settings::build_runtime();
					IMSearch_Settings::bump_version();
					update_option( 'imsearch_endpoint_ok', IMSearch_Frontend::test_fast_endpoint() ? 1 : 0, true );
					break;
				}
				self::save_state( $state );
			}
		} catch ( Throwable $e ) {
			$state['status'] = 'idle';
			$state['error']  = $e->getMessage();
		}

		self::save_state( $state );
		self::unlock();
		return $state;
	}

	/* ------------------------------------------------------------------ */
	/* Real-time updates                                                    */
	/* ------------------------------------------------------------------ */

	public static function on_save_post( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		self::queue_post( $post_id );
	}

	public static function on_post_gone( $post_id ) {
		self::queue_post( $post_id );
	}

	/**
	 * Queue a post (variations queue their parent).
	 *
	 * @param mixed $post_id Post id or product.
	 */
	public static function queue_post( $post_id ) {
		if ( is_object( $post_id ) && method_exists( $post_id, 'get_id' ) ) {
			$post_id = $post_id->get_id();
		}
		$post_id = (int) $post_id;
		if ( ! $post_id ) {
			return;
		}
		$type = get_post_type( $post_id );
		if ( 'product_variation' === $type ) {
			$post_id = (int) wp_get_post_parent_id( $post_id );
			$type    = $post_id ? get_post_type( $post_id ) : '';
		}
		if ( $post_id && ( ! $type || in_array( $type, IMSearch_Settings::indexed_post_types(), true ) ) ) {
			self::$pending['post'][ $post_id ] = true;
		}
	}

	public static function on_meta( $meta_id, $post_id, $meta_key ) {
		$watched = array_merge( array( '_bricks_page_content_2', '_sku', '_price', '_stock_status', '_thumbnail_id' ), (array) IMSearch_Settings::get( 'index_meta_keys' ) );
		if ( in_array( $meta_key, $watched, true ) ) {
			self::queue_post( $post_id );
		}
	}

	/**
	 * When Bricks saves a page or template, make sure every custom field a
	 * Search bar element searches is in the index.
	 *
	 * @param int    $meta_id    Meta id.
	 * @param int    $post_id    Post id.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Elements.
	 */
	public static function on_bricks_save( $meta_id, $post_id, $meta_key, $meta_value ) {
		if ( ! in_array( $meta_key, array( '_bricks_page_content_2', '_bricks_page_header_2', '_bricks_page_footer_2' ), true ) || ! is_array( $meta_value ) ) {
			return;
		}
		$keys = array();
		foreach ( $meta_value as $el ) {
			if ( isset( $el['name'], $el['settings']['searchCustomFields'] ) && 'imsearch-bar' === $el['name'] ) {
				$keys = array_merge( $keys, (array) $el['settings']['searchCustomFields'] );
			}
		}
		if ( $keys ) {
			IMSearch_Settings::add_index_meta_keys( array_map( 'strval', $keys ) );
		}
	}

	public static function on_term( $term_id, $tt_id, $taxonomy ) {
		if ( ! in_array( $taxonomy, IMSearch_Settings::indexed_taxonomies(), true ) ) {
			return;
		}
		self::$pending['term'][ $taxonomy . ':' . (int) $term_id ] = true;
		// Posts carry the term name in their taxonomy field.
		$objects = get_objects_in_term( (int) $term_id, $taxonomy );
		if ( is_array( $objects ) ) {
			foreach ( $objects as $object_id ) {
				self::queue_post( $object_id );
			}
		}
	}

	public static function on_delete_term( $term, $tt_id, $taxonomy, $deleted_term, $object_ids = array() ) {
		self::$pending['term'][ $taxonomy . ':' . (int) $term ] = true;
		foreach ( (array) $object_ids as $object_id ) {
			self::queue_post( $object_id );
		}
	}

	/**
	 * End of request: apply queued changes (or hand them to cron if many).
	 */
	public static function flush_pending() {
		$changes = array(
			'post' => array_keys( self::$pending['post'] ),
			'term' => array_keys( self::$pending['term'] ),
		);
		self::$pending = array(
			'post' => array(),
			'term' => array(),
		);
		if ( ! $changes['post'] && ! $changes['term'] ) {
			return;
		}

		if ( 'building' === self::state()['status'] ) {
			self::merge_option( self::DIRTY, $changes );
		}

		if ( count( $changes['post'] ) + count( $changes['term'] ) > self::INLINE_LIMIT ) {
			self::merge_option( self::QUEUE, $changes );
			if ( ! wp_next_scheduled( self::CRON_QUEUE ) ) {
				wp_schedule_single_event( time(), self::CRON_QUEUE );
			}
			return;
		}
		self::apply_changes( $changes );
	}

	/**
	 * Cron: process queued changes.
	 */
	public static function process_queue() {
		$queue = get_option( self::QUEUE, array() );
		delete_option( self::QUEUE );
		if ( is_array( $queue ) ) {
			self::apply_changes( $queue );
		}
	}

	private static function merge_option( $option, array $changes ) {
		$stored = get_option( $option, array() );
		$stored = is_array( $stored ) ? $stored : array();
		foreach ( array( 'post', 'term' ) as $kind ) {
			$stored[ $kind ] = array_values( array_unique( array_merge( isset( $stored[ $kind ] ) ? (array) $stored[ $kind ] : array(), $changes[ $kind ] ) ) );
		}
		update_option( $option, $stored, false );
	}

	/**
	 * Re-index changed objects in the live tables.
	 *
	 * @param array $changes ['post' => ids, 'term' => ['taxonomy:id']].
	 */
	public static function apply_changes( array $changes ) {
		$live   = IMSearch_DB::tables();
		$before = count( IMSearch_Settings::field_map() );
		foreach ( isset( $changes['post'] ) ? (array) $changes['post'] : array() as $id ) {
			self::index_post( (int) $id, $live, true );
		}
		foreach ( isset( $changes['term'] ) ? (array) $changes['term'] : array() as $key ) {
			$parts = explode( ':', (string) $key );
			if ( 2 === count( $parts ) ) {
				self::index_term( (int) $parts[1], $parts[0], $live, true );
			}
		}
		if ( count( IMSearch_Settings::field_map() ) !== $before ) {
			IMSearch_Settings::build_runtime();
		}
		IMSearch_Settings::bump_version();
		$state                = self::state();
		$state['last_change'] = time();
		self::save_state( $state );
	}

	/* ------------------------------------------------------------------ */
	/* Indexing one object                                                  */
	/* ------------------------------------------------------------------ */

	/**
	 * Remove an object's doc and postings.
	 *
	 * @param string $type 'post'|'term'.
	 * @param int    $id   Object id.
	 * @param array  $t    Tables.
	 * @param string $subtype Optional subtype (terms: tax:<taxonomy>).
	 */
	private static function remove( $type, $id, array $t, $subtype = '' ) {
		global $wpdb;
		if ( $subtype ) {
			$docs = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$t['docs']} WHERE object_type = %s AND object_id = %d AND subtype = %s", $type, $id, $subtype ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} else {
			$docs = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$t['docs']} WHERE object_type = %s AND object_id = %d", $type, $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		if ( $docs ) {
			$in = implode( ',', array_map( 'intval', $docs ) );
			$wpdb->query( "DELETE FROM {$t['postings']} WHERE doc_id IN ($in)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DELETE FROM {$t['docs']} WHERE id IN ($in)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}

	/**
	 * Term ids that exclude content, including descendants.
	 *
	 * @return array<int,bool>
	 */
	private static function excluded_terms() {
		if ( null !== self::$excluded ) {
			return self::$excluded;
		}
		$out = array();
		foreach ( (array) IMSearch_Settings::get( 'exclude_terms' ) as $term_id ) {
			$term = get_term( (int) $term_id );
			if ( ! $term || is_wp_error( $term ) ) {
				continue;
			}
			$out[ (int) $term->term_id ] = true;
			if ( is_taxonomy_hierarchical( $term->taxonomy ) ) {
				$children = get_term_children( $term->term_id, $term->taxonomy );
				foreach ( is_array( $children ) ? $children : array() as $child ) {
					$out[ (int) $child ] = true;
				}
			}
		}
		self::$excluded = $out;
		return $out;
	}

	/**
	 * Should this post be in the index?
	 *
	 * @param WP_Post|null $post Post.
	 * @return bool
	 */
	private static function should_index_post( $post ) {
		if ( ! $post || 'publish' !== $post->post_status || '' !== $post->post_password ) {
			return false;
		}
		if ( ! in_array( $post->post_type, IMSearch_Settings::indexed_post_types(), true ) ) {
			return false;
		}
		if ( 'page' === $post->post_type && function_exists( 'wc_get_page_id' ) ) {
			foreach ( array( 'cart', 'checkout', 'myaccount' ) as $page ) {
				if ( (int) wc_get_page_id( $page ) === (int) $post->ID ) {
					return false;
				}
			}
		}
		if ( 'product' === $post->post_type && function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $post->ID );
			if ( ! $product ) {
				return false;
			}
			if ( in_array( $product->get_catalog_visibility(), array( 'hidden', 'catalog' ), true ) ) {
				return false;
			}
			if ( IMSearch_Settings::get( 'exclude_out_of_stock' ) && 'outofstock' === $product->get_stock_status() ) {
				return false;
			}
		}
		$excluded = self::excluded_terms();
		if ( $excluded ) {
			$terms = wp_get_object_terms( $post->ID, get_object_taxonomies( $post->post_type ), array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $terms ) ) {
				foreach ( $terms as $tid ) {
					if ( isset( $excluded[ (int) $tid ] ) ) {
						return false;
					}
				}
			}
		}
		return (bool) apply_filters( 'imsearch_should_index_post', true, $post );
	}

	/**
	 * Visible text of a Bricks-built post.
	 *
	 * @param int $post_id Post id.
	 * @return string
	 */
	private static function bricks_text( $post_id ) {
		if ( 'bricks' !== get_post_meta( $post_id, '_bricks_editor_mode', true ) ) {
			return '';
		}
		$elements = get_post_meta( $post_id, '_bricks_page_content_2', true );
		if ( ! is_array( $elements ) ) {
			return '';
		}
		$keys = array( 'text', 'title', 'content', 'description', 'subtitle', 'heading', 'caption', 'label', 'question', 'answer' );
		$out  = array();
		$walk = static function ( $value ) use ( &$walk, &$out, $keys ) {
			foreach ( (array) $value as $key => $item ) {
				if ( is_array( $item ) ) {
					$walk( $item );
				} elseif ( is_string( $item ) && in_array( $key, $keys, true ) && false === strpos( $item, '{' ) ) {
					$out[] = $item;
				}
			}
		};
		foreach ( $elements as $el ) {
			if ( isset( $el['settings'] ) ) {
				$walk( $el['settings'] );
			}
		}
		return implode( ' ', $out );
	}

	/**
	 * Plain text from HTML.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private static function plain( $html ) {
		$html = strip_shortcodes( (string) $html );
		$html = preg_replace( '/<(script|style)\b[^>]*>.*?<\/\1>/is', ' ', $html );
		return trim( preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( str_replace( '<', ' <', $html ) ), ENT_QUOTES, 'UTF-8' ) ) );
	}

	/**
	 * Index (or remove) one post.
	 *
	 * @param int   $post_id Post id.
	 * @param array $t       Tables.
	 * @param bool  $remove_first Remove the existing doc first.
	 * @return bool Indexed.
	 */
	public static function index_post( $post_id, array $t, $remove_first = true ) {
		if ( $remove_first ) {
			self::remove( 'post', $post_id, $t );
		}
		$post = get_post( $post_id );
		if ( ! self::should_index_post( $post ) ) {
			return false;
		}

		$fields  = array();
		$title   = html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' );
		$content = self::plain( $post->post_content );
		$bricks  = self::bricks_text( $post->ID );
		if ( '' !== $bricks ) {
			$content .= ' ' . self::plain( $bricks );
		}
		$content = IMSearch_Text::prefix( $content, 30000 );
		$excerpt = self::plain( $post->post_excerpt );

		$fields['title']   = $title;
		$fields['content'] = $content;
		$fields['excerpt'] = $excerpt;

		// Taxonomy fields.
		foreach ( get_object_taxonomies( $post->post_type ) as $tax ) {
			if ( ! in_array( $tax, IMSearch_Settings::indexed_taxonomies(), true ) ) {
				continue;
			}
			$names = wp_get_object_terms( $post->ID, $tax, array( 'fields' => 'names' ) );
			if ( ! is_wp_error( $names ) && $names ) {
				$fields[ 'tax:' . $tax ] = implode( ' ', $names );
			}
		}

		// Custom fields.
		foreach ( (array) IMSearch_Settings::get( 'index_meta_keys' ) as $key ) {
			$values = get_post_meta( $post->ID, $key, false );
			$text   = array();
			array_walk_recursive(
				$values,
				static function ( $v ) use ( &$text ) {
					if ( is_scalar( $v ) && ! is_bool( $v ) ) {
						$text[] = (string) $v;
					}
				}
			);
			if ( $text ) {
				$fields[ 'meta:' . $key ] = self::plain( implode( ' ', $text ) );
			}
		}

		// Display data.
		$doc = array(
			'object_id'     => $post->ID,
			'object_type'   => 'post',
			'subtype'       => $post->post_type,
			'title'         => $title,
			'title_norm'    => implode( ' ', IMSearch_Text::words( $title ) ),
			'excerpt'       => '',
			'sku'           => '',
			'url'           => (string) get_permalink( $post ),
			'image'         => '',
			'price'         => null,
			'price_regular' => null,
			'price_sale'    => null,
			'price_max'     => null,
			'menu_order'    => (int) $post->menu_order,
			'post_date'     => $post->post_date_gmt && '0000-00-00 00:00:00' !== $post->post_date_gmt ? $post->post_date_gmt : $post->post_date,
			'modified'      => $post->post_modified_gmt && '0000-00-00 00:00:00' !== $post->post_modified_gmt ? $post->post_modified_gmt : $post->post_modified,
		);

		$thumb = get_post_thumbnail_id( $post );
		if ( $thumb ) {
			$doc['image'] = (string) wp_get_attachment_image_url( $thumb, 'thumbnail' );
		}

		$skus = array();
		if ( 'product' === $post->post_type && function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $post->ID );
			$sku     = (string) $product->get_sku( 'edit' );
			if ( '' !== $sku ) {
				$skus[]     = $sku;
				$doc['sku'] = $sku;
			}
			if ( $product->is_type( 'variable' ) ) {
				foreach ( $product->get_children() as $child_id ) {
					$child_sku = (string) get_post_meta( $child_id, '_sku', true );
					if ( '' !== $child_sku ) {
						$skus[] = $child_sku;
					}
				}
				$min = $product->get_variation_price( 'min', false );
				$max = $product->get_variation_price( 'max', false );
				if ( '' !== (string) $min ) {
					$doc['price']     = (float) $min;
					$doc['price_max'] = ( '' !== (string) $max && (float) $max !== (float) $min ) ? (float) $max : null;
				}
			} else {
				$price = $product->get_price( 'edit' );
				if ( '' !== (string) $price ) {
					$doc['price']         = (float) $price;
					$regular              = $product->get_regular_price( 'edit' );
					$doc['price_regular'] = '' !== (string) $regular ? (float) $regular : null;
					if ( $product->is_on_sale( 'edit' ) ) {
						$doc['price_sale'] = (float) $product->get_sale_price( 'edit' );
					}
				}
			}
			$short          = self::plain( $product->get_short_description( 'edit' ) );
			$doc['excerpt'] = '' !== $short ? $short : $content;
			if ( ! $doc['image'] && function_exists( 'wc_placeholder_img_src' ) ) {
				$doc['image'] = (string) wc_placeholder_img_src( 'thumbnail' );
			}
		} else {
			$doc['excerpt'] = '' !== $excerpt ? $excerpt : $content;
		}
		$doc['excerpt'] = IMSearch_Text::prefix( wp_trim_words( $doc['excerpt'], 30, '…' ), 300 );
		if ( $skus ) {
			$fields['sku'] = implode( ' ', $skus );
		}

		$doc    = apply_filters( 'imsearch_post_doc', $doc, $post );
		$fields = apply_filters( 'imsearch_post_fields', $fields, $post );

		self::write_doc( $doc, $fields, $skus, $t );
		return true;
	}

	/**
	 * Index (or remove) one term.
	 *
	 * @param int    $term_id  Term id.
	 * @param string $taxonomy Taxonomy.
	 * @param array  $t        Tables.
	 * @param bool   $remove_first Remove first.
	 * @return bool
	 */
	public static function index_term( $term_id, $taxonomy, array $t, $remove_first = true ) {
		if ( $remove_first ) {
			self::remove( 'term', $term_id, $t, 'tax:' . $taxonomy );
		}
		if ( ! in_array( $taxonomy, IMSearch_Settings::indexed_taxonomies(), true ) ) {
			return false;
		}
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term || is_wp_error( $term ) || (int) $term->count < 1 ) {
			return false;
		}
		$excluded = self::excluded_terms();
		if ( isset( $excluded[ (int) $term->term_id ] ) ) {
			return false;
		}
		$link = get_term_link( $term );
		if ( is_wp_error( $link ) ) {
			return false;
		}
		$name  = html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' );
		$desc  = self::plain( $term->description );
		$image = '';
		$thumb = (int) get_term_meta( $term->term_id, 'thumbnail_id', true );
		if ( $thumb ) {
			$image = (string) wp_get_attachment_image_url( $thumb, 'thumbnail' );
		}
		$doc = array(
			'object_id'     => $term->term_id,
			'object_type'   => 'term',
			'subtype'       => 'tax:' . $taxonomy,
			'title'         => $name,
			'title_norm'    => implode( ' ', IMSearch_Text::words( $name ) ),
			'excerpt'       => IMSearch_Text::prefix( wp_trim_words( $desc, 30, '…' ), 300 ),
			'sku'           => '',
			'url'           => (string) $link,
			'image'         => $image,
			'price'         => null,
			'price_regular' => null,
			'price_sale'    => null,
			'price_max'     => null,
			'menu_order'    => (int) get_term_meta( $term->term_id, 'order', true ),
			'post_date'     => '1970-01-01 00:00:01',
			'modified'      => '1970-01-01 00:00:01',
		);
		$fields = array(
			'title'   => $name,
			'content' => $desc,
		);
		self::write_doc( apply_filters( 'imsearch_term_doc', $doc, $term ), $fields, array(), $t );
		return true;
	}

	/**
	 * Insert a doc row and its postings.
	 *
	 * @param array $doc    Doc row.
	 * @param array $fields field code => text.
	 * @param array $codes  SKU-like codes also indexed with separators removed.
	 * @param array $t      Tables.
	 */
	private static function write_doc( array $doc, array $fields, array $codes, array $t ) {
		global $wpdb;
		$formats = array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%f', '%f', '%f', '%d', '%s', '%s' );
		$row     = array(
			'object_id'     => $doc['object_id'],
			'object_type'   => $doc['object_type'],
			'subtype'       => $doc['subtype'],
			'title'         => $doc['title'],
			'title_norm'    => $doc['title_norm'],
			'excerpt'       => $doc['excerpt'],
			'sku'           => IMSearch_Text::prefix( $doc['sku'], 255 ),
			'url'           => $doc['url'],
			'image'         => $doc['image'],
			'price'         => $doc['price'],
			'price_regular' => $doc['price_regular'],
			'price_sale'    => $doc['price_sale'],
			'price_max'     => $doc['price_max'],
			'menu_order'    => $doc['menu_order'],
			'post_date'     => $doc['post_date'],
			'modified'      => $doc['modified'],
		);
		// $wpdb->insert cannot write NULL with a %f format, so drop null prices (column default is NULL).
		foreach ( array( 'price', 'price_regular', 'price_sale', 'price_max' ) as $i => $col ) {
			if ( null === $row[ $col ] ) {
				unset( $row[ $col ] );
			}
		}
		$fmt = array();
		$all = array( 'object_id', 'object_type', 'subtype', 'title', 'title_norm', 'excerpt', 'sku', 'url', 'image', 'price', 'price_regular', 'price_sale', 'price_max', 'menu_order', 'post_date', 'modified' );
		foreach ( $all as $i => $col ) {
			if ( array_key_exists( $col, $row ) ) {
				$fmt[] = $formats[ $i ];
			}
		}
		if ( ! $wpdb->insert( $t['docs'], $row, $fmt ) ) {
			return;
		}
		$doc_id = (int) $wpdb->insert_id;

		// field_id => word => hits.
		$postings = array();
		foreach ( $fields as $code => $text ) {
			$words = IMSearch_Text::words( $text );
			if ( ! $words ) {
				continue;
			}
			$fid = IMSearch_Settings::field_id( $code );
			foreach ( array_slice( $words, 0, 6000 ) as $w ) {
				$postings[ $fid ][ $w ] = isset( $postings[ $fid ][ $w ] ) ? min( 65000, $postings[ $fid ][ $w ] + 1 ) : 1;
			}
		}
		if ( $codes ) {
			$fid = IMSearch_Settings::field_id( 'sku' );
			foreach ( $codes as $code ) {
				$collapsed = IMSearch_Text::collapse( $code );
				if ( '' !== $collapsed ) {
					$postings[ $fid ][ $collapsed ] = isset( $postings[ $fid ][ $collapsed ] ) ? $postings[ $fid ][ $collapsed ] : 1;
				}
			}
		}
		if ( ! $postings ) {
			return;
		}

		$vocab = array();
		foreach ( $postings as $words ) {
			foreach ( $words as $w => $hits ) {
				$vocab[ (string) $w ] = true;
			}
		}
		$ids = self::word_ids( array_keys( $vocab ), $t );

		$values = array();
		foreach ( $postings as $fid => $words ) {
			foreach ( $words as $w => $hits ) {
				if ( isset( $ids[ (string) $w ] ) ) {
					$values[] = '(' . (int) $ids[ (string) $w ] . ',' . (int) $fid . ',' . $doc_id . ',' . (int) $hits . ')';
				}
			}
		}
		foreach ( array_chunk( $values, 500 ) as $chunk ) {
			$wpdb->query( "INSERT IGNORE INTO {$t['postings']} (word_id, field_id, doc_id, hits) VALUES " . implode( ',', $chunk ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}

	/**
	 * Ids for words, inserting new ones.
	 *
	 * @param string[] $words Words.
	 * @param array    $t     Tables.
	 * @return array<string,int>
	 */
	private static function word_ids( array $words, array $t ) {
		global $wpdb;
		$out = array();
		foreach ( array_chunk( $words, 300 ) as $chunk ) {
			$ph = implode( ',', array_fill( 0, count( $chunk ), '(%s)' ) );
			$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$t['words']} (word) VALUES $ph", $chunk ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$ph   = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, word FROM {$t['words']} WHERE word IN ($ph)", $chunk ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( $rows as $row ) {
				$out[ (string) $row->word ] = (int) $row->id;
			}
		}
		return $out;
	}

	/**
	 * Status summary for the admin page.
	 *
	 * @return array
	 */
	public static function status() {
		$state  = self::state();
		$counts = IMSearch_DB::counts();
		return array(
			'status'      => $state['status'],
			'total'       => (int) $state['total'],
			'done'        => (int) $state['done'],
			'percent'     => $state['total'] ? min( 100, round( 100 * $state['done'] / $state['total'] ) ) : 100,
			'started'     => (int) $state['started'],
			'finished'    => (int) $state['finished'],
			'last_change' => (int) $state['last_change'],
			'error'       => (string) $state['error'],
			'docs'        => $counts['docs'],
			'words'       => $counts['words'],
			'next'        => (int) wp_next_scheduled( self::CRON_SCHEDULED ),
		);
	}
}
