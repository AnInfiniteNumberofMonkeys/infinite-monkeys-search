<?php
/**
 * Text normalisation and tokenising.
 *
 * Deliberately has no WordPress dependencies: it is shared by the indexer
 * (full WordPress) and the fast SHORTINIT search endpoint.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class IMSearch_Text {

	const MAX_WORD_LENGTH = 64;

	/**
	 * Lowercase, strip accents and collapse whitespace.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	public static function normalize( $text ) {
		$text = (string) $text;
		if ( '' === $text ) {
			return '';
		}
		if ( function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $text, 'UTF-8' ) ) {
			$text = mb_convert_encoding( $text, 'UTF-8', 'ISO-8859-1' );
		}
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = self::remove_accents( $text );
		$text = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
		// Apostrophes join words ("women's" -> "womens").
		$text = str_replace( array( "'", "\u{2019}", "\u{2018}", '`' ), '', $text );
		return trim( preg_replace( '/\s+/u', ' ', $text ) );
	}

	/**
	 * Remove diacritics. Uses intl when available, otherwise a character map.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function remove_accents( $text ) {
		if ( ! preg_match( '/[\x80-\xff]/', $text ) ) {
			return $text;
		}
		$map = array(
			'ß' => 'ss',
			'æ' => 'ae',
			'Æ' => 'AE',
			'œ' => 'oe',
			'Œ' => 'OE',
			'ø' => 'o',
			'Ø' => 'O',
			'đ' => 'd',
			'Đ' => 'D',
			'ł' => 'l',
			'Ł' => 'L',
			'þ' => 'th',
			'Þ' => 'TH',
			'ð' => 'd',
			'Ð' => 'D',
		);
		$text = strtr( $text, $map );
		if ( class_exists( 'Normalizer' ) ) {
			$decomposed = Normalizer::normalize( $text, Normalizer::FORM_D );
			if ( false !== $decomposed && null !== $decomposed ) {
				return preg_replace( '/\p{Mn}+/u', '', $decomposed );
			}
		}
		$from = 'ÀÁÂÃÄÅàáâãäåÇçÈÉÊËèéêëÌÍÎÏìíîïÑñÒÓÔÕÖòóôõöÙÚÛÜùúûüÝýÿŠšŽžČčĆćŘřŤťĎďĚěŇňŮů';
		$to   = 'AAAAAAaaaaaaCcEEEEeeeeIIIIiiiiNnOOOOOoooooUUUUuuuuYyySsZzCcCcRrTtDdEeNnUu';
		if ( function_exists( 'mb_str_split' ) ) {
			return strtr( $text, array_combine( mb_str_split( $from ), str_split( $to ) ) );
		}
		return $text;
	}

	/**
	 * Split normalised text into words.
	 *
	 * @param string $normalized Text already passed through normalize().
	 * @return string[]
	 */
	public static function tokenize( $normalized ) {
		$parts = preg_split( '/[^\p{L}\p{N}]+/u', (string) $normalized, -1, PREG_SPLIT_NO_EMPTY );
		$out   = array();
		foreach ( (array) $parts as $part ) {
			if ( function_exists( 'mb_substr' ) ) {
				$part = mb_substr( $part, 0, self::MAX_WORD_LENGTH, 'UTF-8' );
			} else {
				$part = substr( $part, 0, self::MAX_WORD_LENGTH );
			}
			$out[] = $part;
		}
		return $out;
	}

	/**
	 * Normalise + tokenise in one step.
	 *
	 * @param string $text Raw text.
	 * @return string[]
	 */
	public static function words( $text ) {
		return self::tokenize( self::normalize( $text ) );
	}

	/**
	 * A code (SKU, ISBN) with separators removed, e.g. "OPF-5812-CH00" -> "opf5812ch00".
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	public static function collapse( $text ) {
		$text = preg_replace( '/[^\p{L}\p{N}]+/u', '', self::normalize( $text ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, self::MAX_WORD_LENGTH, 'UTF-8' ) : substr( $text, 0, self::MAX_WORD_LENGTH );
	}

	/**
	 * Character length (multibyte aware).
	 *
	 * @param string $text Text.
	 * @return int
	 */
	public static function length( $text ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $text, 'UTF-8' ) : strlen( $text );
	}

	/**
	 * Multibyte-safe prefix.
	 *
	 * @param string $text   Text.
	 * @param int    $length Characters.
	 * @return string
	 */
	public static function prefix( $text, $length ) {
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $length, 'UTF-8' ) : substr( $text, 0, $length );
	}

	/**
	 * Levenshtein distance on characters (not bytes) for short words.
	 *
	 * @param string $a Word.
	 * @param string $b Word.
	 * @return int
	 */
	public static function distance( $a, $b ) {
		if ( ! preg_match( '/[\x80-\xff]/', $a . $b ) ) {
			return levenshtein( $a, $b );
		}
		$a = preg_split( '//u', $a, -1, PREG_SPLIT_NO_EMPTY );
		$b = preg_split( '//u', $b, -1, PREG_SPLIT_NO_EMPTY );
		$n = count( $a );
		$m = count( $b );
		if ( 0 === $n ) {
			return $m;
		}
		if ( 0 === $m ) {
			return $n;
		}
		$prev = range( 0, $m );
		for ( $i = 1; $i <= $n; $i++ ) {
			$cur = array( $i );
			for ( $j = 1; $j <= $m; $j++ ) {
				$cost    = ( $a[ $i - 1 ] === $b[ $j - 1 ] ) ? 0 : 1;
				$cur[ $j ] = min( $prev[ $j ] + 1, $cur[ $j - 1 ] + 1, $prev[ $j - 1 ] + $cost );
			}
			$prev = $cur;
		}
		return $prev[ $m ];
	}

	/**
	 * Default English stopwords.
	 *
	 * @return string[]
	 */
	public static function default_stopwords() {
		return array( 'a', 'an', 'and', 'are', 'as', 'at', 'be', 'but', 'by', 'for', 'from', 'has', 'have', 'i', 'if', 'in', 'into', 'is', 'it', 'its', 'of', 'on', 'or', 'our', 'so', 'than', 'that', 'the', 'their', 'then', 'there', 'these', 'they', 'this', 'to', 'was', 'were', 'what', 'when', 'where', 'which', 'who', 'will', 'with', 'you', 'your' );
	}
}
