<?php

namespace Hengebytes\SoapCoreAsyncBundle\Handler;

use Hengebytes\SoapCoreAsyncBundle\Engine\AsyncEngineFactory;
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
        $requestOptions = $request->getOptions();
        $client = AsyncEngineFactory::createFromWSDL($requestOptions['base_uri'], $this->client);

        return $client->request($request->action, $request->getRequestParams(), $requestOptions['headers'] ?? []);
    }
}