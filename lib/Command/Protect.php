<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Sclrit\Command;

use OCA\Sclrit\Exceptions\AlreadyProtectedException;
use OCA\Sclrit\Exceptions\InProgressException;
use OCA\Sclrit\Exceptions\UnsupportedFileException;
use OCA\Sclrit\Service\ProtectionService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ sclrit:protect — admin/batch protection (SDD §4.4).
 * Always runs synchronously, streaming one result line per file.
 * Exit codes: 0 all protected/skipped, 1 at least one failure, 2 usage error.
 */
class Protect extends Command {
	public function __construct(
		private readonly ProtectionService $protectionService,
		private readonly IRootFolder $rootFolder,
		private readonly IUserManager $userManager,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this
			->setName('sclrit:protect')
			->setDescription('Protect a file (or, with --recursive, all files under a folder) with Seclore')
			->addArgument('path', InputArgument::REQUIRED, 'Path relative to the user\'s files, e.g. /Documents/contract.pdf')
			->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Owner of the path; the protection runs as this user')
			->addOption('policy', null, InputOption::VALUE_REQUIRED, 'Hot Folder id (defaults to the configured default policy)')
			->addOption('recursive', 'r', InputOption::VALUE_NONE, 'Protect all files under the given folder');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$userId = (string)$input->getOption('user');
		if ($userId === '') {
			$output->writeln('<error>--user is required.</error>');
			return 2;
		}
		if ($this->userManager->get($userId) === null) {
			$output->writeln(sprintf('<error>User "%s" does not exist.</error>', $userId));
			return 2;
		}

		$path = (string)$input->getArgument('path');
		$userFolder = $this->rootFolder->getUserFolder($userId);
		try {
			$node = $userFolder->get($path);
		} catch (NotFoundException) {
			$output->writeln(sprintf('<error>Path not found: %s</error>', $path));
			return 2;
		}

		if ($node instanceof Folder && !$input->getOption('recursive')) {
			$output->writeln(sprintf('<error>%s is a folder — pass --recursive to protect its contents.</error>', $path));
			return 2;
		}

		$files = $node instanceof File ? [$node] : $this->collectFiles($node);
		if ($files === []) {
			$output->writeln('Nothing to protect.');
			return 0;
		}

		$policy = $input->getOption('policy');
		$policy = is_string($policy) && $policy !== '' ? $policy : null;

		$protected = 0;
		$skipped = 0;
		$failed = 0;
		$basePathLength = strlen($userFolder->getPath());
		foreach ($files as $file) {
			$display = ltrim(substr($file->getPath(), $basePathLength), '/');
			try {
				// forceSync: occ has no request timeout, so large files run
				// inline too (SDD §4.4).
				$state = $this->protectionService->requestProtect($userId, $file->getId(), $policy, true);
				$output->writeln(sprintf('<info>✓</info> %s (%s)', $display, $state->policyName ?? $state->hotFolderId ?? ''));
				$protected++;
			} catch (AlreadyProtectedException) {
				$output->writeln(sprintf('– %s: skipped (already protected)', $display));
				$skipped++;
			} catch (InProgressException | UnsupportedFileException $e) {
				$output->writeln(sprintf('– %s: skipped (%s)', $display, $e->getMessage()));
				$skipped++;
			} catch (\Throwable $e) {
				$output->writeln(sprintf('<error>✗</error> %s: %s', $display, $e->getMessage()));
				$failed++;
			}
		}

		$output->writeln(sprintf('%d protected, %d skipped, %d failed.', $protected, $skipped, $failed));
		return $failed > 0 ? 1 : 0;
	}

	/** @return File[] */
	private function collectFiles(Folder $folder): array {
		$files = [];
		foreach ($folder->getDirectoryListing() as $node) {
			if ($node instanceof File) {
				$files[] = $node;
			} elseif ($node instanceof Folder) {
				$files = array_merge($files, $this->collectFiles($node));
			}
		}
		return $files;
	}
}
