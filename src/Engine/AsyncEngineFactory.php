<?php

namespace Hengebytes\SoapCoreAsyncBundle\Engine;

use Http\Discovery\Psr17FactoryDiscovery;
use Soap\Encoding\Driver;
use Soap\Encoding\EncoderRegistry;
use Soap\Psr18Transport\HttpBinding\Psr7Converter;
use Soap\Wsdl\Loader\FlatteningLoader;
use Soap\Wsdl\Loader\StreamWrapperLoader;
use Soap\WsdlReader\Locator\ServiceSelectionCriteria;
use Soap\WsdlReader\Parser\Context\ParserContext;
use Soap\WsdlReader\Wsdl1Reader;
use Symfony\Contracts\HttpClient\HttpClientInterface;

abstract class AsyncEngineFactory
{
    private static array $engines = [];

    public static function createFromWSDL(string $location, HttpClientInterface $httpClient): AsyncEngine
    {
        if (!isset(self::$engines[$location])) {
            $wsdl = (new Wsdl1Reader(new FlatteningLoader(new StreamWrapperLoader())))(
                $location,
                ParserContext::defaults()
            );

            $driver = Driver::createFromWsdl1(
                $wsdl,
                ServiceSelectionCriteria::defaults(),
                EncoderRegistry::default()
            );

            self::$engines[$location] = new AsyncEngine(
                $driver,
                $httpClient,
                new Psr7Converter(
                    Psr17FactoryDiscovery::findRequestFactory(),
                    Psr17FactoryDiscovery::findStreamFactory()
                )
            );
        }

        return self::$engines[$location];
    }
}