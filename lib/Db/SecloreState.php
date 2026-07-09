<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Sclrit\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Authoritative protection state for one file (SDD §6.1, table oc_seclore_state).
 * State machine: SDD §4.1. A missing row means "not protected".
 *
 * @method int getFileId()
 * @method void setFileId(int $fileId)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method string|null getSecloreFileId()
 * @method void setSecloreFileId(?string $secloreFileId)
 * @method string|null getHotFolderId()
 * @method void setHotFolderId(?string $hotFolderId)
 * @method string|null getPolicyName()
 * @method void setPolicyName(?string $policyName)
 * @method string getRequestedBy()
 * @method void setRequestedBy(string $requestedBy)
 * @method int getAttempts()
 * @method void setAttempts(int $attempts)
 * @method string|null getLastError()
 * @method void setLastError(?string $lastError)
 * @method string|null getEtagBefore()
 * @method void setEtagBefore(?string $etagBefore)
 * @method string|null getRequestId()
 * @method void setRequestId(?string $requestId)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 */
class SecloreState extends Entity {
	public const STATUS_PENDING = 'pending';
	public const STATUS_PROCESSING = 'processing';
	public const STATUS_PROTECTED = 'protected';
	public const STATUS_FAILED = 'failed';

	protected $fileId;
	protected $status;
	protected $secloreFileId;
	protected $hotFolderId;
	protected $policyName;
	protected $requestedBy;
	protected $attempts;
	protected $lastError;
	protected $etagBefore;
	protected $requestId;
	protected $createdAt;
	protected $updatedAt;

	public function __construct() {
		$this->addType('fileId', 'integer');
		$this->addType('status', 'string');
		$this->addType('secloreFileId', 'string');
		$this->addType('hotFolderId', 'string');
		$this->addType('policyName', 'string');
		$this->addType('requestedBy', 'string');
		$this->addType('attempts', 'integer');
		$this->addType('lastError', 'string');
		$this->addType('etagBefore', 'string');
		$this->addType('requestId', 'string');
		$this->addType('createdAt', 'integer');
		$this->addType('updatedAt', 'integer');
	}
}
