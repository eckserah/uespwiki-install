<?php

namespace CirrusSearch\Api;

use ApiBase as CoreApiBase;
use CirrusSearch\Connection;
use MediaWiki\MediaWikiServices;

abstract class ApiBase extends CoreApiBase {
	/** @var Connection */
	private $connection;

	/**
	 * @return Connection
	 */
	public function getCirrusConnection() {
		if ($this->connection === null) {
			$config = MediaWikiServices::getInstance()
				->getConfigFactory()
				->makeConfig( 'CirrusSearch' );
			$this->connection = new Connection( $config );
		}
		return $this->connection;
	}
}
