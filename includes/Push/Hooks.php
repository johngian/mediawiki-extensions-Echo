<?php

namespace EchoPush;

use EchoServices;
use JobQueueGroup;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\MediaWikiServices;
use MediaWiki\Storage\EditResult;
use MediaWiki\User\UserIdentity;
use User;
use WikiPage;

class Hooks {
	/**
	 * Handler for PageSaveComplete hook
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/PageSaveComplete
	 *
	 * @param WikiPage $wikiPage modified WikiPage
	 * @param UserIdentity $userIdentity User who edited
	 * @param string $summary Edit summary
	 * @param int $flags Edit flags
	 * @param RevisionRecord $revisionRecord Revision that was created
	 * @param EditResult $editResult
	 */
	public static function onPageSaveComplete(
		WikiPage $wikiPage,
		UserIdentity $userIdentity,
		string $summary,
		int $flags,
		RevisionRecord $revisionRecord,
		EditResult $editResult
	) {
		$service = MediaWikiServices::getInstance();
		$extensionConfig = $service->getConfigFactory()->makeConfig( 'Echo' );
		$editReminderIsEnabled = $extensionConfig->get( 'EchoPushEditReminderEnabled' );

		if ( $editReminderIsEnabled && $userIdentity->isRegistered() ) {
			$user = User::newFromIdentity( $userIdentity );
			$reminderInterval = $extensionConfig->get( 'EchoPushEditReminderInterval' );
			$params = [
				'centralId' => Utils::getPushUserId( $user ),
				'jobReleaseTimestamp' => (int)wfTimestamp() + $reminderInterval,
			];
			$editReminderJob = new EditReminderJob( 'EchoPushEditReminder', $params );
			JobQueueGroup::singleton()->push( $editReminderJob );
		}
	}
}
