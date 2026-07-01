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
            'TextData' => array('characters', 'layoutSize', 'characterStyleIDs', 'styleOverrideTable', 'layoutVersion', 'lines', 'minContentHeight', 'truncationStartIndex', 'truncatedHeight', 'logicalIndexToCharacterOffsetMap', 'derivedLines'),
            'DerivedTextData' => array('layoutSize', 'baselines', 'fontMetaData', 'truncationStartIndex', 'truncatedHeight', 'logicalIndexToCharacterOffsetMap', 'derivedLines'),
            'TextLineData' => array('lineType', 'styleId', 'indentationLevel', 'sourceDirectionality', 'directionality', 'directionalityIntent', 'downgradeStyleId', 'consistencyStyleId', 'listStartOffset', 'isFirstLineOfList'),
            'DerivedTextLineData' => array('directionality'),
            'Baseline' => array('position', 'width', 'lineY', 'lineHeight', 'lineAscent', 'firstCharacter', 'endCharacter'),
            'Glyph' => array('position', 'fontSize', 'firstCharacter', 'endCharacter', 'advance', 'rotation', 'styleID'),
            'FontMetaData' => array('key', 'fontLineHeight', 'fontStyle', 'fontWeight'),
            'FontVariation' => array('axisTag', 'axisName', 'value'),
            'Number' => array('value', 'units'),
            'Paint' => array('type', 'color', 'opacity', 'visible', 'blendMode', 'stops', 'transform', 'imageTransform', 'cropTransform', 'cropRect', 'image', 'imageThumbnail', 'imageScaleMode', 'originalImageWidth', 'originalImageHeight', 'scale', 'rotation', 'imageShouldColorManage', 'thumbHash', 'animationFrame', 'altText'),
            // Effect struct (#328). The Kiwi blur token is `FOREGROUND_BLUR`
            // (REST calls it `LAYER_BLUR`); the normalizer bridges both. `offset`
            // resolves to the whitelisted `Vector` struct and `color` to `Color`.
            'Effect' => array('type', 'color', 'offset', 'radius', 'spread', 'visible', 'blendMode', 'showShadowBehindNode'),
            'Image' => array('hash', 'name'),
            'Blob' => array('bytes'),
            'Path' => array('commandsBlob', 'windingRule', 'styleID'),
            'VectorPath' => array('commandsBlob', 'windingRule', 'styleID'),
            'VectorData' => array('vectorNetworkBlob', 'vectorNetwork', 'normalizedSize'),
            'ArcData' => array('startingAngle', 'endingAngle', 'innerRadius'),
            'Guide' => array('axis', 'offset', 'guid'),
            'LayoutGrid' => array('type', 'axis', 'visible', 'numSections', 'offset', 'sectionSize', 'gutterSize', 'color', 'pattern'),
            'SymbolData' => array('symbolID', 'symbolOverrides', 'uniformScaleFactor'),
            'DerivedSymbolData' => array('symbolID', 'symbolOverrides', 'uniformScaleFactor'),
            'GUIDPath' => array('guids'),
            'StyleId' => array('guid'),
            'StateGroupPropertyValueOrder' => array('property', 'values'),
            'VariantPropSpec' => array('propDefId', 'value'),
            'ComponentPropAssignment' => array('defID', 'value', 'varValue'),
            'ComponentPropValue' => array('boolValue', 'textValue', 'guidValue', 'floatValue', 'textDataValue', 'symbolIdValue'),
            'VariableDataMap' => array('entries'),
            'VariableDataMapEntry' => array('nodeField', 'variableData', 'variableField'),
            'VariableData' => array('value', 'dataType', 'resolvedDataType'),
            'VariableAnyValue' => array('boolValue', 'textValue', 'floatValue', 'alias', 'colorValue', 'symbolIdValue', 'textDataValue', 'vectorValue', 'linkValue', 'propRefValue'),
            'VariableID' => array('guid', 'assetRef'),
            'VariableOverrideId' => array('guid', 'assetRef'),
            'VariableDataValues' => array('entries'),
            'VariableDataValuesEntry' => array('modeID', 'variableData'),
            'VariableSetMode' => array('id', 'name', 'sortPosition', 'parentVariableSetId', 'parentModeId'),
            'VariableSetID' => array('guid', 'assetRef'),
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
            'masks_effects' => array_values(array_unique(array_merge($this->nodeEffectFields(), array('mask', 'isMask', 'maskType', 'isClip', 'frameMaskDisabled')))),
            'vectors' => array_values(array_unique(array_merge($this->nodeVectorAndImageFields(), array('Path', 'VectorPath', 'VectorData', 'commandsBlob', 'vectorNetworkBlob', 'vectorNetwork')))),
            'prototype_links' => $this->nodePrototypeLinkFields(),
            'variables_bindings' => array('variableConsumptionMap', 'parameterConsumptionMap', 'variableDataValues', 'variableResolvedType', 'variableSetID', 'variableScopes', 'variableSetModes', 'VariableDataMap', 'VariableDataValues', 'VariableResolvedDataType', 'VariableSetID', 'VariableScope', 'VariableSetMode'),
            'export_metadata' => array('exportSettings', 'ExportSettings'),
            'document_metadata' => array('phase', 'autoRename', 'editInfo', 'pluginData', 'version', 'userFacingVersion', 'isPublishable', 'locked', 'isSoftDeleted', 'annotations', 'annotationCategories', 'publishID', 'sourceLibraryKey', 'ancestorPathBeforeDeletion', 'internalOnly', 'isPageDivider', 'pluginRelaunchData', 'slideThemeMap', 'ackID', 'originFileKey', 'sessionID'),
        );
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function scenegraphFieldPolicyWithTextGlyphs(): array
    {
        $policy = $this->defaultScenegraphFieldPolicy();
        $policy['TextData'] = array_values(array_unique(array_merge($policy['TextData'] ?? array(), array('glyphs'))));
        $policy['DerivedTextData'] = array_values(array_unique(array_merge($policy['DerivedTextData'] ?? array(), array('glyphs'))));
        $policy['Glyph'] = array_values(array_unique(array_merge($policy['Glyph'] ?? array(), array('commandsBlob', 'emojiCodePoints', 'emojiImageSet'))));
        return $policy;
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

    public function classifySkippedFieldRole(string $fieldName, string $type, string $parentMessage = ''): string
    {
        $name = strtolower($fieldName . ' ' . $type . ' ' . $parentMessage);
        if ( str_contains($name, 'variable') || str_contains($name, 'varvalue') || str_contains($name, 'parameter') || str_contains($name, 'consumption') ) {
            return 'variables_bindings';
        }
        if ( str_contains($name, 'stategroup') ) {
            return 'component_overrides';
        }
        if ( str_contains($name, 'arcdata') || str_contains($name, 'guide') || str_contains($name, 'bound') || str_contains($name, 'layout') || str_contains($name, 'constraint') || str_contains($name, 'padding') || str_contains($name, 'size') || str_contains($name, 'transform') || str_contains($name, 'corner') || str_contains($name, 'stack') ) {
            return 'geometry_layout';
        }
        if ( str_contains($name, 'paint') || str_contains($name, 'fill') || str_contains($name, 'stroke') || str_contains($name, 'image') || str_contains($name, 'blob') || str_contains($name, 'blendmode') || str_contains($name, 'background') ) {
            return 'fills_images';
        }
        if ( str_contains($name, 'mask') || str_contains($name, 'effect') || str_contains($name, 'shadow') || str_contains($name, 'blur') ) {
            return 'masks_effects';
        }
        if ( str_contains($name, 'text') || str_contains($name, 'font') || str_contains($name, 'style') || str_contains($name, 'letter') || str_contains($name, 'paragraph') || str_contains($name, 'glyph') || str_contains($name, 'character') || str_contains($name, 'truncat') || str_contains($name, 'bidi') || str_contains($name, 'ligature') || str_contains($name, 'leadingtrim') || str_contains($name, 'opentype') || str_contains($name, 'decoration') || str_contains($name, 'hangingpunctuation') ) {
            return 'text_style';
        }
        if ( str_contains($name, 'export') ) {
            return 'export_metadata';
        }
        if ( str_contains($name, 'phase') || str_contains($name, 'autorename') || str_contains($name, 'editinfo') || str_contains($name, 'plugindata') || str_contains($name, 'version') || str_contains($name, 'publish') || str_contains($name, 'locked') || str_contains($name, 'softdeleted') || str_contains($name, 'annotation') || str_contains($name, 'librarykey') || str_contains($name, 'internalonly') || str_contains($name, 'pagedivider') || str_contains($name, 'relaunch') || str_contains($name, 'slidetheme') || str_contains($name, 'ackid') || str_contains($name, 'originfilekey') || str_contains($name, 'sessionid') ) {
            return 'document_metadata';
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
            $this->nodeVariableBindingFields(),
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
            'size', 'transform', 'useAbsoluteBounds', 'cornerRadius', 'arcData', 'guides', 'layoutGrids',
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
            'isStateGroup', 'stateGroupPropertyValueOrders', 'variantPropSpecs',
            'styleIdForEffect',
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
            'textAlignVertical', 'textCase', 'textDecoration', 'textAutoResize', 'listSpacing',
            'derivedTextData', 'styleIdForText', 'fontVariantCommonLigatures', 'fontVariantContextualLigatures',
            'fontVariantDiscretionaryLigatures', 'fontVariantHistoricalLigatures',
            'fontVariantOrdinal', 'fontVariantSlashedZero', 'fontVariantNumericFigure',
            'fontVariantNumericSpacing', 'fontVariantNumericFraction', 'fontVariantCaps',
            'fontVariantPosition', 'fontVersion', 'leadingTrim', 'hangingPunctuation',
            'hangingList', 'fallbackGlyphs', 'maxLines', 'textUserLayoutVersion',
            'textExplicitLayoutVersion', 'toggledOnOTFeatures', 'toggledOffOTFeatures',
            'fontVariations', 'textBidiVersion', 'textTruncation', 'textWrapStyle',
            'hasHadRTLText', 'textTracking',
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
        // Visual effects and mask-source metadata. `mask` is the Kiwi field name;
        // `isMask` is the REST spelling seen in exported scenegraphs.
        return array('effects', 'mask', 'isMask', 'maskType');
    }

    /**
     * @return array<int, string>
     */
    private function nodePrototypeLinkFields(): array
    {
        // Link extraction only; richer animation/overlay/swap action data stays undecoded.
        return array('hyperlink', 'prototypeInteractions', 'reactions', 'transitionNodeID');
    }

    /**
     * @return array<int, string>
     */
    private function nodeVariableBindingFields(): array
    {
        return array('variableConsumptionMap', 'parameterConsumptionMap', 'variableDataValues', 'variableResolvedType', 'variableSetID', 'variableScopes', 'variableSetModes');
    }
}
