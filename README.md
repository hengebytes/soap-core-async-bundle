# Soap Core Async Bundle

[![Latest Stable Version](https://poser.pugx.org/hengebytes/soap-core-async-bundle/v/stable.svg)](https://packagist.org/packages/hengebytes/soap-core-async-bundle)
[![Total Downloads](https://poser.pugx.org/hengebytes/soap-core-async-bundle/downloads.svg)](https://packagist.org/packages/hengebytes/soap-core-async-bundle)
[![License](https://poser.pugx.org/hengebytes/soap-core-async-bundle/license.svg)](https://packagist.org/packages/hengebytes/soap-core-async-bundle)

This bundle provides a way to filter the response of async web services core bundle.

## Add the bundle to your Kernel

```php
// config/bundles.php
return [
    // ...
    Hengebytes\SoapCoreAsyncBundle\HBSoapCoreAsyncBundle::class => ['all' => true],
];
```

## [Usage](https:://github.com/hengebytes/webservice-core-async-bundle)

### If you need some custom SOAP headers, you can add it to the request with middleware.
```php
// src/Middleware/CustomSoapHeaderMiddleware.php
namespace App\Middleware;

use Hengebytes\SettingBundle\Interfaces\SettingHandlerInterface;
use Hengebytes\WebserviceCoreAsyncBundle\Middleware\RequestModifierInterface;
use Hengebytes\WebserviceCoreAsyncBundle\Request\WSRequest;
use Soap\Xml\Builder\SoapHeader;
use function VeeWee\Xml\Dom\Builder\children;
use function VeeWee\Xml\Dom\Builder\namespaced_element;
use function VeeWee\Xml\Dom\Builder\value;

readonly class SoapHeaderRequestModifier implements RequestModifierInterface
{
    public function modify(WSRequest $request): void
    {
        $tns = 'http://htng.org/1.1/Header/';
        $request->setHeaders([
            new SoapHeader(
                $tns,
                'hb322:HTNGHeader',
                children(
                    namespaced_element($tns, 'hb322:From', children(
                            namespaced_element($tns, 'hb322:systemId', value('APPTEST')),
                            namespaced_element($tns, 'hb322:Credential', children(
                                    namespaced_element($tns, 'hb322:userName', value('someUsername')),
                                    namespaced_element($tns, 'hb322:password', value('somePassword'))
                                )
                            )
                        )
                    ),
                    namespaced_element($tns, 'hb322:timeStamp', value(date('c')))
                )
            ),
        ]);
    }

    public function supports(WSRequest $request): bool
    {
        return $request->webService === 'YourServiceName' && $request->subService === 'YourSubServiceName';
    }

    public static function getPriority(): int
    {
        return 0;
    }
}
```