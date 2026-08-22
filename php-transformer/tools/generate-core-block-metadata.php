<?php
declare(strict_types=1);

/**
 * Generate core capabilities that are not represented by support or attribute
 * schemas. Runtime registration remains authoritative when WordPress is loaded.
 *
 * Usage:
 *   php tools/generate-core-block-metadata.php <wp-includes/blocks> <output.json> <source-ref>
 */

if ( 4 !== count($argv) ) {
    fwrite(STDERR, "Usage: php tools/generate-core-block-metadata.php <wp-includes/blocks> <output.json> <source-ref>\n");
    exit(2);
}

$blocksDirectory = rtrim($argv[1], DIRECTORY_SEPARATOR);
$outputPath = $argv[2];
$sourceRef = trim($argv[3]);
if ( ! is_dir($blocksDirectory) || '' === $sourceRef ) {
    fwrite(STDERR, "A readable WordPress blocks directory and non-empty source ref are required.\n");
    exit(2);
}

$blocks = array();
foreach ( (array) glob($blocksDirectory . '/*/block.json') as $blockJsonPath ) {
    $metadata = json_decode((string) file_get_contents($blockJsonPath), true);
    if ( ! is_array($metadata) || ! is_string($metadata['name'] ?? null) || ! str_starts_with($metadata['name'], 'core/') ) {
        continue;
    }

    $assets = array();
    foreach ( array( 'editorScript', 'script', 'viewScript', 'viewScriptModule', 'style', 'editorStyle', 'viewStyle' ) as $field ) {
        if ( isset($metadata[ $field ]) ) {
            $assets[ $field ] = (array) $metadata[ $field ];
        }
    }
    $blocks[ $metadata['name'] ] = array(
        'parent'        => array_values(is_array($metadata['parent'] ?? null) ? $metadata['parent'] : array()),
        'allowedBlocks' => $metadata['allowedBlocks'] ?? null,
        'dynamic'       => isset($metadata['render']),
        'assets'        => $assets,
    );
}
ksort($blocks, SORT_STRING);

$registry = array(
    'schema'      => 'blocks-engine/php-transformer/core-block-metadata/v1',
    'source'      => $sourceRef,
    'block_count' => count($blocks),
    'blocks'      => $blocks,
);
$json = json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if ( false === $json ) {
    fwrite(STDERR, "Unable to encode the core-block metadata registry.\n");
    exit(1);
}

$outputDirectory = dirname($outputPath);
if ( ! is_dir($outputDirectory) && ! mkdir($outputDirectory, 0777, true) && ! is_dir($outputDirectory) ) {
    fwrite(STDERR, "Unable to create output directory: {$outputDirectory}\n");
    exit(1);
}
if ( false === file_put_contents($outputPath, $json . "\n") ) {
    fwrite(STDERR, "Unable to write core-block metadata registry: {$outputPath}\n");
    exit(1);
}

fwrite(STDOUT, sprintf("Core block metadata registry: %d declarations written to %s\n", count($blocks), $outputPath));
