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

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";
include_once __DIR__ . '/PasswordValidator.php';

if ( is_readable( __DIR__ . '/../vendor/autoload.php' ) ) {
	include_once __DIR__ . '/../vendor/autoload.php';
}

use Defuse\Crypto\KeyProtectedByPassword;

class CreateKeys extends Maintenance {
	/** @var User */
	private $user;

	/** @var limit */
	private $limit;

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'create key' );
		$this->requireExtension( 'UserVerification' );

		// name,  description, required = false,
		//	withArg = false, shortName = false, multiOccurrence = false
		//	$this->addOption( 'format', 'import format (csv or json)', true, true );

		$this->addOption( 'password', 'password', true, true );
	}

	/**
	 * @return null
	 */
	private function getRequestId() {
		return null;
	}

	/**
	 * inheritDoc
	 * @return string|void
	 */
	public function execute() {
		$password = $this->getOption( 'password' );

		if ( empty( $password ) ) {
			return 'no password';
		}

		$passwordValidator = new PasswordValidator();
		$errors = $passwordValidator->checkPassword( $password );
		$conf = $passwordValidator->getConf();

		if ( count( $errors ) ) {
			$errorMessages = $this->showMessageError( $conf, $errors );
			foreach ( $errorMessages as $msg ) {
				echo $msg . PHP_EOL;
			}
			return;
		}

		$protected_key = KeyProtectedByPassword::createRandomPasswordProtectedKey( $password );
		$protected_key_encoded = $protected_key->saveToAsciiSafeString();

		// https://php.watch/articles/modern-php-encryption-decryption-sodium
		$keypair = sodium_crypto_box_keypair();
		$secret_key = sodium_crypto_box_secretkey( $keypair );
		$public_key = sodium_crypto_box_publickey( $keypair );

		// $protected_key = KeyProtectedByPassword::loadFromAsciiSafeString( $protected_key_encoded );
		$user_key = $protected_key->unlockKey( $password );
		$encrypted_private_key = \UserVerification::encryptSymmetric( $secret_key, $user_key );

		$row = [
			'public_key' => $public_key,
			'protected_key' => $protected_key_encoded,
			'encrypted_private_key' => $encrypted_private_key,

			// alternate solution: derive key-pair each time
			// @see https://security.stackexchange.com/questions/268242/feedback-wanted-regarding-my-functions-to-encrypt-decrypt-data-using-php-openss
			// 'public_key' => sodium_crypto_box_publickey( $skpk )
			'enabled' => 1,
		];

		$date = date( 'Y-m-d H:i:s' );
		$dbr = \UserVerification::getDB( DB_MASTER );

		try {
			$res = $dbr->insert( 'userverification_keys', $row + [ 'updated_at' => $date, 'created_at' => $date ] );
		} catch ( Exception $e ) {
			echo 'keys exist' . PHP_EOL;
			return;
		}

		echo 'keys created' . PHP_EOL;
	}

	/**
	 * @param array $conf
	 * @param array $errors
	 * @return array
	 */
	private function showMessageError( $conf, $errors ) {
		$errorsMessages = [];

		foreach ( $errors as $error ) {
			$args = [ 'userverification-createkeys-password-error-' . $error ];
			switch ( $error ) {
				case 'length':
					$args[] = $conf['minSize'];
					$args[] = $conf['maxSize'];
					break;

				case 'special':
					$args[] = implode( ', ', $conf['specialCharacters'] );
					break;

				case 'prohibited':
					$args[] = implode( ', ', $conf['prohibitedCharacters'] );
					break;
			}

			$errorsMessages[] = call_user_func_array( 'wfMessage', $args )->text();
		}

		return $errorsMessages;
	}
}

$maintClass = CreateKeys::class;
require_once RUN_MAINTENANCE_IF_MAIN;
