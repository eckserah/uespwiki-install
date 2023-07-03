<?php
namespace JsonConfig;

use ApiBase;

/**
 * Allows JsonConfig to be manipulated via API
 */
class JCApi extends ApiBase {

	private static function addStatusConf( $conf ) {
		// explicitly list values to avoid accidental exposure of private data
		$res = [
			'model' => $conf->model,
			'namespace' => $conf->namespace,
			'nsName' => $conf->nsName,
			'nsTalk' => isset( $conf->nsTalk ) && $conf->nsTalk ? $conf->nsTalk : 'default',
			'isLocal' => $conf->isLocal,
			'cacheExp' => $conf->cacheExp,
			'cacheKey' => $conf->cacheKey,
			'flaggedRevs' => $conf->flaggedRevs,
		];
		if ( isset( $conf->remote ) ) {
			$res['remote'] = [
				'url' => $conf->remote->url,
				'username' => $conf->remote->username !== '', // true or false
				'password' => $conf->remote->password !== '', // true or false
			];
		}
		if ( isset( $conf->store ) ) {
			$res['store'] = [
				'cacheNewValue' => $conf->store->cacheNewValue,
				'notifyUrl' => $conf->store->notifyUrl,
				'notifyUsername' => $conf->store->notifyUsername !== '', // true or false
				'notifyPassword' => $conf->store->notifyPassword !== '', // true or false
			];
		}
		return $res;
	}

	public function execute() {
		$result = $this->getResult();

		$params = $this->extractRequestParams();
		$command = $params['command'];

		switch ( $command ) {
			case 'status':
				$this->getMain()->setCacheMaxAge( 1 * 30 ); // seconds
				$this->getMain()->setCacheMode( 'public' );

				global $wgJsonConfigModels;
				$result->addValue(
					null,
					'models',
					\ExtensionRegistry::getInstance()->getAttribute( 'JsonConfigModels' )
					+ $wgJsonConfigModels
				);

				$data = [];
				foreach ( JCSingleton::getTitleMap() as $ns => $confs ) {
					$vals = [];
					foreach ( $confs as $conf ) {
						$vals[] = self::addStatusConf( $conf );
					}
					$data[$ns] = $vals;
				}
				if ( $data ) {
					$result->setIndexedTagName( $data, 'ns' );
				}
				$result->addValue( null, 'titleMap', $data );
				break;

			case 'reset':
			case 'reload':

				// FIXME: this should be POSTed, not GETed.
				// This code should match JCSingleton::onArticleChangeComplete()
				// Currently, that action is not used because in production store->notifyUrl is null
				// Can MW API allow both for the same action, or should it be a separate action?

				$this->getMain()->setCacheMaxAge( 1 ); // seconds
				$this->getMain()->setCacheMode( 'private' );
				if ( !$this->getUser()->isAllowed( 'jsonconfig-flush' ) ) {
					if ( is_callable( [ $this, 'dieWithError' ] ) ) {
						// Sigh. Can't use $this->checkUserRightsAny() because
						// this has to break API conventions by returning 401
						// (and violate the HTTP RFC by doing so without a
						// WWW-Authenticate header).
						$this->dieWithError(
							[ 'apierror-permissiondenied', $this->msg( "action-jsonconfig-flush" ) ],
							'permissiondenied', [], 401
						);
					} else {
						$this->dieUsage( "Must be authenticated with jsonconfig-flush right to use this API",
							'login', 401 );
					}
				}
				if ( !isset( $params['namespace'] ) ) {
					if ( is_callable( [ $this, 'dieWithError' ] ) ) {
						$this->dieWithError(
							[ 'apierror-jsonconfig-paramrequired', 'namespace' ], 'badparam-namespace'
						);
					} else {
						$this->dieUsage( 'Parameter "namespace" is required for this command', 'badparam-namespace' );
					}
				}
				if ( !isset( $params['title'] ) ) {
					if ( is_callable( [ $this, 'dieWithError' ] ) ) {
						$this->dieWithError( [ 'apierror-jsonconfig-paramrequired', 'title' ], 'badparam-title' );
					} else {
						$this->dieUsage( 'Parameter "title" is required for this command', 'badparam-title' );
					}
				}

				$jct = JCSingleton::parseTitle( $params['title'], $params['namespace'] );
				if ( !$jct ) {
					if ( is_callable( [ $this, 'dieWithError' ] ) ) {
						$this->dieWithError( 'apierror-jsonconfig-badtitle', 'badparam-titles' );
					} else {
						$this->dieUsage( 'The page specified by "namespace" and "title" parameters is either invalid or is not registered in JsonConfig configuration',
							'badparam-titles' );
					}
				}

				if ( isset( $params['content'] ) && $params['content'] !== '' ) {
					if ( $command !== 'reload ' ) {
						if ( is_callable( [ $this, 'dieWithError' ] ) ) {
							$this->dieWithError( [ 'apierror-invalidparammix-mustusewith', 'content', 'command=reload' ],
								'badparam-content' );
						} else {
							$this->dieUsage( 'The "content" parameter may only be used with command=reload',
								'badparam-content' );
						}
					}
					$content = JCSingleton::parseContent( $jct, $params['content'], true );
				} else {
					$content = false;
				}

				$jc = new JCCache( $jct, $content );
				if ( $command === 'reset' ) {
					$jc->resetCache( false ); // clear cache
				} elseif ( $content ) {
					$jc->resetCache( true ); // set new value in cache
				} else {
					$jc->get(); // gets content from the default source and cache
				}

				break;
		}
	}

	public function getAllowedParams() {
		return [
			'command' => [
				ApiBase::PARAM_DFLT => 'status',
				ApiBase::PARAM_TYPE => [
					'status',
					'reset',
					'reload',
				]
			],
			'namespace' => [
				ApiBase::PARAM_TYPE => 'integer',
			],
			'title' => '',
			'content' => '',
		];
	}

	/**
	 * @see ApiBase::getExamplesMessages()
	 */
	protected function getExamplesMessages() {
		return [
			'action=jsonconfig&format=jsonfm'
				=> 'apihelp-jsonconfig-example-1',
			'action=jsonconfig&command=reset&namespace=480&title=TEST&format=jsonfm'
				=> 'apihelp-jsonconfig-example-2',
			'action=jsonconfig&command=reload&namespace=480&title=TEST&format=jsonfm'
				=> 'apihelp-jsonconfig-example-3',
		];
	}
}
