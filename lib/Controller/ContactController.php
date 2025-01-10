<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2019 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Calendar\Controller;

use Exception;
use OCA\Calendar\Service\ContactsService;
use OCA\Calendar\Service\ServiceException;
use OCA\Circles\Exceptions\CircleNotFoundException;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\QueryException;
use OCP\Contacts\IManager;
use OCP\IRequest;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Class ContactController
 *
 * @package OCA\Calendar\Controller
 */
class ContactController extends Controller {
	/**
	 * ContactController constructor.
	 *
	 * @param string $appName
	 * @param IRequest $request
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private IManager $contactsManager,
		private IAppManager $appManager,
		private IUserManager $userManager,
		private ContactsService $contactsService,
		private LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Search for a location based on a contact's name or address
	 *
	 * @param string $search Name or address to search for
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 */
	public function searchLocation(string $search): JSONResponse {
		if (!$this->contactsManager->isEnabled()) {
			return new JSONResponse();
		}

		$result = $this->contactsManager->search($search, ['FN', 'ADR']);

		$contacts = [];
		foreach ($result as $r) {
			// Information about system users is fetched via DAV nowadays
			if ($this->contactsService->isSystemBook($r)) {
				continue;
			}

			if (!isset($r['ADR'])) {
				continue;
			}

			$name = $this->contactsService->getNameFromContact($r);
			$photo = $this->contactsService->getPhotoUri($r);
			$addresses = $this->contactsService->getAddress($r);

			$contacts[] = [
				'name' => $name,
				'addresses' => $addresses,
				'photo' => $photo,
			];
		}

		return new JSONResponse($contacts);
	}


	/**
	 * Search for a contact based on a contact's name or email-address
	 *
	 * @param string $search Name or email to search for
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 */
	public function searchAttendee(string $search):JSONResponse {
		if (!$this->contactsManager->isEnabled()) {
			return new JSONResponse();
		}

		$result = $this->contactsManager->search($search, ['FN', 'EMAIL']);

		$contacts = [];
		foreach ($result as $r) {
			if ($this->contactsService->isSystemBook($r) || !$this->contactsService->hasEmail($r)) {
				continue;
			}
			$name = $this->contactsService->getNameFromContact($r);
			$email = $this->contactsService->getEmail($r);
			$photo = $this->contactsService->getPhotoUri($r);
			$timezoneId = $this->contactsService->getTimezoneId($r);
			$lang = $this->contactsService->getLanguageId($r);
			$contacts[] = [
				'name' => $name,
				'emails' => $email,
				'lang' => $lang,
				'tzid' => $timezoneId,
				'photo' => $photo,
				'type' => 'individual'
			];
		}
		
		$groups = $this->contactsManager->search($search, ['CATEGORIES']);
		$groups = array_filter($groups, function ($group) {
			return $this->contactsService->hasEmail($group);
		});
		$filtered = $this->contactsService->filterGroupsWithCount($groups, $search);
		foreach ($filtered as $groupName => $count) {
			if ($count === 0) {
				continue;
			}
			$contacts[] = [
				'name' => $groupName,
				'emails' => ['mailto:' . urlencode($groupName) . '@group'],
				'lang' => '',
				'tzid' => '',
				'photo' => '',
				'type' => 'contactsgroup',
				'members' => $count,
			];
		}

		return new JSONResponse($contacts);
	}

	/**
	 * Get members of a contact group by group name
	 *
	 * @param string $groupName Name of the group to search for members
	 * @return array List of group members
	 */
	private function fetchGroupMembersByName(string $groupName): array {
		if (!$this->contactsManager->isEnabled()) {
			return [];
		}

		$groupMembers = $this->contactsManager->search($groupName, ['CATEGORIES']);
		$contacts = [];
		foreach ($groupMembers as $r) {
			if (!in_array($groupName, explode(',', $r['CATEGORIES']), true)) {
				continue;
			}
			if (!$this->contactsService->hasEmail($r) || $this->contactsService->isSystemBook($r)) {
				continue;
			}
			$name = $this->contactsService->getNameFromContact($r);
			$email = $this->contactsService->getEmail($r);
			$photo = $this->contactsService->getPhotoUri($r);
			$timezoneId = $this->contactsService->getTimezoneId($r);
			$lang = $this->contactsService->getLanguageId($r);
			$contacts[] = [
				'commonName' => $name,
				'email' => $email[0],
				'calendarUserType' => 'INDIVIDUAL',
				'language' => $lang,
				'timezoneId' => $timezoneId,
				'avatar' => $photo,
				'isUser' => false,
				'member' => 'mailto:' . urlencode($groupName) . '@group',
			];
		}

		return $contacts;
	}

	/**
	 * Retrieve members of a contact group by group name
	 *
	 * @param string $groupName Name of the group to search for members
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 */
	#[NoAdminRequired]
	public function getContactGroupMembers(string $groupName): JSONResponse {
		return new JSONResponse($this->fetchGroupMembersByName($groupName));
	}

	/**
	 * Query members of a circle by circleId
	 *
	 * @param string $circleId CircleId to query for members
	 * @return JSONResponse
	 * @throws Exception
	 * @throws \OCP\AppFramework\QueryException
	 *
	 * @NoAdminRequired
	 */
	public function getCircleMembers(string $circleId):JSONResponse {
		if (!$this->appManager->isEnabledForUser('circles') || !class_exists('\OCA\Circles\Api\v1\Circles')) {
			return new JSONResponse();
		}
		if (!$this->contactsManager->isEnabled()) {
			return new JSONResponse();
		}

		try {
			$circle = \OCA\Circles\Api\v1\Circles::detailsCircle($circleId, true);
		} catch (QueryException $ex) {
			return new JSONResponse();
		} catch (CircleNotFoundException $ex) {
			return new JSONResponse();
		}

		if (!$circle) {
			return new JSONResponse();
		}

		$circleMembers = $circle->getInheritedMembers();

		foreach ($circleMembers as $circleMember) {
			if ($circleMember->isLocal()) {

				$circleMemberType = $circleMember->getUserType();
				$circleMemberUserId = $circleMember->getUserId();

				if ($circleMemberType == \OCA\CIrcles\Api\v1\Circles::TYPE_USER) {
					$user = $this->userManager->get($circleMemberUserId);

					if ($user === null) {
						$this->logger->warning('Could not find circle member with id "{userId}"', ['userId' => $circleMemberUserId]);
					} else {
						$contacts[] = [
							'commonName' => $circleMember->getDisplayName(),
							'calendarUserType' => 'INDIVIDUAL',
							'email' => $user->getEMailAddress(),
							'isUser' => true,
							'avatar' => $circleMemberUserId,
							'hasMultipleEMails' => false,
							'dropdownName' => $circleMember->getDisplayName(),
							'member' => 'mailto:circle+' . $circleId . '@' . $circleMember->getInstance(),
						];
					}
				} else if ($circleMemberType === \OCA\Circles\Api\v1\Circles::TYPE_GROUP) {
					array_push($contacts, ...$this->fetchGroupMembersByName($circleMemberUserId));
				} else {
					$this->logger->warning('Circle members of type "{type}" are not supported as calendar event participants', ['type' => $circleMemberType]);
				}
			}
		}

		return new JSONResponse($contacts);
	}


	/**
	 * Get a contact's photo based on their email-address
	 *
	 * @param string $search Exact email-address to match
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 */
	public function searchPhoto(string $search):JSONResponse {
		if (!$this->contactsManager->isEnabled()) {
			return new JSONResponse([], Http::STATUS_NOT_FOUND);
		}

		$result = $this->contactsManager->search($search, ['EMAIL']);

		foreach ($result as $r) {
			if (!$this->contactsService->hasEmail($r) || $this->contactsService->isSystemBook($r)) {
				continue;
			}
			$email = $this->contactsService->getEmail($r);

			$match = false;
			foreach ($email as $e) {
				if ($e === $search) {
					$match = true;
				}
			}

			if (!$match) {
				continue;
			}

			$photo = $this->contactsService->getPhotoUri($r);
			if ($photo === null) {
				continue;
			}

			$name = $this->contactsService->getNameFromContact($r);

			return new JSONResponse([
				'name' => $name,
				'photo' => $photo,
			]);
		}

		return new JSONResponse([], Http::STATUS_NOT_FOUND);
	}

}
