<?php

namespace EchoPush;

use MediaWiki\MediaWikiServices;

class EditReminderJob extends AbstractNotificationRequestJob {
	/**
	 * @param NotificationServiceClient $client
	 * @param array array of Subscription objects $subscriptions
	 */
	protected function sendPushRequests( $client, $subscriptions ) {
		$user = Utils::getPushUser( $this->params['centralId'] );

		if ( $user && $user->isRegistered ) {
			$service = MediaWikiServices::getInstance();
			$extensionConfig = $service->getConfigFactory()->makeConfig( 'Echo' );

			$lastEditTimestamp = (int)$user->getLatestEditTimestamp();
			$reminderInterval = $extensionConfig->get( 'EchoPushEditReminderInterval' );
			$now = (int)wfTimestamp();

			if ( $now - $reminderInterval > $lastEditTimestamp ) {
				$client->sendEditReminderRequests( $subscriptions );
			}
		}
	}
}
