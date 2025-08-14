<?php

namespace Hengebytes\SoapCoreAsyncBundle\Request;

use Hengebytes\WebserviceCoreAsyncBundle\Request\WSRequest;

class SoapWSRequest extends WSRequest
{
    public ?string $encodedRequest = null;

    public function getLogString(): string
    {
        $logString = parent::getLogString();

        return trim($logString . "\n\n" . $this->encodedRequest);
    }
}
