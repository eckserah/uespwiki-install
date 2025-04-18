<?php

use MediaWiki\Auth\AbstractPreAuthenticationProvider;

class AbuseFilterPreAuthenticationProvider extends AbstractPreAuthenticationProvider {
	public function testForAccountCreation( $user, $creator, array $reqs ) {
		return $this->testUser( $user, $creator, false );
	}

	public function testUserForCreation( $user, $autocreate, array $options = [] ) {
		// if this is not an autocreation, testForAccountCreation already handled it
		if ( $autocreate ) {
			// FIXME Using the constructor directly here a bit hacky but needed for T272244
			return $this->testUser( $user, new User, true );
		}
		return StatusValue::newGood();
	}

	/**
	 * @param User $user The user being created or autocreated
	 * @param User $creator The user who caused $user to be created (can be anonymous)
	 * @param bool $autocreate Is this an autocreation?
	 * @return StatusValue
	 */
	protected function testUser( $user, $creator, $autocreate ) {
		if ( $user->getName() == wfMessage( 'abusefilter-blocker' )->inContentLanguage()->text() ) {
			return StatusValue::newFatal( 'abusefilter-accountreserved' );
		}

		$vars = new AbuseFilterVariableHolder;

		// generateUserVars records $creator->getName() which would be the IP for unregistered users
		if ( $creator->isLoggedIn() ) {
			$vars->addHolders( AbuseFilter::generateUserVars( $creator ) );
		}

		$vars->setVar( 'ACTION', $autocreate ? 'autocreateaccount' : 'createaccount' );
		$vars->setVar( 'ACCOUNTNAME', $user->getName() );

		// pass creator in explicitly to prevent recording the current user on autocreation - T135360
		$status = AbuseFilter::filterAction( $vars, SpecialPage::getTitleFor( 'Userlogin' ),
			'default', $creator );

		return $status->getStatusValue();
	}
}
