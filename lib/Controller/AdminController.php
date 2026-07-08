<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesSeclore\Controller;

use OCA\FilesSeclore\Service\ConfigService;
use OCA\FilesSeclore\Service\Dto\Credentials;
use OCA\FilesSeclore\Service\Dto\HotFolder;
use OCA\FilesSeclore\Service\ISecloreClient;
use OCA\FilesSeclore\Service\TokenStore;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IConfig;
use OCP\IRequest;

/**
 * Admin-only OCS API (SDD §4.3): config get/set and connection test.
 * The app secret is write-only — it never leaves the server (SDD §8.1).
 */
class AdminController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ConfigService $config,
		private readonly ISecloreClient $client,
		private readonly TokenStore $tokenStore,
		private readonly IConfig $systemConfig,
	) {
		parent::__construct($appName, $request);
	}

	#[ApiRoute(verb: 'GET', url: '/api/v1/admin/config')]
	public function getConfig(): DataResponse {
		return new DataResponse([
			'baseUrl' => $this->config->getBaseUrl(),
			'appId' => $this->config->getAppId(),
			'appSecretSet' => $this->config->getAppSecret() !== '',
			'defaultHotFolder' => $this->config->getDefaultHotFolder(),
			'policies' => array_map(
				static fn (HotFolder $f): array => ['id' => $f->id, 'name' => $f->name, 'description' => $f->description],
				$this->config->getPolicies(),
			),
			'allowedGroups' => $this->config->getAllowedGroups(),
			'unprotectGroups' => $this->config->getUnprotectGroups(),
			'syncMaxSize' => $this->config->getSyncMaxSize(),
			'requestTimeoutMax' => $this->config->getRequestTimeoutMax(),
			'verifyTls' => $this->config->getVerifyTls(),
			'purgeVersions' => $this->config->getPurgeVersions(),
			'staleAfter' => $this->config->getStaleAfter(),
		]);
	}

	/**
	 * Partial update: only supplied fields are written. The secret is only
	 * overwritten when a non-empty value is supplied (SDD §4.3).
	 *
	 * @param array<int, array{id: string, name: string, description?: string}>|null $policies
	 * @param string[]|null $allowedGroups
	 * @param string[]|null $unprotectGroups
	 */
	#[PasswordConfirmationRequired]
	#[ApiRoute(verb: 'PUT', url: '/api/v1/admin/config')]
	public function setConfig(
		?string $baseUrl = null,
		?string $appId = null,
		?string $appSecret = null,
		?string $defaultHotFolder = null,
		?array $policies = null,
		?array $allowedGroups = null,
		?array $unprotectGroups = null,
		?int $syncMaxSize = null,
		?int $requestTimeoutMax = null,
		?bool $verifyTls = null,
		?bool $purgeVersions = null,
		?int $staleAfter = null,
	): DataResponse {
		if ($baseUrl !== null) {
			$baseUrl = trim($baseUrl);
			// HTTP base URLs are rejected outside debug mode (SDD §8.1).
			if ($baseUrl !== '' && !str_starts_with($baseUrl, 'https://')
				&& !$this->systemConfig->getSystemValueBool('debug')) {
				return new DataResponse(
					['code' => 'https_required', 'message' => 'The Policy Server base URL must use HTTPS'],
					Http::STATUS_BAD_REQUEST,
				);
			}
			$this->config->setBaseUrl($baseUrl);
		}
		if ($appId !== null) {
			$this->config->setAppId(trim($appId));
		}
		if ($appSecret !== null && $appSecret !== '') {
			$this->config->setAppSecret($appSecret);
		}
		if ($defaultHotFolder !== null) {
			$this->config->setDefaultHotFolder($defaultHotFolder);
		}
		if ($policies !== null) {
			$this->config->setPolicies(array_filter($policies, is_array(...)));
		}
		if ($allowedGroups !== null) {
			$this->config->setAllowedGroups($allowedGroups);
		}
		if ($unprotectGroups !== null) {
			$this->config->setUnprotectGroups($unprotectGroups);
		}
		if ($syncMaxSize !== null) {
			$this->config->setSyncMaxSize(max(0, $syncMaxSize));
		}
		if ($requestTimeoutMax !== null) {
			$this->config->setRequestTimeoutMax(max(1, $requestTimeoutMax));
		}
		if ($verifyTls !== null) {
			$this->config->setVerifyTls($verifyTls);
		}
		if ($purgeVersions !== null) {
			$this->config->setPurgeVersions($purgeVersions);
		}
		if ($staleAfter !== null) {
			$this->config->setStaleAfter(max(60, $staleAfter));
		}

		// Connection settings may have changed: drop the cached session token.
		if ($this->config->isConfigured()) {
			$this->tokenStore->invalidate($this->config->getCredentials());
		}

		return $this->getConfig();
	}

	/**
	 * Exercises the entered (possibly unsaved) values so admins can validate
	 * before committing; trial credentials are never persisted (SDD §4.3, §5.4).
	 * An omitted/empty secret falls back to the stored one so a saved secret
	 * can be re-tested without re-entering it.
	 */
	#[ApiRoute(verb: 'POST', url: '/api/v1/admin/test-connection')]
	public function testConnection(
		?string $baseUrl = null,
		?string $appId = null,
		?string $appSecret = null,
		?bool $verifyTls = null,
	): DataResponse {
		$override = null;
		if ($baseUrl !== null || $appId !== null || $appSecret !== null || $verifyTls !== null) {
			$override = new Credentials(
				rtrim(trim($baseUrl ?? $this->config->getBaseUrl()), '/'),
				trim($appId ?? $this->config->getAppId()),
				($appSecret !== null && $appSecret !== '') ? $appSecret : $this->config->getAppSecret(),
				$verifyTls ?? $this->config->getVerifyTls(),
			);
		}

		$result = $this->client->testConnection($override);
		return new DataResponse([
			'ok' => $result->ok,
			'policyCount' => $result->policyCount,
			'error' => $result->error,
		]);
	}
}
