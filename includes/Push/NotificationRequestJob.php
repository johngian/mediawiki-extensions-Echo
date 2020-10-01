<?php

namespace EchoPush;

class NotificationRequestJob extends AbstractNotificationRequestJob {
	/**
	 * @param NotificationServiceClient $client
	 * @param array array of Subscription objects $subscriptions
	 */
	protected function sendPushRequests( $client, $subscriptions ) {
		$client->sendCheckEchoRequests( $subscriptions );
	}
}
