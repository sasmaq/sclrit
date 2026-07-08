<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesSeclore\Service;

use OCA\FilesSeclore\Exceptions\FileTooLargeException;
use OCA\FilesSeclore\Exceptions\NotConfiguredException;
use OCA\FilesSeclore\Exceptions\PolicyNotFoundException;
use OCA\FilesSeclore\Exceptions\SecloreApiException;
use OCA\FilesSeclore\Exceptions\SecloreAuthException;
use OCA\FilesSeclore\Exceptions\SecloreUnavailableException;
use OCA\FilesSeclore\Service\Dto\ConnectionResult;
use OCA\FilesSeclore\Service\Dto\Credentials;
use OCA\FilesSeclore\Service\Dto\ProtectResult;
use OCA\FilesSeclore\Service\Dto\SecloreFileInfo;

/**
 * Adapter for the Seclore REST API (SDD §7).
 *
 * All knowledge of the Seclore HTTP contract (endpoint paths, auth scheme,
 * payload shapes) is confined to implementations of this interface. The
 * shipped implementation targets the verified DRM tenant API 1.0 contract
 * (SDD §7.3.1); on-premises Policy Server deployments must be reconciled
 * against it (SDD §15, Q1).
 *
 * Hot Folder (policy) listing is deliberately absent: the API offers no such
 * endpoint, so the policy list is admin-maintained (SDD §15, Q1a) and served
 * by PolicyService.
 *
 * Streams are used end-to-end: implementations must never buffer whole files
 * in memory (SDD decision D8).
 */
interface ISecloreClient {
	/**
	 * Validate connectivity and credentials by authenticating.
	 * Expected failures (bad URL, bad credentials, TLS errors) are reported in the
	 * result, not thrown.
	 */
	public function testConnection(?Credentials $override = null): ConnectionResult;

	/**
	 * Protect a byte stream with the given Hot Folder policy.
	 * The protected result is streamed to a temporary file owned by the caller
	 * (the caller must delete ProtectResult::$tempPath when done).
	 *
	 * @param resource $in readable stream of the original file content
	 * @param string|null $ownerEmail acting user's email; the verified API has no
	 *                                on-behalf-of field, so implementations may
	 *                                ignore it (SDD §15, Q2)
	 * @throws NotConfiguredException|SecloreAuthException|SecloreUnavailableException
	 * @throws PolicyNotFoundException|FileTooLargeException|SecloreApiException
	 */
	public function protect($in, string $fileName, string $hotFolderId, ?string $ownerEmail = null): ProtectResult;

	/**
	 * Remove Seclore protection from a byte stream.
	 *
	 * @param resource $in readable stream of the protected file
	 * @return string path of a temporary file holding the unprotected content
	 *                (the caller must delete it when done)
	 * @throws NotConfiguredException|SecloreAuthException|SecloreUnavailableException|SecloreApiException
	 */
	public function unprotect($in, string $fileName): string;

	/**
	 * Optional probe: ask the server whether a stream is Seclore-protected
	 * (SDD §9 E4). Returns null when the probe is unsupported, the server
	 * cannot be reached, or the result is inconclusive — the probe never fails
	 * hard. The verified DRM tenant API 1.0 has no such endpoint (SDD §15 Q6),
	 * so the shipped implementation always returns null.
	 *
	 * @param resource $in
	 * @throws NotConfiguredException
	 */
	public function getFileInfo($in): ?SecloreFileInfo;
}
