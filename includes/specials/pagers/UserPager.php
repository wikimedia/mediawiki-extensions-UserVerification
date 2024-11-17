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

namespace MediaWiki\Extension\UserVerification\Pagers;

// MW 1.42
if ( class_exists( 'MediaWiki\SpecialPage\SpecialPage', false ) ) {
	class_alias( 'MediaWiki\SpecialPage\SpecialPage', 'SpecialPageClass' );
} else {
	class_alias( 'SpecialPage', 'SpecialPageClass' );
}

use Linker;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\MediaWikiServices;
use MWException;
use ParserOutput;
use SpecialPageClass;
use TablePager;

class UserPager extends TablePager {

	/** @var request */
	private $request;

	/** @var parentClass */
	private $parentClass;

	// @IMPORTANT!, otherwise the pager won't show !
	/** @var mLimit */
	public $mLimit = 20;

	/**
	 * @param SpecialVisualDataBrowse $parentClass
	 * @param Request $request
	 * @param LinkRenderer $linkRenderer
	 */
	public function __construct( $parentClass, $request, LinkRenderer $linkRenderer ) {
		parent::__construct( $parentClass->getContext(), $linkRenderer );

		$this->request = $request;
		$this->parentClass = $parentClass;
	}

	/**
	 * @inheritDoc
	 */
	public function getFullOutput() {
		$navigation = $this->getNavigationBar();
		// $body = parent::getBody();

		$parentParent = get_parent_class( get_parent_class( $this ) );
		$body = $parentParent::getBody();

		$pout = new ParserOutput;
		// $navigation .
		$pout->setText( $body . $navigation );
		$pout->addModuleStyles( $this->getModuleStyles() );
		return $pout;
	}

	/**
	 * @param IResultWrapper $result
	 */
	public function preprocessResults( $result ) {
	}

	/**
	 * @return array
	 */
	protected function getFieldNames() {
		$headers = [
			'user_name',
			'user_real_name',
			'user_email',
			'user_email_authenticated',
			'user_registration',
			'user_editcount',
			'autoconfirmed',
			'verification',
			'actions',
		];

		$ret = [];
		foreach ( $headers as $val ) {
			$ret[$val] = $this->msg( "userverification-pager-header-$val" )->text();
		}

		return $ret;
	}

	/**
	 * @param string $field
	 * @param string $value
	 * @return string HTML
	 * @throws MWException
	 */
	public function formatValue( $field, $value ) {
		/** @var object $row */
		$row = $this->mCurrentRow;
		$linkRenderer = $this->getLinkRenderer();
		$formatted = '';

		switch ( $field ) {
			case 'verification':
				$formatted = $row->status ?? $this->msg( 'userverification-pager-field-none' )->text();
				break;

			case 'autoconfirmed':
				$services = MediaWikiServices::getInstance();
				$userIdentityLookup = $services->getUserIdentityLookup();
				$user = $userIdentityLookup->getUserIdentityByName( $row->user_name );

				if ( $user ) {
					$userGroupManager = $services->getUserGroupManager();
					$userGroups = $userGroupManager->getUserEffectiveGroups( $user );

					$msg = ( in_array( 'autoconfirmed', $userGroups ) ? 'yes' : 'no' );
					$formatted = $this->msg( "userverification-pager-field-$msg" )->text();
				} else {
					$formatted = $this->msg( "userverification-pager-field-no-valid-user" )->text();
				}
				break;

			case 'user_registration':
			case 'user_email_authenticated':
				$date = ( (array)$row )[$field];
				if ( $date ) {
					$formatted = htmlspecialchars(
						$this->getLanguage()->userDate(
							wfTimestamp( TS_MW, $date ),
							$this->getUser()
						)
					);
				}
				break;

			case 'user_name':
			case 'user_email':
			case 'user_real_name':
			case 'user_email_authenticated':
			case 'user_registration':
			case 'user_editcount':
				$formatted = ( (array)$row )[$field];
				break;

			case 'actions':
				$link = '<span class="mw-ui-button mw-ui-progressive">edit</span>';
				$title_ = SpecialPageClass::getTitleFor( 'UserVerificationList', $row->user_id );
				$query = [];
				$formatted = Linker::link( $title_, $link, [], $query );
				break;

			default:
				throw new MWException( "Unknown field '$field'" );
		}

		return $formatted;
	}

	/**
	 * @return array
	 */
	public function getQueryInfo() {
		$dbr = \UserVerification::getDB( DB_REPLICA );
		$conds = [];
		$join_conds = [];
		$join_conds['userverification'] = [ 'LEFT JOIN', 'user_alias.user_id=userverification.user_id' ];
		$tables = [];
		$tables['user_alias'] = 'user';
		$tables['userverification'] = 'userverification_verification';
		$options = [];
		$fields = [ 'user_alias.*', 'userverification.status', 'userverification.method' ];

		$username = $this->request->getVal( 'username' );
		if ( !empty( $username ) ) {
			$services = MediaWikiServices::getInstance();
			$userIdentityLookup = $services->getUserIdentityLookup();
			$user = $userIdentityLookup->getUserIdentityByName( $username );
			$user_id = $user->getId();
			$conds[ 'user_alias.user_id' ] = $user_id;
		}

		$status = $this->request->getVal( 'status' );
		if ( !empty( $status ) ) {
			$conds[ 'status' ] = $status !== 'none' ? $status : null;
		}

		$ret['tables'] = $tables;
		$ret['fields'] = $fields;
		$ret['join_conds'] = $join_conds;
		$ret['conds'] = $conds;
		$ret['options'] = $options;

		return $ret;
	}

	/**
	 * @return string
	 */
	protected function getTableClass() {
		return parent::getTableClass() . ' visualdata-special-browse-pager-table';
	}

	/**
	 * @return string
	 */
	public function getIndexField() {
		return 'user_name';
	}

	/**
	 * @return string
	 */
	public function getDefaultSort() {
		return 'user_name';
	}

	/**
	 * @param string $field
	 * @return bool
	 */
	protected function isFieldSortable( $field ) {
		// return false;
	}
}
