<?php

class ApiEchoNotifications extends ApiCrossWikiBase {
	/**
	 * @var bool
	 */
	protected $crossWikiSummary = false;

	public function __construct( $query, $moduleName ) {
		parent::__construct( $query, $moduleName, 'not' );
	}

	public function execute() {

		// To avoid API warning, register the parameter used to bust browser cache
		$this->getMain()->getVal( '_' );

		if ( $this->getUser()->isAnon() ) {
			$this->dieUsage( 'Login is required', 'login-required' );
		}

		$params = $this->extractRequestParams();

		/* @deprecated */
		if ( $params['format'] === 'flyout' ) {
			$this->setWarning(
				"notformat=flyout has been deprecated and will be removed soon.\n".
				"Use notformat=model to get the raw data or notformat=special\n".
				"for pre-rendered HTML."
			);
		} elseif ( $params['format'] === 'html' ) {
			$this->setWarning(
				"notformat=html has been deprecated and will be removed soon.\n".
				"Use notformat=special instead."
			);
		}

		if ( $this->allowCrossWikiNotifications() ) {
			$this->crossWikiSummary = $params['crosswikisummary'];
		}

		$results = array();
		if ( in_array( wfWikiId(), $this->getRequestedWikis() ) ) {
			$results[wfWikiId()] = $this->getLocalNotifications( $params );
		}

		if ( $this->getRequestedForeignWikis() ) {
			$foreignResults = $this->getFromForeign();
			foreach ( $foreignResults as $wiki => $result ) {
				if ( isset( $result['query']['notifications'] ) ) {
					$results[$wiki] = $result['query']['notifications'];
				}
			}
		}

		// after getting local & foreign results, merge them all together
		$result = $this->mergeResults( $results, $params );
		if ( $params['groupbysection'] ) {
			foreach ( $params['sections'] as $section ) {
				if ( in_array( 'list', $params['prop'] ) ) {
					$this->getResult()->setIndexedTagName( $result[$section]['list'], 'notification' );
				}
			}
		} else {
			if ( in_array( 'list', $params['prop'] ) ) {
				$this->getResult()->setIndexedTagName( $result['list'], 'notification' );
			}
		}
		$this->getResult()->addValue( 'query', $this->getModuleName(), $result );
	}

	/**
	 * @param array $params
	 * @return array
	 */
	protected function getLocalNotifications( array $params ) {
		$user = $this->getUser();
		$prop = $params['prop'];
		$titles = null;
		if ( $params['titles'] ) {
			$titles = array_values( array_filter( array_map( 'Title::newFromText', $params['titles'] ) ) );
		}

		$result = array();
		if ( in_array( 'list', $prop ) ) {
			// Group notification results by section
			if ( $params['groupbysection'] ) {
				foreach ( $params['sections'] as $section ) {
					$result[$section] = $this->getSectionPropList(
						$user, $section, $params['filter'], $params['limit'],
						$params[$section . 'continue'], $params['format'],
						$titles, $params[$section . 'unreadfirst']
					);

					if ( $this->crossWikiSummary ) {
						// insert fake notification for foreign notifications
						$foreignNotification = $this->makeForeignNotification( $user, $params['format'], $section );
						if ( $foreignNotification ) {
							array_unshift( $result[$section]['list'], $foreignNotification );
						}
					}
				}
			} else {
				$attributeManager = EchoAttributeManager::newFromGlobalVars();
				$result = $this->getPropList(
					$user,
					$attributeManager->getUserEnabledEventsbySections( $user, 'web', $params['sections'] ),
					$params['filter'], $params['limit'], $params['continue'], $params['format'],
					$titles, $params['unreadfirst']
				);

				// if exactly 1 section is specified, we consider only that section, otherwise
				// we pass ALL to consider all foreign notifications
				$section = count( $params['sections'] ) === 1 ? reset( $params['sections'] ) : EchoAttributeManager::ALL;
				if ( $this->crossWikiSummary ) {
					$foreignNotification = $this->makeForeignNotification( $user, $params['format'], $section );
					if ( $foreignNotification ) {
						array_unshift( $result['list'], $foreignNotification );
					}
				}
			}
		}

		if ( in_array( 'count', $prop ) ) {
			$result = array_merge_recursive(
				$result,
				$this->getPropCount( $user, $params['sections'], $params['groupbysection'] )
			);
		}

		return $result;
	}

	/**
	 * Internal method for getting the property 'list' data for individual section
	 * @param User $user
	 * @param string $section 'alert' or 'message'
	 * @param string $filter 'all', 'read' or 'unread'
	 * @param int $limit
	 * @param string $continue
	 * @param string $format
	 * @param Title[] $titles
	 * @param boolean $unreadFirst
	 * @return array
	 */
	protected function getSectionPropList( User $user, $section, $filter, $limit, $continue, $format, array $titles = null, $unreadFirst = false ) {
		$attributeManager = EchoAttributeManager::newFromGlobalVars();
		$sectionEvents = $attributeManager->getUserEnabledEventsbySections( $user, 'web', array( $section ) );

		if ( !$sectionEvents ) {
			$result = array(
				'list' => array(),
				'continue' => null
			);
		} else {
			$result = $this->getPropList(
				$user, $sectionEvents, $filter, $limit, $continue, $format, $titles, $unreadFirst
			);
		}

		return $result;
	}

	/**
	 * Internal helper method for getting property 'list' data, this is based
	 * on the event types specified in the arguments and it could be event types
	 * of a set of sections or a single section
	 * @param User $user
	 * @param string[] $eventTypes
	 * @param string $filter 'all', 'read' or 'unread'
	 * @param int $limit
	 * @param string $continue
	 * @param string $format
	 * @param Title[] $titles
	 * @param boolean $unreadFirst
	 * @return array
	 */
	protected function getPropList( User $user, array $eventTypes, $filter, $limit, $continue, $format, array $titles = null, $unreadFirst = false ) {
		$result = array(
			'list' => array(),
			'continue' => null
		);

		$notifMapper = new EchoNotificationMapper();

		// check if we want both read & unread...
		if ( in_array( 'read', $filter ) && in_array( '!read', $filter ) ) {
			// Prefer unread notifications. We don't care about next offset in this case
			if ( $unreadFirst ) {
				// query for unread notifications past 'continue' (offset)
				$notifs = $notifMapper->fetchUnreadByUser( $user, $limit + 1, $continue, $eventTypes, $titles );

				/*
				 * 'continue' has a timestamp & id (to start with, in case
				 * there would be multiple events with that same timestamp)
				 * Unread notifications should always load first, but may be
				 * older than read ones, but we can work with current
				 * 'continue' format:
				 * * if there's no continue, first load unread notifications
				 * * if there's a continue, fetch unread notifications first
				 * * if there are no unread ones, continue must've been
				 *   about read notifications: fetch 'em
				 * * if there are unread ones but first one doesn't match
				 *   continue id, it must've been about read notifications:
				 *   discard unread & fetch read
				 */
				if ( $notifs && $continue ) {
					/** @var EchoNotification $first */
					$first = reset( $notifs );
					$continueId = intval( trim( strrchr( $continue, '|' ), '|' ) );
					if ( $first->getEvent()->getID() !== $continueId ) {
						// notification doesn't match continue id, it must've been
						// about read notifications: discard all unread ones
						$notifs = array();
					}
				}

				// If there are less unread notifications than we requested,
				// then fill the result with some read notifications
				$count = count( $notifs );
				// we need 1 more than $limit, so we can respond 'continue'
				if ( $count <= $limit ) {
					// Query planner should be smart enough that passing a short list of ids to exclude
					// will only visit at most that number of extra rows.
					$mixedNotifs = $notifMapper->fetchByUser(
						$user,
						$limit - $count + 1,
						// if there were unread notifications, 'continue' was for
						// unread notifications and we should start fetching read
						// notifications from start
						$count > 0 ? null : $continue,
						$eventTypes,
						array_keys( $notifs ),
						$titles
					);
					foreach ( $mixedNotifs as $notif ) {
						$notifs[$notif->getEvent()->getId()] = $notif;
					}
				}
			} else {
				$notifs = $notifMapper->fetchByUser( $user, $limit + 1, $continue, $eventTypes, array(), $titles );
			}
		} elseif ( in_array( 'read', $filter ) ) {
			$notifs = $notifMapper->fetchReadByUser( $user, $limit + 1, $continue, $eventTypes, $titles );
		} else { // = if ( in_array( '!read', $filter ) ) {
			$notifs = $notifMapper->fetchUnreadByUser( $user, $limit + 1, $continue, $eventTypes, $titles );
		}

		foreach ( $notifs as $notif ) {
			$output = EchoDataOutputFormatter::formatOutput( $notif, $format, $user, $this->getLanguage() );
			if ( $output !== false ) {
				$result['list'][] = $output;
			}
		}

		// Generate offset if necessary
		if ( count( $result['list'] ) > $limit ) {
			$lastItem = array_pop( $result['list'] );
			// @todo: what to do with this when fetching from multiple wikis?
			$result['continue'] = $lastItem['timestamp']['utcunix'] . '|' . $lastItem['id'];
		}

		return $result;
	}

	/**
	 * Internal helper method for getting property 'count' data
	 * @param User $user
	 * @param string[] $sections
	 * @param boolean $groupBySection
	 * @return array
	 */
	protected function getPropCount( User $user, array $sections, $groupBySection ) {
		$result = array();
		$notifUser = MWEchoNotifUser::newFromUser( $user );
		$global = $this->crossWikiSummary ? 'preference' : false;
		// Always get total count
		$rawCount = $notifUser->getNotificationCount( true, DB_SLAVE, EchoAttributeManager::ALL, $global );
		$result['rawcount'] = $rawCount;
		$result['count'] = EchoNotificationController::formatNotificationCount( $rawCount );

		if ( $groupBySection ) {
			foreach ( $sections as $section ) {
				$rawCount = $notifUser->getNotificationCount( /* $tryCache = */true, DB_SLAVE, $section, $global );
				$result[$section]['rawcount'] = $rawCount;
				$result[$section]['count'] = EchoNotificationController::formatNotificationCount( $rawCount );
			}
		}

		return $result;
	}

	/**
	 * Build and format a "fake" notification to represent foreign notifications.
	 * @param User $user
	 * @param string $format
	 * @param string $section
	 * @return array|false A formatted notification, or false if there are no foreign notifications
	 */
	protected function makeForeignNotification( User $user, $format, $section = EchoAttributeManager::ALL ) {
		global $wgEchoSectionTransition, $wgEchoBundleTransition;
		if (
			( $wgEchoSectionTransition && $section !== EchoAttributeManager::ALL ) ||
			$wgEchoBundleTransition
		) {
			// In section transition mode we trust that echo_unread_wikis is accurate for the total of alerts+messages,
			// but not for each section individually (i.e. we don't trust that notifications won't be misclassified).
			// We get all wikis that have any notifications at all according to the euw table,
			// and query them to find out what's really there.
			// In bundle transition mode, we trust that notifications are classified correctly, but we don't
			// trust the counts in the table.
			$potentialWikis = $this->foreignNotifications->getWikis( $wgEchoSectionTransition ? EchoAttributeManager::ALL : $section );
			if ( !$potentialWikis ) {
				return false;
			}
			$foreignResults = $this->getFromForeign( $potentialWikis, array( $this->getModulePrefix() . 'filter' => '!read' ) );

			$countsByWiki = array();
			$timestampsByWiki = array();
			foreach ( $foreignResults as $wiki => $result ) {
				if ( isset( $result['query']['notifications']['list'] ) ) {
					$notifs = $result['query']['notifications']['list'];
				} elseif ( isset( $result['query']['notifications'][$section]['list'] ) ) {
					$notifs = $result['query']['notifications'][$section]['list'];
				} else {
					$notifs = false;
				}
				if ( $notifs ) {
					$countsByWiki[$wiki] = count( $notifs );
					$timestampsByWiki[$wiki] = max( array_map( function ( $n ) {
						return $n['timestamp']['mw'];
					}, $notifs ) );
				}
			}

			$wikis = array_keys( $countsByWiki );
			$count = array_sum( $countsByWiki );
			$maxTimestamp = new MWTimestamp( max( $timestampsByWiki ) );
			$timestampsByWiki = array_map( function ( $ts ) {
				return new MWTimestamp( $ts );
			}, $timestampsByWiki );
		} else {
			// In non-transition mode, or when querying all sections, we can trust the euw table
			$wikis = $this->foreignNotifications->getWikis( $section );
			$count = $this->foreignNotifications->getCount( $section );
			$maxTimestamp = $this->foreignNotifications->getTimestamp( $section );
			$timestampsByWiki = array();
			foreach ( $wikis as $wiki ) {
				$timestampsByWiki[$wiki] = $this->foreignNotifications->getWikiTimestamp( $wiki, $section );
			}
		}

		if ( $count === 0 || count( $wikis ) === 0 ) {
			return false;
		}

		// Sort wikis by timestamp, in descending order (newest first)
		usort( $wikis, function ( $a, $b ) use ( $section, $timestampsByWiki ) {
			return $timestampsByWiki[$b]->getTimestamp( TS_UNIX ) - $timestampsByWiki[$a]->getTimestamp( TS_UNIX );
		} );

		$row = new StdClass;
		$row->event_id = -1;
		$row->event_type = 'foreign';
		$row->event_variant = null;
		$row->event_agent_id = $user->getId();
		$row->event_agent_ip = null;
		$row->event_page_id = null;
		$row->event_page_namespace = null;
		$row->event_page_title = null;
		$row->event_extra = serialize( array(
			'section' => $section ?: 'all',
			'wikis' => $wikis,
			'count' => $count
		) );

		$row->notification_user = $user->getId();
		$row->notification_timestamp = $maxTimestamp;
		$row->notification_read_timestamp = null;
		$row->notification_bundle_base = 1;
		$row->notification_bundle_hash = md5( 'bogus' );
		$row->notification_bundle_display_hash = md5( 'also-bogus' );

		// Format output like any other notification
		$notif = EchoNotification::newFromRow( $row );
		$output = EchoDataOutputFormatter::formatOutput( $notif, $format, $user, $this->getLanguage() );

		// Add cross-wiki-specific data
		$output['section'] = $section ?: 'all';
		$output['count'] = $count;
		$output['sources'] = EchoForeignNotifications::getApiEndpoints( $wikis );
		// Add timestamp information
		foreach ( $output['sources'] as $wiki => &$data ) {
			$data['ts'] = $timestampsByWiki[$wiki]->getTimestamp( TS_MW );
		}
		return $output;
	}

	protected function getForeignQueryParams() {
		$params = parent::getForeignQueryParams();

		// don't request cross-wiki notification summaries
		unset( $params['notcrosswikisummary'] );

		return $params;
	}

	/**
	 * @param array $results
	 * @param array $params
	 * @return mixed
	 */
	protected function mergeResults( array $results, array $params ) {
		$master = array_shift( $results );
		if ( !$master ) {
			$master = array();
		}

		if ( in_array( 'list', $params['prop'] ) ) {
			$master = $this->mergeList( $master, $results, $params['groupbysection'] );
		}

		if ( in_array( 'count', $params['prop'] ) && !$this->crossWikiSummary ) {
			// if crosswiki data was requested, the count in $master
			// is accurate already
			// otherwise, we'll want to combine counts for all wikis
			$master = $this->mergeCount( $master, $results, $params['groupbysection'] );
		}

		return $master;
	}

	/**
	 * @param array $master
	 * @param array $results
	 * @param bool $groupBySection
	 * @return array
	 */
	protected function mergeList( array $master, array $results, $groupBySection ) {
		// sort all notifications by timestamp: most recent first
		$sort = function( $a, $b ) {
			return $a['timestamp']['utcunix'] - $b['timestamp']['utcunix'];
		};

		if ( $groupBySection ) {
			foreach ( EchoAttributeManager::$sections as $section ) {
				if ( !isset( $master[$section]['list'] ) ) {
					$master[$section]['list'] = array();
				}
				foreach ( $results as $result ) {
					$master[$section]['list'] = array_merge( $master[$section]['list'], $result[$section]['list'] );
				}
				usort( $master[$section]['list'], $sort );
			}
		} else {
			if ( !isset( $master['list'] ) || !is_array( $master['list'] ) ) {
				$master['list'] = array();
			}
			foreach ( $results as $result ) {
				$master['list'] = array_merge( $master['list'], $result['list'] );
			}
			usort( $master['list'], $sort );
		}

		return $master;
	}

	/**
	 * @param array $master
	 * @param array $results
	 * @param bool $groupBySection
	 * @return array
	 */
	protected function mergeCount( array $master, array $results, $groupBySection ) {
		if ( $groupBySection ) {
			foreach ( EchoAttributeManager::$sections as $section ) {
				if ( !isset( $master[$section]['rawcount'] ) ) {
					$master[$section]['rawcount'] = 0;
				}
				foreach ( $results as $result ) {
					$master[$section]['rawcount'] += $result[$section]['rawcount'];
				}
				$master[$section]['count'] = EchoNotificationController::formatNotificationCount( $master[$section]['rawcount'] );
			}
		}

		if ( !isset( $master['rawcount'] ) ) {
			$master['rawcount'] = 0;
		}
		foreach ( $results as $result ) {
			// regardless of groupbysection, totals are always included
			$master['rawcount'] += $result['rawcount'];
		}
		$master['count'] = EchoNotificationController::formatNotificationCount( $master['rawcount'] );

		return $master;
	}

	public function getAllowedParams() {
		$sections = EchoAttributeManager::$sections;

		$params = parent::getAllowedParams();
		$params += array(
			'filter' => array(
				ApiBase::PARAM_ISMULTI => true,
				ApiBase::PARAM_DFLT => 'read|!read',
				ApiBase::PARAM_TYPE => array(
					'read',
					'!read',
				),
			),
			'prop' => array(
				ApiBase::PARAM_ISMULTI => true,
				ApiBase::PARAM_TYPE => array(
					'list',
					'count',
				),
				ApiBase::PARAM_DFLT => 'list',
			),
			'sections' => array(
				ApiBase::PARAM_DFLT => implode( '|', $sections ),
				ApiBase::PARAM_TYPE => $sections,
				ApiBase::PARAM_ISMULTI => true,
			),
			'groupbysection' => array(
				ApiBase::PARAM_TYPE => 'boolean',
				ApiBase::PARAM_DFLT => false,
			),
			'format' => array(
				ApiBase::PARAM_TYPE => array(
					'text',
					'model',
					'special',
					'flyout', /* @deprecated */
					'html', /* @deprecated */
				),
				ApiBase::PARAM_HELP_MSG_PER_VALUE => array(),
			),
			'limit' => array(
				ApiBase::PARAM_TYPE => 'limit',
				ApiBase::PARAM_DFLT => 20,
				ApiBase::PARAM_MIN => 1,
				ApiBase::PARAM_MAX => ApiBase::LIMIT_SML1,
				ApiBase::PARAM_MAX2 => ApiBase::LIMIT_SML2,
			),
			'continue' => array(
				ApiBase::PARAM_HELP_MSG => 'api-help-param-continue',
			),
			'unreadfirst' => array(
				ApiBase::PARAM_TYPE => 'boolean',
				ApiBase::PARAM_DFLT => false,
			),
			'titles' => array(
				ApiBase::PARAM_ISMULTI => true,
			),
		);
		foreach ( $sections as $section ) {
			$params[$section . 'continue'] = null;
			$params[$section . 'unreadfirst'] = array(
				ApiBase::PARAM_TYPE => 'boolean',
				ApiBase::PARAM_DFLT => false,
			);
		}

		if ( $this->allowCrossWikiNotifications() ) {
			$params += array(
				// create "x notifications from y wikis" notification bundle &
				// include unread counts from other wikis in prop=count results
				'crosswikisummary' => array(
					ApiBase::PARAM_TYPE => 'boolean',
					ApiBase::PARAM_DFLT => false,
				),
			);
		}

		return $params;
	}

	/**
	 * @see ApiBase::getExamplesMessages()
	 */
	protected function getExamplesMessages() {
		return array(
			'action=query&meta=notifications'
				=> 'apihelp-query+notifications-example-1',
			'action=query&meta=notifications&notprop=count&notsections=alert|message&notgroupbysection=1'
				=> 'apihelp-query+notifications-example-2',
		);
	}

	public function getHelpUrls() {
		return 'https://www.mediawiki.org/wiki/Echo_(Notifications)/API';
	}
}
