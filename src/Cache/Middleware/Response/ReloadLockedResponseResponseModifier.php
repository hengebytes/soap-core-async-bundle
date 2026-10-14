<?php

namespace Hengebytes\SoapCoreAsyncBundle\Cache\Middleware\Response;

use Hengebytes\SoapCoreAsyncBundle\Handler\SoapAsyncRequestHandler;
use Hengebytes\SoapCoreAsyncBundle\Request\SoapWSRequest;
use Hengebytes\WebserviceCoreAsyncBundle\Cache\Middleware\Response\ReloadLockedResponseResponseModifier as BaseModifier;
use Hengebytes\WebserviceCoreAsyncBundle\Callback\OnResponseReceivedCallback;
use Hengebytes\WebserviceCoreAsyncBundle\Middleware\ResponseModificationInterface;
use Hengebytes\WebserviceCoreAsyncBundle\Response\AsyncResponse;
use Hengebytes\WebserviceCoreAsyncBundle\Response\ParsedResponse;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;

#[AsDecorator(decorates: BaseModifier::class, onInvalid: ContainerInterface::IGNORE_ON_INVALID_REFERENCE)]
readonly class ReloadLockedResponseResponseModifier implements ResponseModificationInterface
{
    public function __construct(
        #[AutowireDecorated]
        private ?BaseModifier $inner,
        private SoapAsyncRequestHandler $requestHandler,
    ) {
    }

    public function modify(AsyncResponse $response): void
    {
        if ($response->WSRequest instanceof SoapWSRequest) {
            $response->addOnResponseReceivedCallback(new OnResponseReceivedCallback(
                fn(ParsedResponse $parsedResponse) => BaseModifier::reloadLockedResponse(
                    $parsedResponse,
                    $this->requestHandler
                )
            ));

            return;
        }


        $this->inner->modify($response);
    }

    public function supports(AsyncResponse $response): bool
    {
        if (!$this->inner) {
            return false; // if original disabled - we assume lock mechanism is disabled as well
        }

        return $this->inner->supports($response);
    }

    public static function getPriority(): int
    {
        return BaseModifier::getPriority();
    }
}
