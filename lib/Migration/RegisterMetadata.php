<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Sclrit\Migration;

use OCA\Sclrit\Service\ProtectionService;
use OCP\FilesMetadata\IFilesMetadataManager;
use OCP\FilesMetadata\Model\IMetadataValueWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Declares the protection-status files-metadata key (SDD §4.5) so it is
 * indexed and exposed on WebDAV PROPFIND. Idempotent; runs on install and
 * after every app update.
 */
class RegisterMetadata implements IRepairStep {
	public function __construct(
		private readonly IFilesMetadataManager $metadataManager,
	) {
	}

	public function getName(): string {
		return 'Register the Seclore protection status metadata key';
	}

	public function run(IOutput $output): void {
		$this->metadataManager->initMetadata(
			ProtectionService::METADATA_KEY,
			IMetadataValueWrapper::TYPE_BOOL,
			true,
			IMetadataValueWrapper::EDIT_FORBIDDEN,
		);
	}
}
