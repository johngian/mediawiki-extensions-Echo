<?php

use EchoPush\Utils;

/**
 * @group medium
 * @group API
 * @group Database
 * @covers \EchoPush\Api\ApiEchoPushSubscriptionsDelete
 */
class ApiEchoPushSubscriptionsDeleteTest extends ApiTestCase {

	/** @var User */
	private $user;

	/** @var User */
	private $subscriptionManager;

	/** @var User */
	private $otherUser;

	public function setUp(): void {
		parent::setUp();
		$this->setMwGlobals( [
			'wgEchoEnablePush' => true,
			'wgEchoPushMaxSubscriptionsPerUser' => 3
		] );
		$this->tablesUsed[] = 'echo_push_subscription';
		$this->tablesUsed[] = 'echo_push_provider';

		// Use mutable users for our generic users so we don't get two references to the same User
		$this->user = $this->getMutableTestUser()->getUser();
		$this->otherUser = $this->getMutableTestUser()->getUser();
		$this->subscriptionManager = $this->getTestUser( 'push-subscription-manager' )->getUser();

		$this->createTestData();
	}

	public function testApiDeleteSubscription(): void {
		$params = [
			'action' => 'echopushsubscriptions',
			'command' => 'delete',
			'providertoken' => 'ABC',
		];
		$result = $this->doApiRequestWithToken( $params, null, $this->user );
		$this->assertEquals( 'Success', $result[0]['delete']['result'] );
	}

	public function testApiDeleteSubscriptionNotFound(): void {
		$params = [
			'action' => 'echopushsubscriptions',
			'command' => 'delete',
			'providertoken' => 'XYZ',
		];
		$this->expectException( ApiUsageException::class );
		$this->doApiRequestWithToken( $params, null, $this->user );
	}

	public function testApiDeleteSubscriptionWithOwnCentralId(): void {
		$params = [
			'action' => 'echopushsubscriptions',
			'command' => 'delete',
			'providertoken' => 'ABC',
			'centraluserid' => Utils::getPushUserId( $this->user ),
		];
		$result = $this->doApiRequestWithToken( $params, null, $this->user );
		$this->assertEquals( 'Success', $result[0]['delete']['result'] );
	}

	public function testApiDeleteSubscriptionWithOtherNonSubscriptionManagerUser(): void {
		$params = [
			'action' => 'echopushsubscriptions',
			'command' => 'delete',
			'providertoken' => 'ABC',
			'centraluserid' => Utils::getPushUserId( $this->user ),
		];
		$this->expectException( ApiUsageException::class );
		$this->doApiRequestWithToken( $params, null, $this->otherUser );
	}

	public function testApiDeleteSubscriptionWithPushSubscriptionManager(): void {
		$params = [
			'action' => 'echopushsubscriptions',
			'command' => 'delete',
			'providertoken' => 'ABC',
		];
		$result = $this->doApiRequestWithToken( $params, null, $this->subscriptionManager );
		$this->assertEquals( 'Success', $result[0]['delete']['result'] );
	}

	private function createTestData(): void {
		$subscriptionManager = EchoServices::getInstance()->getPushSubscriptionManager();
		$userId = Utils::getPushUserId( $this->user );
		$subscriptionManager->create( 'fcm', 'ABC', $userId );
	}

}
