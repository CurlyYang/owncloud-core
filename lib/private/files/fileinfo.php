<?php
/**
 * @author Joas Schilling <nickvergessen@owncloud.com>
 * @author Lukas Reschke <lukas@owncloud.com>
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Robin Appelman <icewind@owncloud.com>
 * @author Robin McCorkell <rmccorkell@karoshi.org.uk>
 * @author Scrutinizer Auto-Fixer <auto-fixer@scrutinizer-ci.com>
 * @author tbartenstein <tbartenstein@users.noreply.github.com>
 * @author Thomas Müller <thomas.mueller@tmit.eu>
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

namespace OC\Files;

use OCP\IUser;

class FileInfo implements \OCP\Files\FileInfo, \ArrayAccess {
	/**
	 * @var array $data
	 */
	private $data;

	/**
	 * @var string $path
	 */
	private $path;

	/**
	 * @var \OC\Files\Storage\Storage $storage
	 */
	private $storage;

	/**
	 * @var string $internalPath
	 */
	private $internalPath;

	/**
	 * @var \OCP\Files\Mount\IMountPoint
	 */
	private $mount;

	/**
	 * @var IUser
	 */
	private $owner;

	/**
	 * @var string[]
	 */
	private $childEtags = [];

	/**
	 * @param string|boolean $path
	 * @param Storage\Storage $storage
	 * @param string $internalPath
	 * @param array $data
	 * @param \OCP\Files\Mount\IMountPoint $mount
	 * @param \OCP\IUser|null $owner
	 */
	public function __construct($path, $storage, $internalPath, $data, $mount, $owner= null) {
		$this->path = $path;
		$this->storage = $storage;
		$this->internalPath = $internalPath;
		$this->data = $data;
		$this->mount = $mount;
		$this->owner = $owner;
	}

	public function offsetSet($offset, $value) {
		$this->data[$offset] = $value;
	}

	public function offsetExists($offset) {
		return isset($this->data[$offset]);
	}

	public function offsetUnset($offset) {
		unset($this->data[$offset]);
	}

	public function offsetGet($offset) {
		if ($offset === 'type') {
			return $this->getType();
		} else if ($offset === 'etag') {
			return $this->getEtag();
		} elseif (isset($this->data[$offset])) {
			return $this->data[$offset];
		} else {
			return null;
		}
	}

	/**
	 * @return string
	 */
	public function getPath() {
		return $this->path;
	}

	/**
	 * @return \OCP\Files\Storage
	 */
	public function getStorage() {
		return $this->storage;
	}

	/**
	 * @return string
	 */
	public function getInternalPath() {
		return $this->internalPath;
	}

	/**
	 * @return int
	 */
	public function getId() {
		return $this->data['fileid'];
	}

	/**
	 * @return string
	 */
	public function getMimetype() {
		return $this->data['mimetype'];
	}

	/**
	 * @return string
	 */
	public function getMimePart() {
		return $this->data['mimepart'];
	}

	/**
	 * @return string
	 */
	public function getName() {
		return basename($this->getPath());
	}

	/**
	 * @return string
	 */
	public function getEtag() {
		if (count($this->childEtags) > 0) {
			$combinedEtag = $this->data['etag'] . '::' . implode('::', $this->childEtags);
			return md5($combinedEtag);
		} else {
			return $this->data['etag'];
		}
	}

	/**
	 * @return int
	 */
	public function getSize() {
		return isset($this->data['size']) ? $this->data['size'] : 0;
	}

	/**
	 * @return int
	 */
	public function getMTime() {
		return $this->data['mtime'];
	}

	/**
	 * @return bool
	 */
	public function isEncrypted() {
		return $this->data['encrypted'];
	}

	/**
	 * @return int
	 */
	public function getPermissions() {
		return $this->data['permissions'];
	}

	/**
	 * @return \OCP\Files\FileInfo::TYPE_FILE|\OCP\Files\FileInfo::TYPE_FOLDER
	 */
	public function getType() {
		if (!isset($this->data['type'])) {
			$this->data['type'] = ($this->getMimetype() === 'httpd/unix-directory') ? self::TYPE_FOLDER : self::TYPE_FILE;
		}
		return $this->data['type'];
	}

	public function getData() {
		return $this->data;
	}

	/**
	 * @param int $permissions
	 * @return bool
	 */
	protected function checkPermissions($permissions) {
		return ($this->getPermissions() & $permissions) === $permissions;
	}

	/**
	 * @return bool
	 */
	public function isReadable() {
		return $this->checkPermissions(\OCP\Constants::PERMISSION_READ);
	}

	/**
	 * @return bool
	 */
	public function isUpdateable() {
		return $this->checkPermissions(\OCP\Constants::PERMISSION_UPDATE);
	}

	/**
	 * Check whether new files or folders can be created inside this folder
	 *
	 * @return bool
	 */
	public function isCreatable() {
		return $this->checkPermissions(\OCP\Constants::PERMISSION_CREATE);
	}

	/**
	 * @return bool
	 */
	public function isDeletable() {
		return $this->checkPermissions(\OCP\Constants::PERMISSION_DELETE);
	}

	/**
	 * @return bool
	 */
	public function isShareable() {
		return $this->checkPermissions(\OCP\Constants::PERMISSION_SHARE);
	}

	/**
	 * Check if a file or folder is shared
	 *
	 * @return bool
	 */
	public function isShared() {
		$sid = $this->getStorage()->getId();
		if (!is_null($sid)) {
			$sid = explode(':', $sid);
			return ($sid[0] === 'shared');
		}

		return false;
	}

	public function isMounted() {
		$sid = $this->getStorage()->getId();
		if (!is_null($sid)) {
			$sid = explode(':', $sid);
			return ($sid[0] !== 'home' and $sid[0] !== 'shared');
		}

		return false;
	}

	/**
	 * Get the mountpoint the file belongs to
	 *
	 * @return \OCP\Files\Mount\IMountPoint
	 */
	public function getMountPoint() {
		return $this->mount;
	}

	/**
	 * Get the owner of the file
	 *
	 * @return \OCP\IUser
	 */
	public function getOwner() {
		return $this->owner;
	}

	/**
	 * Add a cache entry which is the child of this folder
	 *
	 * Sets the size, etag and size to for cross-storage childs
	 *
	 * @param array $data cache entry for the child
	 * @param string $entryPath full path of the child entry
	 */
	public function addSubEntry($data, $entryPath) {
		$this->data['size'] += isset($data['size']) ? $data['size'] : 0;
		if (isset($data['mtime'])) {
			$this->data['mtime'] = max($this->data['mtime'], $data['mtime']);
		}
		if (isset($data['etag'])) {
			// prefix the etag with the relative path of the subentry to propagate etag on mount moves
			$relativeEntryPath = substr($entryPath, strlen($this->getPath()));
			// attach the permissions to propagate etag on permision changes of submounts
			$permissions = isset($data['permissions']) ? $data['permissions'] : 0;
			$this->childEtags[] = $relativeEntryPath . '/' . $data['etag'] . $permissions;
		}
	}
}
