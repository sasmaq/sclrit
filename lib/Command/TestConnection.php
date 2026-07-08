<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesSeclore\Command;

use OCA\FilesSeclore\Service\ConfigService;
use OCA\FilesSeclore\Service\ISecloreClient;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ files_seclore:test — connectivity/credentials health check (SDD §4.4).
 * Exit codes: 0 ok, 1 connection/auth failure, 2 not configured.
 */
class TestConnection extends Command {
	public function __construct(
		private readonly ISecloreClient $client,
		private readonly ConfigService $config,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this
			->setName('files_seclore:test')
			->setDescription('Test connectivity and credentials against the Seclore Policy Server');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		if (!$this->config->isConfigured()) {
			$output->writeln('<error>Not configured.</error> Set the connection first:');
			$output->writeln('  occ config:app:set files_seclore base_url --value="https://policy.example.com/api"');
			$output->writeln('  occ config:app:set files_seclore app_id --value="<app id>"');
			$output->writeln('  occ config:app:set files_seclore app_secret --sensitive --value="<secret>"');
			return 2;
		}

		$result = $this->client->testConnection();
		if ($result->ok) {
			$count = $result->policyCount ?? 0;
			$output->writeln(sprintf(
				'<info>OK</info> — authenticated against %s, %d protection %s available.',
				$this->config->getBaseUrl(),
				$count,
				$count === 1 ? 'policy' : 'policies',
			));
			return 0;
		}

		$output->writeln('<error>Failed:</error> ' . ($result->error ?? 'unknown error'));
		return 1;
	}
}
