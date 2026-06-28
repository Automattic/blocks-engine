<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\FigFile;

/**
 * Decodes the Kiwi schema/message pair embedded in modern canvas.fig files.
 */
final class FigKiwiDecoder
{
    private const TYPES = array('bool', 'byte', 'int', 'uint', 'float', 'string', 'int64', 'uint64');
    private const KINDS = array('ENUM', 'STRUCT', 'MESSAGE');

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
                        $definitions[$definitionIndex]['fields'][$fieldIndex]['type'] = self::TYPES[~$type] ?? null;
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
            $definitions = $this->definitionsByName($schema);
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
            $definitions = $this->definitionsByName($schema);
            if ( ! isset($definitions[$rootType]) ) {
                return array('message' => null, 'diagnostics' => array($this->diagnostic('figma_transformer_kiwi_message_schema_missing', 'Kiwi schema does not define the expected root message.', $rootType)));
            }

            $policy = empty($fieldPolicy) ? $this->defaultScenegraphFieldPolicy() : $fieldPolicy;
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
     * @param array<string, mixed> $schema
     * @return array<string, array<string, mixed>>
     */
    private function definitionsByName(array $schema): array
    {
        $definitions = array();
        foreach ( $schema['definitions'] ?? array() as $definition ) {
            if ( is_array($definition) && isset($definition['name']) ) {
                $definitions[(string) $definition['name']] = $definition;
            }
        }

        return $definitions;
    }

    /**
     * @param array<string, mixed>                $definition
     * @param array<string, array<string, mixed>> $definitions
     */
    private function decodeDefinition(FigKiwiByteReader $reader, array $definition, array $definitions): array
    {
        $result = array();
        if ( 'MESSAGE' === ($definition['kind'] ?? null) ) {
            $fieldsByValue = array();
            foreach ( $definition['fields'] ?? array() as $field ) {
                if ( is_array($field) ) {
                    $fieldsByValue[(int) ($field['value'] ?? 0)] = $field;
                }
            }

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

        foreach ( $definition['fields'] ?? array() as $field ) {
            if ( is_array($field) ) {
                $this->decodeField($reader, $field, $definitions, $result);
            }
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
            $fieldsByValue = array();
            foreach ( $definition['fields'] ?? array() as $field ) {
                if ( is_array($field) ) {
                    $fieldsByValue[(int) ($field['value'] ?? 0)] = $field;
                }
            }

            while ( true ) {
                $fieldValue = $reader->readVarUint();
                if ( 0 === $fieldValue ) {
                    return $result;
                }
                if ( ! isset($fieldsByValue[$fieldValue]) ) {
                    throw new \RuntimeException('Attempted to parse invalid message field ' . $fieldValue . '.');
                }

                $field = $fieldsByValue[$fieldValue];
                $fieldName = (string) ($field['name'] ?? '');
                if ( isset($allowed[$fieldName]) ) {
                    $this->decodeFieldSelective($reader, $field, $definitions, $fieldPolicy, $result);
                } else {
                    $this->skipField($reader, $field, $definitions);
                }
            }
        }

        foreach ( $definition['fields'] ?? array() as $field ) {
            if ( ! is_array($field) ) {
                continue;
            }

            $fieldName = (string) ($field['name'] ?? '');
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
        $type = (string) ($field['type'] ?? '');
        if ( true === ($field['is_array'] ?? false) ) {
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

        if ( true !== ($field['is_deprecated'] ?? false) && isset($field['name']) ) {
            $result[(string) $field['name']] = $value;
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
        $type = (string) ($field['type'] ?? '');
        if ( true === ($field['is_array'] ?? false) ) {
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
            $fieldsByValue = array();
            foreach ( $definition['fields'] ?? array() as $field ) {
                if ( is_array($field) ) {
                    $fieldsByValue[(int) ($field['value'] ?? 0)] = $field;
                }
            }

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

        foreach ( $definition['fields'] ?? array() as $field ) {
            if ( is_array($field) ) {
                $this->skipField($reader, $field, $definitions);
            }
        }
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function defaultScenegraphFieldPolicy(): array
    {
        return array(
            // `handoffStatus`/`sectionStatus` may also surface at the file root
            // as a handoff map, so they are whitelisted on the root Message too.
            'Message' => array('type', 'nodeChanges', 'blobs', 'blobBaseIndex', 'fileVersion', 'sectionStatus', 'handoffStatus'),
            'NodeChange' => array(
                // Figma Dev Mode status (#280): Ready-for-dev / Completed signal.
                // `sectionStatus`/`sectionStatusInfo` ride on SECTION nodes,
                // `handoffStatus` carries the dev-handoff map, and
                // `currentStatus`/`statusInfo` mirror NodeStatusChange fields.
                'sectionStatus', 'sectionStatusInfo', 'handoffStatus', 'currentStatus', 'statusInfo', 'devStatus',
                'guid', 'parentIndex', 'type', 'name', 'visible', 'opacity', 'size', 'transform',
                'useAbsoluteBounds', 'cornerRadius', 'rectangleTopLeftCornerRadius',
                'rectangleTopRightCornerRadius', 'rectangleBottomLeftCornerRadius',
                'rectangleBottomRightCornerRadius', 'fillPaints', 'strokePaints', 'backgroundPaints',
                'fillGeometry', 'strokeGeometry', 'vectorData', 'booleanOperation', 'key', 'componentKey',
                'componentOrStateGroupKey', 'originComponentKey', 'componentId', 'mainComponentId',
                'mainComponent', 'component', 'symbolData', 'derivedSymbolData', 'guidPath',
                'fontSize', 'fontName', 'textData', 'lineHeight', 'letterSpacing',
                'paragraphIndent', 'paragraphSpacing', 'styleID',
                'textAlignHorizontal', 'textAlignVertical', 'textCase', 'textDecoration', 'textAutoResize', 'horizontalConstraint',
                'verticalConstraint', 'stackWidth', 'stackHeight', 'stackPrimarySizing',
                'stackMode', 'stackSpacing', 'stackHorizontalPadding', 'stackVerticalPadding',
                'stackPadding', 'stackPaddingLeft', 'stackPaddingRight', 'stackPaddingTop', 'stackPaddingBottom',
                'stackPrimaryAlignItems', 'stackCounterAlignItems', 'stackCounterSizing',
                'stackWrap', 'stackCounterSpacing', 'stackReverseZIndex',
                'stackChildPrimaryGrow', 'stackChildAlignSelf', 'stackPositioning', 'resizeToFit', 'isClip', 'minSize', 'maxSize',
            ),
            'GUID' => array('sessionID', 'localID'),
            'ParentIndex' => array('guid', 'position'),
            'Vector' => array('x', 'y'),
            'Matrix' => array('m00', 'm01', 'm02', 'm10', 'm11', 'm12'),
            'OptionalVector' => array('x', 'y'),
            'Color' => array('r', 'g', 'b', 'a'),
            'ColorStop' => array('position', 'color'),
            'FontName' => array('family', 'style', 'postscript'),
            'TextData' => array('characters', 'layoutSize'),
            'Number' => array('value', 'units'),
            'Paint' => array('type', 'color', 'opacity', 'visible', 'stops', 'transform', 'image', 'imageScaleMode', 'originalImageWidth', 'originalImageHeight', 'altText'),
            'Image' => array('hash', 'name'),
            'Blob' => array('bytes'),
            'Path' => array('commandsBlob', 'windingRule', 'styleID'),
            'VectorPath' => array('commandsBlob', 'windingRule', 'styleID'),
            'VectorData' => array('vectorNetworkBlob', 'vectorNetwork'),
            // Vector geometry (#247). The vectorNetwork structure is carried
            // through the REAL Kiwi decoder — either inline on VectorData or via a
            // second decode pass over the vectorNetworkBlob bytes — and converted
            // to an SVG path. `vertices`/`segments`/`regions` describe the network;
            // segment tangents reuse the whitelisted `Vector` {x,y} type. Over-
            // listing plausible inner field names is safe: unknown fields are
            // skipped, not mis-read.
            'VectorNetwork' => array('vertices', 'segments', 'regions', 'vertexCount', 'segmentCount', 'regionCount'),
            'VectorVertex' => array('x', 'y', 'styleID'),
            'VectorSegment' => array('start', 'end', 'tangentStart', 'tangentEnd', 'startPoint', 'endPoint'),
            'VectorRegion' => array('windingRule', 'loops', 'fillStyleID'),
            'VectorRegionLoop' => array('segments', 'indices', 'segmentIndices'),
            'SymbolData' => array('symbolID', 'symbolOverrides', 'uniformScaleFactor'),
            'DerivedSymbolData' => array('symbolID', 'symbolOverrides', 'uniformScaleFactor'),
            'GUIDPath' => array('guids'),
            'StyleId' => array('guid'),
            // Dev-status structs (#280). The status enum itself decodes to its
            // token string automatically, so only the struct/entry field names
            // that reach it need whitelisting. Over-listing plausible inner
            // field names is safe: unknown fields are skipped, not mis-read.
            'SectionStatusInfo' => array('status', 'currentStatus', 'statusInfo', 'type', 'name', 'description'),
            'HandoffStatusMap' => array('entries', 'values', 'handoffStatuses'),
            'HandoffStatusMapEntry' => array('key', 'guid', 'nodeId', 'value', 'status', 'statusInfo', 'currentStatus'),
            'NodeStatusChange' => array('guid', 'nodeId', 'currentStatus', 'statusInfo', 'status'),
        );
    }

    /**
     * @param array<string, mixed>                $field
     * @param array<string, array<string, mixed>> $definitions
     * @param array<string, mixed>                $result
     */
    private function decodeField(FigKiwiByteReader $reader, array $field, array $definitions, array &$result): void
    {
        $type = (string) ($field['type'] ?? '');
        if ( true === ($field['is_array'] ?? false) ) {
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

        if ( true !== ($field['is_deprecated'] ?? false) && isset($field['name']) ) {
            $result[(string) $field['name']] = $value;
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
