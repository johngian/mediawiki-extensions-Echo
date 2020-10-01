<?php

namespace EchoPush;

use EchoServices;
use Job;

abstract class AbstractNotificationRequestJob extends Job {

	/**
	 * @return bool success
	 */
	public function run(): bool {
		$centralId = $this->params['centralId'];
		$echoServices = EchoServices::getInstance();
		$subscriptionManager = $echoServices->getPushSubscriptionManager();
		$subscriptions = $subscriptionManager->getSubscriptionsForUser( $centralId );
		if ( count( $subscriptions ) === 0 ) {
			return true;
		}
		$this->serviceClient = $echoServices->getPushNotificationServiceClient();
		$this->sendPushRequests( $this->serviceClient, $subscriptions );
		return true;
	}

	/**
	 * @param NotificationServiceClient $client
	 * @param array array of Subscription objects $subscriptions
	 */
	abstract protected function sendPushRequests( $client, $subscriptions );
}
