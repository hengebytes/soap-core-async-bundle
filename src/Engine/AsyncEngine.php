<?php

namespace Hengebytes\SoapCoreAsyncBundle\Engine;

use Psr\Http\Message\RequestInterface;
use Soap\Engine\Driver;
use Soap\Engine\HttpBinding\SoapRequest;
use Soap\Psr18Transport\HttpBinding\Psr7Converter;
use Soap\Xml\Builder\SoapHeaders;
use Soap\Xml\Manipulator\PrependSoapHeaders;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use SynxisBundle\Response\SoapResponse;
use VeeWee\Xml\Dom\Document;

readonly class AsyncEngine
{
    public function __construct(
        private Driver $driver,
        private HttpClientInterface $client,
        private Psr7Converter $converter
    ) {
    }

    public function request(string $method, array $arguments, array $headers = []): ResponseInterface
    {
        $request = $this->driver->encode($method, [$arguments]);

        $requestPayload = $request->getRequest();
        // add headers
        if (!empty($headers)) {
            $document = Document::fromXmlString($requestPayload);
            $builtHeaders = $document->build(new SoapHeaders(...$headers));

            $document->manipulate(new PrependSoapHeaders(...$builtHeaders));

            $requestPayload = $document->toXmlString();
        }

        $request = new SoapRequest(
            $requestPayload,
            $request->getLocation(),
            $request->getAction(),
            $request->getVersion(),
            $request->getOneWay()
        );

        $psr7Request = $this->converter->convertSoapRequest($request);

        return new SoapResponse($this->makeRe($psr7Request), $this->driver, $method);
    }

    private function makeRe(RequestInterface $request): ResponseInterface
    {
        $body = $request->getBody();
        $headers = $request->getHeaders();

        $size = $request->getHeader('content-length')[0] ?? -1;
        if (0 > $size && 0 < $size = $body->getSize() ?? -1) {
            $headers['Content-Length'] = [$size];
        }

        if (0 === $size) {
            $body = '';
        } elseif (0 < $size && $size < 1 << 21) {
            if ($body->isSeekable()) {
                try {
                    $body->seek(0);
                } catch (\RuntimeException) {
                    // ignore
                }
            }

            $body = $body->getContents();
        } else {
            $body = static function (int $size) use ($body) {
                if ($body->isSeekable()) {
                    try {
                        $body->seek(0);
                    } catch (\RuntimeException) {
                        // ignore
                    }
                }

                while (!$body->eof()) {
                    yield $body->read($size);
                }
            };
        }

        $options = [
            'headers' => $headers,
            'body' => $body,
        ];

        if ('1.0' === $request->getProtocolVersion()) {
            $options['http_version'] = '1.0';
        }

        return $this->client->request($request->getMethod(), (string)$request->getUri(), $options);
    }
}