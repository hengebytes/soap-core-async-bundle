<?php

declare(strict_types=1);

namespace Hengebytes\SoapCoreAsyncBundle\Encoding;

use Hengebytes\SoapCoreAsyncBundle\Encoding\Encoder\ObjectEncoder;
use Soap\Encoding\EncoderRegistry;
use Soap\Engine\Metadata\Metadata;
use Soap\Engine\Metadata\Model\TypeMeta;
use stdClass;

final class EncoderRegistryFactory
{
    /**
     * Default registry where every complex type of the metadata is decoded by the bundle's {@see ObjectEncoder}
     * instead of php-soap's built-in one (which is what php-soap falls back to for any unregistered complex type).
     */
    public static function create(Metadata $metadata): EncoderRegistry
    {
        $registry = EncoderRegistry::default();
        $objectEncoder = new ObjectEncoder(stdClass::class);

        foreach ($metadata->getTypes() as $type) {
            $xsdType = $type->getXsdType();
            if (
                $xsdType->getMeta()->isSimple()->unwrapOr(false)
                || self::inheritsRegisteredEncoder($registry, $xsdType->getMeta(), $objectEncoder)
            ) {
                continue;
            }

            // anonymous inline types are referenced by their generated name (e.g. "RatePolicy"),
            // while their xmlTypeName is the element name (e.g. "Policy"): register both
            foreach (array_unique([$type->getName(), $xsdType->getXmlTypeName()]) as $name) {
                if (!$registry->hasRegisteredComplexTypeForNamespaceName($xsdType->getXmlNamespace(), $name)) {
                    $registry->addComplexTypeConverter($xsdType->getXmlNamespace(), $name, $objectEncoder);
                }
            }
        }

        return $registry;
    }

    /**
     * php-soap resolves an unregistered complex type through the encoder of its (non-simple) base type first,
     * e.g. a soapenc:Array restriction to SoapArrayEncoder. Such types must stay unregistered to keep that behaviour.
     */
    private static function inheritsRegisteredEncoder(EncoderRegistry $registry, TypeMeta $meta, ObjectEncoder $objectEncoder): bool
    {
        return $meta->extends()
            ->filter(static fn (array $extends): bool => !($extends['isSimple'] ?? false))
            ->map(static fn (array $extends): bool => $registry->hasRegisteredComplexTypeForNamespaceName($extends['namespace'], $extends['type'])
                && $registry->findComplexEncoderByNamespaceName($extends['namespace'], $extends['type']) !== $objectEncoder)
            ->unwrapOr(false);
    }
}
