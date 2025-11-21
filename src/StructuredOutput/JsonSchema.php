<?php

declare(strict_types=1);

namespace NeuronAI\StructuredOutput;

use NeuronAI\StaticConstructor;
use ReflectionClass;
use ReflectionEnum;
use ReflectionEnumBackedCase;
use ReflectionException;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionUnionType;
use ReflectionType;

/**
 * @method static static make(string $discriminator = '__classname__')
 */
class JsonSchema
{
    use StaticConstructor;

    /**
     * Track classes being processed to prevent infinite recursion
     */
    protected array $processedClasses = [];

    public function __construct(protected string $discriminator = '__classname__')
    {
    }

    /**
     * Generate JSON schema from a PHP class
     *
     * @param string $class Fully qualified class name
     * @return array JSON schema definition
     * @throws ReflectionException
     */
    public function generate(string $class): array
    {
        // Reset processed classes for a new generation
        $this->processedClasses = [];

        // Generate the main schema
        return [
            ...$this->generateClassSchema($class),
            'additionalProperties' => false,
        ];
    }

    /**
     * Generate schema for a class
     *
     * @param string $class Class name
     * @return array The schema
     * @throws ReflectionException
     */
    protected function generateClassSchema(string $class): array
    {
        $reflection = new ReflectionClass($class);

        // Check for circular reference
        if (\in_array($class, $this->processedClasses, true)) {
            return ['type' => 'object'];
        }

        $this->processedClasses[] = $class;

        if ($reflection->isEnum()) {
            $result = $this->processEnum(new ReflectionEnum($class));
            \array_pop($this->processedClasses);
            return $result;
        }

        $schema = [
            'type' => 'object',
            'properties' => [],
            'additionalProperties' => false,
        ];

        $requiredProperties = [];
        $properties = $reflection->getProperties(ReflectionProperty::IS_PUBLIC);

        foreach ($properties as $property) {
            // Property name is always the declared property name
            $propertyName = $property->getName();

            $schema['properties'][$propertyName] = $this->processProperty($property);

            $attribute = $this->getPropertyAttribute($property);
            if ($attribute instanceof SchemaProperty && $attribute->required !== null) {
                if ($attribute->required) {
                    $requiredProperties[] = $propertyName;
                }
            } else {
                $type = $property->getType(); // ReflectionType|null
                $isNullable = $type ? $type->allowsNull() : true;
                if (!$isNullable && !$property->hasDefaultValue()) {
                    $requiredProperties[] = $propertyName;
                }
            }
        }

        if ($requiredProperties !== []) {
            $schema['required'] = $requiredProperties;
        }

        \array_pop($this->processedClasses);
        return $schema;
    }

    /**
     * Process a single property to generate its schema
     *
     * @return array Property schema
     * @throws ReflectionException
     */
    protected function processProperty(ReflectionProperty $property): array
    {
        $schema = [];

        // Attribute passthrough
        $attribute = $this->getPropertyAttribute($property);
        if ($attribute instanceof SchemaProperty) {
            if ($attribute->title !== null) {
                $schema['title'] = $attribute->title;
            }
            if ($attribute->description !== null) {
                $schema['description'] = $attribute->description;
            }
        }

        /** @var ReflectionType|null $type */
        $type = $property->getType();

        // Default values
        if ($property->hasDefaultValue()) {
            $schema['default'] = $property->getDefaultValue();
        }

        // UNION TYPES
        if ($type instanceof ReflectionUnionType) {
            $unionSchema = $this->processUnionType($type, $property);

            // Preserve title/description/default at top-level
            $schema = \array_merge($unionSchema, $schema);

            // Do NOT run nullable post-processing; union already handled null.
            return $schema;
        }

        // NAMED TYPES (or no type)
        /** @var ?ReflectionNamedType $named */
        $named = $type instanceof ReflectionNamedType ? $type : null;
        $typeName = $named?->getName();

        if ($typeName === 'array') {
            $schema['type'] = 'array';
            $docComment = $property->getDocComment();
            if ($docComment) {
                $types = $this->extractArrayItemTypes($docComment);
                if (\count($types) === 1) {
                    $schema['items'] = $this->generateClassSchema($types[0]);
                } elseif (\count($types) > 1) {
                    $schema['items'] = $this->generateAnyOfSchema($types);
                } else {
                    $schema['items'] = ['type' => 'string'];
                }
            } else {
                $schema['items'] = ['type' => 'string'];
            }
        } elseif ($typeName && \enum_exists($typeName)) {
            $enumReflection = new ReflectionEnum($typeName);
            $schema = \array_merge($schema, $this->processEnum($enumReflection));
        } elseif ($typeName && \class_exists($typeName)) {
            $schema = \array_merge($schema, $this->generateClassSchema($typeName));
        } elseif ($typeName) {
            $schema = \array_merge($schema, $this->getBasicTypeSchema($typeName));
        } else {
            $schema['type'] = 'string';
        }

        // Nullable for single named types only (NOT unions)
        if ($named && $named->allowsNull() && isset($schema['type']) && !isset($schema['$ref']) && !isset($schema['allOf'])) {
            if (\is_array($schema['type'])) {
                if (!\in_array('null', $schema['type'], true)) {
                    $schema['type'][] = 'null';
                }
            } else {
                $schema['type'] = [$schema['type'], 'null'];
            }
        }

        return $schema;
    }

    /**
     * Build schema for a union type
     *
     * @throws ReflectionException
     */
    protected function processUnionType(ReflectionUnionType $type, ReflectionProperty $property): array
    {
        $variants = [];
        $basicTypes = [];

        foreach ($type->getTypes() as $t) {
            // $t is ReflectionNamedType
            $name = $t->getName();

            if ($name === 'null') {
                // Treat as a basic type for collapse attempt
                $basicTypes[] = 'null';
                continue;
            }

            if ($name === 'array') {
                // Respect @var parsing for arrays
                $docComment = $property->getDocComment();
                $arraySchema = ['type' => 'array'];
                if ($docComment) {
                    $types = $this->extractArrayItemTypes($docComment);
                    if (\count($types) === 1) {
                        $arraySchema['items'] = $this->generateClassSchema($types[0]);
                    } elseif (\count($types) > 1) {
                        $arraySchema['items'] = $this->generateAnyOfSchema($types);
                    } else {
                        $arraySchema['items'] = ['type' => 'string'];
                    }
                } else {
                    $arraySchema['items'] = ['type' => 'string'];
                }

                $variants[] = $arraySchema;
                continue;
            }

            if (\enum_exists($name)) {
                $variants[] = $this->processEnum(new ReflectionEnum($name));
                continue;
            }

            if (\class_exists($name)) {
                $variants[] = $this->generateClassSchema($name);
                continue;
            }

            // Basic type
            $basicTypes[] = $this->getBasicTypeSchema($name)['type'] ?? 'string';
        }

        // If union is purely basic types (possibly including null), collapse to "type": [ ... ]
        if ($variants === [] && $basicTypes !== []) {
            $types = \array_values(\array_unique(\is_array($basicTypes) ? $basicTypes : [$basicTypes]));
            return ['type' => $types];
        }

        // If we have both complex variants and basic ones, convert basic ones into variant schemas
        foreach ($basicTypes as $bt) {
            // $bt might be 'null' or a basic JSON schema type string
            $variants[] = ['type' => $bt];
        }

        // If all variants are simple {"type": "..."} we can also collapse to a single "type": [..]
        $allSimple = \count($variants) > 0 && \array_reduce(
                $variants,
                fn (bool $carry, array $v): bool => $carry && (\count($v) === 1 && isset($v['type']) && !\is_array($v['type'])),
                true
            );

        if ($allSimple) {
            $types = \array_values(\array_unique(\array_map(fn ($v) => $v['type'], $variants)));
            return ['type' => $types];
        }

        return ['anyOf' => $variants];
    }

    // ... processEnum(), getPropertyAttribute() stay the same ...

    /**
     * Get schema for a basic PHP type
     *
     * @param string $type PHP type name
     * @return array Schema for the type
     * @throws ReflectionException
     */
    protected function getBasicTypeSchema(string $type): array
    {
        switch ($type) {
            case 'string':
                return ['type' => 'string'];

            case 'int':
            case 'integer':
                return ['type' => 'integer'];

            case 'float':
            case 'double':
                return ['type' => 'number'];

            case 'bool':
            case 'boolean':
                return ['type' => 'boolean'];

            case 'array':
                return [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ];

            case 'null':
                return ['type' => 'null'];

            default:
                if (\class_exists($type)) {
                    return $this->generateClassSchema($type);
                }
                if (\enum_exists($type)) {
                    return $this->processEnum(new ReflectionEnum($type));
                }
                return ['type' => 'string'];
        }
    }

    /**
     * Process an enum to generate its schema
     */
    protected function processEnum(ReflectionEnum $enum): array
    {
        // Create enum schema
        $schema = [
            'type' => 'string',
            'enum' => [],
        ];

        // Extract enum values
        foreach ($enum->getCases() as $case) {
            if ($enum->isBacked()) {
                /** @var ReflectionEnumBackedCase $case */
                // For backed enums, use the backing value
                $schema['enum'][] = $case->getBackingValue();
            } else {
                // For non-backed enums, use case name
                $schema['enum'][] = $case->getName();
            }
        }

        return $schema;
    }

    /**
     * Get the Property attribute if it exists on a property
     */
    protected function getPropertyAttribute(ReflectionProperty $property): ?SchemaProperty
    {
        $attributes = $property->getAttributes(SchemaProperty::class);
        if ($attributes !== []) {
            return $attributes[0]->newInstance();
        }
        return null;
    }

    /**
     * Extract array item types from PHPDoc comment
     *
     * Supports formats:
     * - @var \App\Type[]
     * - @var array<\App\Type>
     * - @var \App\TypeOne[]|\App\TypeTwo[]
     * - @var array<\App\TypeOne|\App\TypeTwo>
     *
     * @return array<class-string> Array of type strings (empty if no types found)
     */
    protected function extractArrayItemTypes(string $docComment): array
    {
        // Try to match array<Type1|Type2|...> format
        if (\preg_match('/@var\s+array<([^>]+)>/', $docComment, $matches)) {
            $typesString = $matches[1];
            // Split by pipe and trim whitespace
            return $this->filterClassTypes(
                \array_map(trim(...), \explode('|', $typesString))
            );
        }

        // Try to match Type1[]|Type2[]|... format
        if (\preg_match_all('/@var\s+([a-zA-Z0-9_\\\\]+)\[\](?:\|([a-zA-Z0-9_\\\\]+)\[\])*/', $docComment, $matches)) {
            // Extract all types from the first match group
            $fullMatch = $matches[0][0] ?? '';
            \preg_match_all('/([a-zA-Z0-9_\\\\]+)\[\]/', $fullMatch, $typeMatches);
            return $this->filterClassTypes($typeMatches[1]);
        }

        return [];
    }

    /**
     * Filter array of types to keep only class and enum types
     *
     * @param array $types Array of type strings
     * @return array Array of class/enum type strings
     */
    protected function filterClassTypes(array $types): array
    {
        return \array_filter($types, fn (string $type): bool => \class_exists($type) || \enum_exists($type));
    }

    /**
     * Generate anyOf schema for multiple class/enum types
     *
     * @param array $types Array of class/enum type strings
     * @return array Schema with anyOf structure
     * @throws ReflectionException
     */
    protected function generateAnyOfSchema(array $types): array
    {
        $schemas = [];

        foreach ($types as $type) {
            $schema = null;

            if (\class_exists($type)) {
                $schema = $this->generateClassSchema($type);
            } elseif (\enum_exists($type)) {
                $schema = $this->processEnum(new ReflectionEnum($type));
            }

            if ($schema !== null) {
                // Extract the short class name (lowercase) for discriminator
                $shortName = \strtolower(\basename(\str_replace('\\', '/', $type)));

                // Inject __classname__ discriminator into schema
                $schema = $this->injectDiscriminator($schema, $shortName);
                $schemas[] = $schema;
            }
        }

        return ['anyOf' => $schemas];
    }

    /**
     * Inject __classname__ discriminator field into schema
     *
     * @param array $schema The schema to inject into
     * @param string $discriminatorValue The discriminator value (lowercase class name)
     * @return array Modified schema
     */
    protected function injectDiscriminator(array $schema, string $discriminatorValue): array
    {
        // Only inject for object schemas
        if (isset($schema['type']) && $schema['type'] === 'object') {
            // Add __classname__ property at the beginning
            $schema['properties'] = [
                $this->discriminator => [
                    'type' => 'string',
                    'enum' => [$discriminatorValue],
                    'description' => 'This property is mandatory and can only be filled with "'.$discriminatorValue.'". It is used as a discriminator for class type resolution.',
                ],
                ...($schema['properties'] ?? []),
            ];

            // Make __classname__ required
            $schema['required'] = \array_unique([
                $this->discriminator,
                ...($schema['required'] ?? []),
            ]);
        }

        return $schema;
    }
}
