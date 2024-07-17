<?php
/**
 * This file is part of the MediaWiki extension UserVerification.
 *
 * UserVerification is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * (at your option) any later version.
 *
 * UserVerification is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with UserVerification.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @file
 * @ingroup extensions
 * @author thomas-topway-it <support@topway.it>
 * @copyright Copyright ©2024, https://wikisphere.org
 */

use Defuse\Crypto\Crypto;
use Defuse\Crypto\Key;
use Defuse\Crypto\KeyProtectedByPassword;
use MediaWiki\MainConfigNames;
use MediaWiki\MediaWikiServices;

class UserVerification {

	/** @var string */
	public static $cookieUserKey = 'userverification-userkey';

	/** @var array */
	public static $UserAuthCache = [];

	/** @var array */
	public static $QueryLinkDefaultParameters = [
		'class-attr-name' => [
			'default' => 'class',
			'type' => 'string',
		],
	];

	/**
	 * @see https://www.mediawiki.org/wiki/Extension:VisualData
	 * @param Parser $parser
	 * @param mixed ...$argv
	 * @return array
	 */
	public static function parserFunctionQueryLink( Parser $parser, ...$argv ) {
		$parserOutput = $parser->getOutput();

/*
{{#querylink: pagename
|label
|class=
|class-attr-name=class
|a=b
|c=d
|...
}}
*/
		// unnamed parameters, recognized options,
		// named parameters
		[ $values, $options, $query ] = self::parseParameters( $argv, array_keys( self::$QueryLinkDefaultParameters ) );

		$defaultParameters = self::$QueryLinkDefaultParameters;

		array_walk( $defaultParameters, static function ( &$value, $key ) {
			$value = [ $value['default'], $value['type'] ];
		} );

		$options = self::applyDefaultParams( $defaultParameters, $options );

		if ( !count( $values ) || empty( $values[0] ) ) {
			return 'no page name';
		}

		// assign the indicated name for the "class" attribute
		// to the known options (from the unknown named parameters)
		if ( isset( $query[$options['class-attr-name']] ) ) {
			$options[$options['class-attr-name']] = $query[$options['class-attr-name']];
		}
		unset( $query[$options['class-attr-name']] );

		if ( !count( $query ) ) {
			return 'no query';
		}

		$title_ = Title::newFromText( $values[0] );
		$text = ( !empty( $values[1] ) ? $values[1]
			: $title_->getText() );

		$attr = [];
		if ( !empty( $options[$options['class-attr-name']] ) ) {
			$attr['class'] = $options[$options['class-attr-name']];
		}

		// *** alternatively use $linkRenderer->makePreloadedLink
		// or $GLOBALS['wgArticlePath'] and wfAppendQuery
		$ret = Linker::link( $title_, $text, $attr, $query );

		return [
			$ret,
			'noparse' => true,
			'isHTML' => true
		];
	}

	/**
	 * @param User $user
	 * @return bool
	 */
	public static function isVerified( $user ) {
		$status = self::getStatus( $user );
		return ( $status === 'verified' || $status === 'not_required' );
	}

	/**
	 * @param User $user
	 * @return bool
	 */
	public static function getStatus( $user ) {
		$dbr = self::getDB( DB_REPLICA );
		$status = $dbr->selectField(
			'userverification_verification',
			'status',
			[ 'user_id' => $user->getId() ],
			__METHOD__,
			[ 'LIMIT' => 1 ]
		);
		return $status;
	}

	/**
	 * @param int $user_id
	 * @param array $row
	 * @return string|null|false
	 */
	public static function decryptData( $data ) {
		if ( empty( $data ) ) {
			return null;
		}

		$keys = self::getKeys();
		if ( empty( $keys ) ) {
			throw new MWException( 'keys not set' );
		}

		$user_key = self::getUserKey();

		if ( $user_key !== false ) {
			$secret_key = self::decryptSymmetric( $keys['encrypted_private_key'], $user_key );
		} else {
			throw new MWException( 'cannot decrypt private key' );
		}

		// @see https://php.watch/articles/modern-php-encryption-decryption-sodium
		// Unauthenticated Asymmetric Decryption
		$keypair = sodium_crypto_box_keypair_from_secretkey_and_publickey( $secret_key, $keys['public_key'] );
		return sodium_crypto_box_seal_open( $data, $keypair );
	}

	/**
	 * @see https://www.mediawiki.org/wiki/Extension:PageEncryption
	 * @return array
	 */
	public static function getCookieOptions() {
		$context = RequestContext::getMain();
		$config = $context->getConfig();

		[
			$cookieSameSite,
			$cookiePrefix,
			$cookiePath,
			$cookieDomain,
			$cookieSecure,
			$forceHTTPS,
			$cookieHttpOnly,
		] = ( class_exists( 'MediaWiki\MainConfigNames' ) ?
			[
				MainConfigNames::CookieSameSite,
				MainConfigNames::CookiePrefix,
				MainConfigNames::CookiePath,
				MainConfigNames::CookieDomain,
				MainConfigNames::CookieSecure,
				MainConfigNames::ForceHTTPS,
				MainConfigNames::CookieHttpOnly
			] :
			[
				'CookieSameSite',
				'CookiePrefix',
				'CookiePath',
				'CookieDomain',
				'CookieSecure',
				'ForceHTTPS',
				'CookieHttpOnly'
			]
		);

		// @codeCoverageIgnoreStart
		return [
			'prefix' => $config->get( $cookiePrefix ),
			'path' => $config->get( $cookiePath ),
			'domain' => $config->get( $cookieDomain ),
			'secure' => $config->get( $cookieSecure )
				|| $config->get( $forceHTTPS ),
			'httpOnly' => $config->get( $cookieHttpOnly ),
			'sameSite' => $config->get( $cookieSameSite )
		];
	}

	/**
	 * @see https://www.mediawiki.org/wiki/Extension:PageEncryption
	 * @param string $cookieValue
	 * @return bool
	 */
	public static function setCookie( $cookieValue ) {
		// setcookie( 'pageencryption-passwordkey', $protected_key_encoded, array $options = []): bool
		$context = RequestContext::getMain();
		$request = $context->getRequest();
		$response = $request->response();
		// $session = SessionManager::getGlobalSession();
		// $expiration = $session->getProvider()->getRememberUserDuration();
		$cookieOptions = self::getCookieOptions();

		$session = $request->getSession();

		$sessionProvider = $session->getProvider();
		// !( $session->getProvider() instanceof CookieSessionProvider )
		// $info = $sessionProvider->provideSessionInfo( $request );
		// $provider = $info->getProvider();

		// @TODO subtract (current time - login time)
		$expiryValue = $sessionProvider->getRememberUserDuration() + time();
		$response->setCookie( self::$cookieUserKey, $cookieValue, $expiryValue, $cookieOptions );

		return true;
	}

	/**
	 * @see https://www.mediawiki.org/wiki/Extension:PageEncryption
	 */
	public static function deleteCookie() {
		$context = RequestContext::getMain();
		$request = $context->getRequest();
		$response = $request->response();

		// @see CookieSessionProvider unpersistSession
		$cookies = [
			self::$cookieUserKey => false,
		];
		$cookieOptions = self::getCookieOptions();

		foreach ( $cookies as $key => $value ) {
			$response->clearCookie( $key, $cookieOptions );
		}
	}

	/**
	 * @see https://www.mediawiki.org/wiki/Extension:PageEncryption
	 * @param string $protected_key_encoded
	 * @param string $password
	 * @param string &$message
	 * @return bool
	 */
	public static function setUserKey( $protected_key_encoded, $password, &$message ) {
		$protected_key = KeyProtectedByPassword::loadFromAsciiSafeString( $protected_key_encoded );

		// @see https://github.com/defuse/php-encryption/blob/master/docs/classes/Crypto.md
		try {
			$user_key = $protected_key->unlockKey( $password );
			$user_key_encoded = $user_key->saveToAsciiSafeString();
		} catch ( Defuse\Crypto\Exception\WrongKeyOrModifiedCiphertextException $ex ) {
			$message = wfMessage( 'userverification-error-message-password-doesnotmatch' )->text();
			return false;
		}
		$res = self::setCookie( $user_key_encoded );
		if ( $res === false ) {
			$message = wfMessage( 'userverification-error-message-cannot-set-cookie' )->text();
		}
		return $res;
	}

	/**
	 * @param int $user_id
	 * @param array $row
	 * @return bool
	 */
	public static function setManageVerification( $user_id, $row ) {
		$dbr = self::getDB( DB_MASTER );

		$user_id_ = $dbr->selectField(
			'userverification_verification',
			'user_id',
			[ 'user_id' => $user_id ],
			__METHOD__,
			[ 'LIMIT' => 1 ]
		);

		$date = date( 'Y-m-d H:i:s' );
		if ( !$user_id_ ) {
			$res = $dbr->insert( 'userverification_verification', $row + [ 'user_id' => $user_id, 'updated_at' => $date, 'created_at' => $date ] );

		} else {
			$res = $dbr->update( 'userverification_verification', $row + [ 'updated_at' => $date ], [ 'user_id' => $user_id ], __METHOD__ );
		}

		return $res;
	}

	/**
	 * @param int $user_id
	 * @return string
	 */
	public static function getUploadDir( $user_id ) {
		return str_replace( '{$IP}', $GLOBALS['IP'], $GLOBALS['wgUserVerificationUploadDir'] ) . '/' . $user_id;
	}

	/**
	 * @return array
	 */
	public static function getKeys() {
		$dbr = self::getDB( DB_REPLICA );
		$ret = $dbr->selectRow(
			'userverification_keys',
			'*',
			[ 'enabled' => 1 ],
			__METHOD__,
			[ 'LIMIT' => 1 ]
		);
		return !empty( $ret ) ? (array)$ret : [];
	}

	/**
	 * @param User $user
	 * @param array $data
	 * @return bool
	 */
	public static function setVerificationData( $user, $data ) {
		$dbr = self::getDB( DB_MASTER );
		$user_id = $user->getId();
		$uploadDir = self::getUploadDir( $user_id );
		if ( !file_exists( $uploadDir ) ) {
			mkdir( $uploadDir, 0777, true );
		}

		$maxSize = $GLOBALS['wgMaxUploadSize'];
		$keys = self::getKeys();
		if ( empty( $keys ) ) {
			throw new MWException( 'keys not set' );
		}

		// @see https://php.watch/articles/modern-php-encryption-decryption-sodium
		// Unauthenticated Asymmetric Encryption
		foreach ( $data as $key => $value ) {
			[ $type, $value ] = $value;
			if ( $type === 'file' && !empty( $_FILES[$key]['tmp_name'] ) ) {
				$target_file = $uploadDir . '/' . basename( $_FILES[$key]['name'] );
				// if ( !move_uploaded_file( $_FILES['proof_of_identity']['tmp_name'], $target_file) ) {
				// 	exit( 'cannot save uploaded file' );
				// }
				if ( $_FILES[$key]['size'] > $maxSize ) {
					exit( 'file is too large' );
				}
				$contents = file_get_contents( $_FILES[$key]['tmp_name'] );
				unlink( $_FILES[$key]['tmp_name'] );
				file_put_contents( $target_file, sodium_crypto_box_seal( $contents, $keys['public_key'] ) );
			}
		}

		$row = $dbr->selectRow(
			'userverification_verification',
			'*',
			[ 'user_id' => $user_id ],
			__METHOD__,
			[ 'LIMIT' => 1 ]
		);

		$data = json_encode( $data );
		$data = sodium_crypto_box_seal( $data, $keys['public_key'] );

		if ( !$row ) {
			$date = date( 'Y-m-d H:i:s' );
			$res = $dbr->insert( 'userverification_verification', [
				'user_id' => $user_id,
				'data' => $data,
				'status' => 'pending',
				'updated_at' => $date,
				'created_at' => $date
			] );

		} else {
			$res = $dbr->update( 'userverification_verification', [
				'data' => $data,
				'status' => 'pending',
			], [ 'user_id' => $user_id ], __METHOD__ );
		}

		return $res;
	}

	/**
	 * @param OutputPage $outputPage
	 * @param array $items
	 * @return array
	 */
	public static function addHeaditem( $outputPage, $items ) {
		foreach ( $items as $key => $val ) {
			[ $type, $url ] = $val;
			switch ( $type ) {
				case 'stylesheet':
					$item = '<link rel="stylesheet" href="' . $url . '" />';
					break;
				case 'script':
					$item = '<script src="' . $url . '"></script>';
					break;
			}
			// @phan-suppress-next-line PhanTypeMismatchArgumentNullable
			$outputPage->addHeadItem( 'userverification_head_item' . $key, $item );
		}
	}

	/**
	 * @param User $user
	 * @return bool
	 */
	public static function isAuthorizedGroup( $user ) {
		$cacheKey = $user->getName();
		if ( array_key_exists( $cacheKey, self::$UserAuthCache ) ) {
			return self::$UserAuthCache[$cacheKey];
		}
		$userGroupManager = MediaWikiServices::getInstance()->getUserGroupManager();
		$userGroups = $userGroupManager->getUserEffectiveGroups( $user );
		$authorizedGroups = [
			'sysop',
			'bureaucrat',
			'interface-admin',
			// 'autoconfirmed'
			'userverification-admin'
		];
		self::$UserAuthCache[$cacheKey] = count( array_intersect( $authorizedGroups, $userGroups ) );
		return self::$UserAuthCache[$cacheKey];
	}

	/**
	 * @see https://www.mediawiki.org/wiki/Extension:PageEncryption
	 * @return Key
	 */
	public static function getUserKey() {
		$context = RequestContext::getMain();
		$request = $context->getRequest();
		if ( !$user_key_encoded = $request->getCookie( self::$cookieUserKey ) ) {
			return false;
		}

		try {
			$ret = Key::loadFromAsciiSafeString( $user_key_encoded );
		} catch ( Defuse\Crypto\Exception\WrongKeyOrModifiedCiphertextException $ex ) {
			throw new MWException( 'WrongKeyOrModifiedCiphertextException' );
		}
		return $ret;
	}

	/**
	 * @see https://www.mediawiki.org/wiki/Extension:PageEncryption
	 * @param string $text
	 * @param Key $user_key
	 * @return false|string
	 */
	public static function decryptSymmetric( $text, $user_key ) {
		try {
			$text = Crypto::decrypt( $text, $user_key );
		} catch ( Defuse\Crypto\Exception\WrongKeyOrModifiedCiphertextException $ex ) {
			return false;
		}
		return $text;
	}

	/**
	 * @see https://www.mediawiki.org/wiki/Extension:PageEncryption
	 * @param string $text
	 * @param Key $user_key
	 * @return false|string
	 */
	public static function encryptSymmetric( $text, $user_key ) {
		try {
			$text = Crypto::encrypt( $text, $user_key );
		} catch ( Defuse\Crypto\Exception\WrongKeyOrModifiedCiphertextException $ex ) {
			return false;
		}
		return $text;
	}

	/**
	 * @param OutputPage $outputPage
	 * @param User $user
	 */
	public static function addJsConfigVars( $outputPage, $user ) {
		$outputPage->addJsConfigVars( [
			'userverification-config' => [
				'canManageVerification' => $user->isAllowed( 'userverification-can-manage-verification' ),
				'showNoticeOutdatedVersion' => empty( $GLOBALS['wgUserVerificationDisableVersionCheck'] )
			]
		] );
	}

	/**
	 * @param OutputPage $out
	 */
	public static function disableClientCache( $out ) {
		if ( method_exists( $out, 'disableClientCache' ) ) {
			// MW 1.38+
			$out->disableClientCache();
		} else {
			$out->enableClientCache( false );
		}
	}

	/**
	 * @param int $db
	 * @return \Wikimedia\Rdbms\DBConnRef
	 */
	public static function getDB( $db ) {
		if ( !method_exists( MediaWikiServices::class, 'getConnectionProvider' ) ) {
			// @see https://gerrit.wikimedia.org/r/c/mediawiki/extensions/PageEncryption/+/1038754/comment/4ccfc553_58a41db8/
			return MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( $db );
		}
		$connectionProvider = MediaWikiServices::getInstance()->getConnectionProvider();
		switch ( $db ) {
			case DB_PRIMARY:
			case DB_MASTER:
				return $connectionProvider->getPrimaryDatabase();
			case DB_REPLICA:
			default:
				return $connectionProvider->getReplicaDatabase();
		}
	}

	/**
	 * @see https://www.mediawiki.org/wiki/Extension:VisualData
	 * @param array $defaultParams
	 * @param array $params
	 * @return array
	 */
	public static function applyDefaultParams( $defaultParams, $params ) {
		$ret = [];
		foreach ( $defaultParams as $key => $value ) {
			[ $defaultValue, $type ] = $value;
			$val = $defaultValue;
			if ( array_key_exists( $key, $params ) ) {
				$val = $params[$key];
			}

			switch ( $type ) {
				case 'bool':
				case 'boolean':
					$val = filter_var( $val, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
					if ( $val === null ) {
						$val = filter_var( $defaultValue, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
					}
					settype( $val, "bool" );
					break;

				case 'array':
					$val = array_filter(
						preg_split( '/\s*,\s*/', $val, -1, PREG_SPLIT_NO_EMPTY ) );
					break;

				case 'number':
					$val = filter_var( $val, FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE );
					settype( $val, "float" );
					break;

				case 'int':
				case 'integer':
					$val = filter_var( $val, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE );
					settype( $val, "integer" );
					break;

				default:
			}

			$ret[$key] = $val;
		}

		return $ret;
	}

	/**
	 * @see https://www.mediawiki.org/wiki/Extension:VisualData
	 * @param array $parameters
	 * @param array $defaultParameters []
	 * @return array
	 */
	public static function parseParameters( $parameters, $defaultParameters = [] ) {
		// unnamed parameters
		$a = [];

		// known named parameters
		$b = [];

		// unknown named parameters
		$c = [];
		foreach ( $parameters as $value ) {
			if ( strpos( $value, '=' ) !== false ) {
				[ $k, $v ] = explode( '=', $value, 2 );
				$k = trim( $k );
				$k_ = str_replace( ' ', '-', $k );

				if ( in_array( $k, $defaultParameters ) || in_array( $k_, $defaultParameters ) ) {
					$b[$k_] = trim( $v );
					continue;
				} else {
					$c[$k] = trim( $v );
					continue;
				}
			}
			$a[] = $value;
		}

		return [ $a, $b, $c ];
	}
}
