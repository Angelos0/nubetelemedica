<?php
/**
 * @author Robin McCorkell <rmccorkell@karoshi.org.uk>
 * @author Vincent Petry <pvince81@owncloud.com>
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

namespace OCA\Files_external\Tests;

use \OCA\Files_external\Lib\StorageConfig;

class StorageConfigTest extends \Test\TestCase {

	public function testJsonSerialization() {
		$backend = $this->getMockBuilder('\OCA\Files_External\Lib\Backend\Backend')
			->disableOriginalConstructor()
			->getMock();
		$backend->method('getIdentifier')
			->willReturn('storage::identifier');

		$authMech = $this->getMockBuilder('\OCA\Files_External\Lib\Auth\AuthMechanism')
			->disableOriginalConstructor()
			->getMock();
		$authMech->method('getIdentifier')
			->willReturn('auth::identifier');

		$storageConfig = new StorageConfig(1);
		$storageConfig->setMountPoint('test');
		$storageConfig->setBackend($backend);
		$storageConfig->setAuthMechanism($authMech);
		$storageConfig->setBackendOptions(['user' => 'test', 'password' => 'password123']);
		$storageConfig->setPriority(128);
		$storageConfig->setApplicableUsers(['user1', 'user2']);
		$storageConfig->setApplicableGroups(['group1', 'group2']);
		$storageConfig->setMountOptions(['preview' => false]);

		$json = $storageConfig->jsonSerialize();

		$this->assertEquals(1, $json['id']);
		$this->assertEquals('/test', $json['mountPoint']);
		$this->assertEquals('storage::identifier', $json['backend']);
		$this->assertEquals('auth::identifier', $json['authMechanism']);
		$this->assertEquals('test', $json['backendOptions']['user']);
		$this->assertEquals('password123', $json['backendOptions']['password']);
		$this->assertEquals(128, $json['priority']);
		$this->assertEquals(['user1', 'user2'], $json['applicableUsers']);
		$this->assertEquals(['group1', 'group2'], $json['applicableGroups']);
		$this->assertEquals(['preview' => false], $json['mountOptions']);
	}

}
