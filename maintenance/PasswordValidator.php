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

class PasswordValidator {
	// @credits https://github.com/briannippert/Password-Validator/blob/master/PasswordValidatorv2.js
	private $conf = [
		'minSize' => 5,
		'maxSize' => 15,
		'lengthConfigured' => true,
		'uppercaseConfigured' => true,
		'digitConfigured' => true,
		'specialConfigured' => true,
		'prohibitedConfigured' => true,
		'specialCharacters' => [ '_', '#', '%', '*', '@' ],
		'prohibitedCharacters' => [ '$', '&', '=', '!' ]
	];

	public function __construct() {
	}

	public function checkPassword( $value ) {
		$length = $this->conf['lengthConfigured'] ? $this->checkLength( $value ) : true;
		$upper = $this->conf['uppercaseConfigured'] ? $this->checkUpperCase( $value ) : true;
		$digit = $this->conf['digitConfigured'] ? $this->checkDigit( $value ) : true;
		$special = $this->conf['specialConfigured'] ? $this->checkSpecialCharacters( $value ) : true;
		$prohibited = $this->conf['prohibitedConfigured'] ?
			$this->checkProhibitedCharacter( $value ) :
			true;

		$errors = [];
		if ( !$length ) {
			$errors[] = 'length';
		}
		if ( !$upper ) {
			$errors[] = 'uppercase';
		}
		if ( !$digit ) {
			$errors[] = 'digit';
		}
		if ( !$special ) {
			$errors[] = 'special';
		}
		if ( $prohibited ) {
			$errors[] = 'prohibited';
		}

		return $errors;
	}

	public function checkSpecialCharacters( $str ) {
		// var specialChar = new RegExp("[_\\-#%*\\+]");
		return preg_match( '/[' . preg_quote( implode( '', $this->conf['specialCharacters'] ) ) . ']/', $str );
	}

	public function checkProhibitedCharacter( $str ) {
		return preg_match( '/[' . preg_quote( implode( '', $this->conf['prohibitedCharacters'] ) ) . ']/', $str );
	}

	public function checkDigit( $str ) {
		return preg_match( '/\d/', $str );
	}

	public function checkUpperCase( $str ) {
		return preg_match( '/[^A-Z]/', $str );
	}

	public function checkLength( $str ) {
		return strlen( $str ) >= $this->conf['minSize'] && strlen( $str ) <= $this->conf['maxSize'];
	}

	public function getConf() {
		return $this->conf;
	}
}
