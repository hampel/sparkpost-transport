<?php

declare(strict_types=1);

namespace Hampel\SparkPost\Transport\Tests;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A PSR-18 client that answers from a queue and remembers what it was asked.
 *
 * The suite needs no network and no Guzzle mock handler because of this: PSR-18 is a
 * one-method interface, so the seam the package exposes to its consumers is also the seam
 * the tests drive it through. Guzzle is here only for its PSR-7 objects.
 */
final class StubClient implements ClientInterface
{
    /** @var list<ResponseInterface|ClientExceptionInterface> */
    private array $queue = [];

    /** @var list<RequestInterface> */
    public array $requests = [];

    public function push(ResponseInterface|ClientExceptionInterface $response): self
    {
        $this->queue[] = $response;

        return $this;
    }

    /**
     * @param  array<string, string>  $headers
     */
    public function pushJson(int $status, mixed $body, array $headers = []): self
    {
        return $this->push(new Response(
            $status,
            $headers + ['Content-Type' => 'application/json'],
            json_encode($body, JSON_THROW_ON_ERROR)
        ));
    }

    /**
     * @param  array<string, string>  $headers
     */
    public function pushRaw(int $status, string $body, array $headers = []): self
    {
        return $this->push(new Response($status, $headers, $body));
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        $next = array_shift($this->queue);

        if ($next === null) {
            throw new \LogicException('StubClient was asked for a response it does not have.');
        }

        if ($next instanceof ClientExceptionInterface) {
            throw $next;
        }

        return $next;
    }

    public function lastRequest(): RequestInterface
    {
        $last = end($this->requests);

        if ($last === false) {
            throw new \LogicException('No request was made.');
        }

        return $last;
    }
}
