<?php

declare(strict_types=1);

namespace Hampel\SparkPost\Transport\Tests;

use GuzzleHttp\Psr7\HttpFactory;
use Hampel\SparkPost\Config;
use Hampel\SparkPost\SparkPost;
use Hampel\SparkPost\Transport\SparkPostTransport;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase as BaseTestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

abstract class TestCase extends BaseTestCase
{
    protected StubClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = new StubClient();
    }

    protected function transport(?LoggerInterface $logger = null): SparkPostTransport
    {
        $factory = new HttpFactory();

        $sparkpost = new SparkPost(new Config('test-api-key'), $this->client, $factory, $factory);

        return new SparkPostTransport($sparkpost, null, $logger ?? new NullLogger());
    }

    /**
     * The transmission payload the transport actually posted.
     *
     * @return array<mixed>
     */
    protected function sentTransmission(): array
    {
        $decoded = json_decode((string) $this->client->lastRequest()->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<mixed>|null  $data
     */
    protected static function path(?array $data, string $path): mixed
    {
        $value = $data;

        foreach (explode('.', $path) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * @param  array<mixed>|null  $data
     * @return array<mixed>
     */
    protected static function arrayAt(?array $data, string $path): array
    {
        $value = self::path($data, $path);

        if (! is_array($value)) {
            throw new AssertionFailedError(sprintf('Expected an array at "%s", found %s.', $path, get_debug_type($value)));
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $results
     */
    protected function queueAccepted(int $accepted = 1, int $rejected = 0, string $id = '11668787484950529', array $results = []): void
    {
        $this->client->pushJson(200, ['results' => $results + [
            'id' => $id,
            'total_accepted_recipients' => $accepted,
            'total_rejected_recipients' => $rejected,
        ]]);
    }
}
