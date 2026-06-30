<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\FigmaTransformer\FigFile;

/**
 * Selective Kiwi decode policy and inventory classification helpers.
 */
final class FigKiwiDecodePolicy
{
    /**
     * @return array<string, array<int, string>>
     */
    public function defaultScenegraphFieldPolicy(): array
    {
        return array(
            'Message' => $this->scenegraphRootFields(),
            'NodeChange' => $this->nodeChangeScenegraphFields(),
            'GUID' => array('sessionID', 'localID'),
            'ParentIndex' => array('guid', 'position'),
            'Vector' => array('x', 'y'),
            'Matrix' => array('m00', 'm01', 'm02', 'm10', 'm11', 'm12'),
            'OptionalVector' => array('x', 'y'),
            'Color' => array('r', 'g', 'b', 'a'),
            'ColorStop' => array('position', 'color'),
            'FontName' => array('family', 'style', 'postscript'),
            // Inline styled-text spans (#328, feeding the normalizer path added in
            // #305/#299). In the Kiwi encoding the per-character style-run IDs ride
            // on `characterStyleIDs` (the REST API calls the same data
            // `characterStyleOverrides`), and `styleOverrideTable` is a `NodeChange[]`
            // of partial style overrides each carrying a `styleID` plus the overriding
            // properties (`fontName`, `fontSize`, `fillPaints`, ...). The override
            // entries decode against the existing `NodeChange` policy below, which
            // already whitelists `styleID`/`fontName`/`fontSize`/`fillPaints`/etc.
            // Without these two names the per-character override data is dropped by
            // `skipField()` and every .fig text node emits flat, single-style text.
            'TextData' => array('characters', 'layoutSize', 'characterStyleIDs', 'styleOverrideTable'),
            'DerivedTextData' => array('layoutSize', 'baselines', 'glyphs', 'fontMetaData'),
            'Baseline' => array('position', 'width', 'lineY', 'lineHeight', 'lineAscent', 'firstCharacter', 'endCharacter'),
            'Glyph' => array('commandsBlob', 'position', 'fontSize', 'firstCharacter', 'endCharacter', 'advance', 'rotation', 'styleID'),
            'FontMetaData' => array('key', 'fontLineHeight', 'fontWeight'),
            'Number' => array('value', 'units'),
            'Paint' => array('type', 'color', 'opacity', 'visible', 'blendMode', 'stops', 'transform', 'image', 'imageThumbnail', 'imageScaleMode', 'originalImageWidth', 'originalImageHeight', 'altText'),
            // Effect struct (#328). The Kiwi blur token is `FOREGROUND_BLUR`
            // (REST calls it `LAYER_BLUR`); the normalizer bridges both. `offset`
            // resolves to the whitelisted `Vector` struct and `color` to `Color`.
            'Effect' => array('type', 'color', 'offset', 'radius', 'spread', 'visible', 'blendMode'),
            'Image' => array('hash', 'name'),
            'Blob' => array('bytes'),
            'Path' => array('commandsBlob', 'windingRule', 'styleID'),
            'VectorPath' => array('commandsBlob', 'windingRule', 'styleID'),
            'VectorData' => array('vectorNetworkBlob', 'vectorNetwork'),
            'SymbolData' => array('symbolID', 'symbolOverrides', 'uniformScaleFactor'),
            'DerivedSymbolData' => array('symbolID', 'symbolOverrides', 'uniformScaleFactor'),
            'GUIDPath' => array('guids'),
            'StyleId' => array('guid'),
            'ComponentPropAssignment' => array('defID', 'value', 'varValue'),
            'ComponentPropValue' => array('boolValue', 'textValue', 'guidValue', 'textDataValue', 'symbolIdValue'),
            'VariableData' => array('value', 'dataType', 'resolvedDataType'),
            'VariableAnyValue' => array('boolValue', 'textValue', 'floatValue', 'colorValue', 'symbolIdValue', 'textDataValue'),
            'SymbolId' => array('guid'),
            'ComponentPropDef' => array('id', 'parentPropDefId', 'name', 'initialValue', 'sortPosition', 'type', 'preferredValues', 'varValue'),
            'ComponentPropRef' => array('defID', 'componentPropNodeField'),
            // Dev-status structs (#280). The status enum itself decodes to its
            // token string automatically, so only the struct/entry field names
            // that reach it need whitelisting. Over-listing plausible inner
            // field names is safe: unknown fields are skipped, not mis-read.
            'SectionStatusInfo' => array('status', 'currentStatus', 'statusInfo', 'type', 'name', 'description'),
            'HandoffStatusMap' => array('entries', 'values', 'handoffStatuses'),
            'HandoffStatusMapEntry' => array('key', 'guid', 'nodeId', 'value', 'status', 'statusInfo', 'currentStatus'),
            'NodeStatusChange' => array('guid', 'nodeId', 'currentStatus', 'statusInfo', 'status'),
            // Links + prototype navigation (#328). The Kiwi schema models a
            // text/node hyperlink as `Hyperlink { url, guid }` and prototype
            // interactions as `PrototypeInteraction { event, actions }` whose
            // `PrototypeAction` carries `connectionType` (URL/INTERNAL_NODE),
            // `connectionURL`, `navigationType`, and the `transitionNodeID`
            // GUID destination. Only the fields the normalizer needs to build a
            // URL or node-navigation `figma_link` are whitelisted; animation,
            // overlay, swap, and variable-mutation action data is left undecoded.
            'Hyperlink' => array('url', 'guid'),
            'PrototypeInteraction' => array('event', 'actions', 'id'),
            'PrototypeEvent' => array('interactionType'),
            'PrototypeAction' => array('connectionType', 'connectionURL', 'transitionNodeID', 'navigationType'),
        );
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function scenegraphFieldPolicyGroups(): array
    {
        return array(
            'identity' => $this->nodeIdentityFields(),
            'dev_status' => $this->nodeDevStatusFields(),
            'geometry_layout' => array_values(array_unique(array_merge($this->nodeGeometryFields(), $this->nodeLayoutFields()))),
            'fills_images' => array_values(array_unique(array_merge($this->nodePaintAndStrokeFields(), $this->nodeVectorAndImageFields(), array('Paint', 'Image', 'Blob', 'image', 'hash', 'bytes')))),
            'component_overrides' => $this->nodeComponentFields(),
            'text_style' => $this->nodeTextFields(),
            'masks_effects' => array_values(array_unique(array_merge($this->nodeEffectFields(), array('isClip', 'frameMaskDisabled')))),
            'vectors' => array_values(array_unique(array_merge($this->nodeVectorAndImageFields(), array('Path', 'VectorPath', 'VectorData', 'commandsBlob', 'vectorNetworkBlob', 'vectorNetwork')))),
            'prototype_links' => $this->nodePrototypeLinkFields(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function initialInventoryContext(string $rootType): array
    {
        return array(
            'path'        => $rootType,
            'parent_type' => $rootType,
            'node_type'   => null,
            'node_id'     => null,
        );
    }

    public function classifySkippedFieldRole(string $fieldName, string $type): string
    {
        $name = strtolower($fieldName . ' ' . $type);
        if ( str_contains($name, 'bound') || str_contains($name, 'layout') || str_contains($name, 'constraint') || str_contains($name, 'padding') || str_contains($name, 'size') || str_contains($name, 'transform') || str_contains($name, 'corner') || str_contains($name, 'stack') ) {
            return 'geometry_layout';
        }
        if ( str_contains($name, 'paint') || str_contains($name, 'fill') || str_contains($name, 'stroke') || str_contains($name, 'image') || str_contains($name, 'blob') || str_contains($name, 'blendmode') ) {
            return 'fills_images';
        }
        if ( str_contains($name, 'mask') || str_contains($name, 'effect') || str_contains($name, 'shadow') || str_contains($name, 'blur') ) {
            return 'masks_effects';
        }
        if ( str_contains($name, 'text') || str_contains($name, 'font') || str_contains($name, 'style') || str_contains($name, 'letter') || str_contains($name, 'paragraph') ) {
            return 'text_style';
        }
        if ( str_contains($name, 'component') || str_contains($name, 'symbol') || str_contains($name, 'override') || str_contains($name, 'prop') || str_contains($name, 'variant') ) {
            return 'component_overrides';
        }
        if ( str_contains($name, 'vector') || str_contains($name, 'commandsblob') || 'path' === strtolower($type) || 'vectorpath' === strtolower($type) ) {
            return 'vectors';
        }

        foreach ( $this->scenegraphFieldPolicyGroups() as $role => $fields ) {
            if ( in_array($fieldName, $fields, true) || in_array($type, $fields, true) ) {
                return $role;
            }
        }

        return 'unknown';
    }

    public function formatInventoryNodeId(mixed $value): ?string
    {
        if ( is_array($value) ) {
            $session = $value['sessionID'] ?? null;
            $local = $value['localID'] ?? null;
            if ( is_scalar($session) && is_scalar($local) ) {
                return (string) $session . ':' . (string) $local;
            }
            if ( is_scalar($local) ) {
                return (string) $local;
            }
        }

        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * @param array<string, array<string, mixed>> $fields
     * @return array<string, mixed>
     */
    public function summarizeSkippedFieldInventory(array &$fields): array
    {
        $byRole = array();
        $total = 0;
        foreach ( $fields as &$field ) {
            arsort($field['node_types']);
            $role = (string) ($field['field_role'] ?? 'unknown');
            $count = (int) ($field['occurrences'] ?? 0);
            $byRole[$role] = ($byRole[$role] ?? 0) + $count;
            $total += $count;
        }
        unset($field);

        uasort($fields, static fn (array $left, array $right): int => ((int) ($right['occurrences'] ?? 0) <=> (int) ($left['occurrences'] ?? 0)) ?: strcmp((string) ($left['path'] ?? ''), (string) ($right['path'] ?? '')));
        arsort($byRole);

        return array(
            'field_count' => count($fields),
            'occurrences' => $total,
            'by_role'     => $byRole,
        );
    }

    /**
     * @return array<int, string>
     */
    private function scenegraphRootFields(): array
    {
        // `handoffStatus`/`sectionStatus` may also surface at the file root as a handoff map.
        return array('type', 'nodeChanges', 'blobs', 'blobBaseIndex', 'fileVersion', 'sectionStatus', 'handoffStatus');
    }

    /**
     * @return array<int, string>
     */
    private function nodeChangeScenegraphFields(): array
    {
        return array_merge(
            $this->nodeIdentityFields(),
            $this->nodeDevStatusFields(),
            $this->nodeGeometryFields(),
            $this->nodePaintAndStrokeFields(),
            $this->nodeVectorAndImageFields(),
            $this->nodeComponentFields(),
            $this->nodeTextFields(),
            $this->nodeLayoutFields(),
            $this->nodeEffectFields(),
            $this->nodePrototypeLinkFields()
        );
    }

    /**
     * @return array<int, string>
     */
    private function nodeIdentityFields(): array
    {
        return array('guid', 'parentIndex', 'sortPosition', 'type', 'name', 'visible', 'opacity', 'blendMode');
    }

    /**
     * @return array<int, string>
     */
    private function nodeDevStatusFields(): array
    {
        // Figma Dev Mode status (#280): Ready-for-dev / Completed signal.
        return array('sectionStatus', 'sectionStatusInfo', 'handoffStatus', 'currentStatus', 'statusInfo', 'devStatus');
    }

    /**
     * @return array<int, string>
     */
    private function nodeGeometryFields(): array
    {
        return array(
            'size', 'transform', 'useAbsoluteBounds', 'cornerRadius',
            'rectangleTopLeftCornerRadius', 'rectangleTopRightCornerRadius',
            'rectangleBottomLeftCornerRadius', 'rectangleBottomRightCornerRadius',
            'horizontalConstraint', 'verticalConstraint', 'resizeToFit', 'isClip', 'frameMaskDisabled', 'minSize', 'maxSize',
        );
    }

    /**
     * @return array<int, string>
     */
    private function nodePaintAndStrokeFields(): array
    {
        // Stroke geometry (#328): weight/align/dash fields feed border emission.
        return array(
            'fillPaints', 'strokePaints', 'backgroundPaints',
            'strokeWeight', 'strokeAlign', 'strokeCap', 'strokeJoin', 'dashPattern',
            'borderStrokeWeightsIndependent', 'borderTopWeight', 'borderBottomWeight',
            'borderLeftWeight', 'borderRightWeight',
            'strokeTopWeight', 'strokeBottomWeight', 'strokeLeftWeight', 'strokeRightWeight',
        );
    }

    /**
     * @return array<int, string>
     */
    private function nodeVectorAndImageFields(): array
    {
        return array('fillGeometry', 'strokeGeometry', 'vectorData', 'booleanOperation');
    }

    /**
     * @return array<int, string>
     */
    private function nodeComponentFields(): array
    {
        return array(
            'key', 'componentKey', 'componentOrStateGroupKey', 'originComponentKey',
            'componentId', 'mainComponentId', 'componentPropAssignments', 'componentPropDefs', 'componentPropRefs',
            'mainComponent', 'component', 'symbolData', 'derivedSymbolData', 'guidPath',
        );
    }

    /**
     * @return array<int, string>
     */
    private function nodeTextFields(): array
    {
        return array(
            'fontSize', 'fontName', 'textData', 'lineHeight', 'letterSpacing',
            'paragraphIndent', 'paragraphSpacing', 'styleID', 'textAlignHorizontal',
            'textAlignVertical', 'textCase', 'textDecoration', 'textAutoResize',
            'derivedTextData',
        );
    }

    /**
     * @return array<int, string>
     */
    private function nodeLayoutFields(): array
    {
        return array(
            'stackWidth', 'stackHeight', 'stackPrimarySizing', 'stackMode', 'stackSpacing',
            'stackHorizontalPadding', 'stackVerticalPadding', 'stackPadding', 'stackPaddingLeft',
            'stackPaddingRight', 'stackPaddingTop', 'stackPaddingBottom', 'stackPrimaryAlignItems',
            'stackCounterAlignItems', 'stackCounterSizing', 'stackWrap', 'stackCounterSpacing',
            'stackReverseZIndex', 'stackChildPrimaryGrow', 'stackChildAlignSelf', 'stackPositioning',
        );
    }

    /**
     * @return array<int, string>
     */
    private function nodeEffectFields(): array
    {
        // Visual effects (#328): shadows + blur.
        return array('effects');
    }

    /**
     * @return array<int, string>
     */
    private function nodePrototypeLinkFields(): array
    {
        // Link extraction only; richer animation/overlay/swap action data stays undecoded.
        return array('hyperlink', 'prototypeInteractions', 'reactions', 'transitionNodeID');
    }
}
