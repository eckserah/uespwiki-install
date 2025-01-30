<?php

use MediaWiki\MediaWikiServices;
use Wikimedia\Rdbms\IDatabase;

/**
 * Handles all SQL-related functions for MetaTemplate. For the time being, write methods to a read-only database will
 * simply fail silently, as did the original, though they will return at least some minimal indication of failure.
 */
class MetaTemplateSql
{
	#region Public Constants
	public const D_SET_ID = self::DATA_ALIAS . '.' . self::FIELD_SET_ID;
	public const D_VAR_NAME = self::DATA_ALIAS . '.' . self::FIELD_VAR_NAME;
	public const D_VAR_VALUE = self::DATA_ALIAS . '.' . self::FIELD_VAR_VALUE;
	public const DATA_ALIAS = 'd';

	public const FIELD_PAGE_ID = 'pageId';
	public const FIELD_REV_ID = 'revId';
	public const FIELD_SET_ID = 'setId';
	public const FIELD_SET_NAME = 'setName';
	public const FIELD_VAR_NAME = 'varName';
	public const FIELD_VAR_VALUE = 'varValue';

	public const S_PAGE_ID = self::SET_ALIAS . '.' . self::FIELD_PAGE_ID;
	public const S_SET_ID = self::SET_ALIAS . '.' . self::FIELD_SET_ID;
	public const SET_ALIAS = 's';

	public const TABLE_DATA = 'mtSaveData';
	public const TABLE_DATA_ALIAS = [self::DATA_ALIAS => self::TABLE_DATA];
	public const TABLE_SET = 'mtSaveSet';
	public const TABLE_SET_ALIAS = [self::SET_ALIAS => self::TABLE_SET];
	#endregion

	#region Private Constants
	private const OLDTABLE_SET = 'mt_save_set';
	private const OLDTABLE_DATA = 'mt_save_data';
	#endregion

	#region Private Static Variables
	/** @var MetaTemplateSql */
	private static $instance;

	/**
	 * A list of all the pages saved during this session to avoid looping.
	 *
	 * @var array
	 */
	private static $pagesSaved = [];
	#endregion

	#region Private Variables
	/** @var IDatabase */
	private $dbRead;

	/** @var IDatabase */
	private $dbWrite;
	#endregion

	#region Constructor (private)
	/**
	 * Creates an instance of the MetaTemplateSql class.
	 */
	private function __construct()
	{
		$dbWriteConst = defined('DB_PRIMARY') ? 'DB_PRIMARY' : 'DB_MASTER';
		$lb = MediaWikiServices::getInstance()->getDBLoadBalancer();
		$this->dbRead = $lb->getConnectionRef(DB_REPLICA);

		// We get dbWrite lazily since writing will often be unnecessary.
		$this->dbWrite = $lb->getConnectionRef(constant($dbWriteConst));
	}
	#endregion

	#region Public Static Functions
	/**
	 * Gets the global singleton instance of the class.
	 *
	 * @return MetaTemplateSql
	 */
	public static function getInstance(): MetaTemplateSql
	{
		if (!isset(self::$instance)) {
			self::$instance = new self;
		}

		return self::$instance;
	}

	/**
	 * Gets the query information for PagesWithMetaVars but does not actually run the query.
	 *
	 * @param string|null The namespace number to query.
	 * @param string|null The set to look for (main set if null or an empty string).
	 * @param string|null The variable to search for.
	 *
	 * @return array A standard query array.
	 */
	public static function getPageswWithMetaVarsQueryInfo(?string $nsNum, ?string $setName, ?string $varName): array
	{
		$tables = array_merge(
			['page'],
			self::TABLE_SET_ALIAS
		);
		$fields = [
			'page.page_id',
			'page.page_namespace',
			'page.page_title',
			'page.page_len',
			'page.page_is_redirect',
			'page.page_latest',
			self::FIELD_SET_NAME,
		];
		$conds = [];
		$joinConds = [self::SET_ALIAS => ['JOIN', 'page.page_id = ' . self::S_PAGE_ID]];
		$setName = $setName ?? '*';
		if ($setName === '*' || isset($varName)) {
			$tables = array_merge($tables, self::TABLE_DATA_ALIAS);
			$fields[] = self::FIELD_VAR_NAME;
			$fields[] = self::FIELD_VAR_VALUE;
			$conds[self::FIELD_VAR_NAME] =  $varName;
			$joinConds[self::DATA_ALIAS] = ['JOIN', self::S_SET_ID . ' = ' . self::D_SET_ID];
		}

		if ($setName !== '*') {
			$conds[self::FIELD_SET_NAME] = $setName;
		}

		if (
			$nsNum !== null && $nsNum !== 'all'
		) {
			$conds['page_namespace'] = $nsNum;
		}

		$retval = [
			'tables' => $tables,
			'fields' => $fields,
			'conds' => $conds,
			'join_conds' => $joinConds
		];

		#RHDebug::show('Query values', $retval);
		return $retval;
	}
	#endregion

	#region Public Functions
	/**
	 * Gets all the secondary information necessary for a <catpagetemplate> call.
	 *
	 * @param MetaTemplatePage[] $pages
	 * @param string[] $varNames
	 *
	 * @return void
	 */
	public function catQuery(array $pages, array $variables): void
	{
		[$tables, $fields, $options] = self::baseQuery(self::FIELD_PAGE_ID, self::FIELD_SET_NAME);
		$conds = [self::FIELD_PAGE_ID => array_keys($pages)];
		$joinConds = [self::S_SET_ID . '=' . self::D_SET_ID];
		if (!empty($variables)) {
			// Doing this as part of the join allows the LEFT JOIN functionality to produce empty rows.
			$joinConds[self::FIELD_VAR_NAME] = $variables;
		}

		$joinConds = [self::DATA_ALIAS => ['LEFT JOIN', $joinConds]];
		$rows = $this->dbRead->select($tables, $fields, $conds, __METHOD__, $options, $joinConds);
		for ($row = $rows->fetchRow(); $row; $row = $rows->fetchRow()) {
			$pageId = $row[self::FIELD_PAGE_ID];
			$setName = $row[self::FIELD_SET_NAME];
			$page = $pages[$pageId];

			if (isset($page->sets[$setName])) {
				$set = $page->sets[$setName];
			} else {
				$set = new MetaTemplateSet($setName);
				$page->sets[$setName] = $set;
			}

			$varName = $row[self::FIELD_VAR_NAME];
			if (!is_null($varName)) {
				$set->variables[$varName] = $row[self::FIELD_VAR_VALUE];
			}
		}
	}

	/**
	 * Handles data to be deleted.
	 *
	 * @param Title $title The title of the page to delete from.
	 *
	 * @return bool False if the database is in read-only mode; otherwise, true.
	 */
	public function deleteVariables(int $pageId): bool
	{
		if (wfReadOnly()) {
			return false;
		}

		#RHDebug::writeFile(__METHOD__, ' !!!DELETE!!!');
		// Assumes cascading is in effect to delete TABLE_DATA rows.
		$this->dbWrite->delete(self::TABLE_SET, [self::FIELD_PAGE_ID => $pageId]);
		return true;
	}

	/**
	 * Checks the database to see if the page has any variables defined.
	 *
	 * @param Title $title The title to check.
	 *
	 * @return MetaTemplateSetCollection
	 */
	public function hasPageVariables(int $pageId): bool
	{
		// Sorting is to ensure that we're always using the latest data in the event of redundant data. Any redundant
		// data is tracked with $deleteIds.

		// logFunctionText("($pageId)");
		$tables = [self::TABLE_SET];
		$fields = [self::FIELD_PAGE_ID];
		$options = ['LIMIT' => 1];
		$conds = [self::FIELD_PAGE_ID => $pageId];
		$result = $this->dbRead->select($tables, $fields, $conds, __METHOD__, $options);
		$row = $result->fetchRow();
		return (bool)$row;
	}

	/**
	 * Gets all data for listsaved, limited by the parameters provided.
	 *
	 * @param ?int $namespace The integer namespace to restrict results to.
	 * @param ?string $setName The name of the set to be filtered to. (Null means no filter.)
	 * @param string[] $fieldNames An array of field names to limit the query to if the entire set is too much.
	 * @param array<string,string> $conditions An array of key=>value strings to use for query conditions.
	 *
	 * @return array An array of page row data indexed by Page ID.
	 */
	public function loadListSavedData(?int $namespace, ?string $setName, array $fieldNames, array $conditions): array
	{
		// Page fields other than title and namespace are here so Title doesn't have to reload them again later on.
		$tables = array_merge(
			['page'],
			self::TABLE_SET_ALIAS,
			self::TABLE_DATA_ALIAS
		);

		$fields = [
			'page.page_title',
			'page.page_namespace',
			self::S_PAGE_ID,
			self::S_SET_ID,
			self::FIELD_SET_NAME,
			self::D_VAR_NAME,
			self::D_VAR_VALUE
		];

		$options = ['ORDER BY' => [
			self::FIELD_PAGE_ID,
			self::FIELD_SET_NAME,
			self::FIELD_REV_ID
		]];

		$conds = [];
		if (!is_null($namespace)) {
			$conds['page.page_namespace'] = $namespace;
		}

		if (!is_null($setName)) {
			$conds = [self::FIELD_SET_NAME => $setName];
		}

		if (!empty($fieldNames)) {
			$conds[self::D_VAR_NAME] = $fieldNames;
		}

		$joinConds = [
			self::SET_ALIAS => ['JOIN', ['page.page_id=' . self::S_PAGE_ID]],
			self::DATA_ALIAS => ['JOIN', [self::S_SET_ID . '=' . self::D_SET_ID]]
		];

		$filter = 1;
		foreach ($conditions as $key => $value) {
			$filterName = 'filter' . $filter;
			$tables[$filterName] = self::TABLE_DATA;
			$joinConds[$filterName] = ['JOIN', [self::S_SET_ID . '=' . $filterName . '.setId']];
			$conds[$filterName . '.' . self::FIELD_VAR_NAME] = $key;
			$conds[$filterName . '.' . self::FIELD_VAR_VALUE] = $value;
			++$filter;
		}

		#RHDebug::show(__METHOD__, $this->dbRead->selectSQLText($tables, $fields, $conds, __METHOD__, $options, $joinConds));
		$rows = $this->dbRead->select($tables, $fields, $conds, __METHOD__, $options, $joinConds);

		$retval = [];
		$prevPageId = 0;
		$prevSetId = 0;
		for ($row = $rows->fetchRow(); $row; $row = $rows->fetchRow()) {
			if ($row[self::FIELD_PAGE_ID] != $prevPageId || $row[self::FIELD_SET_ID] != $prevSetId) {
				if (isset($data)) {
					$retval[] = $data;
				}

				$prevPageId = $row[self::FIELD_PAGE_ID];
				$prevSetId = $row[self::FIELD_SET_ID];

				// newFromRow() is overkill here, since we're just parsing ns and title.
				$title = Title::makeTitle($row['page_namespace'], $row['page_title']);
				$data = [];
				$data[MetaTemplate::$mwFullPageName] = $title->getPrefixedText();
				$data[MetaTemplate::$mwNamespace] = $title->getNsText();
				$data[MetaTemplate::$mwPageId] = $row[self::FIELD_PAGE_ID];
				$data[MetaTemplate::$mwPageName] = $title->getText();
				$data[MetaTemplateData::$mwSet] = $row[self::FIELD_SET_NAME];
			}

			$varValue = $row[self::FIELD_VAR_VALUE];
			$data[$row[self::FIELD_VAR_NAME]] = $varValue;
		}

		if (isset($data)) {
			$retval[] = $data;
		}

		return $retval;
	}

	/**
	 * Gets all data for listsaved, limited by the parameters provided.
	 *
	 * @param ?int[] $pageIds The page ids to filter to. (Null means no filter.)
	 * @param ?string $setName The name of the set to filter to. (Null means no filter.)
	 * @param ?string[] $fieldNames The field names to filter to. (Null means no filter.)
	 *
	 * @return array The returned rows.
	 */
	public function loadPreloadData(?array $pageIds, ?string $setName, array $fieldNames): array
	{
		// Page fields other than title and namespace are here so Title doesn't have to reload them again later on.
		$tables = array_merge(
			self::TABLE_SET_ALIAS,
			self::TABLE_DATA_ALIAS
		);

		$fields = [
			self::S_PAGE_ID,
			self::FIELD_SET_NAME,
			self::D_VAR_NAME,
			self::D_VAR_VALUE
		];

		$conds = [];
		if (!is_null($pageIds)) {
			$conds[self::S_PAGE_ID] = $pageIds;
		}

		if (!is_null($setName)) {
			$conds[self::FIELD_SET_NAME] = $setName;
		}

		if (!empty($fieldNames)) {
			$conds[self::D_VAR_NAME] = $fieldNames;
		}

		$joinConds = [
			self::DATA_ALIAS => ['JOIN', [self::S_SET_ID . '=' . self::D_SET_ID]]
		];

		#RHDebug::show(__METHOD__, $this->dbRead->selectSQLText($tables, $fields, $conds, __METHOD__, [], $joinConds));
		$result = $this->dbRead->select($tables, $fields, $conds, __METHOD__, [], $joinConds);

		// Because we don't always know what fields we're expecting, we can't use fetchRow, which pollutes the output
		// with indexed values and has no option not to. So instead, we use fetchObject and convert it to an array.
		$retval = [];
		for ($row = $result->fetchObject(); $row; $row = $result->fetchObject()) {
			$retval[] = (array)$row;
		}

		return $retval;
	}

	/**
	 * Loads variables from the database.
	 *
	 * @param int $pageId The page ID to load.
	 * @param MetaTemplateSet $set The set to load.
	 *
	 * @return bool True if results were found; otherwise, false.
	 */
	public function loadSetFromPage(int $pageId, MetaTemplateSet &$set): bool
	{
		// No variables asked for means load all, but when specific values are asked for, we only want to load the ones
		// we don't already have.
		$loadSet = [];
		if (count($set->variables)) {
			// If specific variables were called for, iterate through them and add them to the set to be loaded if they
			// don't already have a value.
			foreach ($set->variables as $varName => $varValue) {
				if ($varValue === false) {
					$loadSet[$varName] = false;
				}
			}

			// If all of them are already known, there's nothing to do, so return.
			if (!count($loadSet)) {
				return true;
			}
		}

		[$tables, $fields, $options, $joinConds] = self::baseQuery();
		$conds = [
			self::FIELD_PAGE_ID => $pageId,
			self::FIELD_SET_NAME => $set->name ?? ''
		];

		if (count($loadSet)) {
			$conds[self::FIELD_VAR_NAME] = array_keys($loadSet);
		}

		#RHecho($this->dbRead->selectSQLText($tables, $fields, $conds, __METHOD__, $options, $joinConds));
		$result = $this->dbRead->select($tables, $fields, $conds, __METHOD__ . "-$pageId", $options, $joinConds);
		if ($result) {
			$hasResults = false;
			for ($row = $result->fetchRow(); $row; $row = $result->fetchRow()) {
				$hasResults = true;
				// Because the results are sorted by revId, any duplicate variables caused by an update in mid-select
				// will overwrite the older values.
				$varValue = $row[self::FIELD_VAR_VALUE];
				$set->variables[$row[self::FIELD_VAR_NAME]] = $varValue;
			}
		}

		return $hasResults;
	}

	/**
	 * Loads variables from the database.
	 *
	 * @param int $pageId The page ID to load.
	 * @param string $setName The set to load.
	 *
	 * @return MetaTemplateSet[] The sets found with the requested name.
	 */
	public function loadSetsFromPage(int $pageId, array $varNames = null): array
	{
		[$tables, $fields, $options, $joinConds] = self::baseQuery(self::FIELD_SET_NAME);
		$conds = [self::FIELD_PAGE_ID => $pageId];
		if ($varNames && count($varNames)) {
			$conds[self::FIELD_VAR_NAME] = $varNames;
		}

		// We don't have page info yet, so this doesn't make sense to be put into a MetaTemplatePage object here.
		// Instead, we stick to an array of MetaTemplateSet objects.
		$sets = [];
		#RHshow('Query', $this->dbRead->selectSQLText($tables, $fields, $conds, __METHOD__, $options, $joinConds));
		$result = $this->dbRead->select($tables, $fields, $conds, __METHOD__ . "-$pageId", $options, $joinConds);
		if ($result) {
			for ($row = $result->fetchRow(); $row; $row = $result->fetchRow()) {
				$setName = $row[self::FIELD_SET_NAME];
				if (isset($sets[$setName])) {
					$set = $sets[$setName];
				} else {
					$set = new MetaTemplateSet($setName);
					$sets[$setName] = $set;
				}

				$varValue = $row[self::FIELD_VAR_VALUE];
				$set->variables[$row[self::FIELD_VAR_NAME]] = $varValue;
			}
		}

		return $sets;
	}

	/**
	 * Migrates the MetaTemplate 1.0 data table to the current version.
	 *
	 * @param DatabaseUpdater $updater
	 * @param string $dir
	 */
	public function migrateTables(): void
	{
		$db = $this->dbWrite;
		if ($db->tableExists(self::OLDTABLE_SET)) {
			$varMap = [
				self::FIELD_PAGE_ID => 'mt_set_page_id',
				self::FIELD_SET_NAME => 'mt_set_subset',
				self::FIELD_REV_ID => 'mt_set_rev_id',
				self::FIELD_SET_ID => 'mt_set_id'
			];
			$db->insertSelect(self::TABLE_SET, self::OLDTABLE_SET, $varMap, 'mt_set_page_id IN (SELECT page_id FROM page)', __METHOD__);
		}

		if ($db->tableExists(self::OLDTABLE_DATA)) {
			$varMap = [
				self::FIELD_SET_ID => 'mt_save_id',
				self::FIELD_VAR_NAME => 'mt_save_varname',
				self::FIELD_VAR_VALUE => 'mt_save_value'
			];
			$db->insertSelect(self::TABLE_DATA, self::OLDTABLE_DATA, $varMap, 'mt_save_id IN (SELECT ' . MetaTemplateSql::FIELD_SET_ID . ' FROM ' . MetaTemplateSql::TABLE_SET . ')', __METHOD__);
		}
	}

	/**
	 * Migrates the old MetaTemplate tables to new ones. The basic functionality is the same, but names and indeces
	 * have been altered and the datestamp removed.
	 *
	 * @param DatabaseUpdater $updater
	 *
	 * @return void
	 */
	public function onLoadExtensionSchemaUpdates(DatabaseUpdater $updater): void
	{
		// Initial table setup/modifications from v1.
		if (wfReadOnly()) {
			return;
		}

		/** @var string $dir  */
		$dir = dirname(__DIR__);
		$dbw = $this->dbWrite;
		$doMigrate = false;
		if (!$dbw->tableExists(self::TABLE_SET)) {
			$doMigrate = $dbw->tableExists(self::OLDTABLE_SET);
			$updater->addExtensionTable(self::TABLE_SET, "$dir/sql/create-" . self::TABLE_SET . '.sql');
		}

		if (!$dbw->tableExists(self::TABLE_DATA)) {
			$doMigrate |= $dbw->tableExists(self::OLDTABLE_DATA);
			$updater->addExtensionTable(self::TABLE_DATA, "$dir/sql/create-" . self::TABLE_DATA . '.sql');
		}

		if ($doMigrate) {
			$updater->addExtensionUpdate([[$this, 'migrateTables']]);
		}
	}

	public function pagerQuery(int $pageId): array
	{
		[$tables,, $options, $joinConds] = self::baseQuery();
		$fields = [
			'CAST(' . self::FIELD_SET_NAME . ' AS CHAR) ' . self::FIELD_SET_NAME,
			'CAST(' . self::FIELD_VAR_NAME . ' AS CHAR) ' . self::FIELD_VAR_NAME,
			self::FIELD_VAR_VALUE
		];

		$conds = [self::FIELD_PAGE_ID => $pageId];

		// Transactions should make sure this never happens, but in the event that we got more than one rev_id back,
		// ensure that we start with the lowest first, so data is overridden by the most recent values once we get
		// there, but lower values will exist if the write is incomplete.

		#RHecho($this->dbRead->selectSQLText($tables, $fields, $conds, __METHOD__, $options, $joinConds))
		return [
			'tables' => $tables,
			'fields' => $fields,
			'conds' => $conds,
			'options' => $options,
			'join_conds' => $joinConds
		];
	}

	/**
	 * Saves all upserts and purges any dependent pages.
	 *
	 * @param Title $title The title where the data is being saved.
	 * @param MetaTemplateSetCollection $vars The sets to be saved.
	 *
	 * @return bool True if any updates were made; false is no updates are required or the database is in read-only
	 * mode.
	 */
	public function saveVars(MetaTemplateSetCollection $vars): bool
	{
		#RHDebug::writeFile(__METHOD__ . ': Vars: ', $vars);
		if (wfReadOnly()) {
			return false;
		}

		// Whether or not the data changed, the page has been evaluated, so add it to the list.
		$articleId = $vars->articleId;
		if (!isset(self::$pagesSaved[$articleId])) {
			self::$pagesSaved[$articleId] = true;
			$oldData = $this->loadPageVariables($articleId);
			#RHDebug::writeFile('Old Data', $oldData);
			if (!$oldData || $oldData->revId <= $vars->revId || $vars->revId === 0) {
				// In theory, $oldData->revId < $vars->revId should work, but <= is used in case loaded data is being re-saved without an actual page update.
				$upserts = new MetaTemplateUpserts($oldData, $vars);
				if ($upserts->getTotal() > 0) {
					#RHDebug::writeFile('Saving');
					$this->saveUpserts($upserts);
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Indicates whether the tables needed for MetaTemplate's data features exist.
	 *
	 * @return bool Whether both tables exist.
	 */
	public function tablesExist(): bool
	{
		return
			$this->dbRead->tableExists(self::TABLE_SET) &&
			$this->dbRead->tableExists(self::TABLE_DATA);
	}
	#endregion

	#region Private Static Functions
	/**
	 * Returns the basic query arrays for most MetaTemplate queries.
	 *
	 * @param string[] ...$addFields
	 *
	 * @return array An array containing the basic elements for building Metatemplate-related queries
	 */
	private static function baseQuery(...$addFields)
	{
		$tables = array_merge(self::TABLE_SET_ALIAS, self::TABLE_DATA_ALIAS);

		$fields = array_merge(
			$addFields,
			[
				self::FIELD_VAR_NAME,
				self::FIELD_VAR_VALUE
			]
		);

		$options = ['ORDER BY' => [
			self::FIELD_PAGE_ID,
			self::FIELD_SET_NAME,
			self::FIELD_REV_ID
		]];

		$joinConds = [self::DATA_ALIAS => ['JOIN', [self::S_SET_ID . '=' . self::D_SET_ID]]];

		#RHecho("Tables:\n", $tables, "\n\nFields:\n", $fields, "\n\nOptions:\n", $options, "\n\nJoinConds:\n", $joinConds);
		return [$tables, $fields, $options, $joinConds];
	}
	#endregion

	#region Private Functions
	/**
	 * Handles data to be inserted.
	 *
	 * @param mixed $setId The set ID to insert.
	 * @param MetaTemplateSet $newSet The set to insert.
	 */
	private function insertSetData($setId, MetaTemplateSet $newSet): void
	{
		$data = [];
		foreach ($newSet->variables as $varName => $varValue) {
			$data[] = [
				self::FIELD_SET_ID => $setId,
				self::FIELD_VAR_NAME => $varName,
				self::FIELD_VAR_VALUE => $varValue,
			];
		}

		$result = $this->dbWrite->insert(self::TABLE_DATA, $data);
		#RHDebug::writeFile(__METHOD__ . ' Insert: ' . (int)$result);
	}

	/**
	 * Loads variables for a specific page.
	 *
	 * @param Title $title The title to load.
	 *
	 * @return MetaTemplateSetCollection
	 */
	private function loadPageVariables(int $pageId): ?MetaTemplateSetCollection
	{
		// Sorting is to ensure that we're always using the latest data in the event of redundant data. Any redundant
		// data is tracked with $deleteIds.

		// logFunctionText("($articleId)");
		[$tables, $fields, $options, $joinConds] = self::baseQuery(
			self::S_SET_ID,
			self::FIELD_SET_NAME,
			self::FIELD_REV_ID
		);
		$conds = [self::FIELD_PAGE_ID => $pageId];
		$result = $this->dbRead->select($tables, $fields, $conds, __METHOD__ . " ($pageId)", $options, $joinConds);
		$row = $result->fetchRow();
		if (!$row) {
			return null;
		}

		$retval = new MetaTemplateSetCollection($pageId, $row[self::FIELD_REV_ID], false);
		while ($row) {
			$set =  $retval->addToSet($row[self::FIELD_SET_ID], $row[self::FIELD_SET_NAME]);
			$varName = $row[self::FIELD_VAR_NAME];
			$varValue = $row[self::FIELD_VAR_VALUE];
			$set->variables[$varName] = $varValue;
			$row = $result->fetchRow();
		}

		return $retval;
	}

	/**
	 * Alters the database in whatever ways are necessary to update one revision's variables to the next.
	 *
	 * @param MetaTemplateUpserts $upserts
	 *
	 * @todo See how much of this can be converted to bulk updates and or the built-in upsert methods. (Is it faster?)
	 * Even though MW internally wraps most of these in a transaction (supposedly...unverified), speed could probably
	 * still be improved with bulk updates.
	 */
	private function saveUpserts(MetaTemplateUpserts $upserts)
	{
		#RHDebug::writeFile($upserts);
		$this->dbWrite->startAtomic(__METHOD__);
		$deletes = $upserts->deletes;
		if (count($deletes)) {
			#RHDebug::writeFile(__METHOD__, ' !!!DELETE!!!');
			// Assumes cascading is in effect, so doesn't delete TABLE_DATA entries.
			$this->dbWrite->delete(self::TABLE_SET, [self::FIELD_SET_ID => $deletes]);
		}

		$pageId = $upserts->pageId;
		$newRevId = $upserts->newRevId;
		foreach ($upserts->inserts as $newSet) {
			$record = [
				self::FIELD_PAGE_ID => $pageId,
				self::FIELD_SET_NAME => $newSet->name,
				self::FIELD_REV_ID => $newRevId
			];


			$this->dbWrite->insert(self::TABLE_SET, $record, __METHOD__, ['IGNORE']);
			$setId = $this->dbWrite->insertId();
			if ($setId === 0) {
				// In the event of a set being defined but missing its data row, delete the set row and try again.
				$this->dbWrite->delete(self::TABLE_SET, [self::FIELD_PAGE_ID => $pageId, self::FIELD_SET_NAME => $newSet->name]);
				$this->dbWrite->insert(self::TABLE_SET, $record);
				$setId = $this->dbWrite->insertId();
			}

			#RHDebug::writeFile('Insert ID: ', $setId);
			$this->insertSetData($setId, $newSet);
		}

		if (count($upserts->updates)) {
			foreach ($upserts->updates as $setId => $setData) {
				/**
				 * @var MetaTemplateSet $oldSet
				 * @var MetaTemplateSet $newSet
				 */
				[$oldSet, $newSet] = $setData;
				$this->updateSetData($setId, $oldSet, $newSet);
			}

			if ($upserts->oldRevId <= $newRevId) {
				$this->dbWrite->update(
					self::TABLE_SET,
					[self::FIELD_REV_ID => $newRevId],
					[self::FIELD_SET_ID => $setId]
				);
			}
		}

		$this->dbWrite->endAtomic(__METHOD__);
	}

	/**
	 * Alters the database in whatever ways are necessary to update one revision's sets to the next.
	 *
	 * @param mixed $setId The set ID # from the mtSaveSet table.
	 * @param MetaTemplateSet $oldSet The previous revision's set data.
	 * @param MetaTemplateSet $newSet The current revision's set data.
	 */
	private function updateSetData($setId, MetaTemplateSet $oldSet, MetaTemplateSet $newSet): void
	{
		$oldVars = $oldSet->variables;
		$newVars = $newSet->variables;
		$deletes = [];
		foreach ($oldVars as $varName => $oldValue) {
			if (isset($newVars[$varName])) {
				$newValue = $newVars[$varName];
				#RHwriteFile($oldVars[$varName]);
				if ($oldValue != $newValue) {
					#RHwriteFile("Updating $varName from {$oldValue->value} to {$newValue->value}");
					// Makes the assumption that most of the time, only a few columns are being updated, so does not
					// attempt to batch the operation in any way.
					$this->dbWrite->update(
						self::TABLE_DATA,
						[
							self::FIELD_VAR_VALUE => $newValue
						],
						[
							self::FIELD_SET_ID => $setId,
							self::FIELD_VAR_NAME => $varName
						]
					);
				}

				unset($newVars[$varName]);
			} else {
				$deletes[] = $varName;
			}
		}

		if (count($newVars)) {
			$this->insertSetData($setId, new MetaTemplateSet($newSet->name, $newVars));
		}

		if (count($deletes)) {
			#RHDebug::writeFile(__METHOD__, ' !!!DELETE!!!');
			$this->dbWrite->delete(self::TABLE_DATA, [
				self::FIELD_SET_ID => $setId,
				self::FIELD_VAR_NAME => $deletes
			]);
		}
	}
	#endregion
}
