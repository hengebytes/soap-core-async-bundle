<?php

declare(strict_types=1);

namespace Hengebytes\SoapCoreAsyncBundle\Tests\Encoding;

use Dom\XMLDocument;
use PHPUnit\Framework\TestCase;
use Soap\Encoding\Driver;
use Soap\Engine\HttpBinding\SoapResponse;
use Soap\Wsdl\Loader\StreamWrapperLoader;
use Soap\WsdlReader\Locator\ServiceSelectionCriteria;
use Soap\WsdlReader\Metadata\Wsdl1MetadataProvider;
use Soap\WsdlReader\Wsdl1Reader;

/**
 * A large sparse response (elements that leave most of what their type declares out, as SynXis availability answers
 * do) must stay fast to decode. Up to php-soap/encoding 0.35 every absent node and every stdClass property cost a
 * thrown and caught exception: a 3 MB response took seconds, which is why the bundle carried its own decoder until
 * 0.36 fixed it. This fails if a later version of the library (or of veewee/reflecta, which hydrates the objects)
 * brings that cost back.
 *
 * Measured against parsing the same XML, so the limit holds on a slow machine as on a fast one: decoding costs about
 * 7 times the parse with php-soap/encoding 0.37, and about 50 times with 0.35.
 */
final class DecodingSpeedTest extends TestCase
{
    private const string FIXTURES = __DIR__ . '/../Fixtures/';
    private const int RESPONSE_BYTES = 3 * 1024 * 1024;
    private const float MAX_DECODE_TO_PARSE_RATIO = 20.0;
    private const string SPARSE_ROOM = '<Room Code="EK1"><Rate RatePlanCode="RNRF"/>'
        . '<Rate RatePlanCode="RFLX" Points="1"><Total>1.00</Total></Rate>'
        . '<Rate RatePlanCode="RPKG"><Policy/></Rate></Room>';

    public function testALargeSparseResponseDecodesWithoutThePerAbsentNodeCost(): void
    {
        $xml = (string) file_get_contents(self::FIXTURES . 'decode-response.xml');
        $roomsStart = (int) strpos($xml, '<Room');
        $roomsEnd = (int) strrpos($xml, '</AvailabilityRS>');
        $rooms = (int) ceil(self::RESPONSE_BYTES / strlen(self::SPARSE_ROOM));
        $response = substr($xml, 0, $roomsStart) . str_repeat(self::SPARSE_ROOM, $rooms) . substr($xml, $roomsEnd);

        $wsdl = (new Wsdl1Reader(new StreamWrapperLoader()))(self::FIXTURES . 'decode.wsdl');
        $driver = Driver::createFromMetadata(
            (new Wsdl1MetadataProvider($wsdl, ServiceSelectionCriteria::defaults()))->getMetadata(),
            $wsdl->namespaces,
        );
        // The per-type work, done once, is not what is measured
        $driver->decode('CheckAvailability', new SoapResponse($xml));

        $decoded = null;
        $parse = self::bestOfThree(static fn () => XMLDocument::createFromString($response));
        $decode = self::bestOfThree(static function () use ($driver, $response, &$decoded): void {
            $decoded = $driver->decode('CheckAvailability', new SoapResponse($response));
        });

        self::assertCount($rooms, $decoded->Room);
        self::assertNull($decoded->Room[$rooms - 1]->Rate[0]->Code);
        self::assertLessThan(
            self::MAX_DECODE_TO_PARSE_RATIO,
            $decode / $parse,
            sprintf('Decoding %.1f MB took %.2f s, %.1f times its XML parse (%.3f s)', strlen($response) / 1048576, $decode, $decode / $parse, $parse),
        );
    }

    private static function bestOfThree(callable $run): float
    {
        $best = INF;
        for ($i = 0; $i < 3; $i++) {
            $start = hrtime(true);
            $run();
            $best = min($best, (hrtime(true) - $start) / 1e9);
        }

        return $best;
    }
}
