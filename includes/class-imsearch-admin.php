<?php
/**
 * Settings page: top-level "Search" menu item, standard WordPress markup
 * (form-table, notices, buttons) so admin themes style it automatically.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class IMSearch_Admin {

	const SLUG = 'imsearch';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_ajax_imsearch_reindex', array( __CLASS__, 'ajax_reindex' ) );
		add_action( 'wp_ajax_imsearch_step', array( __CLASS__, 'ajax_step' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( IMSEARCH_FILE ), array( __CLASS__, 'action_links' ) );
	}

	public static function menu() {
		add_menu_page(
			__( 'Search', 'imsearch' ),
			__( 'Search', 'imsearch' ),
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'page' ),
			'dashicons-search',
			58
		);
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Settings', 'imsearch' ) . '</a>' );
		return $links;
	}

	public static function register() {
		register_setting(
			'imsearch',
			IMSearch_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'IMSearch_Settings', 'sanitize' ),
				'default'           => IMSearch_Settings::defaults(),
			)
		);
	}

	public static function assets( $hook ) {
		if ( 'toplevel_page_' . self::SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style( 'imsearch-admin', IMSEARCH_URL . 'assets/css/admin.css', array(), IMSEARCH_VERSION );
		wp_enqueue_script( 'imsearch-admin', IMSEARCH_URL . 'assets/js/admin.js', array( 'jquery' ), IMSEARCH_VERSION, true );
		wp_localize_script(
			'imsearch-admin',
			'imsearchAdmin',
			array(
				'ajax'   => admin_url( 'admin-ajax.php' ),
				'nonce'  => wp_create_nonce( 'imsearch_admin' ),
				'status' => IMSearch_Indexer::status(),
				'i18n'   => array(
					'building' => __( 'Indexing…', 'imsearch' ),
					'idle'     => __( 'Ready', 'imsearch' ),
					'error'    => __( 'Indexing failed. Check the error below and try again.', 'imsearch' ),
					'never'    => __( 'Never', 'imsearch' ),
					'off'      => __( 'Not scheduled', 'imsearch' ),
				),
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* AJAX                                                                 */
	/* ------------------------------------------------------------------ */

	private static function guard() {
		check_ajax_referer( 'imsearch_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'imsearch' ) ), 403 );
		}
	}

	public static function ajax_reindex() {
		self::guard();
		IMSearch_Indexer::start_full();
		IMSearch_Indexer::step( 8 );
		wp_send_json_success( self::status_payload() );
	}

	public static function ajax_step() {
		self::guard();
		IMSearch_Indexer::step( 8 );
		wp_send_json_success( self::status_payload() );
	}

	private static function status_payload() {
		$status              = IMSearch_Indexer::status();
		$status['finished_h'] = self::when( $status['finished'] );
		$status['change_h']   = self::when( $status['last_change'] );
		$status['next_h']     = $status['next'] ? self::when( $status['next'] ) : '';
		return $status;
	}

	private static function when( $ts ) {
		if ( ! $ts ) {
			return '';
		}
		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $ts );
	}

	/* ------------------------------------------------------------------ */
	/* Page                                                                 */
	/* ------------------------------------------------------------------ */

	/**
	 * Non-protected custom field keys used by indexed content.
	 *
	 * @return string[]
	 */
	private static function meta_keys() {
		return IMSearch_Settings::discover_meta_keys();
	}

	private static function name( $key ) {
		return IMSearch_Settings::OPTION . '[' . $key . ']';
	}

	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s        = IMSearch_Settings::all();
		$status   = self::status_payload();
		$excluded = array_flip( (array) $s['exclude_terms'] );
		$metas    = self::meta_keys();
		$extra    = array_diff( (array) $s['index_meta_keys'], $metas );
		?>
		<div class="wrap imsearch-admin">
			<h1><?php esc_html_e( 'Search', 'imsearch' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Infinite Monkeys Search: live search powered by a custom index.', 'imsearch' ); ?></p>

			<?php if ( ! get_option( 'imsearch_endpoint_ok' ) && 'idle' === $status['status'] && $status['finished'] ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'The fast search endpoint is not reachable on this server, so live search is using the WordPress REST API instead (slower). Your host may block PHP files inside plugin folders.', 'imsearch' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'imsearch' ); ?>

				<h2 class="title"><?php esc_html_e( 'Configuration', 'imsearch' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Out of stock products', 'imsearch' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( self::name( 'exclude_out_of_stock' ) ); ?>" value="1" <?php checked( $s['exclude_out_of_stock'] ); ?>> <?php esc_html_e( 'Exclude out of stock products', 'imsearch' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Excluded terms', 'imsearch' ); ?></th>
						<td>
							<p class="description"><?php esc_html_e( 'Content in any ticked category, tag or custom taxonomy term (or its child terms) is left out of search results, and the term itself is not shown as a result.', 'imsearch' ); ?></p>
							<div class="imsearch-terms">
								<?php
								foreach ( IMSearch_Settings::indexed_taxonomies() as $tax ) :
									$taxonomy = get_taxonomy( $tax );
									$terms    = get_terms(
										array(
											'taxonomy'   => $tax,
											'hide_empty' => false,
										)
									);
									if ( ! $taxonomy || is_wp_error( $terms ) || ! $terms ) {
										continue;
									}
									$ordered = self::ordered_terms( $terms );
									?>
									<fieldset class="imsearch-terms-box">
										<legend><strong><?php echo esc_html( $taxonomy->labels->name ); ?></strong></legend>
										<div class="imsearch-checklist">
											<?php foreach ( $ordered as $item ) : ?>
												<label style="padding-left:<?php echo (int) $item['depth'] * 16; ?>px"><input type="checkbox" name="<?php echo esc_attr( self::name( 'exclude_terms' ) ); ?>[]" value="<?php echo (int) $item['term']->term_id; ?>" <?php checked( isset( $excluded[ (int) $item['term']->term_id ] ) ); ?>> <?php echo esc_html( $item['term']->name ); ?> <span class="count">(<?php echo (int) $item['term']->count; ?>)</span></label>
											<?php endforeach; ?>
										</div>
									</fieldset>
								<?php endforeach; ?>
							</div>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="imsearch-fuzzy"><?php esc_html_e( 'Fuzzy matching', 'imsearch' ); ?></label></th>
						<td>
							<select id="imsearch-fuzzy" name="<?php echo esc_attr( self::name( 'fuzzy' ) ); ?>">
								<?php
								foreach ( array(
									'off'    => __( 'Off', 'imsearch' ),
									'soft'   => __( 'Soft (1 typo)', 'imsearch' ),
									'normal' => __( 'Normal (up to 2 typos)', 'imsearch' ),
									'hard'   => __( 'Hard (up to 3 typos)', 'imsearch' ),
								) as $value => $label ) :
									?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $s['fuzzy'], $value ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'When a word matches nothing, find words that start with the same two letters and differ by only a few letters (Levenshtein distance), as FiboSearch does. Short words always allow fewer typos.', 'imsearch' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="imsearch-min"><?php esc_html_e( 'Minimum characters', 'imsearch' ); ?></label></th>
						<td><input id="imsearch-min" type="number" class="small-text" min="1" max="5" name="<?php echo esc_attr( self::name( 'min_chars' ) ); ?>" value="<?php echo (int) $s['min_chars']; ?>"> <span class="description"><?php esc_html_e( 'Characters typed before live results appear.', 'imsearch' ); ?></span></td>
					</tr>
					<tr>
						<th scope="row"><label for="imsearch-stopwords"><?php esc_html_e( 'Stopwords', 'imsearch' ); ?></label></th>
						<td>
							<textarea id="imsearch-stopwords" class="large-text" rows="3" name="<?php echo esc_attr( self::name( 'stopwords' ) ); ?>"><?php echo esc_textarea( $s['stopwords'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Common words ignored in searches (comma or space separated). A search made only of stopwords still runs.', 'imsearch' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="imsearch-synonyms"><?php esc_html_e( 'Synonyms', 'imsearch' ); ?></label></th>
						<td>
							<textarea id="imsearch-synonyms" class="large-text code" rows="5" name="<?php echo esc_attr( self::name( 'synonyms' ) ); ?>" placeholder="atomiser, atomizer, perfume bottle&#10;grey, gray"><?php echo esc_textarea( $s['synonyms'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One group per line; words or phrases separated by commas. Searching any of them finds all of them.', 'imsearch' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Custom fields to index', 'imsearch' ); ?></th>
						<td>
							<p class="description"><?php esc_html_e( 'Fields chosen in any Search bar element\'s search scope are ticked here automatically when you save in Bricks. Changing this rebuilds the index.', 'imsearch' ); ?></p>
							<?php if ( $metas ) : ?>
								<div class="imsearch-checklist imsearch-checklist--meta">
									<?php foreach ( $metas as $key ) : ?>
										<label><input type="checkbox" name="<?php echo esc_attr( self::name( 'index_meta_keys' ) ); ?>[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, (array) $s['index_meta_keys'], true ) ); ?>> <code><?php echo esc_html( $key ); ?></code></label>
									<?php endforeach; ?>
								</div>
							<?php endif; ?>
							<p><label for="imsearch-meta-extra"><?php esc_html_e( 'Other keys (comma separated):', 'imsearch' ); ?></label><br>
							<input id="imsearch-meta-extra" type="text" class="regular-text code" name="<?php echo esc_attr( self::name( 'index_meta_keys_extra' ) ); ?>" value="<?php echo esc_attr( implode( ', ', $extra ) ); ?>"></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Search results page', 'imsearch' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( self::name( 'replace_search_page' ) ); ?>" value="1" <?php checked( $s['replace_search_page'] ); ?>> <?php esc_html_e( 'Use this index for the site\'s search results page', 'imsearch' ); ?></label>
							<p class="description"><?php esc_html_e( 'The "more results" link opens the search page, which shows results using your Bricks search results template. With this on, that page lists the same matches (fuzzy, synonyms, SKU) as the live results.', 'imsearch' ); ?></p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Autocomplete', 'imsearch' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="imsearch-max"><?php esc_html_e( 'Maximum suggestions', 'imsearch' ); ?></label></th>
						<td><input id="imsearch-max" type="number" class="small-text" min="1" max="50" name="<?php echo esc_attr( self::name( 'max_suggestions' ) ); ?>" value="<?php echo (int) $s['max_suggestions']; ?>"> <span class="description"><?php esc_html_e( 'Per group (Products, Pages, …).', 'imsearch' ); ?></span></td>
					</tr>
					<tr>
						<th scope="row"><label for="imsearch-noresults"><?php esc_html_e( '"No results" label', 'imsearch' ); ?></label></th>
						<td><input id="imsearch-noresults" type="text" class="regular-text" name="<?php echo esc_attr( self::name( 'no_results_text' ) ); ?>" value="<?php echo esc_attr( $s['no_results_text'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="imsearch-more"><?php esc_html_e( '"More results" link label', 'imsearch' ); ?></label></th>
						<td>
							<input id="imsearch-more" type="text" class="regular-text" name="<?php echo esc_attr( self::name( 'more_results_text' ) ); ?>" value="<?php echo esc_attr( $s['more_results_text'] ); ?>">
							<p class="description"><?php esc_html_e( 'Shown under the products when there are more matches than the maximum. Use {count} to place the total; otherwise it is added in brackets.', 'imsearch' ); ?></p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Indexing', 'imsearch' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="imsearch-schedule"><?php esc_html_e( 'Scheduled full rebuild', 'imsearch' ); ?></label></th>
						<td>
							<select id="imsearch-schedule" name="<?php echo esc_attr( self::name( 'schedule' ) ); ?>">
								<?php
								foreach ( array(
									'off'         => __( 'Off', 'imsearch' ),
									'daily'       => __( 'Daily', 'imsearch' ),
									'weekly'      => __( 'Weekly', 'imsearch' ),
									'semimonthly' => __( 'Twice a month', 'imsearch' ),
									'monthly'     => __( 'Monthly', 'imsearch' ),
								) as $value => $label ) :
									?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $s['schedule'], $value ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'The index also updates in real time whenever content or terms are added, edited or deleted (save_post, deleted_post, created_term, edited_term, delete_term), so a scheduled rebuild is only a safety net. Scheduled rebuilds run at about 3 am.', 'imsearch' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Status', 'imsearch' ); ?></th>
						<td>
							<table class="widefat striped imsearch-status" role="presentation">
								<tbody>
									<tr><td><?php esc_html_e( 'State', 'imsearch' ); ?></td><td data-imsearch="state"></td></tr>
									<tr><td><?php esc_html_e( 'Indexed items', 'imsearch' ); ?></td><td data-imsearch="docs"><?php echo (int) $status['docs']; ?></td></tr>
									<tr><td><?php esc_html_e( 'Unique words', 'imsearch' ); ?></td><td data-imsearch="words"><?php echo (int) $status['words']; ?></td></tr>
									<tr><td><?php esc_html_e( 'Last full rebuild', 'imsearch' ); ?></td><td data-imsearch="finished_h"><?php echo esc_html( $status['finished_h'] ); ?></td></tr>
									<tr><td><?php esc_html_e( 'Last real-time update', 'imsearch' ); ?></td><td data-imsearch="change_h"><?php echo esc_html( $status['change_h'] ); ?></td></tr>
									<tr><td><?php esc_html_e( 'Next scheduled rebuild', 'imsearch' ); ?></td><td data-imsearch="next_h"><?php echo esc_html( $status['next_h'] ); ?></td></tr>
								</tbody>
							</table>
							<div class="imsearch-progress" hidden><progress max="100" value="0"></progress> <span class="imsearch-progress-text"></span></div>
							<p class="imsearch-error notice notice-error inline" hidden></p>
							<p><button type="button" class="button button-secondary" id="imsearch-reindex"><?php esc_html_e( 'Re-index now', 'imsearch' ); ?></button> <span class="description"><?php esc_html_e( 'Rebuilds the whole index. Search keeps working with the current index until the new one is ready.', 'imsearch' ); ?></span></p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<h2 class="title"><?php esc_html_e( 'Shortcodes', 'imsearch' ); ?></h2>
			<p><?php esc_html_e( 'In Bricks, use the "Search bar" and "Search results" elements (Infinite Monkeys Search category). Anywhere else, use these shortcodes:', 'imsearch' ); ?></p>
			<table class="widefat striped imsearch-shortcodes">
				<thead><tr><th><?php esc_html_e( 'Shortcode', 'imsearch' ); ?></th><th><?php esc_html_e( 'What it does', 'imsearch' ); ?></th></tr></thead>
				<tbody>
					<tr><td><code>[imsearch]</code></td><td><?php esc_html_e( 'A search bar with live results in a dropdown below it.', 'imsearch' ); ?></td></tr>
					<tr><td><code>[imsearch_bar id="main-search"]</code></td><td><?php esc_html_e( 'Just the search bar. Give it an id to connect a separate results area.', 'imsearch' ); ?></td></tr>
					<tr><td><code>[imsearch_results for="main-search"]</code></td><td><?php esc_html_e( 'Just the results, shown wherever you place it, for the bar with that id.', 'imsearch' ); ?></td></tr>
				</tbody>
			</table>
			<p><strong><?php esc_html_e( 'Search bar attributes', 'imsearch' ); ?></strong></p>
			<ul class="ul-disc">
				<li><code>placeholder="Search…"</code></li>
				<li><code>scope="description,sku,categories,tags,brands"</code> — <?php esc_html_e( 'what is searched besides the title', 'imsearch' ); ?></li>
				<li><code>custom_fields="key1,key2"</code>, <code>taxonomies="material-stone,collection"</code> — <?php esc_html_e( 'extra fields to search (custom fields must be indexed above)', 'imsearch' ); ?></li>
				<li><code>submit="yes"</code> <code>button="text|icon"</code> <code>button_text="SEARCH"</code> — <?php esc_html_e( 'search only when the button is pressed', 'imsearch' ); ?></li>
				<li><code>spinner="1"</code> <?php esc_html_e( '(1–6)', 'imsearch' ); ?>, <code>overlay="yes"</code></li>
			</ul>
			<p><strong><?php esc_html_e( 'Results attributes', 'imsearch' ); ?></strong></p>
			<ul class="ul-disc">
				<li><code>types="product,page,post"</code> — <?php esc_html_e( 'post types to show, in this order', 'imsearch' ); ?></li>
				<li><code>show_taxonomies="product_cat,product_brand"</code> — <?php esc_html_e( 'show matching terms as results', 'imsearch' ); ?></li>
				<li><code>image="yes"</code> <code>price="yes"</code> <code>excerpt="no"</code> <code>sku="no"</code> <code>headings="yes"</code> — <?php esc_html_e( 'headings="no" hides the group headings', 'imsearch' ); ?></li>
				<li><code>view_button="yes"</code> <code>view_text="View"</code> — <?php esc_html_e( 'a "View" button at the right of each result (the whole result is the link)', 'imsearch' ); ?></li>
				<li><code>orderby="relevance|title|date|modified|price|menu_order"</code> <code>order="desc|asc"</code></li>
			</ul>
			<p><?php esc_html_e( 'Example:', 'imsearch' ); ?> <code>[imsearch placeholder="Search the collection" types="product,page" excerpt="yes" sku="yes"]</code></p>
		</div>
		<?php
	}

	/**
	 * Flatten terms into hierarchical order with depth.
	 *
	 * @param WP_Term[] $terms Terms.
	 * @return array
	 */
	private static function ordered_terms( array $terms ) {
		$children = array();
		foreach ( $terms as $term ) {
			$children[ (int) $term->parent ][] = $term;
		}
		$out  = array();
		$walk = static function ( $parent, $depth ) use ( &$walk, &$out, $children ) {
			if ( empty( $children[ $parent ] ) || $depth > 10 ) {
				return;
			}
			foreach ( $children[ $parent ] as $term ) {
				$out[] = array(
					'term'  => $term,
					'depth' => $depth,
				);
				$walk( (int) $term->term_id, $depth + 1 );
			}
		};
		$walk( 0, 0 );
		// Orphans whose parent is missing.
		if ( count( $out ) < count( $terms ) ) {
			$seen = array();
			foreach ( $out as $item ) {
				$seen[ (int) $item['term']->term_id ] = true;
			}
			foreach ( $terms as $term ) {
				if ( ! isset( $seen[ (int) $term->term_id ] ) ) {
					$out[] = array(
						'term'  => $term,
						'depth' => 0,
					);
				}
			}
		}
		return $out;
	}
}
