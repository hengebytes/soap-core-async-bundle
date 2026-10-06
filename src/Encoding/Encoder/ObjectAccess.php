<?php
declare(strict_types=1);

/*
 * Copied from php-soap/encoding 0.35.0:
 *   vendor/php-soap/encoding/src/Encoder/ObjectAccess.php
 *   https://github.com/php-soap/encoding/blob/0.35.0/src/Encoder/ObjectAccess.php
 *
 * Change: the default decoder lens resolves absent elements/attributes to null instead of throwing
 * (upstream index() throws an exception per absent node, which optional()/runLens() then catch), and a per-type
 * $decodePlan is precomputed for the bundle's ObjectEncoder::from().
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
use Soap\Encoding\Cache\ScopedCache;
use Soap\Encoding\EncoderRegistry;
use Soap\Encoding\Normalizer\PhpPropertyNameNormalizer;
use Soap\Encoding\TypeInference\ComplexTypeBuilder;
use Soap\Encoding\Xml\Node\Element;
use Soap\Encoding\Xml\Node\ElementList;
use Soap\Engine\Metadata\Model\Property;
use Soap\Engine\Metadata\Model\Type;
use Soap\Engine\Metadata\Model\TypeMeta;
use VeeWee\Reflecta\Iso\IsoInterface;
use VeeWee\Reflecta\Lens\LensInterface;
use function Psl\Vec\sort_by;
use function VeeWee\Reflecta\Lens\index;
use function VeeWee\Reflecta\Lens\optional;
use function VeeWee\Reflecta\Lens\property;

final class ObjectAccess
{
    /**
     * @param array<string, Property> $properties
     * @param array<string, LensInterface<object|null, object|null, mixed, mixed>> $encoderLenses
     * @param array<string, LensInterface<array<array-key, mixed>|null, array<array-key, mixed>|null, mixed, mixed>> $decoderLenses
     * @param array<string, IsoInterface<mixed, mixed, string|null, Element|ElementList|string|null>> $isos
     */
    public function __construct(
        public readonly array $properties,
        public readonly array $encoderLenses,
        public readonly array $decoderLenses,
        public readonly array $isos,
        // + OVERRIDE START: decodePlan added - [normalized name => [default-lens node name|null, isList, isAttribute]]
        public readonly bool  $isAnyPropertyQualified,
        public readonly array $decodePlan,
        // - OVERRIDE END
    ) {
    }

    /**
     * @return ScopedCache<EncoderRegistry, self>
     *
     * @psalm-suppress LessSpecificReturnStatement, MoreSpecificReturnType, MixedReturnStatement
     */
    private static function cache(): ScopedCache
    {
        static $cache = new ScopedCache();

        return $cache;
    }

    private static function cacheKey(Context $context): string
    {
        return $context->type->getXmlNamespace() . '|' . $context->type->getName()
            . '|' . $context->bindingUse->value;
    }

    public static function forContext(Context $context): self
    {
        return self::cache()->lookup(
            $context->registry,
            self::cacheKey($context),
            static fn (): self => self::build($context)
        );
    }

    private static function build(Context $context): self
    {
        $type = ComplexTypeBuilder::default()($context);

        $sortedProperties = sort_by(
            $type->getProperties(),
            static fn (Property $property): bool => !$property->getType()->getMeta()->isAttribute()->unwrapOr(false),
        );

        $normalizedProperties = [];
        $encoderLenses = [];
        $decoderLenses = [];
        $isos = [];
        $isAnyPropertyQualified = false;
        // + OVERRIDE START: decodePlan
        $decodePlan = [];
        // - OVERRIDE END

        foreach ($sortedProperties as $property) {
            $propertyType = $property->getType();
            $propertyTypeMeta = $propertyType->getMeta();
            $propertyContext = $context->withType($propertyType);
            $name = $property->getName();
            $normalizedName = PhpPropertyNameNormalizer::normalize($name);

            $encoder = $context->registry->detectEncoderForContext($propertyContext);
            $shouldLensBeOptional = self::shouldLensBeOptional($propertyTypeMeta);
            $normalizedProperties[$normalizedName] = $property;

            $encoderLenses[$normalizedName] = self::createEncoderLensForType($shouldLensBeOptional, $normalizedName, $encoder, $type, $property);
            $decoderLenses[$normalizedName] = self::createDecoderLensForType($shouldLensBeOptional, $name, $encoder, $type, $property);
            $isos[$normalizedName] = $encoder->iso($propertyContext);
            // + OVERRIDE START: decodePlan - precomputed once per type instead of per decoded element
            $decodePlan[$normalizedName] = [
                self::usesDefaultDecoderLens($encoder) ? $name : null,
                $propertyTypeMeta->isList()->unwrapOr(false),
                $propertyTypeMeta->isAttribute()->unwrapOr(false),
            ];
            // - OVERRIDE END

            $isAnyPropertyQualified = $isAnyPropertyQualified || $propertyTypeMeta->isQualified()->unwrapOr(false);
        }

        return new self(
            $normalizedProperties,
            $encoderLenses,
            $decoderLenses,
            $isos,
            // + OVERRIDE START: decodePlan
            $isAnyPropertyQualified,
            $decodePlan,
            // - OVERRIDE END
        );
    }

    /**
     * @return LensInterface<object|null, object|null, mixed, mixed>
     */
    private static function createEncoderLensForType(
        bool $shouldLensBeOptional,
        string $normalizedName,
        XmlEncoder $encoder,
        Type $type,
        Property $property,
    ): LensInterface {
        $lens = match (true) {
            $encoder instanceof Feature\DecoratingEncoder => self::createEncoderLensForType($shouldLensBeOptional, $normalizedName, $encoder->decoratedEncoder(), $type, $property),
            $encoder instanceof Feature\ProvidesObjectEncoderLens => $encoder::createObjectEncoderLens($type, $property),
            default => property($normalizedName)
        };

        return $shouldLensBeOptional ? optional($lens) : $lens;
    }

    /**
     * @return LensInterface<array<array-key, mixed>|null, array<array-key, mixed>|null, mixed, mixed>
     */
    private static function createDecoderLensForType(
        bool $shouldLensBeOptional,
        string $name,
        XmlEncoder $encoder,
        Type $type,
        Property $property,
    ): LensInterface {
        $lens = match(true) {
            $encoder instanceof Feature\DecoratingEncoder => self::createDecoderLensForType($shouldLensBeOptional, $name, $encoder->decoratedEncoder(), $type, $property),
            $encoder instanceof Feature\ProvidesObjectDecoderLens => $encoder::createObjectDecoderLens($type, $property),
            // + OVERRIDE START: was `default => index($name),` which throws for every absent node
            default => \VeeWee\Reflecta\Lens\Lens::readonly(static fn (?array $data): mixed => $data[$name] ?? null),
            // - OVERRIDE END
        };

        return $shouldLensBeOptional ? optional($lens) : $lens;
    }

    // + OVERRIDE START: tells whether createDecoderLensForType() ends in its default branch (plain node lookup),
    // so ObjectEncoder::from() can read the node directly. MUST mirror the match in createDecoderLensForType():
    // re-check it whenever this file is re-synced with a newer php-soap/encoding.
    private static function usesDefaultDecoderLens(XmlEncoder $encoder): bool
    {
        return match(true) {
            $encoder instanceof Feature\DecoratingEncoder => self::usesDefaultDecoderLens($encoder->decoratedEncoder()),
            $encoder instanceof Feature\ProvidesObjectDecoderLens => false,
            default => true,
        };
    }
    // - OVERRIDE END

    private static function shouldLensBeOptional(TypeMeta $meta): bool
    {
        if ($meta->isNullable()->unwrapOr(false)) {
            return true;
        }

        if (
            $meta->isAttribute()->unwrapOr(false) &&
            $meta->use()->unwrapOr('optional') === 'optional'
        ) {
            return true;
        }

        return false;
    }
}
