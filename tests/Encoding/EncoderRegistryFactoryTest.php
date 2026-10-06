<?php

declare(strict_types=1);

namespace Hengebytes\SoapCoreAsyncBundle\Tests\Encoding;

use Hengebytes\SoapCoreAsyncBundle\Encoding\Encoder\ObjectEncoder;
use Hengebytes\SoapCoreAsyncBundle\Encoding\EncoderRegistryFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Soap\Encoding\Driver;
use Soap\Encoding\Encoder\SoapEnc\SoapArrayEncoder;
use Soap\Engine\HttpBinding\SoapResponse;
use Soap\Engine\Metadata\Metadata;
use Soap\Engine\Metadata\Model\XsdType;
use Soap\Wsdl\Loader\StreamWrapperLoader;
use Soap\WsdlReader\Locator\ServiceSelectionCriteria;
use Soap\WsdlReader\Metadata\Wsdl1MetadataProvider;
use Soap\WsdlReader\Model\Wsdl1;
use Soap\WsdlReader\Wsdl1Reader;

final class EncoderRegistryFactoryTest extends TestCase
{
    private const string FIXTURES = __DIR__ . '/../Fixtures/';

    public function testEveryReachableComplexTypeUsesBundleObjectEncoder(): void
    {
        $metadata = $this->metadata($this->wsdl('decode.wsdl'));
        $registry = EncoderRegistryFactory::create($metadata);

        $complexTypes = [];
        foreach ($metadata->getTypes() as $type) {
            $complexTypes[] = $type->getXsdType();
            foreach ($type->getProperties() as $property) {
                $complexTypes[] = $property->getType();
            }
        }
        foreach ($metadata->getMethods() as $method) {
            $complexTypes[] = $method->getReturnType();
            foreach ($method->getParameters() as $parameter) {
                $complexTypes[] = $parameter->getType();
            }
        }
        $complexTypes = array_filter(
            $complexTypes,
            static fn (XsdType $type): bool => !$type->getMeta()->isSimple()->unwrapOr(false),
        );

        self::assertNotEmpty($complexTypes);
        foreach ($complexTypes as $type) {
            self::assertInstanceOf(
                ObjectEncoder::class,
                $registry->findComplexEncoderByXsdType($type),
                sprintf('Complex type {%s}%s is not decoded by the bundle ObjectEncoder', $type->getXmlNamespace(), $type->getXmlTypeName()),
            );
        }
    }

    public function testTypesDerivedFromBuiltInEncodersAreLeftToPhpSoap(): void
    {
        $metadata = $this->metadata($this->wsdl('encoded.wsdl'));
        $registry = EncoderRegistryFactory::create($metadata);

        // ArrayOfString restricts soapenc:Array: php-soap resolves it through its base type to SoapArrayEncoder
        self::assertFalse($registry->hasRegisteredComplexTypeForNamespaceName('http://test-uri/', 'ArrayOfString'));
        self::assertInstanceOf(
            SoapArrayEncoder::class,
            $registry->findComplexEncoderByNamespaceName('http://schemas.xmlsoap.org/soap/encoding/', 'Array'),
        );
        self::assertInstanceOf(ObjectEncoder::class, $registry->findComplexEncoderByNamespaceName('http://test-uri/', 'Item'));
    }

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
                // guards the fixture: absent nodes and both hydration paths are really exercised
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
    public function testDecodesExactlyLikePhpSoap(string $wsdlFile, string $responseFile, string $method, callable $guard): void
    {
        $wsdl = $this->wsdl($wsdlFile);
        $response = new SoapResponse(file_get_contents(self::FIXTURES . $responseFile));

        // Both drivers must get their own Metadata: php-soap caches detected encoders per XsdType object, so a shared
        // Metadata would serve the second driver the first one's encoders and make this comparison vacuous.
        $expected = Driver::createFromWsdl1($wsdl, ServiceSelectionCriteria::defaults())->decode($method, $response);
        $metadata = $this->metadata($wsdl);
        $actual = Driver::createFromMetadata($metadata, $wsdl->namespaces, EncoderRegistryFactory::create($metadata))
            ->decode($method, $response);

        self::assertEquals($expected, $actual);
        self::assertSame(serialize($expected), serialize($actual));
        $guard($actual);
    }

    private function wsdl(string $file): Wsdl1
    {
        return (new Wsdl1Reader(new StreamWrapperLoader()))(self::FIXTURES . $file);
    }

    private function metadata(Wsdl1 $wsdl): Metadata
    {
        return (new Wsdl1MetadataProvider($wsdl, ServiceSelectionCriteria::defaults()))->getMetadata();
    }
}
