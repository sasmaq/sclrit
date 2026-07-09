<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Sclrit\Command;

use OCA\Sclrit\Db\SecloreState;
use OCA\Sclrit\Db\SecloreStateMapper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ sclrit:status — tabular dump of the protection state rows for
 * triage and scripted monitoring (SDD §4.4, §11).
 */
class Status extends Command {
	private const ERROR_COLUMN_MAX = 80;

	public function __construct(
		private readonly SecloreStateMapper $mapper,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this
			->setName('sclrit:status')
			->setDescription('Show Seclore protection states')
			->addOption('failed', null, InputOption::VALUE_NONE, 'Only show failed entries')
			->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum number of rows (most recently updated first)', '500');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$limit = max(1, (int)$input->getOption('limit'));
		$status = $input->getOption('failed') ? SecloreState::STATUS_FAILED : null;

		$rows = $this->mapper->findForOverview($status, $limit);
		if ($rows === []) {
			$output->writeln($status === null ? 'No entries.' : 'No failed entries.');
			return 0;
		}

		$table = new Table($output);
		$table->setHeaders(['File ID', 'Status', 'Policy', 'Requested by', 'Attempts', 'Updated', 'Error']);
		foreach ($rows as $row) {
			$error = (string)($row->getLastError() ?? '');
			if (mb_strlen($error) > self::ERROR_COLUMN_MAX) {
				$error = mb_substr($error, 0, self::ERROR_COLUMN_MAX - 1) . '…';
			}
			$table->addRow([
				$row->getFileId(),
				$row->getStatus(),
				$row->getPolicyName() ?? $row->getHotFolderId() ?? '',
				$row->getRequestedBy(),
				$row->getAttempts(),
				date('Y-m-d H:i:s', $row->getUpdatedAt()),
				$error,
			]);
		}
		$table->render();
		$output->writeln(sprintf('%d %s.', count($rows), count($rows) === 1 ? 'entry' : 'entries'));
		return 0;
	}
}
