<?php

namespace CirrusSearch;

use Exception;
use GenderCache;
use IP;
use MediaWiki\Logger\LoggerFactory;
use MWNamespace;
use PoolCounterWorkViaCallback;
use RequestContext;
use Status;
use Title;
use User;
use WebRequest;

/**
 * Random utility functions that don't have a better home
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
class Util {
	/**
	 * Cache getDefaultBoostTemplates()
	 *
	 * @var array|null boost templates
	 */
	private static $defaultBoostTemplates = null;

	/**
	 * Get the textual representation of a namespace with underscores stripped, varying
	 * by gender if need be.
	 *
	 * @param Title $title The page title to use
	 * @return string
	 */
	public static function getNamespaceText( Title $title ) {
		global $wgContLang;

		$ns = $title->getNamespace();

		// If we're in NS_USER(_TALK) and we're in a gender-distinct language
		// then vary the namespace on gender like we should.
		$nsText = '';
		if ( MWNamespace::hasGenderDistinction( $ns ) && $wgContLang->needsGenderDistinction() ) {
			$nsText = $wgContLang->getGenderNsText( $ns,
				GenderCache::singleton()->getGenderOf(
					User::newFromName( $title->getText() ),
					__METHOD__
				)
			);
		} elseif ( $nsText !== NS_MAIN ) {
			$nsText = $wgContLang->getNsText( $ns );
		}

		return strtr( $nsText, '_', ' ' );
	}

	/**
	 * Check if too arrays are recursively the same.  Values are compared with != and arrays
	 * are descended into.
	 *
	 * @param array $lhs one array
	 * @param array $rhs the other array
	 * @return bool are they equal
	 */
	public static function recursiveSame( $lhs, $rhs ) {
		if ( array_keys( $lhs ) != array_keys( $rhs ) ) {
			return false;
		}
		foreach ( $lhs as $key => $value ) {
			if ( !isset( $rhs[ $key ] ) ) {
				return false;
			}
			if ( is_array( $value ) ) {
				if ( !is_array( $rhs[ $key ] ) ) {
					return false;
				}
				if ( !self::recursiveSame( $value, $rhs[ $key ] ) ) {
					return false;
				}
			} else {
				if ( $value != $rhs[ $key ] ) {
					return false;
				}
			}
		}
		return true;
	}

	/**
	 * @param string $type The pool counter type, such as CirrusSearch-Search
	 * @param bool $isSuccess If the pool counter gave a success, or failed the request
	 * @return string The key used for collecting timing stats about this pool counter request
	 */
	private static function getPoolStatsKey( $type, $isSuccess ) {
		$pos = strpos( $type, '-' );
		if ( $pos !== false ) {
			$type = substr( $type, $pos + 1 );
		}
		$postfix = $isSuccess ? 'successMs' : 'failureMs';
		return "CirrusSearch.poolCounter.$type.$postfix";
	}

	/**
	 * @param float $startPoolWork The time this pool request started, from microtime( true )
	 * @param string $type The pool counter type, such as CirrusSearch-Search
	 * @param bool $isSuccess If the pool counter gave a success, or failed the request
	 * @param callable $callback The function to wrap
	 * @return callable The original callback wrapped to collect pool counter stats
	 */
	private static function wrapWithPoolStats( $startPoolWork, $type, $isSuccess, $callback ) {
		return function () use ( $type, $isSuccess, $callback, $startPoolWork ) {
			RequestContext::getMain()->getStats()->timing(
				self::getPoolStatsKey( $type, $isSuccess ),
				intval( 1000 * (microtime( true ) - $startPoolWork) )
			);

			return call_user_func_array( $callback, func_get_args() );
		};
	}

	/**
	 * Wraps the complex pool counter interface to force the single call pattern
	 * that Cirrus always uses.
	 *
	 * @param string $type same as type parameter on PoolCounter::factory
	 * @param \User $user the user
	 * @param callable $workCallback callback when pool counter is acquired.  Called with
	 *  no parameters.
	 * @param callable $errorCallback optional callback called on errors.  Called with
	 *  the error string and the key as parameters.  If left undefined defaults
	 *  to a function that returns a fatal status and logs an warning.
	 * @return mixed
	 */
	public static function doPoolCounterWork( $type, $user, $workCallback, $errorCallback = null ) {
		global $wgCirrusSearchPoolCounterKey;

		// By default the pool counter allows you to lock the same key with
		// multiple types.  That might be useful but it isn't how Cirrus thinks.
		// Instead, all keys are scoped to their type.

		if ( !$user ) {
			// We don't want to even use the pool counter if there isn't a user.
			return $workCallback();
		}
		$perUserKey = md5( $user->getName() );
		$perUserKey = "nowait:CirrusSearch:_per_user:$perUserKey";
		$globalKey = "$type:$wgCirrusSearchPoolCounterKey";
		if ( $errorCallback === null ) {
			$errorCallback = function( $error, $key, $userName ) {
				$forUserName = $userName ? "for {userName} " : '';
				LoggerFactory::getInstance( 'CirrusSearch' )->warning(
					"Pool error {$forUserName}on {key}:  {error}",
					array( 'userName' => $userName, 'key' => $key, 'error' => $error )
				);
				return Status::newFatal( 'cirrussearch-backend-error' );
			};
		}
		// wrap some stats collection on the success/failure handlers
		$startPoolWork = microtime( true );
		$workCallback = self::wrapWithPoolStats( $startPoolWork, $type, true, $workCallback );
		$errorCallback = self::wrapWithPoolStats( $startPoolWork, $type, false, $errorCallback );

		$errorHandler = function( $key ) use ( $errorCallback, $user ) {
			return function( Status $status ) use ( $errorCallback, $key, $user ) {
				$status = $status->getErrorsArray();
				// anon usernames are needed within the logs to determine if
				// specific ips (such as large #'s of users behind a proxy)
				// need to be whitelisted. We do not need this information
				// for logged in users and do not store it.
				$userName = $user->isAnon() ? $user->getName() : '';
				return $errorCallback( $status[ 0 ][ 0 ], $key, $userName );
			};
		};
		$doPerUserWork = function() use ( $type, $globalKey, $workCallback, $errorHandler ) {
			// Now that we have the per user lock lets get the operation lock.
			// Note that this could block, causing the user to wait in line with their lock held.
			$work = new PoolCounterWorkViaCallback( $type, $globalKey, array(
				'doWork' => $workCallback,
				'error' => $errorHandler( $globalKey ),
			) );
			return $work->execute();
		};
		$work = new PoolCounterWorkViaCallback( 'CirrusSearch-PerUser', $perUserKey, array(
			'doWork' => $doPerUserWork,
			'error' => function( $status ) use( $errorHandler, $perUserKey, $doPerUserWork ) {
				$errorCallback = $errorHandler( $perUserKey );
				$errorResult = $errorCallback( $status );
				if ( Util::isUserPoolCounterActive() ) {
					return $errorResult;
				} else {
					return $doPerUserWork();
				}
			},
		) );
		return $work->execute();
	}

	/**
	 * @return bool
	 */
	public static function isUserPoolCounterActive() {
		global $wgCirrusSearchBypassPerUserFailure,
			$wgCirrusSearchForcePerUserPoolCounter;

		$ip = RequestContext::getMain()->getRequest()->getIP();
		if ( IP::isInRanges( $ip, $wgCirrusSearchForcePerUserPoolCounter ) ) {
			return true;
		} elseif ( $wgCirrusSearchBypassPerUserFailure ) {
			return false;
		} else {
			return true;
		}
	}

	/**
	 * @param string $str
	 * @return float
	 */
	public static function parsePotentialPercent( $str ) {
		$result = floatval( $str );
		if ( strpos( $str, '%' ) === false ) {
			return (float) $result;
		}
		return $result / 100;
	}

	/**
	 * Matches $data against $properties to clear keys that no longer exist.
	 * E.g.:
	 * $data = array(
	 *     'title' => "I'm a title",
	 *     'useless' => "I'm useless",
	 * );
	 * $properties = array(
	 *     'title' => 'params-for-title'
	 * );
	 *
	 * Will return:
	 * array(
	 *     'title' => "I'm a title",
	 * )
	 * With the no longer existing 'useless' field stripped.
	 *
	 * We could just use array_intersect_key for this simple example, but it
	 * gets more complex with nested data.
	 *
	 * @param array $data
	 * @param array $properties
	 * @return array
	 */
	public static function cleanUnusedFields( array $data, array $properties ) {
		$data = array_intersect_key( $data, $properties );

		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				foreach ( $value as $i => $innerValue ) {
					if ( is_array( $innerValue ) && isset( $properties[$key]['properties'] ) ) {
						// go recursive to intersect multidimensional values
						$data[$key][$i] = static::cleanUnusedFields( $innerValue, $properties[$key]['properties'] );
					}

				}
			}
		}

		return $data;
	}

	/**
	 * Iterate over a scroll.
	 *
	 * @param \Elastica\Index $index
	 * @param string $scrollId the initial $scrollId
	 * @param string $scrollTime the scroll timeout
	 * @param callable $consumer function that receives the results
	 * @param int $limit the max number of results to fetch (0: no limit)
	 * @param int $retryAttempts the number of times we retry
	 * @param callable $retryErrorCallback function called before each retries
	 */
	public static function iterateOverScroll( \Elastica\Index $index, $scrollId, $scrollTime, $consumer, $limit = 0, $retryAttempts = 0, $retryErrorCallback = null ) {
		$clearScroll = true;
		$fetched = 0;

		while( true ) {
			$result = static::withRetry( $retryAttempts,
				function() use ( $index, $scrollId, $scrollTime ) {
					return $index->search ( array(), array(
						'scroll_id' => $scrollId,
						'scroll' => $scrollTime
					) );
				}, $retryErrorCallback );

			$scrollId = $result->getResponse()->getScrollId();

			if( !$result->count() ) {
				// No need to clear scroll on the last call
				$clearScroll = false;
				break;
			}

			$fetched += $result->count();
			$results =  $result->getResults();

			if( $limit > 0 && $fetched > $limit ) {
				$results = array_slice( $results, 0, sizeof( $results ) - ( $fetched - $limit ) );
			}
			$consumer( $results );

			if( $limit > 0 && $fetched >= $limit ) {
				break;
			}
		}
		// @todo: catch errors and clear the scroll, it'd be easy with a finally block ...

		if( $clearScroll ) {
			try {
				$index->getClient()->request( "_search/scroll/".$scrollId, \Elastica\Request::DELETE );
			} catch ( Exception $e ) {}
		}
	}

	/**
	 * A function that retries callback $func if it throws an exception.
	 * The $beforeRetry is called before a retry and receives the underlying
	 * ExceptionInterface object and the number of failed attempts.
	 * It's generally used to log and sleep between retries. Default behaviour
	 * is to sleep with a random backoff.
	 * @see Util::backoffDelay
	 *
	 * @param int $attempts the number of times we retry
	 * @param callable $func
	 * @param callable $beforeRetry function called before each retry
	 * @return mixed
	 */
	public static function withRetry( $attempts, $func, $beforeRetry = null ) {
		$errors = 0;
		while ( true ) {
			if ( $errors < $attempts ) {
				try {
					return $func();
				} catch ( Exception $e ) {
					$errors += 1;
					if( $beforeRetry ) {
						$beforeRetry( $e, $errors );
					} else {
						$seconds = static::backoffDelay( $errors );
						sleep( $seconds );
					}
				}
			} else {
				return $func();
			}
		}
	}

	/**
	 * Backoff with lowest possible upper bound as 16 seconds.
	 * With the default maximum number of errors (5) this maxes out at 256 seconds.
	 *
	 * @param int $errorCount
	 * @return int
	 */
	public static function backoffDelay( $errorCount ) {
		return rand( 1, (int) pow( 2, 3 + $errorCount ) );
	}

	/**
	 * Parse a message content into an array. This function is generally used to
	 * parse settings stored as i18n messages (see cirrussearch-boost-templates).
	 *
	 * @param string $message
	 * @return string[]
	 */
	public static function parseSettingsInMessage( $message ) {
		$lines = explode( "\n", $message );
		$lines = preg_replace( '/#.*$/', '', $lines ); // Remove comments
		$lines = array_map( 'trim', $lines );          // Remove extra spaces
		$lines = array_filter( $lines );               // Remove empty lines
		return $lines;
	}

	/**
	 * Tries to identify the best redirect by finding the link with the
	 * smallest edit distance between the title and the user query.
	 *
	 * @param string $userQuery the user query
	 * @param array $redirects the list of redirects
	 * @return string the best redirect text
	 */
	public static function chooseBestRedirect( $userQuery, $redirects ) {
		$userQuery = mb_strtolower( $userQuery );
		$len = mb_strlen( $userQuery );
		$bestDistance = INF;
		$best = null;

		foreach( $redirects as $redir ) {
			$text = $redir['title'];
			if ( mb_strlen( $text ) > $len ) {
				$text = mb_substr( $text, 0, $len );
			}
			$text = mb_strtolower( $text );
			$distance = levenshtein( $text, $userQuery );
			if ( $distance == 0 ) {
				return $redir['title'];
			}
			if ( $distance < $bestDistance ) {
				$bestDistance = $distance;
				$best = $redir['title'];
			}
		}
		return $best;
	}

	/**
	 * Test if $string ends with $suffix
	 *
	 * @param string $string string to test
	 * @param string $suffix the suffix
	 * @return boolean true if $string ends with $suffix
	 */
	public static function endsWith( $string, $suffix ) {
		$strlen = strlen( $string );
		$suffixlen = strlen( $suffix );
		if ( $suffixlen > $strlen ) {
			return false;
		}
		return substr_compare( $string, $suffix, $strlen - $suffixlen, $suffixlen ) === 0;
	}

	/**
	 * Set $dest to the true/false from $request->getVal( $name ) if yes/no.
	 *
	 * @param mixed &$dest
	 * @param WebRequest $request
	 * @param string $name
	 */
	public static function overrideYesNo( &$dest, $request, $name ) {
		$val = $request->getVal( $name );
		if ( $val !== null ) {
			if ( $val === 'yes' ) {
				$dest = true;
			} elseif( $val = 'no' ) {
				$dest = false;
			}
		}
	}

	/**
	 * Set $dest to the numeric value from $request->getVal( $name ) if it is <= $limit
	 * or => $limit if upperLimit is false.
	 *
	 * @param mixed &$dest
	 * @param WebRequest $request
	 * @param string $name
	 * @param int|null $limit
	 * @param bool $upperLimit
	 */
	public static function overrideNumeric( &$dest, $request, $name, $limit = null, $upperLimit = true ) {
		$val = $request->getVal( $name );
		if ( $val !== null && is_numeric( $val ) ) {
			if ( !isset( $limit ) ) {
				$dest = $val;
			} else if ( $upperLimit && $val <= $limit ) {
				$dest = $val;
			} else if ( !$upperLimit && $val >= $limit ) {
				$dest = $val;
			}
		}
	}

	/**
	 * Parse boosted templates.  Parse failures silently return no boosted templates.
	 *
	 * @param string $text text representation of boosted templates
	 * @return float[] map of boosted templates (key is the template, value is a float).
	 */
	public static function parseBoostTemplates( $text ) {
		$boostTemplates = array();
		$templateMatches = array();
		if ( preg_match_all( '/([^|]+)\|(\d+)% ?/', $text, $templateMatches, PREG_SET_ORDER ) ) {
			foreach ( $templateMatches as $templateMatch ) {
				// templates field is populated with Title::getPrefixedText
				// which will replace _ to ' '. We should do the same here.
				$template = strtr( $templateMatch[ 1 ], '_', ' ' );
				$boostTemplates[ $template ] = floatval( $templateMatch[ 2 ] ) / 100;
			}
		}
		return $boostTemplates;
	}

	/**
	 * @return float[]
	 */
	public static function getDefaultBoostTemplates() {
		if ( self::$defaultBoostTemplates === null ) {
			$cache = \ObjectCache::getLocalServerInstance();
			self::$defaultBoostTemplates = $cache->getWithSetCallback(
				$cache->makeKey( 'cirrussearch-boost-templates' ),
				600,
				function() {
					$source = wfMessage( 'cirrussearch-boost-templates' )->inContentLanguage();
					if( !$source->isDisabled() ) {
						$lines = Util::parseSettingsInMessage( $source->plain() );
						return Util::parseBoostTemplates( implode( ' ', $lines ) );                  // Now parse the templates
					}
					return array();
				}
			);
		}
		return self::$defaultBoostTemplates;
	}
}
