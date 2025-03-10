<?php

namespace CirrusSearch;

use Elastica;
use Elastica\Request;
use CirrusSearch;
use CirrusSearch\BuildDocument\Completion\SuggestBuilder;
use CirrusSearch\Search\SearchContext;
use MediaWiki\MediaWikiServices;
use SearchSuggestion;
use SearchSuggestionSet;
use Status;
use ApiUsageException;
use UsageException;
use User;

/**
 * Performs search as you type queries using Completion Suggester.
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

/**
 * Completion Suggester Searcher
 *
 * NOTES:
 * The CompletionSuggester is built on top of the ElasticSearch Completion
 * Suggester.
 * (https://www.elastic.co/guide/en/elasticsearch/reference/current/search-suggesters-completion.html).
 *
 * This class is used at query time, see
 * CirrusSearch\BuildDocument\SuggestBuilder for index time logic.
 *
 * Document model: Cirrus documents are indexed with 2 suggestions:
 *
 * 1. The title suggestion (and close redirects).
 * This helps to avoid displaying redirects with typos (e.g. Albert Enstein,
 * Unietd States) where we make the assumption that if the redirect is close
 * enough it's likely a typo and it's preferable to display the canonical title.
 * This decision is made at index-time in SuggestBuilder::extractTitleAndSimilarRedirects.
 *
 * 2. The redirect suggestions
 * Because the same canonical title can be returned twice we support fetch_limit_factor
 * in suggest profiles to fetch more than what the use asked.
 */
class CompletionSuggester extends ElasticsearchIntermediary {
	const VARIANT_EXTRA_DISCOUNT = 0.0001;
	/**
	 * @var string term to search.
	 */
	private $term;

	/**
	 * @var string[]|null search variants
	 */
	private $variants;

	/**
	 * @var integer maximum number of result
	 */
	private $limit;

	/**
	 * @var integer offset
	 */
	private $offset;

	/**
	 * @var string index base name to use
	 */
	private $indexBaseName;

	/**
	 * Search environment configuration
	 * @var SearchConfig
	 */
	private $config;

	/**
	 * @var string Query type (comp_suggest_geo or comp_suggest)
	 */
	public $queryType;

	/**
	 * @var SearchContext
	 */
	private $searchContext;

	private $settings;

	/**
	 * @param Connection $conn
	 * @param int $limit Limit the results to this many
	 * @param int $offset the offset
	 * @param SearchConfig $config Configuration settings
	 * @param int[]|null $namespaces Array of namespace numbers to search or null to search all namespaces.
	 * @param User|null $user user for which this search is being performed.  Attached to slow request logs.
	 * @param string|bool $index Base name for index to search from, defaults to $wgCirrusSearchIndexBaseName
	 * @param string|null $profileName
	 */
	public function __construct( Connection $conn, $limit, $offset = 0, SearchConfig $config = null, array $namespaces = null,
		User $user = null, $index = false, $profileName = null ) {
		if ( is_null( $config ) ) {
			// @todo connection has an embedded config ... reuse that? somehow should
			// at least ensure they are the same.
			$config = MediaWikiServices::getInstance()
				->getConfigFactory()
				->makeConfig( 'CirrusSearch' );
		}

		parent::__construct( $conn, $user, $config->get( 'CirrusSearchSlowSearch' ) );
		$this->config = $config;
		$this->limit = $limit;
		$this->offset = $offset;
		$this->indexBaseName = $index ?: $config->get( SearchConfig::INDEX_BASE_NAME );
		$this->searchContext = new SearchContext( $this->config, $namespaces );

		if ( $profileName == null ) {
			$profileName = $this->config->get( 'CirrusSearchCompletionSettings' );
		}
		$this->settings = $this->config->getElement( 'CirrusSearchCompletionProfiles', $profileName );
	}

	/**
	 * @param string $search
	 * @throws ApiUsageException
	 * @throws UsageException
	 */
	private function checkRequestLength( $search ) {
		$requestLength = mb_strlen( $search );
		if ( $requestLength > Searcher::MAX_TITLE_SEARCH ) {
			if ( class_exists( ApiUsageException::class ) ) {
				throw ApiUsageException::newWithMessage(
					null,
					[ 'apierror-cirrus-requesttoolong', $requestLength, Searcher::MAX_TITLE_SEARCH ],
					'request_too_long',
					[],
					400
				);
			} else {
				/** @suppress PhanDeprecatedClass */
				throw new UsageException( 'Prefix search request was longer than the maximum allowed length.' .
					" ($requestLength > " . Searcher::MAX_TITLE_SEARCH . ')', 'request_too_long', 400 );
			}
		}
	}

	/**
	 * Produce a set of completion suggestions for text using _suggest
	 * See https://www.elastic.co/guide/en/elasticsearch/reference/1.6/search-suggesters-completion.html
	 *
	 * WARNING: experimental API
	 *
	 * @param string $text Search term
	 * @param string[]|null $variants Search term variants
	 * (usually issued from $wgContLang->autoConvertToAllVariants( $text ) )
	 * @return Status
	 */
	public function suggest( $text, $variants = null ) {
		// If the offset requested is greater than the hard limit
		// allowed we will always return an empty set so let's do it
		// asap.
		if ( $this->offset >= $this->getHardLimit() ) {
			return Status::newGood( SearchSuggestionSet::emptySuggestionSet() );
		}

		$this->checkRequestLength( $text );
		$this->setTermAndVariants( $text, $variants );

		list( $profiles, $suggest ) = $this->buildQuery();
		$this->connection->setTimeout( $this->config->getElement( 'wgCirrusSearchClientSideSearchTimeout', 'default' ) );

		$index = $this->connection->getIndex( $this->indexBaseName, Connection::TITLE_SUGGEST_TYPE );
		$result = Util::doPoolCounterWork(
			'CirrusSearch-Completion',
			$this->user,
			function () use( $index, $suggest, $profiles, $text ) {
				$log = $this->newLog( "{queryType} search for '{query}'", $this->queryType, [
					'query' => $text,
					'offset' => $this->offset,
				] );
				$this->start( $log );
				try {
					$search = [
						'_source' => [ 'target_title' ],
						'suggest' => $suggest,
					];
					$result = $index->request( "_search", Request::POST, $search, [ 'size' => 0 ] );
					if ( $result->isOk() ) {
						$result = $this->postProcessSuggest( $result, $profiles, $log );
						return $this->success( $result );
					} else {
						throw new \Elastica\Exception\ResponseException(
							new Request( "_suggest", Request::POST, $suggest ),
							$result
						);
					}
				} catch ( \Elastica\Exception\ExceptionInterface $e ) {
					return $this->failure( $e );
				}
			}
		);
		return $result;
	}

	/**
	 * protected for tests
	 *
	 * @param string $term
	 * @param string[]|null $variants
	 */
	protected function setTermAndVariants( $term, array $variants = null ) {
		$this->term = $term;
		if ( empty( $variants ) ) {
			$this->variants = null;
			return;
		}
		$variants = array_diff( array_unique( $variants ), [ $term ] );
		if ( empty( $variants ) ) {
			$this->variants = null;
		} else {
			$this->variants = $variants;
		}
	}

	/**
	 * Builds the suggest queries and profiles.
	 * Use with list( $profiles, $suggest ).
	 * @return array the profiles and suggest queries
	 */
	protected function buildQuery() {
		if ( mb_strlen( $this->term ) > SuggestBuilder::MAX_INPUT_LENGTH ) {
			// Trim the query otherwise we won't find results
			$this->term = mb_substr( $this->term, 0, SuggestBuilder::MAX_INPUT_LENGTH );
		}

		$queryLen = mb_strlen( trim( $this->term ) ); // Avoid cheating with spaces
		$this->queryType = "comp_suggest";

		$profiles = $this->settings;
		$suggest = $this->buildSuggestQueries( $profiles, $this->term, $queryLen );

		// Handle variants, update the set of profiles and suggest queries
		if ( !empty( $this->variants ) ) {
			list( $addProfiles, $addSuggest ) = $this->handleVariants( $profiles, $queryLen );
			$profiles += $addProfiles;
			$suggest += $addSuggest;
		}
		return [ $profiles, $suggest ];
	}

	/**
	 * Builds a set of suggest query by reading the list of profiles
	 * @param array $profiles
	 * @param string $query
	 * @param int $queryLen the length to use when checking min/max_query_len
	 * @return array a set of suggest queries ready to for elastic
	 */
	protected function buildSuggestQueries( array $profiles, $query, $queryLen ) {
		$suggest = [];
		foreach ( $profiles as $name => $config ) {
			$sugg = $this->buildSuggestQuery( $config, $query, $queryLen );
			if ( !$sugg ) {
				continue;
			}
			$suggest[$name] = $sugg;
		}
		return $suggest;
	}

	/**
	 * Builds a suggest query from a profile
	 * @param array $config Profile
	 * @param string $query
	 * @param int $queryLen the length to use when checking min/max_query_len
	 * @return array|null suggest query ready to for elastic or null
	 */
	protected function buildSuggestQuery( array $config, $query, $queryLen ) {
		// Do not remove spaces at the end, the user might tell us he finished writing a word
		$query = ltrim( $query );
		if ( $config['min_query_len'] > $queryLen ) {
			return null;
		}
		if ( isset( $config['max_query_len'] ) && $queryLen > $config['max_query_len'] ) {
			return null;
		}
		$field = $config['field'];
		$limit = $this->getHardLimit();
		$suggest = [
			'prefix' => $query,
			'completion' => [
				'field' => $field,
				'size' => $limit * $config['fetch_limit_factor']
			]
		];
		if ( isset( $config['fuzzy'] ) ) {
			$suggest['completion']['fuzzy'] = $config['fuzzy'];
		}
		return $suggest;
	}

	/**
	 * Update the suggest queries and return additional profiles flagged the 'fallback' key
	 * with a discount factor = originalDiscount * 0.0001/(variantIndex+1).
	 * @param array $profiles the default profiles
	 * @param int $queryLen the original query length
	 * @return array new variant profiles
	 */
	protected function handleVariants( array $profiles, $queryLen ) {
		$variantIndex = 0;
		$allVariantProfiles = [];
		$allSuggestions = [];
		foreach ( $this->variants as $variant ) {
			$variantIndex++;
			foreach ( $profiles as $name => $profile ) {
				$variantProfName = $name . '-variant-' . $variantIndex;
				$profile = $this->buildVariantProfile( $profile, self::VARIANT_EXTRA_DISCOUNT / $variantIndex );
				$suggest = $this->buildSuggestQuery(
					$profile, $variant, $queryLen
				);
				if ( $suggest !== null ) {
					$allVariantProfiles[$variantProfName] = $profile;
					$allSuggestions[$variantProfName] = $suggest;
				}
			}
		}
		return [ $allVariantProfiles, $allSuggestions ];
	}

	/**
	 * Creates a copy of $profile[$name] with a custom '-variant-SEQ' suffix.
	 * And applies an extra discount factor of 0.0001.
	 * The copy is added to the profiles container.
	 * @param array $profile profile to copy
	 * @param float $extraDiscount extra discount factor to rank variant suggestion lower.
	 * @return array
	 */
	protected function buildVariantProfile( array $profile, $extraDiscount = 0.0001 ) {
		// mark the profile as a fallback query
		$profile['fallback'] = true;
		$profile['discount'] *= $extraDiscount;
		return $profile;
	}

	/**
	 * merge top level multi-queries and resolve returned pageIds into Title objects.
	 *
	 * WARNING: experimental API
	 *
	 * @param \Elastica\Response $response Response from elasticsearch _suggest api
	 * @param array $profiles the suggestion profiles
	 * @param CompletionRequestLog $log
	 * @return SearchSuggestionSet a set of Suggestions
	 */
	protected function postProcessSuggest( \Elastica\Response $response, $profiles, CompletionRequestLog $log ) {
		$log->setResponse( $response );
		$fullResponse = $response->getData();
		if ( !isset( $fullResponse['suggest'] ) ) {
			// Edge case where the index contains 0 documents and does not even return the 'suggest' field
			return SearchSuggestionSet::emptySuggestionSet();
		}

		$data = $fullResponse['suggest'];

		$limit = $this->getHardLimit();
		$suggestionsByDocId = [];
		$suggestionProfileByDocId = [];
		$hitsTotal = 0;
		foreach ( $data as $name => $results ) {
			$discount = $profiles[$name]['discount'];
			foreach ( $results  as $suggested ) {
				$hitsTotal += count( $suggested['options'] );
				foreach ( $suggested['options'] as $suggest ) {
					$page = $suggest['text'];
					$targetTitle = $page;
					$targetTitleNS = NS_MAIN;
					if ( isset( $suggest['_source']['target_title'] ) ) {
						$targetTitle = $suggest['_source']['target_title']['title'];
						$targetTitleNS = $suggest['_source']['target_title']['namespace'];
					}
					list( $docId, $type ) = $this->decodeId( $suggest['_id'] );
					$score = $discount * $suggest['_score'];
					if ( !isset( $suggestionsByDocId[$docId] ) ||
						$score > $suggestionsByDocId[$docId]->getScore()
					) {
						$pageId = $this->config->makePageId( $docId );
						$suggestion = new SearchSuggestion( $score, null, null, $pageId );
						// Use setText, it'll build the Title
						if ( $type === SuggestBuilder::TITLE_SUGGESTION && $targetTitleNS === NS_MAIN ) {
							// For title suggestions we always use the target_title
							// This is because we may encounter default_sort or subphrases that are not valid titles...
							// And we prefer to display the title over close redirects
							// for CrossNS redirect we prefer the returned suggestion
							$suggestion->setText( $targetTitle );

						} else {
							$suggestion->setText( $page );
						}
						$suggestionsByDocId[$docId] = $suggestion;
						$suggestionProfileByDocId[$docId] = $name;
					}
				}
			}
		}

		// simply sort by existing scores
		uasort( $suggestionsByDocId, function ( SearchSuggestion $a, SearchSuggestion $b ) {
			return $b->getScore() - $a->getScore();
		} );

		$suggestionsByDocId = $this->offset < $limit
			? array_slice( $suggestionsByDocId, $this->offset, $limit - $this->offset, true )
			: [];

		$indexName = $this->connection->getIndex( $this->indexBaseName, Connection::TITLE_SUGGEST_TYPE )->getName();
		$log->setResult( $indexName, $suggestionsByDocId, $suggestionProfileByDocId );

		return new SearchSuggestionSet( $suggestionsByDocId );
	}

	/**
	 * @param string $id compacted id (id + $type)
	 * @return array 2 elt array [ $id, $type ]
	 */
	private function decodeId( $id ) {
		return [ intval( substr( $id, 0, -1 ) ), substr( $id, -1 ) ];
	}

	/**
	 * Set the max number of results to extract.
	 * @param int $limit
	 */
	public function setLimit( $limit ) {
		$this->limit = $limit;
	}

	/**
	 * Set the offset
	 * @param int $offset
	 */
	public function setOffset( $offset ) {
		$this->offset = $offset;
	}

	/**
	 * @param string $description
	 * @param string $queryType
	 * @param string[] $extra
	 * @return CompletionRequestLog
	 */
	protected function newLog( $description, $queryType, array $extra = [] ) {
		return new CompletionRequestLog(
			$description,
			$queryType,
			$extra
		);
	}

	/**
	 * Get the hard limit
	 * The completion api does not supports offset we have to add a hack
	 * here to work around this limitation.
	 * To avoid ridiculously large queries we set also a hard limit.
	 * Note that this limit will be changed by fetch_limit_factor set to 2 or 1.5
	 * depending on the profile.
	 * @return int the number of results to fetch from elastic
	 */
	private function getHardLimit() {
		$limit = $this->limit + $this->offset;
		$hardLimit = $this->config->get( 'CirrusSearchCompletionSuggesterHardLimit' );
		if ( $hardLimit === null ) {
			$hardLimit = 50;
		}
		if ( $limit > $hardLimit ) {
			return $hardLimit;
		}
		return $limit;
	}
}
