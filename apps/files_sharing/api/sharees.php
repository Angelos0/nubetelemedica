<?php
/**
 * @author Joas Schilling <nickvergessen@owncloud.com>
 * @author Roeland Jago Douma <roeland@famdouma.nl>
 *
 * @copyright Copyright (c) 2015, ownCloud, Inc.
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */
namespace OCA\Files_Sharing\API;

use OCP\AppFramework\Http;
use OCP\Contacts\IManager;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\ILogger;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IConfig;
use OCP\IUserSession;
use OCP\IURLGenerator;
use OCP\Share;

class Sharees {

	/** @var IGroupManager */
	protected $groupManager;

	/** @var IUserManager */
	protected $userManager;

	/** @var IManager */
	protected $contactsManager;

	/** @var IConfig */
	protected $config;

	/** @var IUserSession */
	protected $userSession;

	/** @var IRequest */
	protected $request;

	/** @var IURLGenerator */
	protected $urlGenerator;

	/** @var ILogger */
	protected $logger;

	/** @var bool */
	protected $shareWithGroupOnly = false;

	/** @var bool */
	protected $shareeEnumeration = true;

	/** @var int */
	protected $offset = 0;

	/** @var int */
	protected $limit = 10;

	/** @var array */
	protected $result = [
		'exact' => [
			'users' => [],
			'groups' => [],
			'remotes' => [],
		],
		'users' => [],
		'groups' => [],
		'remotes' => [],
	];

	protected $reachedEndFor = [];

	/**
	 * @param IGroupManager $groupManager
	 * @param IUserManager $userManager
	 * @param IManager $contactsManager
	 * @param IConfig $config
	 * @param IUserSession $userSession
	 * @param IURLGenerator $urlGenerator
	 * @param IRequest $request
	 * @param ILogger $logger
	 */
	public function __construct(IGroupManager $groupManager,
								IUserManager $userManager,
								IManager $contactsManager,
								IConfig $config,
								IUserSession $userSession,
								IURLGenerator $urlGenerator,
								IRequest $request,
								ILogger $logger) {
		$this->groupManager = $groupManager;
		$this->userManager = $userManager;
		$this->contactsManager = $contactsManager;
		$this->config = $config;
		$this->userSession = $userSession;
		$this->urlGenerator = $urlGenerator;
		$this->request = $request;
		$this->logger = $logger;
	}

	/**
	 * @param string $search
	 */
	protected function getUsers($search) {
		$this->result['users'] = $this->result['exact']['users'] = $users = [];

		if ($this->shareWithGroupOnly) {
			// Search in all the groups this user is part of
			$userGroups = $this->groupManager->getUserGroupIds($this->userSession->getUser());
			foreach ($userGroups as $userGroup) {
				$usersTmp = $this->groupManager->displayNamesInGroup($userGroup, $search, $this->limit, $this->offset);
				foreach ($usersTmp as $uid => $userDisplayName) {
					$users[$uid] = $userDisplayName;
				}
			}
		} else {
			// Search in all users
			$usersTmp = $this->userManager->searchDisplayName($search, $this->limit, $this->offset);

			foreach ($usersTmp as $user) {
				$users[$user->getUID()] = $user->getDisplayName();
			}
		}

		if (!$this->shareeEnumeration || sizeof($users) < $this->limit) {
			$this->reachedEndFor[] = 'users';
		}

		$foundUserById = false;
		foreach ($users as $uid => $userDisplayName) {
			if (strtolower($uid) === $search || strtolower($userDisplayName) === $search) {
				if (strtolower($uid) === $search) {
					$foundUserById = true;
				}
				$this->result['exact']['users'][] = [
					'label' => $userDisplayName,
					'value' => [
						'shareType' => Share::SHARE_TYPE_USER,
						'shareWith' => $uid,
					],
				];
			} else {
				$this->result['users'][] = [
					'label' => $userDisplayName,
					'value' => [
						'shareType' => Share::SHARE_TYPE_USER,
						'shareWith' => $uid,
					],
				];
			}
		}

		if ($this->offset === 0 && !$foundUserById) {
			// On page one we try if the search result has a direct hit on the
			// user id and if so, we add that to the exact match list
			$user = $this->userManager->get($search);
			if ($user instanceof IUser) {
				array_push($this->result['exact']['users'], [
					'label' => $user->getDisplayName(),
					'value' => [
						'shareType' => Share::SHARE_TYPE_USER,
						'shareWith' => $user->getUID(),
					],
				]);
			}
		}

		if (!$this->shareeEnumeration) {
			$this->result['users'] = [];
		}
	}

	/**
	 * @param string $search
	 */
	protected function getGroups($search) {
		$this->result['groups'] = $this->result['exact']['groups'] = [];

		$groups = $this->groupManager->search($search, $this->limit, $this->offset);
		$groups = array_map(function (IGroup $group) { return $group->getGID(); }, $groups);

		if (!$this->shareeEnumeration || sizeof($groups) < $this->limit) {
			$this->reachedEndFor[] = 'groups';
		}

		$userGroups =  [];
		if (!empty($groups) && $this->shareWithGroupOnly) {
			// Intersect all the groups that match with the groups this user is a member of
			$userGroups = $this->groupManager->getUserGroups($this->userSession->getUser());
			$userGroups = array_map(function (IGroup $group) { return $group->getGID(); }, $userGroups);
			$groups = array_intersect($groups, $userGroups);
		}

		foreach ($groups as $gid) {
			if (strtolower($gid) === $search) {
				$this->result['exact']['groups'][] = [
					'label' => $search,
					'value' => [
						'shareType' => Share::SHARE_TYPE_GROUP,
						'shareWith' => $search,
					],
				];
			} else {
				$this->result['groups'][] = [
					'label' => $gid,
					'value' => [
						'shareType' => Share::SHARE_TYPE_GROUP,
						'shareWith' => $gid,
					],
				];
			}
		}

		if ($this->offset === 0 && empty($this->result['exact']['groups'])) {
			// On page one we try if the search result has a direct hit on the
			// user id and if so, we add that to the exact match list
			$group = $this->groupManager->get($search);
			if ($group instanceof IGroup && (!$this->shareWithGroupOnly || in_array($group->getGID(), $userGroups))) {
				array_push($this->result['exact']['groups'], [
					'label' => $group->getGID(),
					'value' => [
						'shareType' => Share::SHARE_TYPE_GROUP,
						'shareWith' => $group->getGID(),
					],
				]);
			}
		}

		if (!$this->shareeEnumeration) {
			$this->result['groups'] = [];
		}
	}

	/**
	 * @param string $search
	 * @return array possible sharees
	 */
	protected function getRemote($search) {
		$this->result['remotes'] = [];

		// Search in contacts
		//@todo Pagination missing
		$addressBookContacts = $this->contactsManager->search($search, ['CLOUD', 'FN']);
		$foundRemoteById = false;
		foreach ($addressBookContacts as $contact) {
			if (isset($contact['CLOUD'])) {
				foreach ($contact['CLOUD'] as $cloudId) {
					if (strtolower($contact['FN']) === $search || strtolower($cloudId) === $search) {
						if (strtolower($cloudId) === $search) {
							$foundRemoteById = true;
						}
						$this->result['exact']['remotes'][] = [
							'label' => $contact['FN'],
							'value' => [
								'shareType' => Share::SHARE_TYPE_REMOTE,
								'shareWith' => $cloudId,
							],
						];
					} else {
						$this->result['remotes'][] = [
							'label' => $contact['FN'],
							'value' => [
								'shareType' => Share::SHARE_TYPE_REMOTE,
								'shareWith' => $cloudId,
							],
						];
					}
				}
			}
		}

		if (!$this->shareeEnumeration) {
			$this->result['remotes'] = [];
		}

		if (!$foundRemoteById && substr_count($search, '@') >= 1 && substr_count($search, ' ') === 0 && $this->offset === 0) {
			$this->result['exact']['remotes'][] = [
				'label' => $search,
				'value' => [
					'shareType' => Share::SHARE_TYPE_REMOTE,
					'shareWith' => $search,
				],
			];
		}

		$this->reachedEndFor[] = 'remotes';
	}

	/**
	 * @return \OC_OCS_Result
	 */
	public function search() {
		$search = isset($_GET['search']) ? (string) $_GET['search'] : '';
		$itemType = isset($_GET['itemType']) ? (string) $_GET['itemType'] : null;
		$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
		$perPage = isset($_GET['perPage']) ? (int) $_GET['perPage'] : 200;

		if ($perPage <= 0) {
			return new \OC_OCS_Result(null, Http::STATUS_BAD_REQUEST, 'Invalid perPage argument');
		}
		if ($page <= 0) {
			return new \OC_OCS_Result(null, Http::STATUS_BAD_REQUEST, 'Invalid page');
		}

		$shareTypes = [
			Share::SHARE_TYPE_USER,
			Share::SHARE_TYPE_GROUP,
			Share::SHARE_TYPE_REMOTE,
		];
		if (isset($_GET['shareType']) && is_array($_GET['shareType'])) {
			$shareTypes = array_intersect($shareTypes, $_GET['shareType']);
			sort($shareTypes);

		} else if (isset($_GET['shareType']) && is_numeric($_GET['shareType'])) {
			$shareTypes = array_intersect($shareTypes, [(int) $_GET['shareType']]);
			sort($shareTypes);
		}

		if (in_array(Share::SHARE_TYPE_REMOTE, $shareTypes) && !$this->isRemoteSharingAllowed($itemType)) {
			// Remove remote shares from type array, because it is not allowed.
			$shareTypes = array_diff($shareTypes, [Share::SHARE_TYPE_REMOTE]);
		}

		$this->shareWithGroupOnly = $this->config->getAppValue('core', 'shareapi_only_share_with_group_members', 'no') === 'yes';
		$this->shareeEnumeration = $this->config->getAppValue('core', 'shareapi_allow_share_dialog_user_enumeration', 'yes') === 'yes';
		$this->limit = (int) $perPage;
		$this->offset = $perPage * ($page - 1);

		return $this->searchSharees(strtolower($search), $itemType, $shareTypes, $page, $perPage);
	}

	/**
	 * Method to get out the static call for better testing
	 *
	 * @param string $itemType
	 * @return bool
	 */
	protected function isRemoteSharingAllowed($itemType) {
		try {
			$backend = Share::getBackend($itemType);
			return $backend->isShareTypeAllowed(Share::SHARE_TYPE_REMOTE);
		} catch (\Exception $e) {
			return false;
		}
	}

	/**
	 * Testable search function that does not need globals
	 *
	 * @param string $search
	 * @param string $itemType
	 * @param array $shareTypes
	 * @param int $page
	 * @param int $perPage
	 * @return \OC_OCS_Result
	 */
	protected function searchSharees($search, $itemType, array $shareTypes, $page, $perPage) {
		// Verify arguments
		if ($itemType === null) {
			return new \OC_OCS_Result(null, Http::STATUS_BAD_REQUEST, 'Missing itemType');
		}

		// Get users
		if (in_array(Share::SHARE_TYPE_USER, $shareTypes)) {
			$this->getUsers($search);
		}

		// Get groups
		if (in_array(Share::SHARE_TYPE_GROUP, $shareTypes)) {
			$this->getGroups($search);
		}

		// Get remote
		if (in_array(Share::SHARE_TYPE_REMOTE, $shareTypes)) {
			$this->getRemote($search);
		}

		$response = new \OC_OCS_Result($this->result);
		$response->setItemsPerPage($perPage);

		if (sizeof($this->reachedEndFor) < 3) {
			$response->addHeader('Link', $this->getPaginationLink($page, [
				'search' => $search,
				'itemType' => $itemType,
				'shareType' => $shareTypes,
				'perPage' => $perPage,
			]));
		}

		return $response;
	}

	/**
	 * Generates a bunch of pagination links for the current page
	 *
	 * @param int $page Current page
	 * @param array $params Parameters for the URL
	 * @return string
	 */
	protected function getPaginationLink($page, array $params) {
		if ($this->isV2()) {
			$url = $this->urlGenerator->getAbsoluteURL('/ocs/v2.php/apps/files_sharing/api/v1/sharees') . '?';
		} else {
			$url = $this->urlGenerator->getAbsoluteURL('/ocs/v1.php/apps/files_sharing/api/v1/sharees') . '?';
		}
		$params['page'] = $page + 1;
		$link = '<' . $url . http_build_query($params) . '>; rel="next"';

		return $link;
	}

	/**
	 * @return bool
	 */
	protected function isV2() {
		return $this->request->getScriptName() === '/ocs/v2.php';
	}
}
