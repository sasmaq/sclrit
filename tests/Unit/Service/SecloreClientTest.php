<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Sclrit\Tests\Unit\Service;

use OCA\Sclrit\Service\ConfigService;
use OCA\Sclrit\Service\Dto\Credentials;
use OCA\Sclrit\Service\SecloreClient;
use OCA\Sclrit\Service\TokenStore;
use OCA\Sclrit\Exceptions\FileTooLargeException;
use OCA\Sclrit\Exceptions\PolicyNotFoundException;
use OCA\Sclrit\Exceptions\SecloreApiException;
use OCA\Sclrit\Tests\Unit\AppConfigMockTrait;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\ITempManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class SecloreClientTest extends TestCase {
	use AppConfigMockTrait;

	/** @var array<int, array{method: string, path: string, handler: \Closure(array): IResponse}> */
	private array $httpQueue = [];
	/** @var string[] */
	private array $tempFiles = [];
	/** @var array<int, array{method: string, url: string, options: array}> */
	private array $seenRequests = [];

	protected function tearDown(): void {
		foreach ($this->tempFiles as $path) {
			@unlink($path);
		}
	}

	private function newClient(array $configValues = null): SecloreClient {
		$config = $this->createConfigService($configValues ?? self::configuredValues());

		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($this->newStatefulCache());
		$tokenStore = new TokenStore($cacheFactory, new NullLogger());

		$http = $this->createMock(IClient::class);
		$dispatch = fn (string $method): \Closure
			=> fn (string $url, array $options = []): IResponse => $this->dispatch($method, $url, $options);
		$http->method('get')->willReturnCallback($dispatch('GET'));
		$http->method('post')->willReturnCallback($dispatch('POST'));
		$http->method('delete')->willReturnCallback($dispatch('DELETE'));

		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($http);

		$tempManager = $this->createMock(ITempManager::class);
		$tempManager->method('getTemporaryFile')->willReturnCallback(function (): string {
			$path = (string)tempnam(sys_get_temp_dir(), 'seclore-test');
			$this->tempFiles[] = $path;
			return $path;
		});

		return new SecloreClient($clientService, $config, $tokenStore, $tempManager, new NullLogger());
	}

	private function newStatefulCache(): ICache {
		$store = new \ArrayObject();
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(static fn (string $key) => $store[$key] ?? null);
		$cache->method('set')->willReturnCallback(static function (string $key, $value) use ($store): bool {
			$store[$key] = $value;
			return true;
		});
		$cache->method('remove')->willReturnCallback(static function (string $key) use ($store): bool {
			unset($store[$key]);
			return true;
		});
		return $cache;
	}

	private function queue(string $method, string $path, int $status, string $body = ''): void {
		$this->queueHandler($method, $path, fn (): IResponse => $this->response($status, $body));
	}

	private function queueHandler(string $method, string $path, \Closure $handler): void {
		$this->httpQueue[] = ['method' => $method, 'path' => $path, 'handler' => $handler];
	}

	private function dispatch(string $method, string $url, array $options): IResponse {
		$expected = array_shift($this->httpQueue);
		$this->assertNotNull($expected, "Unexpected extra HTTP request: $method $url");
		$this->assertSame($expected['method'], $method, "Unexpected method for $url");
		$this->assertStringContainsString($expected['path'], $url);
		$this->seenRequests[] = ['method' => $method, 'url' => $url, 'options' => $options];
		return ($expected['handler'])($options);
	}

	private function response(int $status, string $body = ''): IResponse {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$response->method('getBody')->willReturn($body);
		return $response;
	}

	/** @return resource */
	private function stream(string $content) {
		$stream = fopen('php://temp', 'r+');
		fwrite($stream, $content);
		rewind($stream);
		return $stream;
	}

	private function assertQueueDrained(): void {
		$this->assertSame([], $this->httpQueue, 'Not all expected HTTP requests were made');
	}

	// ---- testConnection -------------------------------------------------

	public function testTestConnectionFailsWhenNotConfigured(): void {
		$result = $this->newClient([])->testConnection();
		$this->assertFalse($result->ok);
		$this->assertStringContainsString('Not configured', (string)$result->error);
	}

	public function testTestConnectionSucceedsOnValidLogin(): void {
		$this->queue('POST', '/auth/login', 200, json_encode(['accessToken' => 'tok']));

		$result = $this->newClient()->testConnection();

		$this->assertTrue($result->ok);
		$this->assertNull($result->error);
		$this->assertQueueDrained();
	}

	public function testTestConnectionReportsRejectedCredentials(): void {
		$this->queue('POST', '/auth/login', 401);

		$result = $this->newClient()->testConnection();

		$this->assertFalse($result->ok);
		$this->assertStringContainsString('authentication failed', (string)$result->error);
	}

	public function testTestConnectionReportsAMalformedLoginResponse(): void {
		$this->queue('POST', '/auth/login', 200, '{"unexpected":"shape"}');

		$result = $this->newClient()->testConnection();

		$this->assertFalse($result->ok);
		$this->assertStringContainsString('Unexpected login response shape', (string)$result->error);
	}

	public function testTestConnectionUsesOverrideCredentials(): void {
		$this->queue('POST', '/auth/login', 200, json_encode(['accessToken' => 'tok']));

		$result = $this->newClient([])->testConnection(
			new Credentials('https://other.example.com', 'tenant-2', 'secret'),
		);

		$this->assertTrue($result->ok);
		$this->assertStringStartsWith('https://other.example.com/', $this->seenRequests[0]['url']);
	}

	// ---- protect ---------------------------------------------------------

	public function testProtectRunsTheFullUploadProtectDownloadSequence(): void {
		$this->queue('POST', '/auth/login', 200, json_encode(['accessToken' => 'tok']));
		$this->queue('POST', '/filestorage/1.0/upload', 200, json_encode(['fileStorageId' => 'up1']));
		$this->queue('POST', '/drm/1.0/protect/hf', 200, json_encode(['fileStorageId' => 'prot1', 'secloreFileId' => 'sf1']));
		$this->queue('DELETE', '/filestorage/1.0/up1', 200);
		$this->queueHandler('GET', '/filestorage/1.0/download/prot1', function (array $options): IResponse {
			file_put_contents($options['sink'], 'PROTECTED-BYTES');
			return $this->response(200);
		});
		$this->queue('DELETE', '/filestorage/1.0/prot1', 200);

		$result = $this->newClient()->protect($this->stream('PLAINTEXT'), 'doc.txt', 'hf1', 'alice@example.com');

		$this->assertSame('sf1', $result->secloreFileId);
		$this->assertSame('PROTECTED-BYTES', file_get_contents($result->tempPath));
		$this->assertSame(strlen('PROTECTED-BYTES'), $result->sizeBytes);
		$this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $result->requestId);
		$this->assertQueueDrained();

		// The upload must be authenticated and carry the correlation id.
		$uploadOptions = $this->seenRequests[1]['options'];
		$this->assertSame('Bearer tok', $uploadOptions['headers']['Authorization']);
		$this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $uploadOptions['headers']['X-SECLORE-CORRELATION-ID']);
		$this->assertTrue($uploadOptions['verify']);
		$this->assertFalse($uploadOptions['allow_redirects']);
	}

	public function testProtectMapsA404ToPolicyNotFound(): void {
		$this->queue('POST', '/auth/login', 200, json_encode(['accessToken' => 'tok']));
		$this->queue('POST', '/filestorage/1.0/upload', 200, json_encode(['fileStorageId' => 'up1']));
		$this->queue('POST', '/drm/1.0/protect/hf', 404, 'unknown hot folder');
		$this->queue('DELETE', '/filestorage/1.0/up1', 200);

		$this->expectException(PolicyNotFoundException::class);
		$this->newClient()->protect($this->stream('PLAINTEXT'), 'doc.txt', 'gone-hf');
	}

	public function testProtectMapsServerErrorsToARetryableException(): void {
		$this->queue('POST', '/auth/login', 200, json_encode(['accessToken' => 'tok']));
		$this->queue('POST', '/filestorage/1.0/upload', 200, json_encode(['fileStorageId' => 'up1']));
		$this->queue('POST', '/drm/1.0/protect/hf', 503, 'maintenance');
		$this->queue('DELETE', '/filestorage/1.0/up1', 200);

		try {
			$this->newClient()->protect($this->stream('PLAINTEXT'), 'doc.txt', 'hf1');
			$this->fail('Expected a SecloreApiException');
		} catch (SecloreApiException $e) {
			$this->assertTrue($e->isRetryable());
			$this->assertStringContainsString('HTTP 503', $e->getMessage());
		}
	}

	public function testProtectMapsA413ToFileTooLarge(): void {
		$this->queue('POST', '/auth/login', 200, json_encode(['accessToken' => 'tok']));
		$this->queueHandler('POST', '/filestorage/1.0/upload', fn (): IResponse => $this->response(413));

		$this->expectException(FileTooLargeException::class);
		$this->newClient()->protect($this->stream('PLAINTEXT'), 'doc.txt', 'hf1');
	}

	// ---- token refresh-and-replay (SDD §7.2) ------------------------------

	public function testARejectedTokenIsRefreshedOnceAndTheStepReplayed(): void {
		$this->queue('POST', '/auth/login', 200, json_encode(['accessToken' => 'tok1']));
		$this->queue('POST', '/filestorage/1.0/upload', 401);
		$this->queue('POST', '/auth/login', 200, json_encode(['accessToken' => 'tok2']));
		$this->queue('POST', '/filestorage/1.0/upload', 200, json_encode(['fileStorageId' => 'up1']));
		$this->queue('POST', '/drm/1.0/unprotect', 200, json_encode(['fileStorageId' => 'un1']));
		$this->queue('DELETE', '/filestorage/1.0/up1', 200);
		$this->queueHandler('GET', '/filestorage/1.0/download/un1', function (array $options): IResponse {
			file_put_contents($options['sink'], 'PLAINTEXT');
			return $this->response(200);
		});
		$this->queue('DELETE', '/filestorage/1.0/un1', 200);

		$tempPath = $this->newClient()->unprotect($this->stream('PROTECTED'), 'doc.txt');

		$this->assertSame('PLAINTEXT', file_get_contents($tempPath));
		$this->assertQueueDrained();

		// The replayed upload must carry the fresh token.
		$this->assertSame('Bearer tok1', $this->seenRequests[1]['options']['headers']['Authorization']);
		$this->assertSame('Bearer tok2', $this->seenRequests[3]['options']['headers']['Authorization']);
	}
}
