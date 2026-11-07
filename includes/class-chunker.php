<?php
/**
 * Splits a normalized document into chunks along its structure.
 *
 * @package KritiAIChatbot
 */

namespace KritiAIChatbot;

defined( 'ABSPATH' ) || exit;

/**
 * Heading, then paragraphs, then lists: split on those before splitting on length.
 */
final class Chunker {

	/**
	 * Chunk a document.
	 *
	 * Size is counted in words (about 1.3 tokens each). Chunks never cross a heading, and a chunk
	 * starts with the tail of the previous one in the same section so a sentence is not cut off from its context.
	 *
	 * @param string $text      Normalized text with "## Heading" lines.
	 * @param string $title     Document title, put in front of every heading.
	 * @param int    $max_words Words per chunk.
	 * @param int    $overlap   Words repeated from the previous chunk.
	 * @return array<array{heading: string, content: string, words: int}>
	 */
	public static function chunk( $text, $title, $max_words = 400, $overlap = 50 ) {
		$max_words = max( 20, (int) $max_words );
		$overlap   = max( 0, min( (int) $overlap, (int) floor( $max_words / 2 ) ) );
		$chunks    = array();

		foreach ( self::sections( $text ) as $section ) {
			$heading = '' === $section['heading'] ? $title : $title . ' > ' . $section['heading'];
			$units   = array();

			foreach ( $section['blocks'] as $block ) {
				foreach ( self::split_block( $block, $max_words ) as $unit ) {
					$units[] = $unit;
				}
			}

			$current = array();
			$count   = 0;

			foreach ( $units as $unit ) {
				$words = self::word_count( $unit );

				if ( $count > 0 && $count + $words > $max_words ) {
					$chunks[] = self::make( $heading, $current );
					$current  = self::tail( $current, $overlap );
					$count    = self::word_count( implode( "\n\n", $current ) );
				}

				$current[] = $unit;
				$count    += $words;
			}

			if ( $current && self::has_new_text( $current, $chunks, $heading ) ) {
				$chunks[] = self::make( $heading, $current );
			}
		}

		return $chunks;
	}

	/**
	 * Split text into sections at heading lines.
	 *
	 * @param string $text Text.
	 * @return array<array{heading: string, blocks: string[]}>
	 */
	private static function sections( $text ) {
		$sections = array();
		$heading  = '';
		$blocks   = array();

		foreach ( preg_split( "/\n{2,}/", trim( (string) $text ) ) as $block ) {
			$block = trim( $block );

			if ( '' === $block ) {
				continue;
			}

			if ( preg_match( '/^#{2,6}\s+(.+)$/', $block, $m ) && false === strpos( $block, "\n" ) ) {
				if ( $blocks ) {
					$sections[] = array(
						'heading' => $heading,
						'blocks'  => $blocks,
					);
				}

				$heading = trim( $m[1] );
				$blocks  = array();
				continue;
			}

			$blocks[] = $block;
		}

		if ( $blocks ) {
			$sections[] = array(
				'heading' => $heading,
				'blocks'  => $blocks,
			);
		}

		return $sections;
	}

	/**
	 * A block that is longer than a chunk is split at sentences, and at words as a last resort.
	 *
	 * @param string $block     Block.
	 * @param int    $max_words Maximum words.
	 * @return string[]
	 */
	private static function split_block( $block, $max_words ) {
		if ( self::word_count( $block ) <= $max_words ) {
			return array( $block );
		}

		$parts   = array();
		$current = '';

		// Lists split at items, prose at sentences.
		$pieces = false !== strpos( $block, "\n- " ) || 0 === strpos( $block, '- ' )
			? preg_split( '/\n(?=- )/', $block )
			: ( preg_split( '/(?<=[.!?])\s+/u', $block ) ?: preg_split( '/(?<=[.!?])\s+/', $block ) );

		foreach ( $pieces as $piece ) {
			if ( self::word_count( $piece ) > $max_words ) {
				if ( '' !== $current ) {
					$parts[] = $current;
					$current = '';
				}

				foreach ( array_chunk( self::words( $piece ), $max_words ) as $words ) {
					$parts[] = implode( ' ', $words );
				}

				continue;
			}

			if ( '' !== $current && self::word_count( $current . ' ' . $piece ) > $max_words ) {
				$parts[] = $current;
				$current = '';
			}

			$current = '' === $current ? $piece : $current . ( false !== strpos( $block, "\n- " ) ? "\n" : ' ' ) . $piece;
		}

		if ( '' !== $current ) {
			$parts[] = $current;
		}

		return $parts;
	}

	/**
	 * Last words of the previous chunk, as units for the next one.
	 *
	 * @param string[] $units   Units of the chunk just closed.
	 * @param int      $overlap Words wanted.
	 * @return string[]
	 */
	private static function tail( array $units, $overlap ) {
		if ( $overlap < 1 ) {
			return array();
		}

		$words = self::words( implode( ' ', $units ) );

		if ( count( $words ) <= $overlap ) {
			return array();
		}

		return array( implode( ' ', array_slice( $words, -$overlap ) ) );
	}

	/**
	 * Whether the pending units hold text that no earlier chunk of this heading already holds.
	 *
	 * The tail of a chunk is carried into the next one; if nothing else follows, it is not worth a chunk of its own.
	 *
	 * @param string[] $units   Pending units.
	 * @param array    $chunks  Chunks so far.
	 * @param string   $heading Heading.
	 * @return bool
	 */
	private static function has_new_text( array $units, array $chunks, $heading ) {
		$text = implode( "\n\n", $units );

		foreach ( array_reverse( $chunks ) as $chunk ) {
			if ( $chunk['heading'] !== $heading ) {
				break;
			}

			if ( false !== strpos( $chunk['content'], $text ) ) {
				return false;
			}
		}

		return '' !== trim( $text );
	}

	/**
	 * Build a chunk record.
	 *
	 * @param string   $heading Heading.
	 * @param string[] $units   Units.
	 * @return array
	 */
	private static function make( $heading, array $units ) {
		$content = trim( implode( "\n\n", $units ) );

		return array(
			'heading' => $heading,
			'content' => $content,
			'words'   => self::word_count( $content ),
		);
	}

	/**
	 * Count words in any script.
	 *
	 * @param string $text Text.
	 * @return int
	 */
	private static function word_count( $text ) {
		return count( self::words( $text ) );
	}

	/**
	 * Split on whitespace. Text with broken UTF-8 (a cut-off emoji, for example) makes the unicode
	 * pattern fail, so the plain pattern is used for it instead of crashing the sync.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	private static function words( $text ) {
		$text = trim( (string) $text );

		if ( '' === $text ) {
			return array();
		}

		$words = preg_split( '/\s+/u', $text );

		if ( false === $words ) {
			$words = preg_split( '/\s+/', $text );
		}

		return is_array( $words ) ? $words : array();
	}
}
