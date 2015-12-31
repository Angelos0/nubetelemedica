<?php
/**
 * @author Lukas Reschke <lukas@owncloud.com>
 * @author Victor Dubiniuk <dubiniuk@owncloud.com>
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

namespace OCA\Files_Trashbin\BackgroundJob;

use OCP\IConfig;
use OCP\IUserManager;
use OCA\Files_Trashbin\AppInfo\Application;
use OCA\Files_Trashbin\Expiration;
use OCA\Files_Trashbin\Helper;
use OCA\Files_Trashbin\Trashbin;

class ExpireTrash extends \OC\BackgroundJob\TimedJob {

	const ITEMS_PER_SESSION = 1000;

	/**
	 * @var Expiration
	 */
	private $expiration;

	/**
	 * @var IConfig
	 */
	private $config;
	
	/**
	 * @var IUserManager
	 */
	private $userManager;
	
	const USERS_PER_SESSION = 1000;

	/**
	 * @param IConfig|null $config
	 * @param IUserManager|null $userManager
	 * @param Expiration|null $expiration
	 */
	public function __construct(IConfig $config = null,
								IUserManager $userManager = null,
								Expiration $expiration = null) {
		// Run once per 30 minutes
		$this->setInterval(60 * 30);

		if (is_null($expiration) || is_null($userManager) || is_null($config)) {
			$this->fixDIForJobs();
		} else {
			$this->config = $config;
			$this->userManager = $userManager;
			$this->expiration = $expiration;
		}
	}

	protected function fixDIForJobs() {
		$application = new Application();
		$this->config = \OC::$server->getConfig();
		$this->userManager = \OC::$server->getUserManager();
		$this->expiration = $application->getContainer()->query('Expiration');
	}

	/**
	 * @param $argument
	 * @throws \Exception
	 */
	protected function run($argument) {
		$maxAge = $this->expiration->getMaxAgeAsTimestamp();
		if (!$maxAge) {
			return;
		}
		
		$offset = $this->config->getAppValue('files_trashbin', 'cronjob_user_offset', 0);
		$users = $this->userManager->search('', self::USERS_PER_SESSION, $offset);
		if (!count($users)) {
			// No users found, reset offset and retry
			$offset = 0;
			$users = $this->userManager->search('', self::USERS_PER_SESSION);
		}
		
		$offset += self::USERS_PER_SESSION;
		$this->config->setAppValue('files_trashbin', 'cronjob_user_offset', $offset);
		
		foreach ($users as $user) {
			$uid = $user->getUID();
			if (!$this->setupFS($uid)) {
				continue;
			}
			$dirContent = Helper::getTrashFiles('/', $uid, 'mtime');
			Trashbin::deleteExpiredFiles($dirContent, $uid);
		}
		
		\OC_Util::tearDownFS();
	}

	/**
	 * Act on behalf on trash item owner
	 * @param string $user
	 * @return boolean
	 */
	private function setupFS($user){
		if (!$this->userManager->userExists($user)) {
			return false;
		}

		\OC_Util::tearDownFS();
		\OC_Util::setupFS($user);

		return true;
	}
}
