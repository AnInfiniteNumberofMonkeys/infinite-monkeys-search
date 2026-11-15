<?php
/**
 * Search engine.
 *
 * Reads only the custom index tables through $wpdb and a runtime config
 * array, so it runs both in full WordPress and in the SHORTINIT endpoint.
 *
 * Matching: every query word (or synonym group) must match the document
 * (AND). A word matches indexed words that start with it (autocomplete
 * prefix match). If nothing starts with it, fuzzy matching kicks in, the
 * same way FiboSearch does it: candidates are indexed words sharing the
 * first N characters, kept when their Levenshtein distance is within the
 * level's threshold.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class IMSearch_Engine {

	const MAX_QUERY_LENGTH = 100;
	const MAX_PREFIX_WORDS = 300;
	const ORDERBY          = array( 'relevance', 'title', 'date', 'modified', 'price', 'menu_order' );

	/** @var wpdb */
	private $db;

	/** @var array */
	private $cfg;

	/** @var array */
	private $t;

	/** @var array<string,array<int,float>> */
	private $word_cache = array();

	/**
	 * @param wpdb  $db  Database.
	 * @param array $cfg Runtime config (IMSearch_Settings::runtime()).
	 */
	public function __construct( $db, array $cfg ) {
		$this->db  = $db;
		$this->cfg = $cfg;
		$this->t   = array(
			'docs'     => $db->prefix . 'imsearch_docs',
			'words'    => $db->prefix . 'imsearch_words',
			'postings' => $db->prefix . 'imsearch_postings',
		);
	}

	/* ------------------------------------------------------------------ */
	/* Input validation                                                     */
	/* ------------------------------------------------------------------ */

	/**
	 * Keep only known result types (post types or "tax:<taxonomy>").
	 *
	 * @param mixed $types Types.
	 * @return string[]
	 */
	public function clean_types( $types ) {
		$known = isset( $this->cfg['groups'] ) ? array_keys( $this->cfg['groups'] ) : array();
		$types = is_array( $types ) ? $types : explode( ',', (string) $types );
		$out   = array();
		foreach ( $types as $type ) {
			$type = trim( (string) $type );
			if ( in_array( $type, $known, true ) ) {
				$out[] = $type;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Field codes to numeric ids. Title is always searched.
	 *
	 * @param mixed $codes Field codes.
	 * @return int[]
	 */
	public function clean_fields( $codes ) {
		$map   = isset( $this->cfg['fields'] ) ? $this->cfg['fields'] : array();
		$codes = is_array( $codes ) ? $codes : explode( ',', (string) $codes );
		$codes[] = 'title';
		$out     = array();
		foreach ( $codes as $code ) {
			$code = trim( (string) $code );
			if ( isset( $map[ $code ] ) ) {
				$out[] = (int) $map[ $code ];
			}
		}
		return array_values( array_unique( $out ) );
	}

	/* ------------------------------------------------------------------ */
	/* Query parsing                                                        */
	/* ------------------------------------------------------------------ */

	/**
	 * Split a query into slots. Each slot is a list of alternatives; each
	 * alternative is a list of words that must all match.
	 *
	 * @param string $query Raw query.
	 * @return array|null
	 */
	public function parse( $query ) {
		$query = IMSearch_Text::prefix( trim( (string) $query ), self::MAX_QUERY_LENGTH );
		$norm  = IMSearch_Text::normalize( $query );
		$words = array_slice( IMSearch_Text::tokenize( $norm ), 0, 10 ); // Bound the work per request.
		$min   = isset( $this->cfg['min_chars'] ) ? (int) $this->cfg['min_chars'] : 2;
		if ( ! $words || IMSearch_Text::length( implode( '', $words ) ) < $min ) {
			return null;
		}

		$synonyms = isset( $this->cfg['synonyms'] ) ? (array) $this->cfg['synonyms'] : array();
		$stop     = isset( $this->cfg['stopwords'] ) ? array_flip( (array) $this->cfg['stopwords'] ) : array();
		$slots    = array();
		$n        = count( $words );
		$i        = 0;

		while ( $i < $n ) {
			$best_len   = 0;
			$best_group = null;
			foreach ( $synonyms as $group ) {
				foreach ( $group as $phrase ) {
					$len = count( $phrase );
					if ( $len > $best_len && $len <= $n - $i && array_slice( $words, $i, $len ) === $phrase ) {
						$best_len   = $len;
						$best_group = $group;
					}
				}
			}
			if ( $best_group ) {
				$slots[] = array(
					'alts' => $best_group,
					'stop' => false,
				);
				$i      += $best_len;
				continue;
			}
			$slots[] = array(
				'alts' => array( array( $words[ $i ] ) ),
				'stop' => isset( $stop[ $words[ $i ] ] ),
			);
			++$i;
		}

		$content = array_values(
			array_filter(
				$slots,
				static function ( $slot ) {
					return ! $slot['stop'];
				}
			)
		);
		if ( $content ) {
			$slots = $content;
		}

		return array(
			'raw'   => $query,
			'norm'  => $norm,
			'words' => $words,
			'slots' => $slots,
		);
	}

	/* ------------------------------------------------------------------ */
	/* Word matching                                                        */
	/* ------------------------------------------------------------------ */

	/**
	 * Indexed words matching one query word, with a match quality (0..1).
	 *
	 * @param string $word Normalised query word.
	 * @return array<int,float> word_id => quality
	 */
	public function match_word( $word ) {
		if ( isset( $this->word_cache[ $word ] ) ) {
			return $this->word_cache[ $word ];
		}
		$out  = array();
		$len  = IMSearch_Text::length( $word );
		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT id, word FROM {$this->t['words']} WHERE word LIKE %s ORDER BY CHAR_LENGTH(word) ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$this->db->esc_like( $word ) . '%',
				self::MAX_PREFIX_WORDS
			)
		);
		foreach ( (array) $rows as $row ) {
			if ( $row->word === $word ) {
				$out[ (int) $row->id ] = 1.0;
			} else {
				$extra                 = IMSearch_Text::length( $row->word ) - $len;
				$out[ (int) $row->id ] = max( 0.55, 0.9 - 0.02 * $extra );
			}
		}

		$fuzzy = isset( $this->cfg['fuzzy'] ) ? $this->cfg['fuzzy'] : null;
		if ( ! $out && $fuzzy && $len >= 3 && ! ctype_digit( $word ) ) {
			$prefix = IMSearch_Text::prefix( $word, (int) $fuzzy['prefix'] );
			// Short words tolerate fewer typos, whatever the level.
			$max_d = min( (int) $fuzzy['dist'], $len <= 4 ? 1 : ( $len <= 7 ? 2 : 3 ) );
			$rows  = $this->db->get_results(
				$this->db->prepare(
					"SELECT id, word FROM {$this->t['words']} WHERE word LIKE %s LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$this->db->esc_like( $prefix ) . '%',
					(int) $fuzzy['max']
				)
			);
			foreach ( (array) $rows as $row ) {
				$whole = IMSearch_Text::distance( $word, $row->word );
				if ( $whole <= $max_d ) {
					$out[ (int) $row->id ] = 0.7 - 0.1 * $whole;
					continue;
				}
				// Typo inside a word still being typed: compare with the same-length start.
				if ( IMSearch_Text::length( $row->word ) > $len ) {
					$part = IMSearch_Text::distance( $word, IMSearch_Text::prefix( $row->word, $len ) );
					if ( $part <= $max_d ) {
						$out[ (int) $row->id ] = 0.6 - 0.1 * $part;
					}
				}
			}
		}

		$this->word_cache[ $word ] = $out;
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Scoring                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Score matching docs.
	 *
	 * @param array $parsed    Output of parse().
	 * @param array $types     Clean types.
	 * @param array $field_ids Clean field ids.
	 * @return array<int,array{score:float,subtype:string}> keyed by doc id
	 */
	private function score( array $parsed, array $types, array $field_ids ) {
		if ( ! $types || ! $field_ids ) {
			return array();
		}

		// Which query words each indexed word satisfies.
		$by_word_id = array();
		foreach ( $parsed['slots'] as $slot ) {
			foreach ( $slot['alts'] as $alt ) {
				foreach ( $alt as $qw ) {
					foreach ( $this->match_word( $qw ) as $wid => $quality ) {
						if ( ! isset( $by_word_id[ $wid ][ $qw ] ) || $by_word_id[ $wid ][ $qw ] < $quality ) {
							$by_word_id[ $wid ][ $qw ] = $quality;
						}
					}
				}
			}
		}
		if ( ! $by_word_id ) {
			return array();
		}

		$wids   = implode( ',', array_map( 'intval', array_keys( $by_word_id ) ) );
		$fids   = implode( ',', array_map( 'intval', $field_ids ) );
		$tph    = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$sql    = "SELECT p.doc_id, p.word_id, p.field_id, p.hits, d.subtype FROM {$this->t['postings']} p INNER JOIN {$this->t['docs']} d ON d.id = p.doc_id WHERE p.word_id IN ($wids) AND p.field_id IN ($fids) AND d.subtype IN ($tph)";
		$rows   = $this->db->get_results( $this->db->prepare( $sql, $types ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$weight = isset( $this->cfg['weights'] ) ? $this->cfg['weights'] : array();

		$best    = array(); // doc => qw => score.
		$subtype = array();
		foreach ( (array) $rows as $row ) {
			$doc             = (int) $row->doc_id;
			$subtype[ $doc ] = $row->subtype;
			$w               = isset( $weight[ (string) $row->field_id ] ) ? (float) $weight[ (string) $row->field_id ] : 2.0;
			$hits_bonus      = 1 + min( 4, (int) $row->hits - 1 ) * 0.03;
			foreach ( $by_word_id[ (int) $row->word_id ] as $qw => $quality ) {
				$s = $w * $quality * $hits_bonus;
				if ( ! isset( $best[ $doc ][ $qw ] ) || $best[ $doc ][ $qw ] < $s ) {
					$best[ $doc ][ $qw ] = $s;
				}
			}
		}

		$out = array();
		foreach ( $best as $doc => $words ) {
			$total = 0.0;
			foreach ( $parsed['slots'] as $slot ) {
				$slot_score = 0.0;
				foreach ( $slot['alts'] as $alt ) {
					$sum = 0.0;
					foreach ( $alt as $qw ) {
						if ( ! isset( $words[ $qw ] ) ) {
							$sum = 0.0;
							break;
						}
						$sum += $words[ $qw ];
					}
					$slot_score = max( $slot_score, $sum / count( $alt ) );
				}
				if ( $slot_score <= 0 ) {
					continue 2; // Every slot must match.
				}
				$total += $slot_score;
			}
			$out[ $doc ] = array(
				'score'   => $total,
				'subtype' => $subtype[ $doc ],
			);
		}
		return $out;
	}

	/**
	 * Light rows (for sorting) for a set of docs.
	 *
	 * @param int[] $ids Doc ids.
	 * @return array<int,object>
	 */
	private function light_rows( array $ids ) {
		if ( ! $ids ) {
			return array();
		}
		$in   = implode( ',', array_map( 'intval', $ids ) );
		$rows = $this->db->get_results( "SELECT id, object_id, object_type, subtype, title_norm, price, menu_order, post_date, modified FROM {$this->t['docs']} WHERE id IN ($in)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row->id ] = $row;
		}
		return $out;
	}

	/**
	 * Score, add title bonuses and sort.
	 *
	 * @param array  $parsed    Parsed query.
	 * @param array  $types     Types.
	 * @param array  $field_ids Field ids.
	 * @param string $orderby   Orderby.
	 * @param string $order     asc|desc.
	 * @return array{0:array<int,array>,1:array<int,object>} sorted [doc => data], light rows
	 */
	private function ranked( array $parsed, array $types, array $field_ids, $orderby, $order ) {
		$scored = $this->score( $parsed, $types, $field_ids );
		if ( ! $scored ) {
			return array( array(), array() );
		}
		$light = $this->light_rows( array_keys( $scored ) );
		$q     = implode( ' ', $parsed['words'] );
		foreach ( $scored as $doc => &$data ) {
			if ( ! isset( $light[ $doc ] ) ) {
				unset( $scored[ $doc ] );
				continue;
			}
			$title = $light[ $doc ]->title_norm;
			if ( $title === $q ) {
				$data['score'] += 15;
			} elseif ( 0 === strpos( $title, $q ) ) {
				$data['score'] += 8;
			} elseif ( false !== strpos( $title, $q ) ) {
				$data['score'] += 4;
			}
		}
		unset( $data );

		$orderby = in_array( $orderby, self::ORDERBY, true ) ? $orderby : 'relevance';
		$dir     = ( 'asc' === strtolower( (string) $order ) ) ? 1 : -1;
		if ( 'relevance' === $orderby && 'asc' !== strtolower( (string) $order ) ) {
			$dir = -1;
		}

		uksort(
			$scored,
			static function ( $a, $b ) use ( $scored, $light, $orderby, $dir ) {
				$la = $light[ $a ];
				$lb = $light[ $b ];
				switch ( $orderby ) {
					case 'title':
						$c = strcmp( $la->title_norm, $lb->title_norm );
						break;
					case 'date':
						$c = strcmp( $la->post_date, $lb->post_date );
						break;
					case 'modified':
						$c = strcmp( $la->modified, $lb->modified );
						break;
					case 'menu_order':
						$c = (int) $la->menu_order <=> (int) $lb->menu_order;
						break;
					case 'price':
						if ( null === $la->price || null === $lb->price ) {
							return ( null === $la->price ) <=> ( null === $lb->price ); // Unpriced last.
						}
						$c = (float) $la->price <=> (float) $lb->price;
						break;
					default:
						$c = $scored[ $a ]['score'] <=> $scored[ $b ]['score'];
				}
				if ( 0 === $c ) {
					return strcmp( $la->title_norm, $lb->title_norm );
				}
				return $c * $dir;
			}
		);

		return array( $scored, $light );
	}

	/* ------------------------------------------------------------------ */
	/* Public API                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * Grouped results for the live dropdown.
	 *
	 * @param string $query Query.
	 * @param array  $args  types, fields, limit, orderby, order, with (image, price, excerpt, sku).
	 * @return array
	 */
	public function search( $query, array $args ) {
		$start  = microtime( true );
		$types  = $this->clean_types( isset( $args['types'] ) ? $args['types'] : array() );
		$fields = $this->clean_fields( isset( $args['fields'] ) ? $args['fields'] : array() );
		$limit  = isset( $args['limit'] ) ? max( 1, min( 50, (int) $args['limit'] ) ) : 10;
		$with   = isset( $args['with'] ) ? (array) $args['with'] : array();
		$parsed = $this->parse( $query );

		$result = array(
			'query'  => $parsed ? $parsed['raw'] : '',
			'words'  => $parsed ? $parsed['words'] : array(),
			'total'  => 0,
			'groups' => array(),
		);
		if ( ! $parsed ) {
			return $result;
		}

		list( $ranked, $light ) = $this->ranked(
			$parsed,
			$types,
			$fields,
			isset( $args['orderby'] ) ? $args['orderby'] : 'relevance',
			isset( $args['order'] ) ? $args['order'] : 'desc'
		);

		$per_group = array();
		$totals    = array();
		foreach ( $ranked as $doc => $data ) {
			$st = $data['subtype'];
			$totals[ $st ] = isset( $totals[ $st ] ) ? $totals[ $st ] + 1 : 1;
			if ( $totals[ $st ] <= $limit ) {
				$per_group[ $st ][] = $doc;
			}
		}

		$details = $this->details( array_merge( array(), ...array_values( $per_group ?: array( array() ) ) ) );

		foreach ( $types as $type ) {
			if ( empty( $per_group[ $type ] ) ) {
				continue;
			}
			$items = array();
			foreach ( $per_group[ $type ] as $doc ) {
				if ( ! isset( $details[ $doc ] ) ) {
					continue;
				}
				$d    = $details[ $doc ];
				$item = array(
					'id'    => (int) $d->object_id,
					'title' => $d->title,
					'url'   => $d->url,
				);
				if ( in_array( 'image', $with, true ) ) {
					$item['image'] = $d->image;
				}
				if ( in_array( 'excerpt', $with, true ) ) {
					$item['excerpt'] = $d->excerpt;
				}
				if ( in_array( 'sku', $with, true ) && '' !== $d->sku ) {
					$item['sku'] = $d->sku;
				}
				if ( in_array( 'price', $with, true ) && null !== $d->price ) {
					$item['price'] = array(
						'p' => (float) $d->price,
						'r' => null === $d->price_regular ? null : (float) $d->price_regular,
						's' => null === $d->price_sale ? null : (float) $d->price_sale,
						'm' => null === $d->price_max ? null : (float) $d->price_max,
					);
				}
				$items[] = $item;
			}
			$result['groups'][] = array(
				'type'  => $type,
				'label' => isset( $this->cfg['groups'][ $type ] ) ? $this->cfg['groups'][ $type ] : $type,
				'total' => $totals[ $type ],
				'items' => $items,
			);
			$result['total'] += $totals[ $type ];
		}

		$result['ms'] = round( ( microtime( true ) - $start ) * 1000, 1 );
		return $result;
	}

	/**
	 * Ordered post IDs for the full search results page.
	 *
	 * @param string $query  Query.
	 * @param array  $types  Post types.
	 * @param array  $fields Field codes.
	 * @param int    $max    Max ids.
	 * @return int[]
	 */
	public function search_ids( $query, array $types, array $fields, $max = 1000 ) {
		$parsed = $this->parse( $query );
		if ( ! $parsed ) {
			return array();
		}
		$types = array_values(
			array_filter(
				$this->clean_types( $types ),
				static function ( $t ) {
					return 0 !== strpos( $t, 'tax:' );
				}
			)
		);
		list( $ranked, $light ) = $this->ranked( $parsed, $types, $this->clean_fields( $fields ), 'relevance', 'desc' );
		$ids = array();
		foreach ( array_keys( $ranked ) as $doc ) {
			if ( 'post' === $light[ $doc ]->object_type ) {
				$ids[] = (int) $light[ $doc ]->object_id;
			}
			if ( count( $ids ) >= $max ) {
				break;
			}
		}
		return $ids;
	}

	/**
	 * Display rows for docs.
	 *
	 * @param int[] $ids Doc ids.
	 * @return array<int,object>
	 */
	private function details( array $ids ) {
		if ( ! $ids ) {
			return array();
		}
		$in   = implode( ',', array_map( 'intval', $ids ) );
		$rows = $this->db->get_results( "SELECT id, object_id, title, excerpt, sku, url, image, price, price_regular, price_sale, price_max FROM {$this->t['docs']} WHERE id IN ($in)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row->id ] = $row;
		}
		return $out;
	}
}
