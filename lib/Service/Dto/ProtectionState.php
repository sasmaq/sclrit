<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Sclrit\Service\Dto;

use OCA\Sclrit\Db\SecloreState;

/**
 * API-facing projection of a file's protection state (SDD §4.3 `state` object).
 * A file without a state row reports status "none".
 */
final class ProtectionState implements \JsonSerializable {
	public const STATUS_NONE = 'none';

	public function __construct(
		public readonly int $fileId,
		public readonly string $status,
		public readonly ?string $hotFolderId = null,
		public readonly ?string $policyName = null,
		public readonly ?string $secloreFileId = null,
		public readonly ?string $requestedBy = null,
		public readonly ?int $updatedAt = null,
		public readonly ?string $error = null,
	) {
	}

	public static function none(int $fileId): self {
		return new self($fileId, self::STATUS_NONE);
	}

	public static function fromEntity(SecloreState $state): self {
		return new self(
			$state->getFileId(),
			$state->getStatus(),
			$state->getHotFolderId(),
			$state->getPolicyName(),
			$state->getSecloreFileId(),
			$state->getRequestedBy(),
			$state->getUpdatedAt(),
			$state->getLastError(),
		);
	}

	/** @return array<string, int|string|null> */
	public function jsonSerialize(): array {
		return [
			'fileId' => $this->fileId,
			'status' => $this->status,
			'hotFolderId' => $this->hotFolderId,
			'policyName' => $this->policyName,
			'secloreFileId' => $this->secloreFileId,
			'requestedBy' => $this->requestedBy,
			'updatedAt' => $this->updatedAt,
			'error' => $this->error,
		];
	}
}
