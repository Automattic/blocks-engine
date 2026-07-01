<?php

declare(strict_types=1);

use Automattic\BlocksEngine\FigmaTransformer\FigFile\FigKiwiDecoder;

/**
 * @param callable(bool, string): void $assert
 */
function blocks_engine_figma_transformer_run_kiwi_skipped_field_inventory_contract(callable $assert): void
{
    $decoder = new FigKiwiDecoder();
    $schema = $decoder->decodeSchema(blocks_engine_figma_transformer_kiwi_inventory_schema_fixture())['schema'] ?? array();
    $inventoryResult = $decoder->inventorySkippedFieldsSelective(blocks_engine_figma_transformer_kiwi_inventory_message_fixture(), $schema);
    $inventory = $inventoryResult['inventory'] ?? array();
    $fields = is_array($inventory['fields'] ?? null) ? $inventory['fields'] : array();

    $assert('blocks-engine/figma-transformer/kiwi-skipped-field-inventory/v1' === ($inventory['schema'] ?? null), 'kiwi-skipped-inventory-schema');
    $assert(10 === ($inventory['summary']['field_count'] ?? null), 'kiwi-skipped-inventory-field-count');
    $assert(10 === ($inventory['summary']['occurrences'] ?? null), 'kiwi-skipped-inventory-occurrences');
    $assert(1 === ($inventory['summary']['by_role']['geometry_layout'] ?? null), 'kiwi-skipped-inventory-geometry-role');
    $assert(1 === ($inventory['summary']['by_role']['component_overrides'] ?? null), 'kiwi-skipped-inventory-component-role');
    $assert(1 === ($inventory['summary']['by_role']['fills_images'] ?? null), 'kiwi-skipped-inventory-image-role');
    $assert(! isset($inventory['summary']['by_role']['masks_effects']), 'kiwi-skipped-inventory-mask-role');
    $assert(2 === ($inventory['summary']['by_role']['text_style'] ?? null), 'kiwi-skipped-inventory-text-role');
    $assert(1 === ($inventory['summary']['by_role']['vectors'] ?? null), 'kiwi-skipped-inventory-vector-role');
    $assert(! isset($inventory['summary']['by_role']['variables_bindings']), 'kiwi-skipped-inventory-variables-role-decoded');
    $assert(1 === ($inventory['summary']['by_role']['export_metadata'] ?? null), 'kiwi-skipped-inventory-export-role');
    $assert(1 === ($inventory['summary']['by_role']['document_metadata'] ?? null), 'kiwi-skipped-inventory-document-role');

    $layoutEntry = blocks_engine_figma_transformer_kiwi_inventory_find_field($fields, 'layoutBoundsDebug');
    $assert('NodeChange' === ($layoutEntry['parent_message'] ?? null), 'kiwi-skipped-inventory-parent-message');
    $assert('FRAME' === array_key_first(is_array($layoutEntry['node_types'] ?? null) ? $layoutEntry['node_types'] : array()), 'kiwi-skipped-inventory-node-type');
    $assert(array('7:42') === ($layoutEntry['sample_node_ids'] ?? null), 'kiwi-skipped-inventory-node-id-sample');
    $assert(array() === blocks_engine_figma_transformer_kiwi_inventory_find_field($fields, 'maskType'), 'kiwi-skipped-inventory-mask-type-decoded');
    $assert(array() === blocks_engine_figma_transformer_kiwi_inventory_find_field($fields, 'arcData'), 'kiwi-skipped-inventory-arc-data-decoded');
    $assert(array() === blocks_engine_figma_transformer_kiwi_inventory_find_field($fields, 'guides'), 'kiwi-skipped-inventory-guides-decoded');
    $assert(array() === blocks_engine_figma_transformer_kiwi_inventory_find_field($fields, 'listSpacing'), 'kiwi-skipped-inventory-list-spacing-decoded');
    $phaseEntry = blocks_engine_figma_transformer_kiwi_inventory_find_field($fields, 'phase');
    $assert('document_metadata' === ($phaseEntry['field_role'] ?? null), 'kiwi-skipped-inventory-phase-document-role');
    $glyphEntry = blocks_engine_figma_transformer_kiwi_inventory_find_field($fields, 'glyphs');
    $assert('text_style' === ($glyphEntry['field_role'] ?? null), 'kiwi-skipped-inventory-glyph-text-role');
    $assert('null_terminated_string' === ($glyphEntry['wire_type'] ?? null), 'kiwi-skipped-inventory-string-wire-type');
    $assert('glyphs' === ($glyphEntry['sample_raw_values'][0]['value'] ?? null), 'kiwi-skipped-inventory-string-sample-value');
    $statusEntry = blocks_engine_figma_transformer_kiwi_inventory_find_field($fields, 'inventoryStatus');
    $assert('ENUM' === ($statusEntry['type_kind'] ?? null), 'kiwi-skipped-inventory-enum-kind');
    $assert('varint_enum' === ($statusEntry['wire_type'] ?? null), 'kiwi-skipped-inventory-enum-wire-type');
    $assert('READY' === ($statusEntry['sample_raw_values'][0]['value'] ?? null), 'kiwi-skipped-inventory-enum-sample-value');
    $assert('READY' === ($statusEntry['type_definition']['fields'][1]['name'] ?? null), 'kiwi-skipped-inventory-enum-definition');
    $guidEntry = blocks_engine_figma_transformer_kiwi_inventory_find_field($fields, 'extraGuid');
    $assert('STRUCT' === ($guidEntry['type_kind'] ?? null), 'kiwi-skipped-inventory-struct-kind');
    $assert('kiwi_struct' === ($guidEntry['wire_type'] ?? null), 'kiwi-skipped-inventory-struct-wire-type');
    $assert(99 === ($guidEntry['sample_raw_values'][0]['items']['localID']['value'] ?? null), 'kiwi-skipped-inventory-struct-sample-value');
    $assert('sessionID' === ($inventory['schema_definitions']['GUID']['fields'][0]['name'] ?? null), 'kiwi-skipped-inventory-schema-definition-inventory');

    $message = $decoder->decodeMessageSelective(blocks_engine_figma_transformer_kiwi_inventory_message_fixture(), $schema)['message'] ?? array();
    $node = is_array($message['nodeChanges'][0] ?? null) ? $message['nodeChanges'][0] : array();
    $assert(array('startingAngle' => 0.0, 'endingAngle' => 1.5, 'innerRadius' => 0.25) === ($node['arcData'] ?? null), 'kiwi-selective-arc-data-shape');
    $assert('HORIZONTAL' === ($node['guides'][0]['axis'] ?? null), 'kiwi-selective-guide-axis');
    $assert(12.5 === ($node['guides'][0]['offset'] ?? null), 'kiwi-selective-guide-offset');
    $assert('7:99' === blocks_engine_figma_transformer_kiwi_inventory_format_guid($node['guides'][0]['guid'] ?? null), 'kiwi-selective-guide-guid');
    $assert(6.0 === ($node['listSpacing'] ?? null), 'kiwi-selective-list-spacing');

    $message = $decoder->decodeMessageSelective(blocks_engine_figma_transformer_kiwi_inventory_message_fixture(), $schema)['message'] ?? array();
    $assert('variables' === ($message['nodeChanges'][0]['variableConsumptionMap'] ?? null), 'kiwi-selective-decodes-variable-consumption-map');

    $fixture = SyntheticFigKiwiFixtureBuilder::figArchive(
        SyntheticFigKiwiFixtureBuilder::canvas(array(
            SyntheticFigKiwiFixtureBuilder::zlibChunk(blocks_engine_figma_transformer_kiwi_inventory_schema_fixture()),
            SyntheticFigKiwiFixtureBuilder::zlibChunk(blocks_engine_figma_transformer_kiwi_inventory_message_fixture()),
        ))
    );
    $command = escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg(__DIR__ . '/../../scripts/figma-kiwi-skipped-field-inventory.php')
        . ' ' . escapeshellarg($fixture);
    $output = shell_exec($command);
    @unlink($fixture);
    $cli = is_string($output) ? json_decode($output, true) : null;

    $assert(is_array($cli), 'kiwi-skipped-inventory-cli-json');
    $assert('blocks-engine/figma-transformer/kiwi-skipped-field-inventory-file/v1' === ($cli['schema'] ?? null), 'kiwi-skipped-inventory-cli-schema');
    $assert(10 === ($cli['summary']['field_count'] ?? null), 'kiwi-skipped-inventory-cli-field-count');

    $fixture = SyntheticFigKiwiFixtureBuilder::figArchive(
        SyntheticFigKiwiFixtureBuilder::canvas(array(
            SyntheticFigKiwiFixtureBuilder::zlibChunk(blocks_engine_figma_transformer_kiwi_inventory_schema_fixture()),
            SyntheticFigKiwiFixtureBuilder::zlibChunk(blocks_engine_figma_transformer_kiwi_inventory_message_fixture()),
        ))
    );
    $outputPath = tempnam(sys_get_temp_dir(), 'blocks-engine-kiwi-inventory-output-');
    $command = escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg(__DIR__ . '/../../scripts/figma-kiwi-skipped-field-inventory.php')
        . ' ' . escapeshellarg($fixture)
        . ' --output=' . escapeshellarg((string) $outputPath);
    $output = shell_exec($command);
    @unlink($fixture);
    $cli = is_string($output) ? json_decode($output, true) : null;
    $written = is_string($outputPath) && is_readable($outputPath) ? json_decode((string) file_get_contents($outputPath), true) : null;
    if ( is_string($outputPath) ) {
        @unlink($outputPath);
    }

    $assert('blocks-engine/figma-transformer/kiwi-skipped-field-inventory-output/v1' === ($cli['schema'] ?? null), 'kiwi-skipped-inventory-output-cli-schema');
    $assert(is_array($written), 'kiwi-skipped-inventory-output-file-json');
    $assert('blocks-engine/figma-transformer/kiwi-skipped-field-inventory-file/v1' === ($written['schema'] ?? null), 'kiwi-skipped-inventory-output-file-schema');
    $assert(10 === ($written['summary']['field_count'] ?? null), 'kiwi-skipped-inventory-output-file-field-count');
}

/**
 * @param array<string, array<string, mixed>> $fields
 * @return array<string, mixed>
 */
function blocks_engine_figma_transformer_kiwi_inventory_find_field(array $fields, string $fieldName): array
{
    foreach ( $fields as $field ) {
        if ( $fieldName === ($field['field'] ?? null) ) {
            return $field;
        }
    }
    return array();
}

function blocks_engine_figma_transformer_kiwi_inventory_schema_fixture(): string
{
    return blocks_engine_figma_transformer_wire_varint(6)
        . blocks_engine_figma_transformer_kiwi_string('GUID')
        . chr(1)
        . blocks_engine_figma_transformer_wire_varint(2)
        . blocks_engine_figma_transformer_kiwi_schema_field('sessionID', -4, false, 1)
        . blocks_engine_figma_transformer_kiwi_schema_field('localID', -4, false, 2)
        . blocks_engine_figma_transformer_kiwi_string('ArcData')
        . chr(2)
        . blocks_engine_figma_transformer_wire_varint(3)
        . blocks_engine_figma_transformer_kiwi_schema_field('startingAngle', -5, false, 1)
        . blocks_engine_figma_transformer_kiwi_schema_field('endingAngle', -5, false, 2)
        . blocks_engine_figma_transformer_kiwi_schema_field('innerRadius', -5, false, 3)
        . blocks_engine_figma_transformer_kiwi_string('Guide')
        . chr(2)
        . blocks_engine_figma_transformer_wire_varint(3)
        . blocks_engine_figma_transformer_kiwi_schema_field('axis', -6, false, 1)
        . blocks_engine_figma_transformer_kiwi_schema_field('offset', -5, false, 2)
        . blocks_engine_figma_transformer_kiwi_schema_field('guid', 0, false, 3)
        . blocks_engine_figma_transformer_kiwi_string('NodeChange')
        . chr(2)
        . blocks_engine_figma_transformer_wire_varint(18)
        . blocks_engine_figma_transformer_kiwi_schema_field('guid', 0, false, 1)
        . blocks_engine_figma_transformer_kiwi_schema_field('type', -6, false, 2)
        . blocks_engine_figma_transformer_kiwi_schema_field('name', -6, false, 3)
        . blocks_engine_figma_transformer_kiwi_schema_field('layoutBoundsDebug', -6, false, 4)
        . blocks_engine_figma_transformer_kiwi_schema_field('componentProperties', -6, false, 5)
        . blocks_engine_figma_transformer_kiwi_schema_field('imageFilters', -6, false, 6)
        . blocks_engine_figma_transformer_kiwi_schema_field('maskType', -6, false, 7)
        . blocks_engine_figma_transformer_kiwi_schema_field('textStyleOverrides', -6, false, 8)
        . blocks_engine_figma_transformer_kiwi_schema_field('vectorNetwork', -6, false, 9)
        . blocks_engine_figma_transformer_kiwi_schema_field('phase', -6, false, 10)
        . blocks_engine_figma_transformer_kiwi_schema_field('variableConsumptionMap', -6, false, 11)
        . blocks_engine_figma_transformer_kiwi_schema_field('exportSettings', -6, false, 12)
        . blocks_engine_figma_transformer_kiwi_schema_field('glyphs', -6, false, 13)
        . blocks_engine_figma_transformer_kiwi_schema_field('arcData', 1, false, 14)
        . blocks_engine_figma_transformer_kiwi_schema_field('guides', 2, true, 15)
        . blocks_engine_figma_transformer_kiwi_schema_field('listSpacing', -5, false, 16)
        . blocks_engine_figma_transformer_kiwi_schema_field('inventoryStatus', 5, false, 17)
        . blocks_engine_figma_transformer_kiwi_schema_field('extraGuid', 0, false, 18)
        . blocks_engine_figma_transformer_kiwi_string('Message')
        . chr(2)
        . blocks_engine_figma_transformer_wire_varint(2)
        . blocks_engine_figma_transformer_kiwi_schema_field('type', -6, false, 1)
        . blocks_engine_figma_transformer_kiwi_schema_field('nodeChanges', 3, true, 2)
        . blocks_engine_figma_transformer_kiwi_string('InventoryStatus')
        . chr(0)
        . blocks_engine_figma_transformer_wire_varint(2)
        . blocks_engine_figma_transformer_kiwi_schema_field('UNKNOWN', -3, false, 0)
        . blocks_engine_figma_transformer_kiwi_schema_field('READY', -3, false, 1);
}

function blocks_engine_figma_transformer_kiwi_inventory_message_fixture(): string
{
    return blocks_engine_figma_transformer_wire_varint(1)
        . blocks_engine_figma_transformer_kiwi_string('NODE_CHANGES')
        . blocks_engine_figma_transformer_wire_varint(2)
        . blocks_engine_figma_transformer_wire_varint(1)
        . blocks_engine_figma_transformer_wire_varint(1)
        . blocks_engine_figma_transformer_wire_varint(7)
        . blocks_engine_figma_transformer_wire_varint(42)
        . blocks_engine_figma_transformer_wire_varint(2)
        . blocks_engine_figma_transformer_kiwi_string('FRAME')
        . blocks_engine_figma_transformer_wire_varint(3)
        . blocks_engine_figma_transformer_kiwi_string('Inventory Frame')
        . blocks_engine_figma_transformer_wire_varint(4)
        . blocks_engine_figma_transformer_kiwi_string('layout')
        . blocks_engine_figma_transformer_wire_varint(5)
        . blocks_engine_figma_transformer_kiwi_string('component')
        . blocks_engine_figma_transformer_wire_varint(6)
        . blocks_engine_figma_transformer_kiwi_string('image')
        . blocks_engine_figma_transformer_wire_varint(7)
        . blocks_engine_figma_transformer_kiwi_string('mask')
        . blocks_engine_figma_transformer_wire_varint(8)
        . blocks_engine_figma_transformer_kiwi_string('text')
        . blocks_engine_figma_transformer_wire_varint(9)
        . blocks_engine_figma_transformer_kiwi_string('vector')
        . blocks_engine_figma_transformer_wire_varint(10)
        . blocks_engine_figma_transformer_kiwi_string('phase')
        . blocks_engine_figma_transformer_wire_varint(11)
        . blocks_engine_figma_transformer_kiwi_string('variables')
        . blocks_engine_figma_transformer_wire_varint(12)
        . blocks_engine_figma_transformer_kiwi_string('export')
        . blocks_engine_figma_transformer_wire_varint(13)
        . blocks_engine_figma_transformer_kiwi_string('glyphs')
        . blocks_engine_figma_transformer_wire_varint(14)
        . blocks_engine_figma_transformer_wire_varint(1)
        . blocks_engine_figma_transformer_kiwi_float(0.0)
        . blocks_engine_figma_transformer_wire_varint(2)
        . blocks_engine_figma_transformer_kiwi_float(1.5)
        . blocks_engine_figma_transformer_wire_varint(3)
        . blocks_engine_figma_transformer_kiwi_float(0.25)
        . blocks_engine_figma_transformer_wire_varint(0)
        . blocks_engine_figma_transformer_wire_varint(15)
        . blocks_engine_figma_transformer_wire_varint(1)
        . blocks_engine_figma_transformer_wire_varint(1)
        . blocks_engine_figma_transformer_kiwi_string('HORIZONTAL')
        . blocks_engine_figma_transformer_wire_varint(2)
        . blocks_engine_figma_transformer_kiwi_float(12.5)
        . blocks_engine_figma_transformer_wire_varint(3)
        . blocks_engine_figma_transformer_wire_varint(7)
        . blocks_engine_figma_transformer_wire_varint(99)
        . blocks_engine_figma_transformer_wire_varint(0)
        . blocks_engine_figma_transformer_wire_varint(16)
        . blocks_engine_figma_transformer_kiwi_float(6.0)
        . blocks_engine_figma_transformer_wire_varint(17)
        . blocks_engine_figma_transformer_wire_varint(1)
        . blocks_engine_figma_transformer_wire_varint(18)
        . blocks_engine_figma_transformer_wire_varint(9)
        . blocks_engine_figma_transformer_wire_varint(99)
        . blocks_engine_figma_transformer_wire_varint(0)
        . blocks_engine_figma_transformer_wire_varint(0);
}

function blocks_engine_figma_transformer_kiwi_inventory_format_guid(mixed $guid): ?string
{
    if ( ! is_array($guid) || ! isset($guid['sessionID'], $guid['localID']) ) {
        return null;
    }
    return (string) $guid['sessionID'] . ':' . (string) $guid['localID'];
}
