<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\EmptyStylesheets;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\PlanWrites;

$sheet = static fn (string $name, string $content): array => array('kind' => 'css', 'source_path' => "$name.css", 'target_path' => "assets/$name.css", 'token' => "asset-$name", 'content' => $content);
$import = static fn (string $name, string $tail = ''): string => '@import url("{{wordpress-site-plan:asset:asset-' . $name . '}}")' . $tail . ';';
$assets = array(
    $sheet('empty', ''),
    $sheet('live', '.a{color:red}'),
    // Only imports of empty sheets: empty once they go.
    $sheet('chain', $import('empty') . "\n" . $import('empty', ' screen')),
    // A layer import still orders its layer; a live import still loads rules.
    $sheet('root', '@charset "utf-8";' . $import('chain') . $import('empty', ' layer(base)') . $import('live') . '.b{color:blue}'),
);
$writes = array_map(static fn (array $asset): array => PlanWrites::inline('theme_asset', $asset['target_path'], $asset['content'], $asset['source_path']), $assets);
$result = EmptyStylesheets::withoutEmptyImports($assets, $writes);
$written = array_column(array_map(static fn (array $write): array => array($write['target_path'], $write['payload']['data']), $result['writes']), 1, 0);
$contents = array_column($result['assets'], 'content', 'target_path');
if ('' !== $written['assets/chain.css'] || '' !== $contents['assets/chain.css']) throw new RuntimeException('A sheet of imports of empty sheets must be written empty.');
$root = '@charset "utf-8";' . $import('empty', ' layer(base)') . $import('live') . '.b{color:blue}';
if ($root !== $written['assets/root.css'] || $root !== $contents['assets/root.css']) throw new RuntimeException('Only imports of emptied sheets without a layer may go: ' . $written['assets/root.css']);
if ($writes[1] !== $result['writes'][1]) throw new RuntimeException('A sheet without imports of empty sheets keeps its write.');
echo "Empty stylesheet imports passed\n";
