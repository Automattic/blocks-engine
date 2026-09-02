<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

if ( $argc < 3 ) {
    fwrite(STDERR, "Usage: php staged-page-compilation.php <artifact.json> <page-id> [...]\n");
    exit(1);
}

$artifact = json_decode((string) file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
$compiler = new ArtifactCompiler();
$sharedPlan = $compiler->prepareShared($artifact);
$preparedPages = $compiler->preparePages($artifact, $sharedPlan);
$pagePlans = array();
foreach ( array_slice($argv, 2) as $pageId ) {
    if ( ! isset($preparedPages[$pageId]) ) {
        throw new RuntimeException(sprintf('Artifact does not contain page %s.', $pageId));
    }
    $pagePlans[] = $preparedPages[$pageId];
}

$startedAt = hrtime(true);
$receipts = $compiler->compilePreparedPages($sharedPlan, $pagePlans);

fwrite(STDOUT, json_encode(array(
    'elapsed_ms' => (hrtime(true) - $startedAt) / 1000000,
    'peak_memory_bytes' => memory_get_peak_usage(true),
    'pages' => array_map(static fn (array $receipt): array => array(
        'page_id' => $receipt['page_id'],
        'compile_duration_ms' => $receipt['work']['compile_duration_ms'] ?? null,
    ), array_values($receipts)),
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
