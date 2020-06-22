<?php

namespace CirrusSearch\Search;

use Elastica;
use Elastica\Filter\AbstractFilter;

/**
 * Utilities for dealing with filters.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 */
class Filters {
	/**
	 * Merges lists of include/exclude filters into a single filter that
	 * Elasticsearch will execute efficiently.
	 *
	 * @param AbstractFilter[] $mustFilters filters that must match all returned documents
	 * @param AbstractFilter[] $mustNotFilters filters that must not match all returned documents
	 * @return null|AbstractFilter null if there are no filters or one that will execute
	 *     all of the provided filters
	 */
	public static function unify( $mustFilters, $mustNotFilters ) {
		// We want to make sure that we execute script filters last.  So we do these steps:
		// 1.  Strip script filters from $must and $mustNot.
		// 2.  Unify the non-script filters.
		// 3.  Build a BoolAnd filter out of the script filters if there are any.
		$scriptFilters = array();
		$nonScriptMust = array();
		$nonScriptMustNot = array();
		foreach ( $mustFilters as $must ) {
			if ( $must->hasParam( 'script' ) ) {
				$scriptFilters[] = $must;
			} else {
				$nonScriptMust[] = $must;
			}
		}
		foreach ( $mustNotFilters as $mustNot ) {
			if ( $mustNot->hasParam( 'script' ) ) {
				$scriptFilters[] = new \Elastica\Filter\BoolNot( $mustNot );
			} else {
				$nonScriptMustNot[] = $mustNot;
			}
		}

		$nonScript = self::unifyNonScript( $nonScriptMust, $nonScriptMustNot );
		$scriptFiltersCount = count( $scriptFilters );
		if ( $scriptFiltersCount === 0 ) {
			return $nonScript;
		}

		$boolAndFilter = new \Elastica\Filter\BoolAnd();
		if ( $nonScript === null ) {
			if ( $scriptFiltersCount === 1 ) {
				return $scriptFilters[ 0 ];
			}
		} else {
			$boolAndFilter->addFilter( $nonScript );
		}
		foreach ( $scriptFilters as $scriptFilter ) {
			$boolAndFilter->addFilter( $scriptFilter );
		}
		return $boolAndFilter;

	}

	/**
	 * Unify non-script filters into a single filter.
	 *
	 * @param AbstractFilter[] $mustFilters filters that must be found
	 * @param AbstractFilter[] $mustNotFilters filters that must not be found
	 * @return null|AbstractFilter null if there are no filters or one that will execute
	 *     all of the provided filters
	 */
	private static function unifyNonScript( $mustFilters, $mustNotFilters ) {
		$mustFilterCount = count( $mustFilters );
		$mustNotFilterCount = count( $mustNotFilters );
		if ( $mustFilterCount + $mustNotFilterCount === 0 ) {
			return null;
		}
		if ( $mustFilterCount === 1 && $mustNotFilterCount == 0 ) {
			return $mustFilters[ 0 ];
		}
		if ( $mustFilterCount === 0 && $mustNotFilterCount == 1 ) {
			return new \Elastica\Filter\BoolNot( $mustNotFilters[ 0 ] );
		}
		$boolFilter = new \Elastica\Filter\BoolFilter();
		foreach ( $mustFilters as $must ) {
			$boolFilter->addMust( $must );
		}
		foreach ( $mustNotFilters as $mustNot ) {
			$boolFilter->addMustNot( $mustNot );
		}
		return $boolFilter;
	}

	/**
	 * Create a filter for insource: queries.  This was extracted from the big
	 * switch block in Searcher.php.  This function is pure, deferring state
	 * changes to the reference-updating return function.
	 *
	 * @param Escaper $escaper
	 * @param SearchContext $context
	 * @param string $value
	 * @return callable a side-effecting function to update several references
	 */
	public static function insource( Escaper $escaper, SearchContext $context, $value ) {
		return self::insourceOrIntitle( $escaper, $context, $value, true, function () {
			return 'source_text.plain';
		});
	}

	/**
	 * Create a filter for intitle: queries.  This was extracted from the big
	 * switch block in Searcher.php.  This function is pure, deferring state
	 * changes to the reference-updating return function.
	 *
	 * @param Escaper $escaper
	 * @param SearchContext $context
	 * @param string $value
	 * @return callable a side-effecting function to update several references
	 */
	public static function intitle( Escaper $escaper, SearchContext $context, $value ) {
		return self::insourceOrIntitle( $escaper, $context, $value, false, function ( $queryString ) {
			if ( preg_match( '/[?*]/u', $queryString ) ) {
				return 'title.plain';
			} else {
				return 'title';
			}
		});
	}

	/**
	 * @param Escaper $escaper
	 * @param SearchContext $context
	 * @param string $value
	 * @param bool $updateHighlightSourceRef
	 * @param callable $fieldF
	 * @return callable
	 */
	private static function insourceOrIntitle( Escaper $escaper, SearchContext $context, $value, $updateHighlightSourceRef, $fieldF ) {
		list( $queryString, $fuzzyQuery ) = $escaper->fixupWholeQueryString(
			$escaper->fixupQueryStringPart( $value ) );
		$field = $fieldF( $queryString );
		$query = new \Elastica\Query\QueryString( $queryString );
		$query->setFields( array( $field ) );
		$query->setDefaultOperator( 'AND' );
		$query->setAllowLeadingWildcard( $escaper->getAllowLeadingWildcard() );
		$query->setFuzzyPrefixLength( 2 );
		$query->setRewrite( 'top_terms_boost_1024' );
		$wrappedQuery = $context->wrapInSaferIfPossible( $query, false );

		$updateReferences =
			function ( &$fuzzyQueryRef, &$filterDestinationRef, &$highlightSourceRef, &$searchContainedSyntaxRef )
			     use ( $fuzzyQuery, $wrappedQuery, $updateHighlightSourceRef ) {
				$fuzzyQueryRef             = $fuzzyQuery;
				$filterDestinationRef[]    = new \Elastica\Filter\Query( $wrappedQuery );
				if ($updateHighlightSourceRef) {
					$highlightSourceRef[]      = array( 'query' => $wrappedQuery );
				}
				$searchContainedSyntaxRef  = true;
			};

		return $updateReferences;
	}

}
