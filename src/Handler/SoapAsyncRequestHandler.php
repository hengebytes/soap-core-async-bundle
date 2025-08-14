<?php

namespace Hengebytes\SoapCoreAsyncBundle\Handler;

use Hengebytes\SoapCoreAsyncBundle\Engine\AsyncEngineFactory;
use Hengebytes\SoapCoreAsyncBundle\Request\SoapWSRequest;
use Hengebytes\WebserviceCoreAsyncBundle\Cache\CacheManager;
use Hengebytes\WebserviceCoreAsyncBundle\Handler\BaseRequestHandler;
use Hengebytes\WebserviceCoreAsyncBundle\Middleware\RequestModification;
use Hengebytes\WebserviceCoreAsyncBundle\Middleware\ResponseModification;
use Hengebytes\WebserviceCoreAsyncBundle\Provider\ModelProvider;
use Hengebytes\WebserviceCoreAsyncBundle\Request\WSRequest;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

readonly class SoapAsyncRequestHandler extends BaseRequestHandler
{
    public function __construct(
        private HttpClientInterface $client,
        RequestModification $requestModification,
        ResponseModification $responseModification,
        ModelProvider $modelProvider,
        ?CacheManager $cacheManager = null
    ) {
        parent::__construct($requestModification, $responseModification, $modelProvider, $cacheManager);
    }

    /**
     * @inheritDoc
     */
    protected function performRequest(WSRequest $request): ResponseInterface
    {
        if (!$request instanceof SoapWSRequest) {
            throw new \InvalidArgumentException(
                'SoapAsyncRequestHandler can only handle SoapWSRequest but got ' . get_class($request)
            );
        }

        $requestOptions = $request->getOptions();
        $client = AsyncEngineFactory::createFromWSDL($requestOptions['base_uri'], $this->client);

        [$req, $res] = $client->request(
            $request->action, $request->getRequestParams(), $requestOptions['headers'] ?? []
        );
        $request->encodedRequest = $req->getRequest();

        return $res;
    }
}
