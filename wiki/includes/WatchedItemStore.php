<?php

use Wikimedia\Rdbms\IDatabase;
use Liuggio\StatsdClient\Factory\StatsdDataFactoryInterface;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\MediaWikiServices;
use Wikimedia\Assert\Assert;
use Wikimedia\ScopedCallback;
use Wikimedia\Rdbms\LoadBalancer;
use Wikimedia\Rdbms\DBUnexpectedError;

/**
 * Storage layer class for WatchedItems.
 * Database interaction.
 *
 * Uses database because this uses User::isAnon
 *
 * @group Database
 *
 * @author Addshore
 * @since 1.27
 */
class WatchedItemStore implements StatsdAwareInterface {

	const SORT_DESC = 'DESC';
	const SORT_ASC = 'ASC';

	/**
	 * @var LoadBalancer
	 */
	private $loadBalancer;

	/**
	 * @var ReadOnlyMode
	 */
	private $readOnlyMode;

	/**
	 * @var HashBagOStuff
	 */
	private $cache;

	/**
	 * @var array[] Looks like $cacheIndex[Namespace ID][Target DB Key][User Id] => 'key'
	 * The index is needed so that on mass changes all relevant items can be un-cached.
	 * For example: Clearing a users watchlist of all items or updating notification timestamps
	 *              for all users watching a single target.
	 */
	private $cacheIndex = [];

	/**
	 * @var callable|null
	 */
	private $deferredUpdatesAddCallableUpdateCallback;

	/**
	 * @var callable|null
	 */
	private $revisionGetTimestampFromIdCallback;

	/**
	 * @var StatsdDataFactoryInterface
	 */
	private $stats;

	/**
	 * @param LoadBalancer $loadBalancer
	 * @param HashBagOStuff $cache
	 * @param ReadOnlyMode $readOnlyMode
	 */
	public function __construct(
		LoadBalancer $loadBalancer,
		HashBagOStuff $cache,
		ReadOnlyMode $readOnlyMode
	) {
		$this->loadBalancer = $loadBalancer;
		$this->cache = $cache;
		$this->readOnlyMode = $readOnlyMode;
		$this->stats = new NullStatsdDataFactory();
		$this->deferredUpdatesAddCallableUpdateCallback = [ 'DeferredUpdates', 'addCallableUpdate' ];
		$this->revisionGetTimestampFromIdCallback = [ 'Revision', 'getTimestampFromId' ];
	}

	public function setStatsdDataFactory( StatsdDataFactoryInterface $stats ) {
		$this->stats = $stats;
	}

	/**
	 * Overrides the DeferredUpdates::addCallableUpdate callback
	 * This is intended for use while testing and will fail if MW_PHPUNIT_TEST is not defined.
	 *
	 * @param callable $callback
	 *
	 * @see DeferredUpdates::addCallableUpdate for callback signiture
	 *
	 * @return ScopedCallback to reset the overridden value
	 * @throws MWException
	 */
	public function overrideDeferredUpdatesAddCallableUpdateCallback( callable $callback ) {
		if ( !defined( 'MW_PHPUNIT_TEST' ) ) {
			throw new MWException(
				'Cannot override DeferredUpdates::addCallableUpdate callback in operation.'
			);
		}
		$previousValue = $this->deferredUpdatesAddCallableUpdateCallback;
		$this->deferredUpdatesAddCallableUpdateCallback = $callback;
		return new ScopedCallback( function () use ( $previousValue ) {
			$this->deferredUpdatesAddCallableUpdateCallback = $previousValue;
		} );
	}

	/**
	 * Overrides the Revision::getTimestampFromId callback
	 * This is intended for use while testing and will fail if MW_PHPUNIT_TEST is not defined.
	 *
	 * @param callable $callback
	 * @see Revision::getTimestampFromId for callback signiture
	 *
	 * @return ScopedCallback to reset the overridden value
	 * @throws MWException
	 */
	public function overrideRevisionGetTimestampFromIdCallback( callable $callback ) {
		if ( !defined( 'MW_PHPUNIT_TEST' ) ) {
			throw new MWException(
				'Cannot override Revision::getTimestampFromId callback in operation.'
			);
		}
		$previousValue = $this->revisionGetTimestampFromIdCallback;
		$this->revisionGetTimestampFromIdCallback = $callback;
		return new ScopedCallback( function () use ( $previousValue ) {
			$this->revisionGetTimestampFromIdCallback = $previousValue;
		} );
	}

	private function getCacheKey( User $user, LinkTarget $target ) {
		return $this->cache->makeKey(
			(string)$target->getNamespace(),
			$target->getDBkey(),
			(string)$user->getId()
		);
	}

	private function cache( WatchedItem $item ) {
		$user = $item->getUser();
		$target = $item->getLinkTarget();
		$key = $this->getCacheKey( $user, $target );
		$this->cache->set( $key, $item );
		$this->cacheIndex[$target->getNamespace()][$target->getDBkey()][$user->getId()] = $key;
		$this->stats->increment( 'WatchedItemStore.cache' );
	}

	private function uncache( User $user, LinkTarget $target ) {
		$this->cache->delete( $this->getCacheKey( $user, $target ) );
		unset( $this->cacheIndex[$target->getNamespace()][$target->getDBkey()][$user->getId()] );
		$this->stats->increment( 'WatchedItemStore.uncache' );
	}

	private function uncacheLinkTarget( LinkTarget $target ) {
		$this->stats->increment( 'WatchedItemStore.uncacheLinkTarget' );
		if ( !isset( $this->cacheIndex[$target->getNamespace()][$target->getDBkey()] ) ) {
			return;
		}
		foreach ( $this->cacheIndex[$target->getNamespace()][$target->getDBkey()] as $key ) {
			$this->stats->increment( 'WatchedItemStore.uncacheLinkTarget.items' );
			$this->cache->delete( $key );
		}
	}

	private function uncacheUser( User $user ) {
		$this->stats->increment( 'WatchedItemStore.uncacheUser' );
		foreach ( $this->cacheIndex as $ns => $dbKeyArray ) {
			foreach ( $dbKeyArray as $dbKey => $userArray ) {
				if ( isset( $userArray[$user->getId()] ) ) {
					$this->stats->increment( 'WatchedItemStore.uncacheUser.items' );
					$this->cache->delete( $userArray[$user->getId()] );
				}
			}
		}
	}

	/**
	 * @param User $user
	 * @param LinkTarget $target
	 *
	 * @return WatchedItem|false
	 */
	private function getCached( User $user, LinkTarget $target ) {
		return $this->cache->get( $this->getCacheKey( $user, $target ) );
	}

	/**
	 * Return an array of conditions to select or update the appropriate database
	 * row.
	 *
	 * @param User $user
	 * @param LinkTarget $target
	 *
	 * @return array
	 */
	private function dbCond( User $user, LinkTarget $target ) {
		return [
			'wl_user' => $user->getId(),
			'wl_namespace' => $target->getNamespace(),
			'wl_title' => $target->getDBkey(),
		];
	}

	/**
	 * @param int $dbIndex DB_MASTER or DB_REPLICA
	 *
	 * @return IDatabase
	 * @throws MWException
	 */
	private function getConnectionRef( $dbIndex ) {
		return $this->loadBalancer->getConnectionRef( $dbIndex, [ 'watchlist' ] );
	}

	/**
	 * Count the number of individual items that are watched by the user.
	 * If a subject and corresponding talk page are watched this will return 2.
	 *
	 * @param User $user
	 *
	 * @return int
	 */
	public function countWatchedItems( User $user ) {
		$dbr = $this->getConnectionRef( DB_REPLICA );
		$return = (int)$dbr->selectField(
			'watchlist',
			'COUNT(*)',
			[
				'wl_user' => $user->getId()
			],
			__METHOD__
		);

		return $return;
	}

	/**
	 * @param LinkTarget $target
	 *
	 * @return int
	 */
	public function countWatchers( LinkTarget $target ) {
		$dbr = $this->getConnectionRef( DB_REPLICA );
		$return = (int)$dbr->selectField(
			'watchlist',
			'COUNT(*)',
			[
				'wl_namespace' => $target->getNamespace(),
				'wl_title' => $target->getDBkey(),
			],
			__METHOD__
		);

		return $return;
	}

	/**
	 * Number of page watchers who also visited a "recent" edit
	 *
	 * @param LinkTarget $target
	 * @param mixed $threshold timestamp accepted by wfTimestamp
	 *
	 * @return int
	 * @throws DBUnexpectedError
	 * @throws MWException
	 */
	public function countVisitingWatchers( LinkTarget $target, $threshold ) {
		$dbr = $this->getConnectionRef( DB_REPLICA );
		$visitingWatchers = (int)$dbr->selectField(
			'watchlist',
			'COUNT(*)',
			[
				'wl_namespace' => $target->getNamespace(),
				'wl_title' => $target->getDBkey(),
				'wl_notificationtimestamp >= ' .
				$dbr->addQuotes( $dbr->timestamp( $threshold ) ) .
				' OR wl_notificationtimestamp IS NULL'
			],
			__METHOD__
		);

		return $visitingWatchers;
	}

	/**
	 * @param LinkTarget[] $targets
	 * @param array $options Allowed keys:
	 *        'minimumWatchers' => int
	 *
	 * @return array multi dimensional like $return[$namespaceId][$titleString] = int $watchers
	 *         All targets will be present in the result. 0 either means no watchers or the number
	 *         of watchers was below the minimumWatchers option if passed.
	 */
	public function countWatchersMultiple( array $targets, array $options = [] ) {
		if ( $targets === [] ) {
			// No titles requested => no results returned
			return [];
		}

		$dbOptions = [ 'GROUP BY' => [ 'wl_namespace', 'wl_title' ] ];

		$dbr = $this->getConnectionRef( DB_REPLICA );

		if ( array_key_exists( 'minimumWatchers', $options ) ) {
			$dbOptions['HAVING'] = 'COUNT(*) >= ' . (int)$options['minimumWatchers'];
		}

		$lb = new LinkBatch( $targets );
		$res = $dbr->select(
			'watchlist',
			[ 'wl_title', 'wl_namespace', 'watchers' => 'COUNT(*)' ],
			[ $lb->constructSet( 'wl', $dbr ) ],
			__METHOD__,
			$dbOptions
		);

		$watchCounts = [];
		foreach ( $targets as $linkTarget ) {
			$watchCounts[$linkTarget->getNamespace()][$linkTarget->getDBkey()] = 0;
		}

		foreach ( $res as $row ) {
			$watchCounts[$row->wl_namespace][$row->wl_title] = (int)$row->watchers;
		}

		return $watchCounts;
	}

	/**
	 * Number of watchers of each page who have visited recent edits to that page
	 *
	 * @param array $targetsWithVisitThresholds array of pairs (LinkTarget $target, mixed $threshold),
	 *        $threshold is:
	 *        - a timestamp of the recent edit if $target exists (format accepted by wfTimestamp)
	 *        - null if $target doesn't exist
	 * @param int|null $minimumWatchers
	 * @return array multi-dimensional like $return[$namespaceId][$titleString] = $watchers,
	 *         where $watchers is an int:
	 *         - if the page exists, number of users watching who have visited the page recently
	 *         - if the page doesn't exist, number of users that have the page on their watchlist
	 *         - 0 means there are no visiting watchers or their number is below the minimumWatchers
	 *         option (if passed).
	 */
	public function countVisitingWatchersMultiple(
		array $targetsWithVisitThresholds,
		$minimumWatchers = null
	) {
		$dbr = $this->getConnectionRef( DB_REPLICA );

		$conds = $this->getVisitingWatchersCondition( $dbr, $targetsWithVisitThresholds );

		$dbOptions = [ 'GROUP BY' => [ 'wl_namespace', 'wl_title' ] ];
		if ( $minimumWatchers !== null ) {
			$dbOptions['HAVING'] = 'COUNT(*) >= ' . (int)$minimumWatchers;
		}
		$res = $dbr->select(
			'watchlist',
			[ 'wl_namespace', 'wl_title', 'watchers' => 'COUNT(*)' ],
			$conds,
			__METHOD__,
			$dbOptions
		);

		$watcherCounts = [];
		foreach ( $targetsWithVisitThresholds as list( $target ) ) {
			/* @var LinkTarget $target */
			$watcherCounts[$target->getNamespace()][$target->getDBkey()] = 0;
		}

		foreach ( $res as $row ) {
			$watcherCounts[$row->wl_namespace][$row->wl_title] = (int)$row->watchers;
		}

		return $watcherCounts;
	}

	/**
	 * Generates condition for the query used in a batch count visiting watchers.
	 *
	 * @param IDatabase $db
	 * @param array $targetsWithVisitThresholds array of pairs (LinkTarget, last visit threshold)
	 * @return string
	 */
	private function getVisitingWatchersCondition(
		IDatabase $db,
		array $targetsWithVisitThresholds
	) {
		$missingTargets = [];
		$namespaceConds = [];
		foreach ( $targetsWithVisitThresholds as list( $target, $threshold ) ) {
			if ( $threshold === null ) {
				$missingTargets[] = $target;
				continue;
			}
			/* @var LinkTarget $target */
			$namespaceConds[$target->getNamespace()][] = $db->makeList( [
				'wl_title = ' . $db->addQuotes( $target->getDBkey() ),
				$db->makeList( [
					'wl_notificationtimestamp >= ' . $db->addQuotes( $db->timestamp( $threshold ) ),
					'wl_notificationtimestamp IS NULL'
				], LIST_OR )
			], LIST_AND );
		}

		$conds = [];
		foreach ( $namespaceConds as $namespace => $pageConds ) {
			$conds[] = $db->makeList( [
				'wl_namespace = ' . $namespace,
				'(' . $db->makeList( $pageConds, LIST_OR ) . ')'
			], LIST_AND );
		}

		if ( $missingTargets ) {
			$lb = new LinkBatch( $missingTargets );
			$conds[] = $lb->constructSet( 'wl', $db );
		}

		return $db->makeList( $conds, LIST_OR );
	}

	/**
	 * Get an item (may be cached)
	 *
	 * @param User $user
	 * @param LinkTarget $target
	 *
	 * @return WatchedItem|false
	 */
	public function getWatchedItem( User $user, LinkTarget $target ) {
		if ( $user->isAnon() ) {
			return false;
		}

		$cached = $this->getCached( $user, $target );
		if ( $cached ) {
			$this->stats->increment( 'WatchedItemStore.getWatchedItem.cached' );
			return $cached;
		}
		$this->stats->increment( 'WatchedItemStore.getWatchedItem.load' );
		return $this->loadWatchedItem( $user, $target );
	}

	/**
	 * Loads an item from the db
	 *
	 * @param User $user
	 * @param LinkTarget $target
	 *
	 * @return WatchedItem|false
	 */
	public function loadWatchedItem( User $user, LinkTarget $target ) {
		// Only loggedin user can have a watchlist
		if ( $user->isAnon() ) {
			return false;
		}

		$dbr = $this->getConnectionRef( DB_REPLICA );
		$row = $dbr->selectRow(
			'watchlist',
			'wl_notificationtimestamp',
			$this->dbCond( $user, $target ),
			__METHOD__
		);

		if ( !$row ) {
			return false;
		}

		$item = new WatchedItem(
			$user,
			$target,
			wfTimestampOrNull( TS_MW, $row->wl_notificationtimestamp )
		);
		$this->cache( $item );

		return $item;
	}

	/**
	 * @param User $user
	 * @param array $options Allowed keys:
	 *        'forWrite' => bool defaults to false
	 *        'sort' => string optional sorting by namespace ID and title
	 *                     one of the self::SORT_* constants
	 *
	 * @return WatchedItem[]
	 */
	public function getWatchedItemsForUser( User $user, array $options = [] ) {
		$options += [ 'forWrite' => false ];

		$dbOptions = [];
		if ( array_key_exists( 'sort', $options ) ) {
			Assert::parameter(
				( in_array( $options['sort'], [ self::SORT_ASC, self::SORT_DESC ] ) ),
				'$options[\'sort\']',
				'must be SORT_ASC or SORT_DESC'
			);
			$dbOptions['ORDER BY'] = [
				"wl_namespace {$options['sort']}",
				"wl_title {$options['sort']}"
			];
		}
		$db = $this->getConnectionRef( $options['forWrite'] ? DB_MASTER : DB_REPLICA );

		$res = $db->select(
			'watchlist',
			[ 'wl_namespace', 'wl_title', 'wl_notificationtimestamp' ],
			[ 'wl_user' => $user->getId() ],
			__METHOD__,
			$dbOptions
		);

		$watchedItems = [];
		foreach ( $res as $row ) {
			// @todo: Should we add these to the process cache?
			$watchedItems[] = new WatchedItem(
				$user,
				new TitleValue( (int)$row->wl_namespace, $row->wl_title ),
				$row->wl_notificationtimestamp
			);
		}

		return $watchedItems;
	}

	/**
	 * Must be called separately for Subject & Talk namespaces
	 *
	 * @param User $user
	 * @param LinkTarget $target
	 *
	 * @return bool
	 */
	public function isWatched( User $user, LinkTarget $target ) {
		return (bool)$this->getWatchedItem( $user, $target );
	}

	/**
	 * @param User $user
	 * @param LinkTarget[] $targets
	 *
	 * @return array multi-dimensional like $return[$namespaceId][$titleString] = $timestamp,
	 *         where $timestamp is:
	 *         - string|null value of wl_notificationtimestamp,
	 *         - false if $target is not watched by $user.
	 */
	public function getNotificationTimestampsBatch( User $user, array $targets ) {
		$timestamps = [];
		foreach ( $targets as $target ) {
			$timestamps[$target->getNamespace()][$target->getDBkey()] = false;
		}

		if ( $user->isAnon() ) {
			return $timestamps;
		}

		$targetsToLoad = [];
		foreach ( $targets as $target ) {
			$cachedItem = $this->getCached( $user, $target );
			if ( $cachedItem ) {
				$timestamps[$target->getNamespace()][$target->getDBkey()] =
					$cachedItem->getNotificationTimestamp();
			} else {
				$targetsToLoad[] = $target;
			}
		}

		if ( !$targetsToLoad ) {
			return $timestamps;
		}

		$dbr = $this->getConnectionRef( DB_REPLICA );

		$lb = new LinkBatch( $targetsToLoad );
		$res = $dbr->select(
			'watchlist',
			[ 'wl_namespace', 'wl_title', 'wl_notificationtimestamp' ],
			[
				$lb->constructSet( 'wl', $dbr ),
				'wl_user' => $user->getId(),
			],
			__METHOD__
		);

		foreach ( $res as $row ) {
			$timestamps[$row->wl_namespace][$row->wl_title] =
				wfTimestampOrNull( TS_MW, $row->wl_notificationtimestamp );
		}

		return $timestamps;
	}

	/**
	 * Must be called separately for Subject & Talk namespaces
	 *
	 * @param User $user
	 * @param LinkTarget $target
	 */
	public function addWatch( User $user, LinkTarget $target ) {
		$this->addWatchBatchForUser( $user, [ $target ] );
	}

	/**
	 * @param User $user
	 * @param LinkTarget[] $targets
	 *
	 * @return bool success
	 */
	public function addWatchBatchForUser( User $user, array $targets ) {
		if ( $this->readOnlyMode->isReadOnly() ) {
			return false;
		}
		// Only loggedin user can have a watchlist
		if ( $user->isAnon() ) {
			return false;
		}

		if ( !$targets ) {
			return true;
		}

		$rows = [];
		$items = [];
		foreach ( $targets as $target ) {
			$rows[] = [
				'wl_user' => $user->getId(),
				'wl_namespace' => $target->getNamespace(),
				'wl_title' => $target->getDBkey(),
				'wl_notificationtimestamp' => null,
			];
			$items[] = new WatchedItem(
				$user,
				$target,
				null
			);
			$this->uncache( $user, $target );
		}

		$dbw = $this->getConnectionRef( DB_MASTER );
		foreach ( array_chunk( $rows, 100 ) as $toInsert ) {
			// Use INSERT IGNORE to avoid overwriting the notification timestamp
			// if there's already an entry for this page
			$dbw->insert( 'watchlist', $toInsert, __METHOD__, 'IGNORE' );
		}
		// Update process cache to ensure skin doesn't claim that the current
		// page is unwatched in the response of action=watch itself (T28292).
		// This would otherwise be re-queried from a slave by isWatched().
		foreach ( $items as $item ) {
			$this->cache( $item );
		}

		return true;
	}

	/**
	 * Removes the an entry for the User watching the LinkTarget
	 * Must be called separately for Subject & Talk namespaces
	 *
	 * @param User $user
	 * @param LinkTarget $target
	 *
	 * @return bool success
	 * @throws DBUnexpectedError
	 * @throws MWException
	 */
	public function removeWatch( User $user, LinkTarget $target ) {
		// Only logged in user can have a watchlist
		if ( $this->readOnlyMode->isReadOnly() || $user->isAnon() ) {
			return false;
		}

		$this->uncache( $user, $target );

		$dbw = $this->getConnectionRef( DB_MASTER );
		$dbw->delete( 'watchlist',
			[
				'wl_user' => $user->getId(),
				'wl_namespace' => $target->getNamespace(),
				'wl_title' => $target->getDBkey(),
			], __METHOD__
		);
		$success = (bool)$dbw->affectedRows();

		return $success;
	}

	/**
	 * @param User $user The user to set the timestamp for
	 * @param string|null $timestamp Set the update timestamp to this value
	 * @param LinkTarget[] $targets List of targets to update. Default to all targets
	 *
	 * @return bool success
	 */
	public function setNotificationTimestampsForUser( User $user, $timestamp, array $targets = [] ) {
		// Only loggedin user can have a watchlist
		if ( $user->isAnon() ) {
			return false;
		}

		$dbw = $this->getConnectionRef( DB_MASTER );

		$conds = [ 'wl_user' => $user->getId() ];
		if ( $targets ) {
			$batch = new LinkBatch( $targets );
			$conds[] = $batch->constructSet( 'wl', $dbw );
		}

		if ( $timestamp !== null ) {
			$timestamp = $dbw->timestamp( $timestamp );
		}

		$success = $dbw->update(
			'watchlist',
			[ 'wl_notificationtimestamp' => $timestamp ],
			$conds,
			__METHOD__
		);

		$this->uncacheUser( $user );

		return $success;
	}

	/**
	 * @param User $editor The editor that triggered the update. Their notification
	 *  timestamp will not be updated(they have already seen it)
	 * @param LinkTarget $target The target to update timestamps for
	 * @param string $timestamp Set the update timestamp to this value
	 *
	 * @return int[] Array of user IDs the timestamp has been updated for
	 */
	public function updateNotificationTimestamp( User $editor, LinkTarget $target, $timestamp ) {
		$dbw = $this->getConnectionRef( DB_MASTER );
		$uids = $dbw->selectFieldValues(
			'watchlist',
			'wl_user',
			[
				'wl_user != ' . intval( $editor->getId() ),
				'wl_namespace' => $target->getNamespace(),
				'wl_title' => $target->getDBkey(),
				'wl_notificationtimestamp IS NULL',
			],
			__METHOD__
		);

		$watchers = array_map( 'intval', $uids );
		if ( $watchers ) {
			// Update wl_notificationtimestamp for all watching users except the editor
			$fname = __METHOD__;
			DeferredUpdates::addCallableUpdate(
				function () use ( $timestamp, $watchers, $target, $fname ) {
					global $wgUpdateRowsPerQuery;

					$dbw = $this->getConnectionRef( DB_MASTER );
					$factory = MediaWikiServices::getInstance()->getDBLoadBalancerFactory();
					$ticket = $factory->getEmptyTransactionTicket( __METHOD__ );

					$watchersChunks = array_chunk( $watchers, $wgUpdateRowsPerQuery );
					foreach ( $watchersChunks as $watchersChunk ) {
						$dbw->update( 'watchlist',
							[ /* SET */
								'wl_notificationtimestamp' => $dbw->timestamp( $timestamp )
							], [ /* WHERE - TODO Use wl_id T130067 */
								'wl_user' => $watchersChunk,
								'wl_namespace' => $target->getNamespace(),
								'wl_title' => $target->getDBkey(),
							], $fname
						);
						if ( count( $watchersChunks ) > 1 ) {
							$factory->commitAndWaitForReplication(
								__METHOD__, $ticket, [ 'domain' => $dbw->getDomainID() ]
							);
						}
					}
					$this->uncacheLinkTarget( $target );
				},
				DeferredUpdates::POSTSEND,
				$dbw
			);
		}

		return $watchers;
	}

	/**
	 * Reset the notification timestamp of this entry
	 *
	 * @param User $user
	 * @param Title $title
	 * @param string $force Whether to force the write query to be executed even if the
	 *    page is not watched or the notification timestamp is already NULL.
	 *    'force' in order to force
	 * @param int $oldid The revision id being viewed. If not given or 0, latest revision is assumed.
	 *
	 * @return bool success
	 */
	public function resetNotificationTimestamp( User $user, Title $title, $force = '', $oldid = 0 ) {
		// Only loggedin user can have a watchlist
		if ( $this->readOnlyMode->isReadOnly() || $user->isAnon() ) {
			return false;
		}

		$item = null;
		if ( $force != 'force' ) {
			$item = $this->loadWatchedItem( $user, $title );
			if ( !$item || $item->getNotificationTimestamp() === null ) {
				return false;
			}
		}

		// If the page is watched by the user (or may be watched), update the timestamp
		$job = new ActivityUpdateJob(
			$title,
			[
				'type'      => 'updateWatchlistNotification',
				'userid'    => $user->getId(),
				'notifTime' => $this->getNotificationTimestamp( $user, $title, $item, $force, $oldid ),
				'curTime'   => time()
			]
		);

		// Try to run this post-send
		// Calls DeferredUpdates::addCallableUpdate in normal operation
		call_user_func(
			$this->deferredUpdatesAddCallableUpdateCallback,
			function () use ( $job ) {
				$job->run();
			}
		);

		$this->uncache( $user, $title );

		return true;
	}

	private function getNotificationTimestamp( User $user, Title $title, $item, $force, $oldid ) {
		if ( !$oldid ) {
			// No oldid given, assuming latest revision; clear the timestamp.
			return null;
		}

		if ( !$title->getNextRevisionID( $oldid ) ) {
			// Oldid given and is the latest revision for this title; clear the timestamp.
			return null;
		}

		if ( $item === null ) {
			$item = $this->loadWatchedItem( $user, $title );
		}

		if ( !$item ) {
			// This can only happen if $force is enabled.
			return null;
		}

		// Oldid given and isn't the latest; update the timestamp.
		// This will result in no further notification emails being sent!
		// Calls Revision::getTimestampFromId in normal operation
		$notificationTimestamp = call_user_func(
			$this->revisionGetTimestampFromIdCallback,
			$title,
			$oldid
		);

		// We need to go one second to the future because of various strict comparisons
		// throughout the codebase
		$ts = new MWTimestamp( $notificationTimestamp );
		$ts->timestamp->add( new DateInterval( 'PT1S' ) );
		$notificationTimestamp = $ts->getTimestamp( TS_MW );

		if ( $notificationTimestamp < $item->getNotificationTimestamp() ) {
			if ( $force != 'force' ) {
				return false;
			} else {
				// This is a little silly…
				return $item->getNotificationTimestamp();
			}
		}

		return $notificationTimestamp;
	}

	/**
	 * @param User $user
	 * @param int $unreadLimit
	 *
	 * @return int|bool The number of unread notifications
	 *                  true if greater than or equal to $unreadLimit
	 */
	public function countUnreadNotifications( User $user, $unreadLimit = null ) {
		$queryOptions = [];
		if ( $unreadLimit !== null ) {
			$unreadLimit = (int)$unreadLimit;
			$queryOptions['LIMIT'] = $unreadLimit;
		}

		$dbr = $this->getConnectionRef( DB_REPLICA );
		$rowCount = $dbr->selectRowCount(
			'watchlist',
			'1',
			[
				'wl_user' => $user->getId(),
				'wl_notificationtimestamp IS NOT NULL',
			],
			__METHOD__,
			$queryOptions
		);

		if ( !isset( $unreadLimit ) ) {
			return $rowCount;
		}

		if ( $rowCount >= $unreadLimit ) {
			return true;
		}

		return $rowCount;
	}

	/**
	 * Check if the given title already is watched by the user, and if so
	 * add a watch for the new title.
	 *
	 * To be used for page renames and such.
	 *
	 * @param LinkTarget $oldTarget
	 * @param LinkTarget $newTarget
	 */
	public function duplicateAllAssociatedEntries( LinkTarget $oldTarget, LinkTarget $newTarget ) {
		$oldTarget = Title::newFromLinkTarget( $oldTarget );
		$newTarget = Title::newFromLinkTarget( $newTarget );

		$this->duplicateEntry( $oldTarget->getSubjectPage(), $newTarget->getSubjectPage() );
		$this->duplicateEntry( $oldTarget->getTalkPage(), $newTarget->getTalkPage() );
	}

	/**
	 * Check if the given title already is watched by the user, and if so
	 * add a watch for the new title.
	 *
	 * To be used for page renames and such.
	 * This must be called separately for Subject and Talk pages
	 *
	 * @param LinkTarget $oldTarget
	 * @param LinkTarget $newTarget
	 */
	public function duplicateEntry( LinkTarget $oldTarget, LinkTarget $newTarget ) {
		$dbw = $this->getConnectionRef( DB_MASTER );

		$result = $dbw->select(
			'watchlist',
			[ 'wl_user', 'wl_notificationtimestamp' ],
			[
				'wl_namespace' => $oldTarget->getNamespace(),
				'wl_title' => $oldTarget->getDBkey(),
			],
			__METHOD__,
			[ 'FOR UPDATE' ]
		);

		$newNamespace = $newTarget->getNamespace();
		$newDBkey = $newTarget->getDBkey();

		# Construct array to replace into the watchlist
		$values = [];
		foreach ( $result as $row ) {
			$values[] = [
				'wl_user' => $row->wl_user,
				'wl_namespace' => $newNamespace,
				'wl_title' => $newDBkey,
				'wl_notificationtimestamp' => $row->wl_notificationtimestamp,
			];
		}

		if ( !empty( $values ) ) {
			# Perform replace
			# Note that multi-row replace is very efficient for MySQL but may be inefficient for
			# some other DBMSes, mostly due to poor simulation by us
			$dbw->replace(
				'watchlist',
				[ [ 'wl_user', 'wl_namespace', 'wl_title' ] ],
				$values,
				__METHOD__
			);
		}
	}

}
