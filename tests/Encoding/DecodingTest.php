<?php

declare(strict_types=1);

namespace Hengebytes\SoapCoreAsyncBundle\Tests\Encoding;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Soap\Encoding\Driver;
use Soap\Engine\HttpBinding\SoapResponse;
use Soap\Wsdl\Loader\StreamWrapperLoader;
use Soap\WsdlReader\Locator\ServiceSelectionCriteria;
use Soap\WsdlReader\Metadata\Wsdl1MetadataProvider;
use Soap\WsdlReader\Wsdl1Reader;
use stdClass;

/**
 * What php-soap/encoding decodes a response to, as AsyncEngineFactory sets its driver up: the shape the bundle's users
 * read, kept under test across the library's upgrades (the bundle carried its own decoder up to php-soap/encoding 0.35)
 */
final class DecodingTest extends TestCase
{
    private const string FIXTURES = __DIR__ . '/../Fixtures/';

    /**
     * @return iterable<string, array{string, string, string, callable(mixed): void}>
     */
    public static function provideFixtures(): iterable
    {
        yield 'document/literal' => [
            'decode.wsdl',
            'decode-response.xml',
            'CheckAvailability',
            static function (mixed $decoded): void {
                self::assertInstanceOf(stdClass::class, $decoded);
                // An absent element or attribute is null, an absent list empty
                self::assertNull($decoded->Room[0]->Rate[1]->Code);
                self::assertNull($decoded->Room[1]->Code);
                self::assertSame([], $decoded->Room[2]->Rate);
            },
        ];
        yield 'rpc/encoded' => [
            'encoded.wsdl',
            'encoded-response.xml',
            'GetItem',
            static function (mixed $decoded): void {
                self::assertSame(['a', 'b'], $decoded->tags);
                self::assertNull($decoded->note);
            },
        ];
    }

    #[DataProvider('provideFixtures')]
    public function testDecodesAResponse(string $wsdlFile, string $responseFile, string $method, callable $assert): void
    {
        $wsdl = (new Wsdl1Reader(new StreamWrapperLoader()))(self::FIXTURES . $wsdlFile);
        $metadata = (new Wsdl1MetadataProvider($wsdl, ServiceSelectionCriteria::defaults()))->getMetadata();

        $decoded = Driver::createFromMetadata($metadata, $wsdl->namespaces)
            ->decode($method, new SoapResponse((string) file_get_contents(self::FIXTURES . $responseFile)));

        $assert($decoded);
    }
}
