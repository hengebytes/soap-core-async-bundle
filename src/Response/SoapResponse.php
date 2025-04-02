<?php

namespace Hengebytes\SoapCoreAsyncBundle\Response;

use Soap\Engine\Driver;
use Symfony\Contracts\HttpClient\ResponseInterface;

readonly class SoapResponse implements ResponseInterface
{
    public function __construct(private ResponseInterface $response, private Driver $driver, private string $method)
    {
    }

    /**
     * @inheritDoc
     */
    public function getStatusCode(): int
    {
        return $this->response->getStatusCode();
    }

    /**
     * @inheritDoc
     */
    public function getHeaders(bool $throw = true): array
    {
        return $this->response->getHeaders($throw);
    }

    /**
     * @inheritDoc
     */
    public function getContent(bool $throw = true): string
    {
        return $this->response->getContent($throw);
    }

    /**
     * @inheritDoc
     */
    public function toArray(bool $throw = true): array
    {
        $libSoapResponse = new \Soap\Engine\HttpBinding\SoapResponse(
            $this->response->getContent($throw),
        );
        $decoded = $this->driver->decode($this->method, $libSoapResponse);

        return (array)$decoded;
    }

    /**
     * @inheritDoc
     */
    public function cancel(): void
    {
        $this->response->cancel();
    }

    /**
     * @inheritDoc
     */
    public function getInfo(?string $type = null): mixed
    {
        return $this->response->getInfo($type);
    }
}