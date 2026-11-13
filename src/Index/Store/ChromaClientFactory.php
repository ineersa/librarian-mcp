<?php

declare(strict_types=1);

namespace App\Index\Store;

use Codewithkyrian\ChromaDB\Api;
use Codewithkyrian\ChromaDB\Client;
use Http\Discovery\Psr17Factory;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;

/**
 * Builds the official Chroma PHP client with a finite Symfony HTTP transport.
 */
final readonly class ChromaClientFactory
{
    public function __construct(
        private string $host,
        private int $port,
        private float $timeout = 30.0,
    ) {
        if ('' === trim($this->host)) {
            throw new \InvalidArgumentException('Chroma host is required.');
        }
        if ($this->port < 1 || $this->port > 65535) {
            throw new \InvalidArgumentException('Chroma port must be between 1 and 65535.');
        }
    }

    public function create(): Client
    {
        $http = HttpClient::create([
            'timeout' => $this->timeout,
            'max_duration' => $this->timeout,
            'extra' => [
                'trace_content' => false,
            ],
        ]);
        $psr17 = new Psr17Factory();
        $psr18 = new Psr18Client($http, $psr17, $psr17);
        $baseUri = rtrim($this->host, '/');
        if (!str_contains($baseUri, '://')) {
            $baseUri = 'http://'.$baseUri;
        }
        $baseUri .= ':'.$this->port;

        return new Client(new Api($psr18, $psr17, $psr17, $baseUri), 'default_database', 'default_tenant');
    }
}
