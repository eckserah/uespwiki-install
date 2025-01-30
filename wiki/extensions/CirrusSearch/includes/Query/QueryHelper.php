<?php

namespace CirrusSearch\Query;

use CirrusSearch\Search\SearchContext;

/**
 * helpers for building queries
 */
class QueryHelper {
	/**
	 * Builds a match query against $field for $title. $title is munged to make
	 * title matching better more intuitive for users.
	 *
	 * @param string $field field containing the title
	 * @param string $title title query text to match against
	 * @param bool $underscores If the field contains underscores instead of
	 *  spaces. Defaults to false.
	 * @return \Elastica\Query\Match For matching $title to $field
	 */
	public static function matchPage( $field, $title, $underscores = false ) {
		if ( $underscores ) {
			$title = str_replace( ' ', '_', $title );
		} else {
			$title = str_replace( '_', ' ', $title );
		}
		$match = new \Elastica\Query\Match();
		$match->setFieldQuery( $field, $title );

		return $match;
	}

	/**
	 * Extracts syntax matching $regex from $term and applies $callback to
	 * the matching pieces. The callback must return a string indicating what,
	 * if anything, of the matching piece of $query should be retained in
	 * the search string. If the piece is completely removed (most common case)
	 * it will be injected as a prefix into any search suggestions made.
	 *
	 * @param SearchContext $context
	 * @param string $term The current search term
	 * @param string $regex An expression that matches the desired special syntax
	 * @param callable $callback Called on for each piece of $term that matches
	 *  $regex. This function will be provided with $matches from
	 *  preg_replace_callback and must return a string which will replace the
	 *  match in $term.
	 * @return string The search term after extracting special syntax
	 */
	public static function extractSpecialSyntaxFromTerm( SearchContext $context, $term, $regex, $callback ) {
		return preg_replace_callback(
			$regex,
			function ( $matches ) use ( $context, $callback ) {
				$result = $callback( $matches );
				if ( $result === '' ) {
					$context->addSuggestPrefix( $matches[0] );
				}
				return $result;
			},
			$term
		);
	}
}
