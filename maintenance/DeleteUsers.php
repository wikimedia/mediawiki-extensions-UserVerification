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
 * @copyright Copyright ©2025, https://wikisphere.org
 */

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";

class DeleteUsers extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'delete users with no edits or specific users' );
		$this->requireExtension( 'UserVerification' );

		// name,  description, required = false,
		// withArg = false, shortName = false, multiOccurrence = false
		$this->addOption( 'registered-before', 'registered before (days)', false, true );
		$this->addOption( 'users', 'specify users', false, true );
		$this->addOption( 'delete', 'do delete', false, false );
	}

	/**
	 * inheritDoc
	 * @return string|void
	 */
	public function execute() {
		$registrationBefore = (int)$this->getOption( 'registeredBefore' ) ?? 0;

		if ( $this->hasOption( 'users' ) ) {
			$users = $this->parseUsers();
		} else {
			$users = $this->getUsersDB();
		}

		$user = User::newSystemUser( 'Maintenance script', [ 'steal' => true ] );
		echo 'The following users will be deleted: ' . implode( ', ', $users ) . PHP_EOL;

		if ( $this->hasOption( 'delete' ) ) {
			$ret = \UserVerification::deleteUsers( $user, $users );
			echo ( $ret === true ? 'done' : 'no changes' ) . PHP_EOL;

		} else {
			echo 'use the --delete option to actually perform the deletion' . PHP_EOL;
		}
	}

	/**
	 * @return array
	 */
	protected function parseUsers() {
		$users = (string)$this->getOption( 'users' ) ?? '';

		$filterNumeric = static function ( $value ) {
			if ( is_numeric( $value ) ) {
				return (int)$value;
			}
		};

		if ( empty( $users ) ) {
			return [];
		}

		$users = preg_split( '/\s*,\s*/', $users, -1, PREG_SPLIT_NO_EMPTY );
		return array_filter( $users, $filterNumeric );
	}

	/**
	 * @return array
	 */
	protected function getUsersDB() {
		$dbr = \UserVerification::getDB( DB_REPLICA );
		$tables = [ 'user' ];
		$conds = [
			'user_editcount' => 0,

			// select only regular users
			'user_password != ""'
		];

		if ( !empty( $registrationBefore ) ) {
			$ts = strtotime( "-$registrationBefore days" );
			$mwTs = wfTimestamp( TS_MW, $ts );
			$conds[] = "user_registration < $mwTs";
		}

		$options = [];
		$joins = [];
		$res = $dbr->select(
			$tables,
			// fields
			[ 'user_id' ],
			$conds,
			__METHOD__,
			$options,
			$joins
		);

		$ret = [];
		foreach ( $res as $row ) {
			$ret[] = $row->user_id;
		}

		return $ret;
	}

}

$maintClass = DeleteUsers::class;
require_once RUN_MAINTENANCE_IF_MAIN;
