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
    $assert(6 === ($inventory['summary']['field_count'] ?? null), 'kiwi-skipped-inventory-field-count');
    $assert(6 === ($inventory['summary']['occurrences'] ?? null), 'kiwi-skipped-inventory-occurrences');
    $assert(1 === ($inventory['summary']['by_role']['geometry_layout'] ?? null), 'kiwi-skipped-inventory-geometry-role');
    $assert(1 === ($inventory['summary']['by_role']['component_overrides'] ?? null), 'kiwi-skipped-inventory-component-role');
    $assert(1 === ($inventory['summary']['by_role']['fills_images'] ?? null), 'kiwi-skipped-inventory-image-role');
    $assert(1 === ($inventory['summary']['by_role']['masks_effects'] ?? null), 'kiwi-skipped-inventory-mask-role');
    $assert(1 === ($inventory['summary']['by_role']['text_style'] ?? null), 'kiwi-skipped-inventory-text-role');
    $assert(1 === ($inventory['summary']['by_role']['vectors'] ?? null), 'kiwi-skipped-inventory-vector-role');

    $layoutEntry = blocks_engine_figma_transformer_kiwi_inventory_find_field($fields, 'layoutGrids');
    $assert('NodeChange' === ($layoutEntry['parent_message'] ?? null), 'kiwi-skipped-inventory-parent-message');
    $assert('FRAME' === array_key_first(is_array($layoutEntry['node_types'] ?? null) ? $layoutEntry['node_types'] : array()), 'kiwi-skipped-inventory-node-type');
    $assert(array('7:42') === ($layoutEntry['sample_node_ids'] ?? null), 'kiwi-skipped-inventory-node-id-sample');

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
    $assert(6 === ($cli['summary']['field_count'] ?? null), 'kiwi-skipped-inventory-cli-field-count');

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
    $assert(6 === ($written['summary']['field_count'] ?? null), 'kiwi-skipped-inventory-output-file-field-count');
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
    return blocks_engine_figma_transformer_wire_varint(3)
        . blocks_engine_figma_transformer_kiwi_string('GUID')
        . chr(1)
        . blocks_engine_figma_transformer_wire_varint(2)
        . blocks_engine_figma_transformer_kiwi_schema_field('sessionID', -4, false, 1)
        . blocks_engine_figma_transformer_kiwi_schema_field('localID', -4, false, 2)
        . blocks_engine_figma_transformer_kiwi_string('NodeChange')
        . chr(2)
        . blocks_engine_figma_transformer_wire_varint(9)
        . blocks_engine_figma_transformer_kiwi_schema_field('guid', 0, false, 1)
        . blocks_engine_figma_transformer_kiwi_schema_field('type', -6, false, 2)
        . blocks_engine_figma_transformer_kiwi_schema_field('name', -6, false, 3)
        . blocks_engine_figma_transformer_kiwi_schema_field('layoutGrids', -6, false, 4)
        . blocks_engine_figma_transformer_kiwi_schema_field('componentProperties', -6, false, 5)
        . blocks_engine_figma_transformer_kiwi_schema_field('imageFilters', -6, false, 6)
        . blocks_engine_figma_transformer_kiwi_schema_field('maskType', -6, false, 7)
        . blocks_engine_figma_transformer_kiwi_schema_field('textStyleOverrides', -6, false, 8)
        . blocks_engine_figma_transformer_kiwi_schema_field('vectorNetwork', -6, false, 9)
        . blocks_engine_figma_transformer_kiwi_string('Message')
        . chr(2)
        . blocks_engine_figma_transformer_wire_varint(2)
        . blocks_engine_figma_transformer_kiwi_schema_field('type', -6, false, 1)
        . blocks_engine_figma_transformer_kiwi_schema_field('nodeChanges', 1, true, 2);
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
        . blocks_engine_figma_transformer_wire_varint(0)
        . blocks_engine_figma_transformer_wire_varint(0);
}
