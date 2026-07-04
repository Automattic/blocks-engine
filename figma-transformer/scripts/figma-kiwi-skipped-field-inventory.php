#!/usr/bin/env php
<?php

declare(strict_types=1);

use Automattic\BlocksEngine\FigmaTransformer\Compression\ZstdCapability;
use Automattic\BlocksEngine\FigmaTransformer\Compression\ZstdCommandDecoder;
use Automattic\BlocksEngine\FigmaTransformer\FigFile\FigKiwiDecoder;

$autoload = __DIR__ . '/../vendor/autoload.php';
if ( is_readable($autoload) ) {
    require_once $autoload;
} else {
    require_once __DIR__ . '/../figma-transformer.php';
}

$options = blocks_engine_figma_kiwi_inventory_options($argv);
if ( true === ($options['help'] ?? false) || '' === ($options['input'] ?? '') ) {
    blocks_engine_figma_kiwi_inventory_usage(true === ($options['help'] ?? false) ? STDOUT : STDERR);
    exit(true === ($options['help'] ?? false) ? 0 : 1);
}

$zstdCommand = $options['zstd_command'] ?? (getenv('FIGMA_TRANSFORMER_ZSTD_COMMAND') ?: null);
$result = blocks_engine_figma_kiwi_inventory((string) $options['input'], is_string($zstdCommand) && '' !== $zstdCommand ? $zstdCommand : null);
$json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
if ( false === $json ) {
    fwrite(STDERR, 'Failed to encode skipped-field inventory output: ' . json_last_error_msg() . "\n");
    exit(1);
}
$json .= "\n";
$outputPath = $options['output'] ?? null;
if ( is_string($outputPath) && '' !== $outputPath ) {
    if ( false === file_put_contents($outputPath, $json) ) {
        fwrite(STDERR, "Failed to write skipped-field inventory output to {$outputPath}\n");
        exit(1);
    }
    fwrite(STDOUT, json_encode(array(
        'schema' => 'blocks-engine/figma-transformer/kiwi-skipped-field-inventory-output/v1',
        'output' => $outputPath,
        'summary' => $result['summary'] ?? array(),
    ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR) . "\n");
} else {
    fwrite(STDOUT, $json);
}
exit(empty($result['diagnostics']) ? 0 : 0);

/**
 * @return array<string, mixed>
 */
function blocks_engine_figma_kiwi_inventory_options(array $argv): array
{
    $options = array('input' => '');
    foreach ( array_slice($argv, 1) as $argument ) {
        if ( '--help' === $argument || '-h' === $argument ) {
            $options['help'] = true;
            continue;
        }
        if ( ! str_starts_with($argument, '--') ) {
            $options['input'] = $argument;
            continue;
        }
        $parts = explode('=', substr($argument, 2), 2);
        $options[str_replace('-', '_', $parts[0])] = $parts[1] ?? '1';
    }
    return $options;
}

function blocks_engine_figma_kiwi_inventory_usage(mixed $stream): void
{
    fwrite($stream, "Usage: figma-kiwi-skipped-field-inventory.php <path-to-fig> [--zstd-command=/opt/homebrew/bin/zstd] [--output=/tmp/inventory.json]\n");
}

/**
 * @return array<string, mixed>
 */
function blocks_engine_figma_kiwi_inventory(string $input, ?string $zstdCommand): array
{
    $canvas = blocks_engine_figma_kiwi_inventory_canvas($input);
    if ( null === $canvas ) {
        return array(
            'schema'      => 'blocks-engine/figma-transformer/kiwi-skipped-field-inventory-file/v1',
            'input'       => array('path' => $input),
            'diagnostics' => array(array('code' => 'figma_transformer_inventory_canvas_missing', 'message' => 'No canvas.fig entry could be read from the .fig archive.')),
        );
    }

    $decoder = new FigKiwiDecoder();
    $zstd = null === $zstdCommand ? new ZstdCapability() : new ZstdCapability(new ZstdCommandDecoder(array($zstdCommand, '-dc')));
    $offset = 12;
    $chunkIndex = 0;
    $schema = null;
    $diagnostics = array();
    $inventories = array();

    while ( $offset + 4 <= strlen($canvas) ) {
        $length = unpack('V', substr($canvas, $offset, 4));
        $compressedBytes = is_array($length) ? (int) $length[1] : 0;
        $payload = substr($canvas, $offset + 4, $compressedBytes);
        $offset += 4 + $compressedBytes;
        $inflated = blocks_engine_figma_kiwi_inventory_payload($payload, $zstd, $chunkIndex, $diagnostics);
        if ( null === $inflated ) {
            $chunkIndex++;
            continue;
        }

        if ( null === $schema ) {
            $schemaResult = $decoder->decodeSchema($inflated);
            if ( is_array($schemaResult['schema'] ?? null) && ! empty($schemaResult['schema']['definitions'] ?? array()) ) {
                $schema = $schemaResult['schema'];
                $chunkIndex++;
                continue;
            }
        } else {
            $inventoryResult = $decoder->inventorySkippedFieldsSelective($inflated, $schema);
            $diagnostics = array_merge($diagnostics, $inventoryResult['diagnostics']);
            if ( is_array($inventoryResult['inventory'] ?? null) ) {
                $inventory = $inventoryResult['inventory'];
                $inventory['chunk_index'] = $chunkIndex;
                $inventories[] = $inventory;
            }
        }

        $chunkIndex++;
    }

    return array(
        'schema'      => 'blocks-engine/figma-transformer/kiwi-skipped-field-inventory-file/v1',
        'input'       => array('path' => $input),
        'chunks'      => $chunkIndex,
        'inventories' => $inventories,
        'summary'     => blocks_engine_figma_kiwi_inventory_file_summary($inventories),
        'diagnostics' => $diagnostics,
    );
}

function blocks_engine_figma_kiwi_inventory_canvas(string $input): ?string
{
    if ( ! class_exists(ZipArchive::class) || ! is_readable($input) ) {
        return null;
    }

    $zip = new ZipArchive();
    if ( true !== $zip->open($input) ) {
        return null;
    }

    $canvas = $zip->getFromName('canvas.fig');
    if ( false !== $canvas ) {
        $zip->close();
        return $canvas;
    }

    for ( $index = 0; $index < $zip->numFiles; $index++ ) {
        $name = $zip->getNameIndex($index);
        if ( false !== $name && str_ends_with(strtolower($name), '.fig') ) {
            $nested = $zip->getFromName($name);
            $zip->close();
            if ( false === $nested ) {
                return null;
            }
            $tmp = tempnam(sys_get_temp_dir(), 'blocks-engine-fig-inventory-');
            if ( false === $tmp ) {
                return null;
            }
            file_put_contents($tmp, $nested);
            $nestedCanvas = blocks_engine_figma_kiwi_inventory_canvas($tmp);
            @unlink($tmp);
            return $nestedCanvas;
        }
    }

    $zip->close();
    return null;
}

function blocks_engine_figma_kiwi_inventory_payload(string $payload, ZstdCapability $zstd, int $chunkIndex, array &$diagnostics): ?string
{
    if ( str_starts_with($payload, "\x28\xb5\x2f\xfd") ) {
        $result = $zstd->uncompress($payload, 'FigKiwiSkippedFieldInventory', $chunkIndex);
        $diagnostics = array_merge($diagnostics, $result['diagnostics']);
        return is_string($result['data'] ?? null) ? $result['data'] : null;
    }

    $inflated = @gzinflate($payload);
    return false === $inflated ? $payload : $inflated;
}

/**
 * @param array<int, array<string, mixed>> $inventories
 * @return array<string, mixed>
 */
function blocks_engine_figma_kiwi_inventory_file_summary(array $inventories): array
{
    $byRole = array();
    $fieldCount = 0;
    $occurrences = 0;
    foreach ( $inventories as $inventory ) {
        $summary = is_array($inventory['summary'] ?? null) ? $inventory['summary'] : array();
        $fieldCount += (int) ($summary['field_count'] ?? 0);
        $occurrences += (int) ($summary['occurrences'] ?? 0);
        foreach ( is_array($summary['by_role'] ?? null) ? $summary['by_role'] : array() as $role => $count ) {
            $byRole[(string) $role] = ($byRole[(string) $role] ?? 0) + (int) $count;
        }
    }
    arsort($byRole);
    return array('field_count' => $fieldCount, 'occurrences' => $occurrences, 'by_role' => $byRole);
}
