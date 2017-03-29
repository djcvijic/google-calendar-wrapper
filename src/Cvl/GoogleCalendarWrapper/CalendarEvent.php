<?php

namespace Cvl\GoogleCalendarWrapper;

class CalendarEvent {
	const DRIVE_FILE_URL_PATTERN = 'https://drive.google.com/uc?export=download&id=%s';
	const DATE_ONLY = 1;
	const DATE_AND_TIME = 2;

	private $summary;
	private $description;
	private $location;
	/**
	 * Represents type of start time - whether it consists info about only date or date and time
	 * @var int
	 */
	private $startTimeType;
	/**
	 * 
	 * @var \DateTime
	 */
	private $startTime;
	/**
	 * Represents type of end time - whether it consists info about only date or date and time
	 * @var int 
	 */
	private $endTimeType;
	/**
	 * 
	 * @var \DateTime
	 */
	private $endTime;
	private $imageUrl;
	
	public static function createFromGoogleEvent(\Google_Service_Calendar_Event $googleEvent) {
		$imageUrl = null;
		if (!empty($googleEvent->attachments)) {
			// it is neccessary to convert link to a file web view to link to a raw file
			// this is done by changing links with following format:
			// https://drive.google.com/file/d/FILE_ID/edit?usp=sharing
			// to the following format:
			// https://drive.google.com/uc?export=download&id=FILE_ID
			$url = $googleEvent->attachments[0]['fileUrl'];
			$matches = array();
			if (preg_match('/file\/d\/([[:ascii:]]*)\//', $url, $matches)) {
				$fileId = $matches[1];
				$imageUrl = sprintf(self::DRIVE_FILE_URL_PATTERN, $fileId);
			}
		}
		$startTimeType = null;
		$startTime = null;
		if (!empty($googleEvent->start->dateTime)) {
			$startTimeType = CalendarEvent::DATE_AND_TIME;
			$startTime = new \DateTime($googleEvent->start->dateTime);
		} else if (!empty($googleEvent->start->date)) {
			$startTimeType = CalendarEvent::DATE_ONLY;
			$startTime = new \DateTime($googleEvent->start->date);
		}
		$endTimeType = null;
		$endTime = null;
		if (!empty($googleEvent->end->dateTime)) {
			$endTimeType = CalendarEvent::DATE_AND_TIME;
			$endTime = new \DateTime($googleEvent->end->dateTime);
		} else if (!empty($googleEvent->start->date)) {
			$endTimeType = CalendarEvent::DATE_ONLY;
			$endTime = new \DateTime($googleEvent->end->date);
		}
		return new CalendarEvent($googleEvent->getSummary(), $googleEvent->getDescription(), $googleEvent->getLocation(), $startTimeType, $startTime, $endTimeType, $endTime, $imageUrl);
	}

	/**
	 * 
	 * @param string $summary
	 * @param string $description
	 * @param string $location
	 * @param int $startTimeType
	 * @param \DateTime $startTime
	 * @param int $endTimeType
	 * @param \DateTime $endTime
	 * @param string $imageUrl
	 */
	private function __construct($summary, $description, $location, $startTimeType, $startTime, $endTimeType, $endTime, $imageUrl) {
		$this->summary			= $summary;
		$this->description		= $description;
		$this->location			= $location;
		$this->startTimeType	= $startTimeType;
		$this->startTime		= $startTime;
		$this->endTimeType		= $endTimeType;
		$this->endTime			= $endTime;
		$this->imageUrl			= $imageUrl;
	}

	public function getSummary() {
		return $this->summary;
	}

	public function getDescription() { 
		return $this->description;
	}

	public function getLocation() {
		return $this->location;
	}

	public function getStartTimeType() {
		return $this->startTimeType;
	}

	/**
	 * 
	 * @return \DateTime
	 */
	public function getStartTime() {
		return $this->startTime;
	}

	public function getEndTimeType() {
		return $this->endTimeType;
	}

	/**
	 * 
	 * @return \DateTime
	 */
	public function getEndTime() {
		return $this->endTime;
	}

	public function getImageUrl() {
		return $this->imageUrl;
	}
}