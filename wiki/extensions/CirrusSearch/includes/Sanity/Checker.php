<?php

namespace CirrusSearch\Sanity;

use CirrusSearch\Connection;
use CirrusSearch\Searcher;
use Status;
use Title;
use WikiPage;

/**
 * Checks if a WikiPage's representation in search index is sane.
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

class Checker {
	/**
	 * @var Connection
	 */
	private $connection;

	/**
	 * @var Searcher Used for fetching data, so we can check the content.
	 */
	private $searcher;

	/**
	 * @var Remediator Do something with the problems we found
	 */
	private $remediator;

	/**
	 * @var bool Should we log id's that are found to have no problems
	 */
	private $logSane;

	/**
	 * Build the checker.
	 * @param Connection $connection
	 * @param Remediator $remediator the remediator to which to send titles
	 *   that are insane
	 * @param Searcher $searcher searcher to use for fetches
	 * @param bool $logSane should we log sane ids
	 */
	public function __construct( Connection $connection, Remediator $remediator, Searcher $searcher, $logSane ) {
		$this->connection = $connection;
		$this->remediator = $remediator;
		$this->searcher = $searcher;
		$this->logSane = $logSane;
	}

	/**
	 * Check if a title is insane.
	 *
	 * @param int $pageId page to check
	 * @return Status status of the operation
	 */
	public function check( $pageId ) {
		$status = $this->searcher->get( array( $pageId ), array( 'namespace', 'title' ) );
		if ( !$status->isOK() ) {
			return $status;
		}
		$fromIndex = $status->getValue();

		$inIndex = count( $fromIndex ) > 0;
		$page = WikiPage::newFromID( $pageId );

		if ( $page !== null && $page->exists() ) {
			if ( $page->isRedirect() ) {
				if ( $inIndex ) {
					$this->remediator->redirectInIndex( $page );
				} else {
					$this->sane( $pageId, 'Redirect not in index' );
				}
			} else {
				if ( $inIndex ) {
					$foundInsanityInIndex = false;
					$expectedType = $this->connection->getIndexSuffixForNamespace( $page->getTitle()->getNamespace() );
					/** @var IndexInfo $indexInfo */
					foreach ( $fromIndex as $indexInfo ) {
						$matches = array();
						if ( !preg_match( '/_(.+)_.+$/', $indexInfo->getIndex(), $matches ) ) {
							return Status::newFatal( "Can't parse index name:  " . $indexInfo->getIndex() );
						}
						$type = $matches[ 1 ];
						if ( $type !== $expectedType ) {
							// Got to grab the index type from the index name....
							$this->remediator->pageInWrongIndex( $page, $type );
							$foundInsanityInIndex = true;
						}
					}
					if ( !$foundInsanityInIndex ) {
						$this->sane( $pageId, 'Page in index' );
					}
				} else {
					$this->remediator->pageNotInIndex( $page );
				}
			}
		} else {
			if ( $inIndex ) {
				$r = $fromIndex[ 0 ];
				$title = Title::makeTitle( $r->namespace, $r->title );
				$this->remediator->ghostPageInIndex( $pageId, $title );
			} else {
				$this->sane( $pageId, 'No ghost' );
			}
		}
		return Status::newGood();
	}

	private function sane( $pageId, $reason ) {
		if ( $this->logSane ) {
			printf( "%30s %10d\n", $reason, $pageId );
		}
	}
}
