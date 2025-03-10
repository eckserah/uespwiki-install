<?php

class SpecialAbuseLog extends SpecialPage {
	/**
	 * @var User
	 */
	protected $mSearchUser;

	/**
	 * @var Title
	 */
	protected $mSearchTitle;

	protected $mSearchWiki;

	protected $mSearchFilter;

	protected $mSearchEntries;

	public function __construct() {
		parent::__construct( 'AbuseLog', 'abusefilter-log' );
	}

	public function doesWrites() {
		return true;
	}

	public function execute( $parameter ) {
		$out = $this->getOutput();
		$request = $this->getRequest();

		AbuseFilter::addNavigationLinks( $this->getContext(), 'log' );

		$this->setHeaders();
		$this->outputHeader( 'abusefilter-log-summary' );
		$this->loadParameters();

		$out->setPageTitle( $this->msg( 'abusefilter-log' ) );
		$out->setRobotPolicy( "noindex,nofollow" );
		$out->setArticleRelated( false );
		$out->enableClientCache( false );

		$out->addModuleStyles( 'ext.abuseFilter' );

		// Are we allowed?
		$errors = $this->getPageTitle()->getUserPermissionsErrors(
			'abusefilter-log', $this->getUser(), true, [ 'ns-specialprotected' ] );
		if ( count( $errors ) ) {
			// Go away.
			$out->showPermissionsErrorPage( $errors, 'abusefilter-log' );

			return;
		}

		$detailsid = $request->getIntOrNull( 'details' );
		$hideid = $request->getIntOrNull( 'hide' );

		if ( $parameter ) {
			$detailsid = $parameter;
		}

		if ( $detailsid ) {
			$this->showDetails( $detailsid );
		} elseif ( $hideid ) {
			$this->showHideForm( $hideid );
		} else {
			// Show the search form.
			$this->searchForm();

			// Show the log itself.
			$this->showList();
		}
	}

	function loadParameters() {
		global $wgAbuseFilterIsCentral;

		$request = $this->getRequest();

		$this->mSearchUser = trim( $request->getText( 'wpSearchUser' ) );
		if ( $wgAbuseFilterIsCentral ) {
			$this->mSearchWiki = $request->getText( 'wpSearchWiki' );
		}

		$u = User::newFromName( $this->mSearchUser );
		if ( $u ) {
			$this->mSearchUser = $u->getName(); // Username normalisation
		} elseif ( IP::isIPAddress( $this->mSearchUser ) ) {
			// It's an IP
			$this->mSearchUser = IP::sanitizeIP( $this->mSearchUser );
		} else {
			$this->mSearchUser = null;
		}

		$this->mSearchTitle = $request->getText( 'wpSearchTitle' );
		$this->mSearchFilter = null;
		if ( self::canSeeDetails() ) {
			$this->mSearchFilter = $request->getText( 'wpSearchFilter' );
		}

		$this->mSearchEntries = $request->getText( 'wpSearchEntries' );
	}

	function searchForm() {
		global $wgAbuseFilterIsCentral;

		$formDescriptor = [
			'SearchUser' => [
				'label-message' => 'abusefilter-log-search-user',
				'type' => 'user',
				'default' => $this->mSearchUser,
			],
			'SearchTitle' => [
				'label-message' => 'abusefilter-log-search-title',
				'type' => 'title',
				'default' => $this->mSearchTitle,
			]
		];
		if ( self::canSeeDetails() ) {
			$formDescriptor['SearchFilter'] = [
				'label-message' => 'abusefilter-log-search-filter',
				'type' => 'text',
				'default' => $this->mSearchFilter,
			];
		}
		if ( $wgAbuseFilterIsCentral ) {
			// Add free form input for wiki name. Would be nice to generate
			// a select with unique names in the db at some point.
			$formDescriptor['SearchWiki'] = [
				'label-message' => 'abusefilter-log-search-wiki',
				'type' => 'text',
				'default' => $this->mSearchWiki,
			];
		}
		if ( self::canSeeHidden() ) {
			$formDescriptor['SearchEntries'] = [
				'type' => 'select',
				'label-message' => 'abusefilter-log-search-entries-label',
				'options' => [
					$this->msg( 'abusefilter-log-search-entries-all' )->text() => 0,
					$this->msg( 'abusefilter-log-search-entries-hidden' )->text() => 1,
					$this->msg( 'abusefilter-log-search-entries-visible' )->text() => 2,
				],
			];
		}

		$htmlForm = HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext() )
			->setWrapperLegendMsg( 'abusefilter-log-search' )
			->setSubmitTextMsg( 'abusefilter-log-search-submit' )
			->setMethod( 'get' )
			->prepareForm()
			->displayForm( false );
	}

	/**
	 * @param $id
	 * @return mixed
	 */
	function showHideForm( $id ) {
		if ( !$this->getUser()->isAllowed( 'abusefilter-hide-log' ) ) {
			$this->getOutput()->addWikiMsg( 'abusefilter-log-hide-forbidden' );

			return;
		}

		$dbr = wfGetDB( DB_REPLICA );

		$row = $dbr->selectRow(
			[ 'abuse_filter_log', 'abuse_filter' ],
			'*',
			[ 'afl_id' => $id ],
			__METHOD__,
			[],
			[ 'abuse_filter' => [ 'LEFT JOIN', 'af_id=afl_filter' ] ]
		);

		if ( !$row ) {
			return;
		}

		$formInfo = [
			'logid' => [
				'type' => 'info',
				'default' => $id,
				'label-message' => 'abusefilter-log-hide-id',
			],
			'reason' => [
				'type' => 'text',
				'label-message' => 'abusefilter-log-hide-reason',
			],
			'hidden' => [
				'type' => 'toggle',
				'default' => $row->afl_deleted,
				'label-message' => 'abusefilter-log-hide-hidden',
			],
		];

		$form = new HTMLForm( $formInfo, $this->getContext() );
		$form->setTitle( $this->getPageTitle() );
		$form->setWrapperLegend( $this->msg( 'abusefilter-log-hide-legend' )->text() );
		$form->addHiddenField( 'hide', $id );
		$form->setSubmitCallback( [ $this, 'saveHideForm' ] );
		$form->show();
	}

	/**
	 * @param $fields
	 * @return bool
	 */
	function saveHideForm( $fields ) {
		$logid = $this->getRequest()->getVal( 'hide' );

		$dbw = wfGetDB( DB_MASTER );

		$dbw->update(
			'abuse_filter_log',
			[ 'afl_deleted' => $fields['hidden'] ],
			[ 'afl_id' => $logid ],
			__METHOD__
		);

		$logPage = new LogPage( 'suppress' );
		$action = $fields['hidden'] ? 'hide-afl' : 'unhide-afl';

		$logPage->addEntry( $action, $this->getPageTitle( $logid ), $fields['reason'] );

		$this->getOutput()->redirect( SpecialPage::getTitleFor( 'AbuseLog' )->getFullURL() );

		return true;
	}

	function showList() {
		$out = $this->getOutput();

		// Generate conditions list.
		$conds = [];

		if ( $this->mSearchUser ) {
			$user = User::newFromName( $this->mSearchUser );

			if ( !$user ) {
				$conds['afl_user'] = 0;
				$conds['afl_user_text'] = $this->mSearchUser;
			} else {
				$conds['afl_user'] = $user->getId();
				$conds['afl_user_text'] = $user->getName();
			}
		}

		if ( $this->mSearchWiki ) {
			if ( $this->mSearchWiki == wfWikiID() ) {
				$conds['afl_wiki'] = null;
			} else {
				$conds['afl_wiki'] = $this->mSearchWiki;
			}
		}

		if ( $this->mSearchFilter ) {
			$searchFilters = array_map( 'trim', explode( '|', $this->mSearchFilter ) );
			// if a filter is hidden, users who can't view private filters should
			// not be able to find log entries generated by it.
			if ( !AbuseFilterView::canViewPrivate()
				&& !$this->getUser()->isAllowed( 'abusefilter-log-private' )
			) {
				$searchedForPrivate = false;
				foreach ( $searchFilters as $index => $filter ) {
					if ( AbuseFilter::filterHidden( $filter ) ) {
						unset( $searchFilters[$index] );
						$searchedForPrivate = true;
					}
				}
				if ( $searchedForPrivate ) {
					$out->addWikiMsg( 'abusefilter-log-private-not-included' );
				}
			}
			if ( empty( $searchFilters ) ) {
				$out->addWikiMsg( 'abusefilter-log-noresults' );

				return;
			}
			$conds['afl_filter'] = $searchFilters;
		}

		$searchTitle = Title::newFromText( $this->mSearchTitle );
		if ( $this->mSearchTitle && $searchTitle ) {
			$conds['afl_namespace'] = $searchTitle->getNamespace();
			$conds['afl_title'] = $searchTitle->getDBkey();
		}

		if ( self::canSeeHidden() ) {
			if ( $this->mSearchEntries == '1' ) {
				$conds['afl_deleted'] = 1;
			} elseif ( $this->mSearchEntries == '2' ) {
				$conds[] = self::getNotDeletedCond( wfGetDB( DB_REPLICA ) );
			}
		}

		$pager = new AbuseLogPager( $this, $conds );
		$pager->doQuery();
		$result = $pager->getResult();
		if ( $result && $result->numRows() !== 0 ) {
			$out->addHTML( $pager->getNavigationBar() .
				Xml::tags( 'ul', [ 'class' => 'plainlinks' ], $pager->getBody() ) .
				$pager->getNavigationBar() );
		} else {
			$out->addWikiMsg( 'abusefilter-log-noresults' );
		}
	}

	/**
	 * @param $id
	 * @return mixed
	 */
	function showDetails( $id ) {
		$out = $this->getOutput();

		$dbr = wfGetDB( DB_REPLICA );

		$row = $dbr->selectRow(
			[ 'abuse_filter_log', 'abuse_filter' ],
			'*',
			[ 'afl_id' => $id ],
			__METHOD__,
			[],
			[ 'abuse_filter' => [ 'LEFT JOIN', 'af_id=afl_filter' ] ]
		);

		if ( !$row ) {
			$out->addWikiMsg( 'abusefilter-log-nonexistent' );

			return;
		}

		if ( AbuseFilter::decodeGlobalName( $row->afl_filter ) ) {
			$filter_hidden = null;
		} else {
			$filter_hidden = $row->af_hidden;
		}

		if ( !self::canSeeDetails( $row->afl_filter, $filter_hidden ) ) {
			$out->addWikiMsg( 'abusefilter-log-cannot-see-details' );

			return;
		}

		if ( self::isHidden( $row ) && !self::canSeeHidden() ) {
			$out->addWikiMsg( 'abusefilter-log-details-hidden' );

			return;
		} elseif ( self::isHidden( $row ) === 'implicit' ) {
			$rev = Revision::newFromId( $row->afl_rev_id );
			// The log is visible, but refers to a deleted revision
			if ( !$rev->userCan( Revision::SUPPRESSED_ALL, $this->getUser() ) ) {
				$out->addWikiMsg( 'abusefilter-log-details-hidden-implicit' );
				return;
			}
		}

		$output = Xml::element(
			'legend',
			null,
			$this->msg( 'abusefilter-log-details-legend', $id )->text()
		);
		$output .= Xml::tags( 'p', null, $this->formatRow( $row, false ) );

		// Load data
		$vars = AbuseFilter::loadVarDump( $row->afl_var_dump );
		$out->addJsConfigVars( 'wgAbuseFilterVariables', $vars->dumpAllVars( true ) );

		// Diff, if available
		if ( $vars && $vars->getVar( 'action' )->toString() == 'edit' ) {
			$old_wikitext = $vars->getVar( 'old_wikitext' )->toString();
			$new_wikitext = $vars->getVar( 'new_wikitext' )->toString();

			$diffEngine = new DifferenceEngine( $this->getContext() );

			$diffEngine->showDiffStyle();

			$formattedDiff = $diffEngine->generateTextDiffBody( $old_wikitext, $new_wikitext );
			$formattedDiff = $diffEngine->addHeader( $formattedDiff, '', '' );

			$output .=
				Xml::tags(
					'h3',
					null,
					$this->msg( 'abusefilter-log-details-diff' )->parse()
				);

			$output .= $formattedDiff;
		}

		$output .= Xml::element( 'h3', null, $this->msg( 'abusefilter-log-details-vars' )->text() );

		// Build a table.
		$output .= AbuseFilter::buildVarDumpTable( $vars, $this->getContext() );

		if ( self::canSeePrivate() ) {
			// Private stuff, like IPs.
			$header =
				Xml::element( 'th', null, $this->msg( 'abusefilter-log-details-var' )->text() ) .
				Xml::element( 'th', null, $this->msg( 'abusefilter-log-details-val' )->text() );
			$output .= Xml::element( 'h3', null, $this->msg( 'abusefilter-log-details-private' )->text() );
			$output .=
				Xml::openElement( 'table',
					[
						'class' => 'wikitable mw-abuselog-private',
						'style' => 'width: 80%;'
					]
				) .
				Xml::openElement( 'tbody' );
			$output .= $header;

			// IP address
			$output .=
				Xml::tags( 'tr', null,
					Xml::element( 'td',
						[ 'style' => 'width: 30%;' ],
						$this->msg( 'abusefilter-log-details-ip' )->text()
					) .
					Xml::element( 'td', null, $row->afl_ip )
				);

			$output .= Xml::closeElement( 'tbody' ) . Xml::closeElement( 'table' );
		}

		$output = Xml::tags( 'fieldset', null, $output );

		$out->addHTML( $output );
	}

	/**
	 * @param $filter_id null
	 * @param $filter_hidden null
	 * @return bool
	 */
	static function canSeeDetails( $filter_id = null, $filter_hidden = null ) {
		global $wgUser;

		if ( $filter_id !== null ) {
			if ( $filter_hidden === null ) {
				$filter_hidden = AbuseFilter::filterHidden( $filter_id );
			}
			if ( $filter_hidden ) {
				return $wgUser->isAllowed( 'abusefilter-log-detail' ) && (
					AbuseFilterView::canViewPrivate() || $wgUser->isAllowed( 'abusefilter-log-private' )
				);
			}
		}

		return $wgUser->isAllowed( 'abusefilter-log-detail' );
	}

	/**
	 * @return bool
	 */
	static function canSeePrivate() {
		global $wgUser;

		return $wgUser->isAllowed( 'abusefilter-private' );
	}

	/**
	 * @return bool
	 */
	static function canSeeHidden() {
		global $wgUser;

		return $wgUser->isAllowed( 'abusefilter-hidden-log' );
	}

	/**
	 * @param $row
	 * @param $isListItem bool
	 * @return String
	 */
	function formatRow( $row, $isListItem = true ) {
		$user = $this->getUser();
		$lang = $this->getLanguage();

		$actionLinks = [];

		$title = Title::makeTitle( $row->afl_namespace, $row->afl_title );

		$diffLink = false;
		$isHidden = self::isHidden( $row );

		if ( !self::canSeeHidden() && $isHidden ) {
			return '';
		}

		$linkRenderer = $this->getLinkRenderer();

		if ( !$row->afl_wiki ) {
			$pageLink = $linkRenderer->makeLink( $title );
			if ( $row->afl_rev_id && $title->exists() ) {
				$diffLink = $linkRenderer->makeKnownLink(
					$title,
					new HtmlArmor( $this->msg( 'abusefilter-log-diff' )->parse() ),
					[],
					[ 'diff' => 'prev', 'oldid' => $row->afl_rev_id ] );
			}
		} else {
			$pageLink = WikiMap::makeForeignLink( $row->afl_wiki, $row->afl_title );

			if ( $row->afl_rev_id ) {
				$diffUrl = WikiMap::getForeignURL( $row->afl_wiki, $row->afl_title );
				$diffUrl = wfAppendQuery( $diffUrl,
					[ 'diff' => 'prev', 'oldid' => $row->afl_rev_id ] );

				$diffLink = Linker::makeExternalLink( $diffUrl,
					$this->msg( 'abusefilter-log-diff' )->parse() );
			}
		}

		if ( !$row->afl_wiki ) {
			// Local user
			$userLink = self::getUserLinks( $row->afl_user, $row->afl_user_text );
		} else {
			$userLink = WikiMap::foreignUserLink( $row->afl_wiki, $row->afl_user_text );
			$userLink .= ' (' . WikiMap::getWikiName( $row->afl_wiki ) . ')';
		}

		$timestamp = $lang->timeanddate( $row->afl_timestamp, true );

		$actions_taken = $row->afl_actions;
		if ( !strlen( trim( $actions_taken ) ) ) {
			$actions_taken = $this->msg( 'abusefilter-log-noactions' )->text();
		} else {
			$actions = explode( ',', $actions_taken );
			$displayActions = [];

			foreach ( $actions as $action ) {
				$displayActions[] = AbuseFilter::getActionDisplay( $action );
			}
			$actions_taken = $lang->commaList( $displayActions );
		}

		$globalIndex = AbuseFilter::decodeGlobalName( $row->afl_filter );

		if ( $globalIndex ) {
			// Pull global filter description
			$parsed_comments =
				$this->getOutput()->parseInline( AbuseFilter::getGlobalFilterDescription( $globalIndex ) );
			$filter_hidden = null;
		} else {
			$parsed_comments = $this->getOutput()->parseInline( $row->af_public_comments );
			$filter_hidden = $row->af_hidden;
		}

		if ( self::canSeeDetails( $row->afl_filter, $filter_hidden ) ) {
			if ( $isListItem ) {
				$detailsLink = $linkRenderer->makeKnownLink(
					$this->getPageTitle( $row->afl_id ),
					$this->msg( 'abusefilter-log-detailslink' )->text()
				);
				$actionLinks[] = $detailsLink;
			}

			$examineTitle = SpecialPage::getTitleFor( 'AbuseFilter', 'examine/log/' . $row->afl_id );
			$examineLink = $linkRenderer->makeKnownLink(
				$examineTitle,
				new HtmlArmor( $this->msg( 'abusefilter-changeslist-examine' )->parse() )
			);
			$actionLinks[] = $examineLink;

			if ( $diffLink ) {
				$actionLinks[] = $diffLink;
			}

			if ( $user->isAllowed( 'abusefilter-hide-log' ) ) {
				$hideLink = $linkRenderer->makeKnownLink(
					$this->getPageTitle(),
					$this->msg( 'abusefilter-log-hidelink' )->text(),
					[],
					[ 'hide' => $row->afl_id ]
				);

				$actionLinks[] = $hideLink;
			}

			if ( $globalIndex ) {
				global $wgAbuseFilterCentralDB;
				$globalURL =
					WikiMap::getForeignURL( $wgAbuseFilterCentralDB,
						'Special:AbuseFilter/' . $globalIndex );

				$linkText = $this->msg( 'abusefilter-log-detailedentry-global' )
					->numParams( $globalIndex )->escaped();
				$filterLink = Linker::makeExternalLink( $globalURL, $linkText );
			} else {
				$title = SpecialPage::getTitleFor( 'AbuseFilter', $row->afl_filter );
				$linkText = $this->msg( 'abusefilter-log-detailedentry-local' )
					->numParams( $row->afl_filter )->text();
				$filterLink = $linkRenderer->makeKnownLink( $title, $linkText );
			}
			$description = $this->msg( 'abusefilter-log-detailedentry-meta' )->rawParams(
				$timestamp,
				$userLink,
				$filterLink,
				$row->afl_action,
				$pageLink,
				$actions_taken,
				$parsed_comments,
				$lang->pipeList( $actionLinks )
			)->params( $row->afl_user_text )->parse();
		} else {
			if ( $diffLink ) {
				$msg = 'abusefilter-log-entry-withdiff';
			} else {
				$msg = 'abusefilter-log-entry';
			}
			$description = $this->msg( $msg )->rawParams(
				$timestamp,
				$userLink,
				$row->afl_action,
				$pageLink,
				$actions_taken,
				$parsed_comments,
				$diffLink // Passing $7 to 'abusefilter-log-entry' will do nothing, as it's not used.
			)->params( $row->afl_user_text )->parse();
		}

		if ( $isHidden === true ) {
			$description .= ' ' .
				$this->msg( 'abusefilter-log-hidden' )->parse();
			$class = 'afl-hidden';
		} elseif ( $isHidden === 'implicit' ) {
			$description .= ' ' .
				$this->msg( 'abusefilter-log-hidden-implicit' )->parse();
		}

		if ( $isListItem ) {
			return Xml::tags( 'li', isset( $class ) ? [ 'class' => $class ] : null, $description );
		} else {
			return Xml::tags( 'span', isset( $class ) ? [ 'class' => $class ] : null, $description );
		}
	}

	protected static function getUserLinks( $userId, $userName ) {
		static $cache = [];

		if ( !isset( $cache[$userName][$userId] ) ) {
			$cache[$userName][$userId] = Linker::userLink( $userId, $userName ) .
				Linker::userToolLinks( $userId, $userName, true );
		}

		return $cache[$userName][$userId];
	}

	/**
	 * @param $db DatabaseBase
	 * @return string
	 */
	public static function getNotDeletedCond( $db ) {
		$deletedZeroCond = $db->makeList(
			[ 'afl_deleted' => 0 ], LIST_AND );
		$deletedNullCond = $db->makeList(
			[ 'afl_deleted' => null ], LIST_AND );
		$notDeletedCond = $db->makeList(
			[ $deletedZeroCond, $deletedNullCond ], LIST_OR );

		return $notDeletedCond;
	}

	/**
	 * Given a log entry row, decides whether or not it can be viewed by the public.
	 *
	 * @param $row stdClass The abuse_filter_log row object.
	 *
	 * @return bool|string true if the item is explicitly hidden, false if it is not.
	 *    The string 'implicit' if it is hidden because the corresponding revision is hidden.
	 */
	public static function isHidden( $row ) {
		if ( $row->afl_rev_id ) {
			$revision = Revision::newFromId( $row->afl_rev_id );
			if ( $revision && $revision->getVisibility() != 0 ) {
				return 'implicit';
			}
		}

		return (bool)$row->afl_deleted;
	}

	protected function getGroupName() {
		return 'changes';
	}
}

class AbuseLogPager extends ReverseChronologicalPager {
	/**
	 * @var SpecialAbuseLog
	 */
	public $mForm;

	/**
	 * @var array
	 */
	public $mConds;

	/**
	 * @param SpecialAbuseLog $form
	 * @param array $conds
	 * @param bool $details
	 */
	function __construct( $form, $conds = [], $details = false ) {
		$this->mForm = $form;
		$this->mConds = $conds;
		parent::__construct();
	}

	function formatRow( $row ) {
		return $this->mForm->formatRow( $row );
	}

	function getQueryInfo() {
		$conds = $this->mConds;

		$info = [
			'tables' => [ 'abuse_filter_log', 'abuse_filter' ],
			'fields' => '*',
			'conds' => $conds,
			'join_conds' =>
				[ 'abuse_filter' =>
					[
						'LEFT JOIN',
						'af_id=afl_filter',
					],
				],
		];

		if ( !$this->mForm->canSeeHidden() ) {
			$db = $this->mDb;
			$info['conds'][] = SpecialAbuseLog::getNotDeletedCond( $db );
		}

		return $info;
	}

	/**
	 * @param ResultWrapper $result
	 */
	protected function preprocessResults( $result ) {
		if ( $this->getNumRows() === 0 ) {
			return;
		}

		$lb = new LinkBatch();
		$lb->setCaller( __METHOD__ );
		foreach ( $result as $row ) {
			// Only for local wiki results
			if ( !$row->afl_wiki ) {
				$lb->add( $row->afl_namespace, $row->afl_title );
				$lb->add( NS_USER,  $row->afl_user );
				$lb->add( NS_USER_TALK, $row->afl_user_text );
			}
		}
		$lb->execute();
		$result->seek( 0 );
	}

	function getIndexField() {
		return 'afl_timestamp';
	}
}
