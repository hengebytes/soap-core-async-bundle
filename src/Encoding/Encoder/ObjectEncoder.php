<?php
declare(strict_types=1);

/*
 * Copied from php-soap/encoding 0.35.0:
 *   vendor/php-soap/encoding/src/Encoder/ObjectEncoder.php
 *   https://github.com/php-soap/encoding/blob/0.35.0/src/Encoder/ObjectEncoder.php
 *
 * Change: from() decodes without exception-driven control flow and without per-property lens/Result objects:
 * absent nodes resolve to null, stdClass is hydrated by a plain (object) cast (upstream object_data() throws and
 * catches an exception per dynamic property), and per-type decode data is precomputed in the bundle's ObjectAccess
 * copy (same namespace). 3 MB SynXis availability response: ~6 s upstream, ~0.35 s here, identical output.
 * Changed parts are wrapped in "// + OVERRIDE START" / "// - OVERRIDE END" comments; everything else is verbatim.
 * Remove this copy once php-soap/encoding ships the fix:
 *   https://github.com/ITernovtsii/encoding/tree/perf/decode-without-exception-control-flow
 */

// + OVERRIDE START: bundle namespace (was Soap\Encoding\Encoder), so its classes are imported explicitly
namespace Hengebytes\SoapCoreAsyncBundle\Encoding\Encoder;

use Soap\Encoding\Encoder\Context;
use Soap\Encoding\Encoder\Feature;
use Soap\Encoding\Encoder\XmlEncoder;
// - OVERRIDE END
use Closure;
use Exception;
use Soap\Encoding\Xml\Node\Element;
use Soap\Encoding\Xml\Reader\DocumentToLookupArrayReader;
use Soap\Encoding\Xml\Writer\AttributeBuilder;
use Soap\Encoding\Xml\Writer\NilAttributeBuilder;
use Soap\Encoding\Xml\Writer\XsdTypeXmlElementWriter;
use Soap\Encoding\Xml\Writer\XsiAttributeBuilder;
use Soap\Engine\Metadata\Model\Property;
use VeeWee\Reflecta\Iso\Iso;
use VeeWee\Reflecta\Lens\LensInterface;
use function is_array;
use function Psl\Dict\map_with_key;
use function Psl\Fun\lazy;
use function VeeWee\Reflecta\Iso\object_data;
use function VeeWee\Xml\Writer\Builder\children as writeChildren;
use function VeeWee\Xml\Writer\Builder\raw;
use function VeeWee\Xml\Writer\Builder\value as buildValue;

/**
 * @template TObj extends object
 *
 * @implements XmlEncoder<TObj|array, TObj, non-empty-string, Element|non-empty-string>
 */
final class ObjectEncoder implements Feature\ElementAware, XmlEncoder
{
    /**
     * @param class-string<TObj> $className
     */
    public function __construct(
        private readonly string $className
    ) {
    }

    /**
     * @return Iso<TObj|array, TObj, non-empty-string, Element|non-empty-string>
     */
    public function iso(Context $context): Iso
    {
        $objectAccess = lazy(static fn (): ObjectAccess => ObjectAccess::forContext($context));

        return new Iso(
            /**
             * @param TObj|array $value
             * @return non-empty-string
             */
            function (object|array $value) use ($context, $objectAccess) : string {
                return $this->to($context, $objectAccess(), $value);
            },
            /**
             * @param non-empty-string|Element $value
             * @return TObj
             */
            function (string|Element $value) use ($context, $objectAccess) : object {
                return $this->from(
                    $context,
                    $objectAccess(),
                    ($value instanceof Element ? $value : Element::fromString($value))
                );
            }
        );
    }

    /**
     * @param TObj|array $data
     *
     * @return non-empty-string
     */
    private function to(Context $context, ObjectAccess $objectAccess, object|array $data): string
    {
        if (is_array($data)) {
            $data = (object) $data;
        }
        $defaultAction = writeChildren([]);

        return (new XsdTypeXmlElementWriter())(
            $context,
            writeChildren(
                [
                    XsiAttributeBuilder::forEncodedValue(
                        $context,
                        $this,
                        $data,
                        forceIncludeXsiTargetNamespace: !$objectAccess->isAnyPropertyQualified,
                    ),
                    ...map_with_key(
                        $objectAccess->properties,
                        static function (string $normalizePropertyName, Property $property) use ($objectAccess, $data, $defaultAction) : Closure {
                            $type = $property->getType();
                            $meta = $type->getMeta();
                            $isAttribute = $meta->isAttribute()->unwrapOr(false);

                            /** @var mixed $value */
                            $value = self::runLens(
                                $objectAccess->encoderLenses[$normalizePropertyName],
                                $data
                            );
                            $iso = $objectAccess->isos[$normalizePropertyName];

                            return match(true) {
                                $isAttribute => $value !== null ? (new AttributeBuilder(
                                    $type,
                                    (string) $iso->to($value)
                                ))(...) : $defaultAction,
                                $property->getName() === '_' => $value !== null
                                    ? buildValue((string) $iso->to($value))
                                    : (new NilAttributeBuilder())(...),
                                default => raw((string) $iso->to($value))
                            };
                        }
                    )
                ]
            )
        );
    }

    /**
     * @return TObj
     */
    private function from(Context $context, ObjectAccess $objectAccess, Element $data): object
    {
        // + OVERRIDE START: body rewritten; upstream maps every property through runLens() (lens + Psl Result objects
        // per property), re-reads type meta per element and hydrates via object_data(). Here it follows
        // ObjectAccess::$decodePlan: default-lens properties are read straight from the node lookup array, flags are
        // precomputed, and stdClass is hydrated with a plain cast. Decoded values are identical.
        $nodes = (new DocumentToLookupArrayReader())($data);
        $values = [];
        foreach ($objectAccess->decodePlan as $normalizePropertyName => [$nodeName, $isList, $isAttribute]) {
            /** @var string|null $value */
            $value = $nodeName !== null
                ? ($nodes[$nodeName] ?? null)
                : self::runLens($objectAccess->decoderLenses[$normalizePropertyName], $nodes);
            $iso = $objectAccess->isos[$normalizePropertyName];

            /** @psalm-suppress PossiblyNullArgument */
            $values[$normalizePropertyName] = match(true) {
                $isAttribute => $iso->from($value),
                default => $value !== null ? $iso->from($value) : ($isList ? [] : null),
            };
        }

        if ($this->className === \stdClass::class) {
            /** @var TObj */
            return (object) $values;
        }

        /** @var Iso<TObj, TObj, array<string, mixed>, array<string, mixed>> $objectData */
        $objectData = object_data($this->className);

        return $objectData->from($values);
        // - OVERRIDE END
    }

    private static function runLens(LensInterface $lens, mixed $data, mixed $default = null): mixed
    {
        try {
            /** @var mixed */
            return $lens->get($data);
        } catch (Exception $e) {
            return $default;
        }
    }
}
