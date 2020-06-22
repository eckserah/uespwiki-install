<?php

namespace CirrusSearch\Maintenance;

use CirrusSearch;
use CirrusSearch\Search\ResultSet;
use RequestContext;
use SearchSuggestionSet;
use Status;

/**
 * Run search queries provided on stdin
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

$IP = getenv( 'MW_INSTALL_PATH' );
if( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once( "$IP/maintenance/Maintenance.php" );
require_once( __DIR__ . '/../includes/Maintenance/Maintenance.php' );

class RunSearch extends Maintenance {

	/**
	 * @var string
	 */
	protected $indexBaseName;

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Run one or more searches against the specified cluster. ' .
			'search queries are read from stdin.' );
		$this->addOption( 'baseName', 'What basename to use for all indexes, ' .
			'defaults to wiki id', false, true );
		$this->addOption( 'type', 'What type of search to run, prefix, suggest or full_text. ' .
			'defaults to full_text.', false, true );
		$this->addOption( 'options', 'A JSON object mapping from global variable to ' .
			'its test value', false, true );
		$this->addOption( 'fork', 'Fork multiple processes to run queries from.' .
			'defaults to false.', false, true );
		$this->addOption( 'decode', 'urldecode() queries before running them', false, false );
		$this->addOption( 'explain', 'Include lucene explanation in the results', false, false );
		$this->addOption( 'limit', 'Set the max number of results returned by query (defaults to 10)', false, true );
	}

	public function execute() {
		global $wgPoolCounterConf, $wgCirrusSearchLogElasticRequests;

		// Make sure we don't flood the pool counter
		unset( $wgPoolCounterConf['CirrusSearch-Search'],
			$wgPoolCounterConf['CirrusSearch-PerUser'] );

		// Don't skew the dashboards by logging these requests to
		// the global request log.
		$wgCirrusSearchLogElasticRequests = false;

		$this->indexBaseName = $this->getOption( 'baseName', wfWikiID() );

		$this->applyGlobals();
		$callback = array( $this, 'consume' );
		$forks = $this->getOption( 'fork', false );
		$forks = ctype_digit( $forks ) ? intval( $forks ) : 0;
		$controller = new OrderedStreamingForkController( $forks, $callback, STDIN, STDOUT );
		$controller->start();
	}

	/**
	 * Applies global variables provided as the options CLI argument
	 * to override current settings.
	 */
	protected function applyGlobals() {
		$options = json_decode( $this->getOption( 'options', 'false' ), true );
		if ( $options ) {
			foreach ( $options as $key => $value ) {
				if ( array_key_exists( $key, $GLOBALS ) ) {
					$GLOBALS[$key] = $value;
				} else {
					$this->error( "\nERROR: $key is not a valid global variable\n" );
					exit();
				}
			}
		}
	}

	/**
	 * Transform the search request into a JSON string representing the
	 * search result.
	 *
	 * @param string $query
	 * @return string JSON object
	 */
	public function consume( $query ) {
		if ( $this->getOption( 'decode' ) ) {
			$query = urldecode( $query );
		}
		$data = array( 'query' => $query );
		$status = $this->searchFor( $query );
		if ( $status->isOK() ) {
			$value = $status->getValue();
			if ( $value instanceof ResultSet ) {
				// these are prefix or full text results
				$data['totalHits'] = $value->getTotalHits();
				$data['rows'] = array();
				$result = $value->next();
				while ( $result ) {
					$data['rows'][] = array(
						// use getDocId() rather than asking the title to allow this script
						// to work when a production index has been imported to a test es instance
						'pageId' => $result->getDocId(),
						'title' => $result->getTitle()->getPrefixedText(),
						'score' => $result->getScore(),
						'snippets' => array(
							'text' => $result->getTextSnippet( $query ),
							'title' => $result->getTitleSnippet(),
							'redirect' => $result->getRedirectSnippet(),
							'section' => $result->getSectionSnippet(),
							'category' => $result->getCategorySnippet(),
						),
						'explanation' => $result->getExplanation(),
					);
					$result = $value->next();
				}
			} elseif ( $value instanceof SearchSuggestionSet ) {
				// these are suggestion results
				$data['totalHits'] = $value->getSize();
				foreach ( $value->getSuggestions() as $suggestion ) {
					$data['rows'][] = array(
						'pageId' => $suggestion->getSuggestedTitleID(),
						'title' => $suggestion->getSuggestedTitle()->getPrefixedText(),
						'snippets' => array(),
					);
				}
			} else {
				throw new \RuntimeException(
					"Unknown result type: "
					. is_object( $value ) ? get_class( $value ) : gettype( $value )
				);
			}
		} else {
			$data['error'] = $status->getMessage()->text();
		}
		return json_encode( $data );
	}

	/**
	 * Transform the search request into a Status object representing the
	 * search result. Varies based on CLI input argument `type`.
	 *
	 * @param string $query
	 * @return Status<ResultSet>
	 */
	protected function searchFor( $query ) {
		$searchType = $this->getOption( 'type', 'full_text' );
		$limit = $this->getOption( 'limit', 10 );
		if ( $this->getOption( 'explain' ) ) {
			RequestContext::getMain()->getRequest()->setVal( 'cirrusExplain', true );
		}

		$engine = new CirrusSearch( $this->indexBaseName );
		$engine->setConnection( $this->getConnection() );
		$engine->setLimitOffset( $limit );

		switch ( $searchType ) {
		case 'full_text':
			// @todo pass through $this->getConnection() ?
			$result = $engine->searchText( $query );
			if ( $result instanceof Status ) {
				return $result;
			} else {
				return Status::newGood( $result );
			}

		case 'prefix':
			$titles = $engine->defaultPrefixSearch( $query );
			$resultSet = SearchSuggestionSet::fromTitles( $titles );
			return Status::newGood( $resultSet );

		case 'suggest':
			$engine->setFeatureData( CirrusSearch::COMPLETION_SUGGESTER_FEATURE, true );
			$result = $engine->completionSearch( $query );
			if ( $result instanceof Status ) {
				return $result;
			} else {
				return Status::newGood( $result );
			}

		default:
			$this->error( "\nERROR: Unknown search type $searchType\n" );
			exit( 1 );
		}
	}
}

$maintClass = "CirrusSearch\Maintenance\RunSearch";
require_once RUN_MAINTENANCE_IF_MAIN;
