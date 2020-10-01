<?php

namespace EchoPush;

use CentralIdLookup;
use User;

class Utils {

	/**
	 * Attempt to get a unique ID for the specified user, accounting for installations both with
	 * and without CentralAuth: Return the user's central ID, if available. If there is no central
	 * user associated with the local user (i.e., centralIdFromLocalUser returns 0), fall back to
	 * returning the local user ID.
	 * @param User $user
	 * @return int
	 */
	public static function getPushUserId( User $user ): int {
		return CentralIdLookup::factory()->centralIdFromLocalUser(
			$user,
			CentralIdLookup::AUDIENCE_RAW
		) ?: $user->getId();
	}

	/**
	 * Return a User object given a matching central ID and fallback to a local ID.
	 * @param int $userId
	 * @return User
	 */
	public static function getPushUser( int $userId ): User {
		return CentralIdLookup::factory()->localUserFromCentralId(
			$userId,
			CentralIdLookup::AUDIENCE_RAW
		) ?: User::newFromId( $userId );
	}
}
