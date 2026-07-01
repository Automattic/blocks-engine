<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\FigFile;

/**
 * Decodes the Kiwi schema/message pair embedded in modern canvas.fig files.
 */
final class FigKiwiDecoder
{
    private const KINDS = array('ENUM', 'STRUCT', 'MESSAGE');
    private const INVENTORY_SAMPLE_LIMIT = 3;
    private const INVENTORY_SAMPLE_STRING_BYTES = 120;
    private const INVENTORY_SAMPLE_ARRAY_ITEMS = 8;

    private FigKiwiDecodePolicy $decodePolicy;
    private FigKiwiSchemaFields $schemaFields;

    public function __construct(?FigKiwiDecodePolicy $decodePolicy = null, ?FigKiwiSchemaFields $schemaFields = null)
    {
        $this->decodePolicy = $decodePolicy ?? new FigKiwiDecodePolicy();
        $this->schemaFields = $schemaFields ?? new FigKiwiSchemaFields();
    }

    /**
     * @return array{schema: array<string, mixed>|null, diagnostics: array<int, array<string, mixed>>}
     */
    public function decodeSchema(string $payload): array
    {
        try {
            $reader = new FigKiwiByteReader($payload);
            $definitions = array();
            $definitionCount = $reader->readVarUint();

            for ( $i = 0; $i < $definitionCount; $i++ ) {
                $kindIndex = null;
                $definition = array(
                    'name'   => $reader->readString(),
                    'kind'   => null,
                    'fields' => array(),
                );
                $kindIndex = $reader->readByte();
                $definition['kind'] = self::KINDS[$kindIndex] ?? 'UNKNOWN';
                $fieldCount = $reader->readVarUint();

                for ( $j = 0; $j < $fieldCount; $j++ ) {
                    $definition['fields'][] = array(
                        'name'          => $reader->readString(),
                        'type'          => $reader->readVarInt(),
                        'is_array'      => 1 === ($reader->readByte() & 1),
                        'is_deprecated' => false,
                        'value'         => $reader->readVarUint(),
                    );
                }

                $definitions[] = $definition;
            }

            foreach ( $definitions as $definitionIndex => $definition ) {
                foreach ( $definition['fields'] as $fieldIndex => $field ) {
                    $type = $field['type'];
                    if ( null !== $type && $type < 0 ) {
                        $definitions[$definitionIndex]['fields'][$fieldIndex]['type'] = FigKiwiSchemaFields::PRIMITIVE_TYPES[~$type] ?? null;
                    } elseif ( null !== $type ) {
                        $definitions[$definitionIndex]['fields'][$fieldIndex]['type'] = $definitions[$type]['name'] ?? null;
                    }
                }
            }

            return array('schema' => array('definitions' => $definitions), 'diagnostics' => array());
        } catch ( \Throwable $throwable ) {
            return array(
                'schema'      => null,
                'diagnostics' => array($this->diagnostic('figma_transformer_kiwi_schema_decode_failed', 'Kiwi schema chunk could not be decoded.', $throwable->getMessage())),
            );
        }
    }

    /**
     * @param array<string, mixed> $schema
     * @return array{message: array<string, mixed>|null, diagnostics: array<int, array<string, mixed>>}
     */
    public function decodeMessage(string $payload, array $schema, string $rootType = 'Message'): array
    {
        try {
            $definitions = $this->schemaFields->definitionsByName($schema);
            if ( ! isset($definitions[$rootType]) ) {
                return array('message' => null, 'diagnostics' => array($this->diagnostic('figma_transformer_kiwi_message_schema_missing', 'Kiwi schema does not define the expected root message.', $rootType)));
            }

            $message = $this->decodeDefinition(new FigKiwiByteReader($payload), $definitions[$rootType], $definitions);
            return array('message' => is_array($message) ? $message : null, 'diagnostics' => array());
        } catch ( \Throwable $throwable ) {
            return array(
                'message'     => null,
                'diagnostics' => array($this->diagnostic('figma_transformer_kiwi_message_decode_failed', 'Kiwi message chunk could not be decoded.', $throwable->getMessage())),
            );
        }
    }

    /**
     * Decode only fields needed to build a static scenegraph from production Kiwi messages.
     *
     * @param array<string, mixed> $schema
     * @return array{message: array<string, mixed>|null, diagnostics: array<int, array<string, mixed>>}
     */
    public function decodeMessageSelective(string $payload, array $schema, string $rootType = 'Message', array $fieldPolicy = array()): array
    {
        try {
            $definitions = $this->schemaFields->definitionsByName($schema);
            if ( ! isset($definitions[$rootType]) ) {
                return array('message' => null, 'diagnostics' => array($this->diagnostic('figma_transformer_kiwi_message_schema_missing', 'Kiwi schema does not define the expected root message.', $rootType)));
            }

            $policy = empty($fieldPolicy) ? $this->decodePolicy->defaultScenegraphFieldPolicy() : $fieldPolicy;
            $message = $this->decodeDefinitionSelective(new FigKiwiByteReader($payload), $definitions[$rootType], $definitions, $policy);
            return array('message' => is_array($message) ? $message : null, 'diagnostics' => array());
        } catch ( \Throwable $throwable ) {
            return array(
                'message'     => null,
                'diagnostics' => array($this->diagnostic('figma_transformer_kiwi_message_decode_failed', 'Kiwi message chunk could not be selectively decoded.', $throwable->getMessage())),
            );
        }
    }

    /**
     * Inventory fields skipped by the selective scenegraph decoder without changing
     * the production decoded payload shape.
     *
     * @param array<string, mixed> $schema
     * @return array{inventory: array<string, mixed>|null, diagnostics: array<int, array<string, mixed>>}
     */
    public function inventorySkippedFieldsSelective(string $payload, array $schema, string $rootType = 'Message', array $fieldPolicy = array()): array
    {
        try {
            $definitions = $this->schemaFields->definitionsByName($schema);
            if ( ! isset($definitions[$rootType]) ) {
                return array('inventory' => null, 'diagnostics' => array($this->diagnostic('figma_transformer_kiwi_message_schema_missing', 'Kiwi schema does not define the expected root message.', $rootType)));
            }

            $policy = empty($fieldPolicy) ? $this->decodePolicy->defaultScenegraphFieldPolicy() : $fieldPolicy;
            $inventory = array(
                'schema'             => 'blocks-engine/figma-transformer/kiwi-skipped-field-inventory/v1',
                'root_type'          => $rootType,
                'policy_groups'      => $this->decodePolicy->scenegraphFieldPolicyGroups(),
                'schema_definitions' => $this->schemaFields->schemaDefinitionInventory($schema),
                'fields'             => array(),
            );
            $context = $this->decodePolicy->initialInventoryContext($rootType);

            $this->inventoryDefinitionSelective(new FigKiwiByteReader($payload), $definitions[$rootType], $definitions, $policy, $inventory, $context);
            $inventory['summary'] = $this->decodePolicy->summarizeSkippedFieldInventory($inventory['fields']);
            return array('inventory' => $inventory, 'diagnostics' => array());
        } catch ( \Throwable $throwable ) {
            return array(
                'inventory'   => null,
                'diagnostics' => array($this->diagnostic('figma_transformer_kiwi_message_decode_failed', 'Kiwi message chunk could not be inventoried.', $throwable->getMessage())),
            );
        }
    }

    /**
     * @param array<string, mixed>                $definition
     * @param array<string, array<string, mixed>> $definitions
     */
    private function decodeDefinition(FigKiwiByteReader $reader, array $definition, array $definitions): array
    {
        $result = array();
        if ( 'MESSAGE' === ($definition['kind'] ?? null) ) {
            $fieldsByValue = $this->schemaFields->fieldsByValue($definition);

            while ( true ) {
                $fieldValue = $reader->readVarUint();
                if ( 0 === $fieldValue ) {
                    return $result;
                }
                if ( ! isset($fieldsByValue[$fieldValue]) ) {
                    throw new \RuntimeException('Attempted to parse invalid message field ' . $fieldValue . '.');
                }
                $this->decodeField($reader, $fieldsByValue[$fieldValue], $definitions, $result);
            }
        }

        foreach ( $this->schemaFields->fields($definition) as $field ) {
            $this->decodeField($reader, $field, $definitions, $result);
        }

        return $result;
    }

    /**
     * @param array<string, mixed>                $definition
     * @param array<string, array<string, mixed>> $definitions
     * @param array<string, array<int, string>>   $fieldPolicy
     */
    private function decodeDefinitionSelective(FigKiwiByteReader $reader, array $definition, array $definitions, array $fieldPolicy): array
    {
        $result = array();
        $typeName = (string) ($definition['name'] ?? '');
        $allowed = array_flip($fieldPolicy[$typeName] ?? array());

        if ( 'MESSAGE' === ($definition['kind'] ?? null) ) {
            $fieldsByValue = $this->schemaFields->fieldsByValue($definition);

            while ( true ) {
                $fieldValue = $reader->readVarUint();
                if ( 0 === $fieldValue ) {
                    return $result;
                }
                if ( ! isset($fieldsByValue[$fieldValue]) ) {
                    throw new \RuntimeException('Attempted to parse invalid message field ' . $fieldValue . '.');
                }

                $field = $fieldsByValue[$fieldValue];
                $fieldName = $this->schemaFields->fieldName($field);
                if ( isset($allowed[$fieldName]) ) {
                    $this->decodeFieldSelective($reader, $field, $definitions, $fieldPolicy, $result);
                } else {
                    $this->skipField($reader, $field, $definitions);
                }
            }
        }

        foreach ( $this->schemaFields->fields($definition) as $field ) {
            $fieldName = $this->schemaFields->fieldName($field);
            if ( isset($allowed[$fieldName]) ) {
                $this->decodeFieldSelective($reader, $field, $definitions, $fieldPolicy, $result);
            } else {
                $this->skipField($reader, $field, $definitions);
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed>                $field
     * @param array<string, array<string, mixed>> $definitions
     * @param array<string, array<int, string>>   $fieldPolicy
     * @param array<string, mixed>                $result
     */
    private function decodeFieldSelective(FigKiwiByteReader $reader, array $field, array $definitions, array $fieldPolicy, array &$result): void
    {
        $type = $this->schemaFields->fieldType($field);
        if ( $this->schemaFields->isArrayField($field) ) {
            if ( 'byte' === $type ) {
                $value = $reader->readByteArray();
            } else {
                $length = $reader->readVarUint();
                $value = array();
                for ( $i = 0; $i < $length; $i++ ) {
                    $value[] = $this->decodeValueSelective($reader, $type, $definitions, $fieldPolicy);
                }
            }
        } else {
            $value = $this->decodeValueSelective($reader, $type, $definitions, $fieldPolicy);
        }

        if ( ! $this->schemaFields->isDeprecatedField($field) && isset($field['name']) ) {
            $result[$this->schemaFields->fieldName($field)] = $value;
        }
    }

    /**
     * @param array<string, array<string, mixed>> $definitions
     * @param array<string, array<int, string>>   $fieldPolicy
     */
    private function decodeValueSelective(FigKiwiByteReader $reader, string $type, array $definitions, array $fieldPolicy): mixed
    {
        return match ( $type ) {
            'bool' => 0 !== $reader->readByte(),
            'byte' => $reader->readByte(),
            'int' => $reader->readVarInt(),
            'uint' => $reader->readVarUint(),
            'float' => $reader->readVarFloat(),
            'string' => $reader->readString(),
            'int64' => $reader->readVarInt64(),
            'uint64' => $reader->readVarUint64(),
            default => $this->decodeNamedValueSelective($reader, $type, $definitions, $fieldPolicy),
        };
    }

    /**
     * @param array<string, array<string, mixed>> $definitions
     * @param array<string, array<int, string>>   $fieldPolicy
     */
    private function decodeNamedValueSelective(FigKiwiByteReader $reader, string $type, array $definitions, array $fieldPolicy): mixed
    {
        $definition = $definitions[$type] ?? null;
        if ( ! is_array($definition) ) {
            throw new \RuntimeException('Invalid Kiwi type ' . $type . '.');
        }

        if ( 'ENUM' === ($definition['kind'] ?? null) ) {
            $value = $reader->readVarUint();
            foreach ( $definition['fields'] ?? array() as $field ) {
                if ( is_array($field) && (int) ($field['value'] ?? -1) === $value ) {
                    return (string) ($field['name'] ?? $value);
                }
            }
            return $value;
        }

        return $this->decodeDefinitionSelective($reader, $definition, $definitions, $fieldPolicy);
    }

    /**
     * @param array<string, mixed>                $field
     * @param array<string, array<string, mixed>> $definitions
     */
    private function skipField(FigKiwiByteReader $reader, array $field, array $definitions): void
    {
        $type = $this->schemaFields->fieldType($field);
        if ( $this->schemaFields->isArrayField($field) ) {
            if ( 'byte' === $type ) {
                $reader->skipByteArray();
                return;
            }

            $length = $reader->readVarUint();
            for ( $i = 0; $i < $length; $i++ ) {
                $this->skipValue($reader, $type, $definitions);
            }
            return;
        }

        $this->skipValue($reader, $type, $definitions);
    }

    /**
     * @param array<string, array<string, mixed>> $definitions
     */
    private function skipValue(FigKiwiByteReader $reader, string $type, array $definitions): void
    {
        match ( $type ) {
            'bool', 'byte' => $reader->readByte(),
            'int' => $reader->readVarInt(),
            'uint' => $reader->readVarUint(),
            'float' => $reader->readVarFloat(),
            'string' => $reader->skipString(),
            'int64' => $reader->readVarInt64(),
            'uint64' => $reader->readVarUint64(),
            default => $this->skipNamedValue($reader, $type, $definitions),
        };
    }

    /**
     * @param array<string, array<string, mixed>> $definitions
     */
    private function skipNamedValue(FigKiwiByteReader $reader, string $type, array $definitions): void
    {
        $definition = $definitions[$type] ?? null;
        if ( ! is_array($definition) ) {
            throw new \RuntimeException('Invalid Kiwi type ' . $type . '.');
        }

        if ( 'ENUM' === ($definition['kind'] ?? null) ) {
            $reader->readVarUint();
            return;
        }

        if ( 'MESSAGE' === ($definition['kind'] ?? null) ) {
            $fieldsByValue = $this->schemaFields->fieldsByValue($definition);

            while ( true ) {
                $fieldValue = $reader->readVarUint();
                if ( 0 === $fieldValue ) {
                    return;
                }
                if ( ! isset($fieldsByValue[$fieldValue]) ) {
                    throw new \RuntimeException('Attempted to skip invalid message field ' . $fieldValue . '.');
                }
                $this->skipField($reader, $fieldsByValue[$fieldValue], $definitions);
            }
        }

        foreach ( $this->schemaFields->fields($definition) as $field ) {
            $this->skipField($reader, $field, $definitions);
        }
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function defaultScenegraphFieldPolicy(): array
    {
        return $this->decodePolicy->defaultScenegraphFieldPolicy();
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function scenegraphFieldPolicyWithTextGlyphs(): array
    {
        return $this->decodePolicy->scenegraphFieldPolicyWithTextGlyphs();
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function scenegraphFieldPolicyGroups(): array
    {
        return $this->decodePolicy->scenegraphFieldPolicyGroups();
    }

    /**
     * @param array<string, mixed>                $definition
     * @param array<string, array<string, mixed>> $definitions
     * @param array<string, array<int, string>>   $fieldPolicy
     * @param array<string, mixed>                $inventory
     * @param array<string, mixed>                $context
     */
    private function inventoryDefinitionSelective(FigKiwiByteReader $reader, array $definition, array $definitions, array $fieldPolicy, array &$inventory, array $context): void
    {
        $typeName = (string) ($definition['name'] ?? '');
        $allowed = array_flip($fieldPolicy[$typeName] ?? array());
        $context['parent_type'] = $typeName;

        if ( 'MESSAGE' === ($definition['kind'] ?? null) ) {
            $fieldsByValue = $this->schemaFields->fieldsByValue($definition);

            while ( true ) {
                $fieldValue = $reader->readVarUint();
                if ( 0 === $fieldValue ) {
                    return;
                }
                if ( ! isset($fieldsByValue[$fieldValue]) ) {
                    throw new \RuntimeException('Attempted to inventory invalid message field ' . $fieldValue . '.');
                }

                $field = $fieldsByValue[$fieldValue];
                $fieldName = $this->schemaFields->fieldName($field);
                if ( isset($allowed[$fieldName]) ) {
                    $this->inventoryDecodeFieldSelective($reader, $field, $definitions, $fieldPolicy, $inventory, $context);
                } else {
                    $sample = $this->readFieldValue($reader, $field, $definitions);
                    $this->recordSkippedField($inventory, $field, $definitions, $context, $sample);
                }
            }
        }

        foreach ( $this->schemaFields->fields($definition) as $field ) {
            $fieldName = $this->schemaFields->fieldName($field);
            if ( isset($allowed[$fieldName]) ) {
                $this->inventoryDecodeFieldSelective($reader, $field, $definitions, $fieldPolicy, $inventory, $context);
            } else {
                $sample = $this->readFieldValue($reader, $field, $definitions);
                $this->recordSkippedField($inventory, $field, $definitions, $context, $sample);
            }
        }
    }

    /**
     * @param array<string, mixed>                $field
     * @param array<string, array<string, mixed>> $definitions
     * @param array<string, array<int, string>>   $fieldPolicy
     * @param array<string, mixed>                $inventory
     * @param array<string, mixed>                $context
     */
    private function inventoryDecodeFieldSelective(FigKiwiByteReader $reader, array $field, array $definitions, array $fieldPolicy, array &$inventory, array &$context): void
    {
        $fieldName = $this->schemaFields->fieldName($field);
        $type = $this->schemaFields->fieldType($field);
        $fieldPath = $this->schemaFields->fieldPath((string) ($context['path'] ?? ''), $fieldName);

        if ( $this->schemaFields->isArrayField($field) ) {
            if ( 'byte' === $type ) {
                $value = $reader->readByteArray();
            } else {
                $length = $reader->readVarUint();
                $value = array();
                for ( $i = 0; $i < $length; $i++ ) {
                    $elementContext = $context;
                    $elementContext['path'] = $fieldPath . '[]';
                    $value[] = $this->inventoryDecodeValueSelective($reader, $type, $definitions, $fieldPolicy, $inventory, $elementContext);
                }
            }
        } else {
            $value = $this->inventoryDecodeValueSelective($reader, $type, $definitions, $fieldPolicy, $inventory, array_merge($context, array('path' => $fieldPath)));
        }

        if ( 'NodeChange' === ($context['parent_type'] ?? null) ) {
            if ( 'type' === $fieldName && is_scalar($value) ) {
                $context['node_type'] = (string) $value;
            } elseif ( 'guid' === $fieldName ) {
                $context['node_id'] = $this->decodePolicy->formatInventoryNodeId($value);
            }
        }
    }

    /**
     * @param array<string, array<string, mixed>> $definitions
     * @param array<string, array<int, string>>   $fieldPolicy
     * @param array<string, mixed>                $inventory
     * @param array<string, mixed>                $context
     */
    private function inventoryDecodeValueSelective(FigKiwiByteReader $reader, string $type, array $definitions, array $fieldPolicy, array &$inventory, array $context): mixed
    {
        return match ( $type ) {
            'bool' => 0 !== $reader->readByte(),
            'byte' => $reader->readByte(),
            'int' => $reader->readVarInt(),
            'uint' => $reader->readVarUint(),
            'float' => $reader->readVarFloat(),
            'string' => $reader->readString(),
            'int64' => $reader->readVarInt64(),
            'uint64' => $reader->readVarUint64(),
            default => $this->inventoryDecodeNamedValueSelective($reader, $type, $definitions, $fieldPolicy, $inventory, $context),
        };
    }

    /**
     * @param array<string, array<string, mixed>> $definitions
     * @param array<string, array<int, string>>   $fieldPolicy
     * @param array<string, mixed>                $inventory
     * @param array<string, mixed>                $context
     */
    private function inventoryDecodeNamedValueSelective(FigKiwiByteReader $reader, string $type, array $definitions, array $fieldPolicy, array &$inventory, array $context): mixed
    {
        $definition = $definitions[$type] ?? null;
        if ( ! is_array($definition) ) {
            throw new \RuntimeException('Invalid Kiwi type ' . $type . '.');
        }

        if ( 'ENUM' === ($definition['kind'] ?? null) ) {
            $value = $reader->readVarUint();
            foreach ( $definition['fields'] ?? array() as $field ) {
                if ( is_array($field) && (int) ($field['value'] ?? -1) === $value ) {
                    return (string) ($field['name'] ?? $value);
                }
            }
            return $value;
        }

        if ( 'STRUCT' === ($definition['kind'] ?? null) ) {
            return $this->decodeDefinitionSelective($reader, $definition, $definitions, $fieldPolicy);
        }

        $childContext = $context;
        $childContext['parent_type'] = $type;
        if ( 'NodeChange' === $type ) {
            $childContext['node_type'] = null;
            $childContext['node_id'] = null;
        }

        $this->inventoryDefinitionSelective($reader, $definition, $definitions, $fieldPolicy, $inventory, $childContext);
        return array();
    }

    /**
     * @param array<string, mixed> $inventory
     * @param array<string, mixed> $field
     * @param array<string, mixed> $context
     */
    private function recordSkippedField(array &$inventory, array $field, array $definitions, array $context, mixed $sample): void
    {
        $fieldName = $this->schemaFields->fieldName($field);
        $type = $this->schemaFields->fieldType($field);
        $parentType = (string) ($context['parent_type'] ?? '');
        $path = $this->schemaFields->fieldPath((string) ($context['path'] ?? $parentType), $fieldName);
        $role = $this->decodePolicy->classifySkippedFieldRole($fieldName, $type, $parentType);
        $key = $this->schemaFields->inventoryKey($parentType, $path, $fieldName, $type);
        $typeDefinition = $this->schemaFields->typeDefinition($type, $definitions);

        if ( ! isset($inventory['fields'][$key]) ) {
            $inventory['fields'][$key] = array(
                'path'              => $path,
                'field'             => $fieldName,
                'type'              => $type,
                'type_kind'         => $typeDefinition['kind'] ?? 'PRIMITIVE',
                'wire_type'         => $this->schemaFields->wireType($field, $definitions),
                'type_definition'   => $typeDefinition,
                'parent_message'    => $parentType,
                'field_role'        => $role,
                'is_array'          => $this->schemaFields->isArrayField($field),
                'field_number'      => $this->schemaFields->fieldNumber($field),
                'occurrences'       => 0,
                'node_types'        => array(),
                'sample_node_ids'   => array(),
                'sample_nodes'      => array(),
                'sample_raw_values' => array(),
            );
        }

        $inventory['fields'][$key]['occurrences']++;
        $nodeType = is_scalar($context['node_type'] ?? null) ? (string) $context['node_type'] : 'unknown';
        $inventory['fields'][$key]['node_types'][$nodeType] = ($inventory['fields'][$key]['node_types'][$nodeType] ?? 0) + 1;
        $nodeId = is_scalar($context['node_id'] ?? null) ? (string) $context['node_id'] : '';
        if ( '' !== $nodeId && count($inventory['fields'][$key]['sample_node_ids']) < 5 && ! in_array($nodeId, $inventory['fields'][$key]['sample_node_ids'], true) ) {
            $inventory['fields'][$key]['sample_node_ids'][] = $nodeId;
        }

        $normalized = $this->normalizeInventorySample($sample);
        if ( count($inventory['fields'][$key]['sample_nodes']) < self::INVENTORY_SAMPLE_LIMIT ) {
            $nodeSample = array_filter(array(
                'node_id'   => '' !== $nodeId ? $nodeId : null,
                'node_type' => $nodeType,
                'path'      => $path,
                'raw_value' => $normalized,
            ), static fn (mixed $value): bool => null !== $value);
            if ( ! in_array($nodeSample, $inventory['fields'][$key]['sample_nodes'], true) ) {
                $inventory['fields'][$key]['sample_nodes'][] = $nodeSample;
            }
        }

        if ( count($inventory['fields'][$key]['sample_raw_values']) < self::INVENTORY_SAMPLE_LIMIT ) {
            if ( ! in_array($normalized, $inventory['fields'][$key]['sample_raw_values'], true) ) {
                $inventory['fields'][$key]['sample_raw_values'][] = $normalized;
            }
        }
    }

    /**
     * @param array<string, mixed>                $field
     * @param array<string, array<string, mixed>> $definitions
     */
    private function readFieldValue(FigKiwiByteReader $reader, array $field, array $definitions): mixed
    {
        $type = $this->schemaFields->fieldType($field);
        if ( $this->schemaFields->isArrayField($field) ) {
            if ( 'byte' === $type ) {
                return $reader->readByteArray();
            }

            $length = $reader->readVarUint();
            $value = array();
            for ( $i = 0; $i < $length; $i++ ) {
                $value[] = $this->decodeValue($reader, $type, $definitions);
            }
            return $value;
        }

        return $this->decodeValue($reader, $type, $definitions);
    }

    /**
     * @return mixed
     */
    private function normalizeInventorySample(mixed $value): mixed
    {
        if ( is_string($value) ) {
            $bytes = strlen($value);
            $printable = preg_match('/^[\x09\x0A\x0D\x20-\x7E]*$/', $value) ? $value : null;
            return array(
                'kind'        => 'string',
                'bytes'       => $bytes,
                'value'       => null === $printable ? null : substr($printable, 0, self::INVENTORY_SAMPLE_STRING_BYTES),
                'truncated'   => null !== $printable && $bytes > self::INVENTORY_SAMPLE_STRING_BYTES,
                'preview_hex' => bin2hex(substr($value, 0, 32)),
            );
        }

        if ( is_array($value) ) {
            $items = array();
            $count = 0;
            foreach ( $value as $key => $item ) {
                if ( $count >= self::INVENTORY_SAMPLE_ARRAY_ITEMS ) {
                    break;
                }
                $items[(string) $key] = $this->normalizeInventorySample($item);
                $count++;
            }
            return array(
                'kind'      => 'array',
                'count'     => count($value),
                'items'     => $items,
                'truncated' => count($value) > self::INVENTORY_SAMPLE_ARRAY_ITEMS,
            );
        }

        if ( is_bool($value) ) {
            return array('kind' => 'bool', 'value' => $value);
        }
        if ( is_int($value) ) {
            return array('kind' => 'int', 'value' => $value);
        }
        if ( is_float($value) ) {
            return array('kind' => 'float', 'value' => $value);
        }
        if ( null === $value ) {
            return array('kind' => 'null', 'value' => null);
        }

        return array('kind' => get_debug_type($value));
    }

    /**
     * @param array<string, mixed>                $field
     * @param array<string, array<string, mixed>> $definitions
     * @param array<string, mixed>                $result
     */
    private function decodeField(FigKiwiByteReader $reader, array $field, array $definitions, array &$result): void
    {
        $type = $this->schemaFields->fieldType($field);
        if ( $this->schemaFields->isArrayField($field) ) {
            if ( 'byte' === $type ) {
                $value = $reader->readByteArray();
            } else {
                $length = $reader->readVarUint();
                $value = array();
                for ( $i = 0; $i < $length; $i++ ) {
                    $value[] = $this->decodeValue($reader, $type, $definitions);
                }
            }
        } else {
            $value = $this->decodeValue($reader, $type, $definitions);
        }

        if ( ! $this->schemaFields->isDeprecatedField($field) && isset($field['name']) ) {
            $result[$this->schemaFields->fieldName($field)] = $value;
        }
    }

    /**
     * @param array<string, array<string, mixed>> $definitions
     */
    private function decodeValue(FigKiwiByteReader $reader, string $type, array $definitions): mixed
    {
        return match ( $type ) {
            'bool' => 0 !== $reader->readByte(),
            'byte' => $reader->readByte(),
            'int' => $reader->readVarInt(),
            'uint' => $reader->readVarUint(),
            'float' => $reader->readVarFloat(),
            'string' => $reader->readString(),
            'int64' => $reader->readVarInt64(),
            'uint64' => $reader->readVarUint64(),
            default => $this->decodeNamedValue($reader, $type, $definitions),
        };
    }

    /**
     * @param array<string, array<string, mixed>> $definitions
     */
    private function decodeNamedValue(FigKiwiByteReader $reader, string $type, array $definitions): mixed
    {
        $definition = $definitions[$type] ?? null;
        if ( ! is_array($definition) ) {
            throw new \RuntimeException('Invalid Kiwi type ' . $type . '.');
        }

        if ( 'ENUM' === ($definition['kind'] ?? null) ) {
            $value = $reader->readVarUint();
            foreach ( $definition['fields'] ?? array() as $field ) {
                if ( is_array($field) && (int) ($field['value'] ?? -1) === $value ) {
                    return (string) ($field['name'] ?? $value);
                }
            }
            return $value;
        }

        return $this->decodeDefinition($reader, $definition, $definitions);
    }

    /**
     * @return array<string, mixed>
     */
    private function diagnostic(string $code, string $message, string $error): array
    {
        return array('code' => $code, 'message' => $message, 'source' => 'FigKiwiDecoder', 'context' => array('error' => $error));
    }
}

final class FigKiwiByteReader
{
    private int $offset = 0;

    public function __construct(private readonly string $data)
    {
    }

    public function readByte(): int
    {
        if ( $this->offset >= strlen($this->data) ) {
            throw new \RuntimeException('Index out of bounds.');
        }

        return ord($this->data[$this->offset++]);
    }

    public function readByteArray(): string
    {
        $length = $this->readVarUint();
        if ( $this->offset + $length > strlen($this->data) ) {
            throw new \RuntimeException('Read array out of bounds.');
        }
        $value = substr($this->data, $this->offset, $length);
        $this->offset += $length;
        return $value;
    }

    public function skipByteArray(): void
    {
        $this->skipBytes($this->readVarUint());
    }

    public function readVarUint(): int
    {
        $value = 0;
        $shift = 0;
        do {
            $byte = $this->readByte();
            $value |= ($byte & 0x7f) << $shift;
            $shift += 7;
        } while ( ($byte & 0x80) && $shift < 35 );

        return $value;
    }

    public function readVarInt(): int
    {
        $value = $this->readVarUint();
        return ($value & 1) ? ~($value >> 1) : ($value >> 1);
    }

    public function readVarUint64(): int
    {
        $value = 0;
        $shift = 0;
        do {
            $byte = $this->readByte();
            $value |= ($byte & 0x7f) << $shift;
            $shift += 7;
        } while ( ($byte & 0x80) && $shift < 63 );

        return $value;
    }

    public function readVarInt64(): int
    {
        $value = $this->readVarUint64();
        return ($value & 1) ? ~($value >> 1) : ($value >> 1);
    }

    public function readVarFloat(): float
    {
        $first = $this->readByte();
        if ( 0 === $first ) {
            return 0.0;
        }

        $bits = unpack('V', chr($first) . chr($this->readByte()) . chr($this->readByte()) . chr($this->readByte()));
        $value = is_array($bits) ? (int) $bits[1] : 0;
        $rotated = (($value << 23) & 0xffffffff) | (($value >> 9) & 0x7fffff);
        $float = unpack('f', pack('V', $rotated));

        return is_array($float) ? (float) $float[1] : 0.0;
    }

    public function readString(): string
    {
        $bytes = '';
        while ( true ) {
            $byte = $this->readByte();
            if ( 0 === $byte ) {
                return $bytes;
            }
            $bytes .= chr($byte);
        }
    }

    public function skipString(): void
    {
        while ( 0 !== $this->readByte() ) {
            // Strings are null-terminated in the Kiwi schema chunk.
        }
    }

    public function skipBytes(int $length): void
    {
        if ( $length < 0 || $this->offset + $length > strlen($this->data) ) {
            throw new \RuntimeException('Skip out of bounds.');
        }

        $this->offset += $length;
    }
}
