<?php

namespace Hengebytes\SoapCoreAsyncBundle\Wsdl;

use Soap\Wsdl\Loader\WsdlLoader;

readonly class FileCacheWsdlLoader implements WsdlLoader
{
    public function __construct(private WsdlLoader $loader)
    {
    }

    public function __invoke(string $location): string
    {
        $cacheFile = sys_get_temp_dir() . '/wsdl-cache-' . md5($location) . '.xml';

        if (file_exists($cacheFile) && filemtime($cacheFile) > time() - 86400) {
            return file_get_contents($cacheFile);
        }

        $wsdlContent = ($this->loader)($location);
        file_put_contents($cacheFile, $wsdlContent);

        return $wsdlContent;
    }

}