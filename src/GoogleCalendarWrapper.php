<?php

namespace Cvl\GoogleCalendarWrapper;

class GoogleCalendarWrapper {
	const ACCESS_TOKEN_BASIC = 'BASIC';
	const ACCESS_TOKEN_ADVANCED = 'ADVANCED';
	const ACCESS_TOKEN_OAUTH_DEFAULT = null;

	protected $calendarIds = null;
	protected $hasAdvancedService = false;
	protected $readerAclRule;
	protected $ownerAclRule;
	protected $mainService;

	protected $config = null;
	protected $clients = array();

	/**
	 * Sets calendarIds if necessary and initializes the Google client.
	 * If it's present in the config, add required authentication information and set the required ACL rules.
	 * Set the scopes of the client (either default or readonly), and finally creates the Google Calendar service.
	 * @param array $googleCalendarConfig with optional keys application_name, developer_key, calendar_ids, client_id, oauth_client_id, client_public_key, client_email, client_private_key_file, client_auth_config_file, calendar_owner.
	 * @throws \Exception If a required config parameter is missing, or the private key file could not be opened, or any Google error occurs.
	 */
	public function __construct($googleCalendarConfig) {
		$this->config = $googleCalendarConfig;
		// calendar_ids is used when only working with a static list of preexisting calendars.
		if (isset($googleCalendarConfig['calendar_ids'])) {
			$this->calendarIds = $googleCalendarConfig['calendar_ids'];
		}
		$client = null;
		// If config has all required values, also add advanced authentication information to the Google client.
		$configRequiredForService = array('application_name', 'client_id', 'client_public_key', 'client_email', 'client_private_key_file', 'calendar_owner');
		if (empty(array_diff($configRequiredForService, array_keys($googleCalendarConfig)))) {
			$this->hasAdvancedService = true;
			$client = $this->getClient(self::ACCESS_TOKEN_ADVANCED);
			// Set the reader ACL rule as default - reader has read permission the calendar.
			$this->readerAclRule = new \Google_Service_Calendar_AclRule();
			$readerAclRuleScope = new \Google_Service_Calendar_AclRuleScope();
			$readerAclRuleScope->setType('default');
			$this->readerAclRule->setRole('reader');
			$this->readerAclRule->setScope($readerAclRuleScope);
			// Set the owner ACL rule to a specific user - owner has all permissions on the calendar.
			$this->ownerAclRule = new \Google_Service_Calendar_AclRule();
			$ownerAclRuleScope = new \Google_Service_Calendar_AclRuleScope();
			$ownerAclRuleScope->setType('user');
			$ownerAclRuleScope->setValue($googleCalendarConfig['calendar_owner']);
			$this->ownerAclRule->setRole('owner');
			$this->ownerAclRule->setScope($ownerAclRuleScope);
		} else if (!empty($googleCalendarConfig['application_name']) && !empty($googleCalendarConfig['developer_key'])) {
			$client = $this->getClient(self::ACCESS_TOKEN_BASIC);
		} else {
			throw new \Exception('Not enough configuration parameters supplied, need at least application_name and developer_key');
		}
		// Create the Google Calendar service based on the Google client.
		$this->mainService = new \Google_Service_Calendar($client);
	}

	/**
	 * @param string|null $accessToken 'BASIC' for basic service client, 'ADVANCED' for advanced service client, anything else for oauth client.
	 * @return \Google_Client
	 * @throws \Exception
	 */
	private function getClient($accessToken = self::ACCESS_TOKEN_OAUTH_DEFAULT) {
		if (!empty($this->clients[$accessToken])) {
			return $this->clients[$accessToken];
		}
		$client = new \Google_Client();
		$scopes = null;
		$configRequiredForBasicService = array('application_name', 'developer_key');
		$configRequiredForAdvancedService = array('application_name', 'client_id', 'client_public_key', 'client_email', 'client_private_key_file', 'calendar_owner');
		$configRequiredForOauth = array('application_name', 'oauth_client_id', 'client_auth_config_file');
		if ($accessToken === self::ACCESS_TOKEN_BASIC) {
			// Basic service client (read public calendars)
			if (!empty(array_diff($configRequiredForBasicService, array_keys($this->config)))) {
				throw new \Exception('Not enough configuration parameters supplied to instantiate basic service client');
			}
			$client->setApplicationName($this->config['application_name']);
			$client->setDeveloperKey($this->config['developer_key']);
			$scopes = array(\Google_Service_Calendar::CALENDAR_READONLY);
		} else if ($accessToken === self::ACCESS_TOKEN_ADVANCED) {
			// Advanced service client (read and modify calendars owned by the service)
			if (!empty(array_diff($configRequiredForAdvancedService, array_keys($this->config)))) {
				throw new \Exception('Not enough configuration parameters supplied to instantiate advanced service client');
			}
			$client->setApplicationName($this->config['application_name']);
			$client->setClientId($this->config['client_id']);
			$client->setClientSecret($this->config['client_public_key']);
			$privateKey = @file_get_contents($this->config['client_private_key_file']);
			if (empty($privateKey)) {
				throw new \Exception('Could not open Google client private key file');
			}
			$scopes = array(\Google_Service_Calendar::CALENDAR);
			$credential = new \Google_Auth_AssertionCredentials($this->config['client_email'], $scopes, $privateKey);
			$client->setAssertionCredentials($credential);
		} else {
			// Oauth client (read and modify calendars owned by a user who has granted permission)
			if (!empty(array_diff($configRequiredForOauth, array_keys($this->config)))) {
				throw new \Exception('Not enough configuration parameters supplied to instantiate oauth client');
			}
			$client->setApplicationName($this->config['application_name']);
			$client->setClientId($this->config['oauth_client_id']);
			$client->setAuthConfigFile($this->config['client_auth_config_file']);
			$client->setAccessType('offline');
			$scopes = array(\Google_Service_Calendar::CALENDAR);
		}
		$client->setScopes($scopes);
		$this->clients[$accessToken] = $client;
		return $client;
	}

	/**
	 * @param string|null $accessToken 'BASIC' for basic service, 'ADVANCED' for advanced service, anything else for oauth service.
	 * @return \Google_Service_Calendar
	 * @throws \Exception
	 */
	private function getService($accessToken = self::ACCESS_TOKEN_OAUTH_DEFAULT) {
		return new \Google_Service_Calendar($this->getClient($accessToken));
	}

	/**
	 * Gets a URL to the OAuth consent screen where the user should be sent in order to acquire a Google access token.
	 * @return string
	 */
	public function getAuthUrl() {
		$client = $this->getClient();
		return $client->createAuthUrl();
	}

	/**
	 * If called with a $currentAccessToken, will attempt to refresh the token if necessary, and then return the new one.
	 * If called with an $authCode, will use that auth code to authenticate, generating a new access token, and returning it.
	 * @param string|null $currentAccessToken
	 * @param string|null $authCode
	 * @return string|null
	 */
	public function acquireAccessToken($currentAccessToken, $authCode = null) {
		$accessToken = $currentAccessToken;
		$client = $this->getClient($accessToken);
		if (!empty($authCode)) {
			$accessToken = $client->authenticate($authCode);
			$client->setAccessToken($accessToken);
		} else if (!empty($accessToken)) {
			$client->setAccessToken($accessToken);
			if ($client->isAccessTokenExpired()) {
				$client->refreshToken($client->getRefreshToken());
				$accessToken = $client->getAccessToken();
			}
		}
		return $accessToken;
	}

	/**
	 * @param string $calendarId
	 * @param \DateTime|null $startDate
	 * @param \DateTime|null $endDate
	 * @param number|null $maxNumberOfEvents
	 * @return CalendarEvent[]
	 */
	public function getEventsFromCalendar($calendarId, \DateTime $startDate = null, \DateTime $endDate = null, $maxNumberOfEvents = null) {
		$optParams = array(
			'orderBy' => 'startTime',
			'singleEvents' => true,
		);
		if (!empty($startDate)) {
			$optParams['timeMin'] = $startDate->format('c');
		}
		if (!empty($endDate)) {
			$optParams['timeMax'] = $endDate->format('c');
		}
		if (!empty($maxNumberOfEvents)) {
			$optParams['maxResults'] = $maxNumberOfEvents;
		}
		$events = $this->mainService->events->listEvents($calendarId, $optParams)->getItems();
		$calendarEvents = array();
		foreach ($events as $event) {
			$calendarEvents[] = CalendarEvent::createFromGoogleEvent($event);
		}
		return $calendarEvents;
	}

	/**
	 * Returns $maxNumberOfEvents events from the calendar with the given ID, starting from today, ordered by ascending start time.
	 * @param string $calendarId
	 * @param number $maxNumberOfEvents
	 * @return \Google_Service_Calendar_Events
	 */
	protected function getFollowingEventsFromCalendar($calendarId, $maxNumberOfEvents) {
		$optParams = array(
			'maxResults' => $maxNumberOfEvents,
			'orderBy' => 'startTime',
			'singleEvents' => true,
			'timeMin' => date('c')
		);
		return $this->mainService->events->listEvents($calendarId, $optParams);
	}

	/**
	 * Returns $maxNumberOfEvents event from all calendars in $this->calendarIds, starting today, ordered by start date ascending, as CalendarEvent objects.
	 * @param number $maxNumberOfEvents
	 * @throws \Exception If $this->calendarIds is empty.
	 * @return CalendarEvent[] Array of CalendarEvent
	 */
	public function getFollowingEvents($maxNumberOfEvents) {
		if (empty($this->calendarIds)) {
			throw new \Exception('Calendar ids must not be empty');
		}
		$calendarEvents = array();
		$events = array();
		foreach ($this->calendarIds as $calendarId) {
			$eventsFromCal = $this->getFollowingEventsFromCalendar($calendarId, $maxNumberOfEvents);
			$events = array_merge($events, $eventsFromCal->getItems());
		}
		foreach ($events as $event) {
			$calendarEvents[] = CalendarEvent::createFromGoogleEvent($event);
		}
		usort($calendarEvents, function(CalendarEvent $a, CalendarEvent $b) {
			return $a->getStartTime() < $b->getStartTime() ? -1 : 1;
		});
		if (count($calendarEvents) > $maxNumberOfEvents) {
			$calendarEvents = array_slice($calendarEvents, 0, $maxNumberOfEvents);
		}
		return $calendarEvents;
	}

	/**
	 * Returns $maxNumberOfEvents event from all calendars in $this->calendarIds, starting today, grouped by start date, as CalendarEvent objects.
	 * @param number $maxNumberOfEvents
	 * @return array Associative array of (date string => numeric array of CalendarEvent).
	 */
	public function getFollowingEventsGroupedByDay($maxNumberOfEvents) {
		$calendarEvents = $this->getFollowingEvents($maxNumberOfEvents);
		$groupedEvents = array();
		foreach ($calendarEvents as $calendarEvent) {
			$groupedEvents[$calendarEvent->getStartTime()->format('M j, Y')][] = $calendarEvent;
		}
		return $groupedEvents;
	}

	/**
	 * Creates a Google Calendar with the given $summary and returns the ID.
	 * @param string $summary
	 * @return string The ID of the new calendar.
	 * @throws \Exception If the Google Calendar service was not initialized with advanced config, or any Google error occurs.
	 */
	public function createCalendar($summary) {
		if (!$this->hasAdvancedService) {
			throw new \Exception('Google Calendar service was never initialized');
		}
		$model = new \Google_Service_Calendar_Calendar();
		$model->setSummary($summary);
		$model->setTimeZone('GMT');
		$newCalendar = $this->mainService->calendars->insert($model);
		$calendarId = $newCalendar->getId();
		$this->mainService->acl->insert($calendarId, $this->readerAclRule);
		$this->mainService->acl->insert($calendarId, $this->ownerAclRule);
		return $calendarId;
	}

	/**
	 * @param string $calendarId
	 * @throws \Exception If the Google Calendar service was not initialized with advanced config, or any Google error occurs.
	 */
	public function deleteCalendar($calendarId) {
		if (!$this->hasAdvancedService) {
			throw new \Exception('Google Calendar service was never initialized');
		}
		$this->mainService->calendars->delete($calendarId);
	}

	/**
	 * Creates or updates a Google Calendar event with new summary, startDate and endDate.
	 * @param string $calendarId
	 * @param string $eventId If empty, a new event will be created, otherwise an existing one will be updated.
	 * @param string $summary
	 * @param \DateTime $startDate
	 * @param \DateTime $endDate
	 * @param array $attendees Optional, numeric array of email addresses of any attendees.
	 * @param string $accessToken Optional, the oauth access token to use,
	 * 	or 'BASIC' to use basic service instead, or 'ADVANCED' to use advanced service instead.
	 * @return string The ID of the updated or newly created event.
	 * @throws \Exception If the Google Calendar service was not initialized with advanced config, or any Google error occurs.
	 */
	protected function upsertTimeOffEvent($calendarId, $eventId, $summary, \DateTime $startDate, \DateTime $endDate, $attendees = array(), $accessToken = self::ACCESS_TOKEN_ADVANCED) {
		if (!$this->hasAdvancedService) {
			throw new \Exception('Google Calendar service was never initialized');
		}
		$model = new \Google_Service_Calendar_Event();
		$model->setSummary($summary);
		$calendarStartDate = new \Google_Service_Calendar_EventDateTime();
		$calendarStartDate->setDate($startDate->format('Y-m-d'));
		$model->setStart($calendarStartDate);
		// End date in Google Calendar seems to be the first day AFTER the end of the event, so we must increment our DateTime.
		$endDate = clone $endDate;
		$endDate->add(new \DateInterval('P1D'));
		$calendarEndDate = new \Google_Service_Calendar_EventDateTime();
		$calendarEndDate->setDate($endDate->format('Y-m-d'));
		$model->setEnd($calendarEndDate);
		if (!empty($attendees)) {
			$attendeeObjects = array_map(function($attendeeEmail) {
				$attendee = new \Google_Service_Calendar_EventAttendee();
				$attendee->setEmail($attendeeEmail);
				return $attendee;
			}, $attendees);
			$model->setAttendees($attendeeObjects);
		}
		if (empty($eventId)) {
			$newEvent = $this->getService($accessToken)->events->insert($calendarId, $model);
			return $newEvent->getId();
		}
		$this->getService($accessToken)->events->update($calendarId, $eventId, $model);
		return $eventId;
	}

	/**
	 * Creates a new Google Calendar event.
	 * @param string $calendarId
	 * @param string $summary
	 * @param \DateTime $startDate
	 * @param \DateTime $endDate
	 * @param array $attendees Optional, numeric array of email addresses of any attendees.
	 * @param string|null $accessToken Optional, the oauth access token to use,
	 * 	or 'BASIC' to use basic service instead, or 'ADVANCED' to use advanced service instead.
	 * @return string The ID of the updated or newly created event.
	 * @throws \Exception If the Google Calendar service was not initialized with advanced config, or any Google error occurs.
	 */
	public function createTimeOffEvent($calendarId, $summary, \DateTime $startDate, \DateTime $endDate, $attendees = array(), $accessToken = self::ACCESS_TOKEN_ADVANCED) {
		if ($accessToken !== self::ACCESS_TOKEN_ADVANCED && empty($this->acquireAccessToken($accessToken))) {
			throw new \Exception('Could not acquire access token');
		}
		return $this->upsertTimeOffEvent($calendarId, null, $summary, $startDate, $endDate, $attendees, $accessToken);
	}

	/**
	 * Updates an existing Google Calendar event with new summary, startDate and endDate.
	 * @param string $calendarId
	 * @param string $eventId
	 * @param string $summary
	 * @param \DateTime $startDate
	 * @param \DateTime $endDate
	 * @param array $attendees Optional, numeric array of email addresses of any attendees.
	 * @param string|null $accessToken Optional, the oauth access token to use,
	 * 	or 'BASIC' to use basic service instead, or 'ADVANCED' to use advanced service instead.
	 * @throws \Exception If the Google Calendar service was not initialized with advanced config, or any Google error occurs.
	 */
	public function updateTimeOffEvent($calendarId, $eventId, $summary, \DateTime $startDate, \DateTime $endDate, $attendees = array(), $accessToken = self::ACCESS_TOKEN_ADVANCED) {
		if ($accessToken !== self::ACCESS_TOKEN_ADVANCED && empty($this->acquireAccessToken($accessToken))) {
			throw new \Exception('Could not acquire access token');
		}
		$this->upsertTimeOffEvent($calendarId, $eventId, $summary, $startDate, $endDate, $attendees, $accessToken);
	}

	/**
	 * @param string $calendarId
	 * @param string $eventId
	 * @param string|null $accessToken Optional, the oauth access token to use,
	 * 	or 'BASIC' to use basic service instead, or 'ADVANCED' to use advanced service instead.
	 * @throws \Exception If the Google Calendar service was not initialized with advanced config, or any Google error occurs.
	 */
	public function deleteTimeOffEvent($calendarId, $eventId, $accessToken = self::ACCESS_TOKEN_ADVANCED) {
		if (!$this->hasAdvancedService) {
			throw new \Exception('Google Calendar service was never initialized');
		}
		if ($accessToken !== self::ACCESS_TOKEN_ADVANCED && empty($this->acquireAccessToken($accessToken))) {
			throw new \Exception('Could not acquire access token');
		}
		$this->getService($accessToken)->events->delete($calendarId, $eventId);
	}
}
