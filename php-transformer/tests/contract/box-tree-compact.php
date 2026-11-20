<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\BoxTreeReport;
use Automattic\BlocksEngine\PhpTransformer\Contract\BoxTree;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
    echo "PASS: $message\n";
};
$html = file_get_contents(__DIR__ . '/../fixtures/box-tree/index.html');
$v2 = json_decode(file_get_contents(__DIR__ . '/../fixtures/box-tree/index.json'), true, 32, JSON_THROW_ON_ERROR);
$pseudo = $v2['nodes']['n5']['viewports']['desktop'];
$pseudo['content'] = '""';
$v2['nodes']['n5']['viewports']['desktop']['pseudo']['before'] = $pseudo;

/** The writer's v3: v2 with each used_styles replaced by an index into one deduplicated table. */
$compact = static function (array $tree): array {
    $styles = $keys = array();
    $index = static function (array $used) use (&$styles, &$keys): int {
        $key = json_encode($used, JSON_THROW_ON_ERROR);
        if (!isset($keys[$key])) {
            $keys[$key] = count($styles);
            $styles[] = $used;
        }
        return $keys[$key];
    };
    foreach ($tree['nodes'] as &$node) {
        foreach ($node['viewports'] as &$observation) {
            $observation['used_styles'] = $index($observation['used_styles']);
            foreach ($observation['pseudo'] ?? array() as $name => $value) $observation['pseudo'][$name]['used_styles'] = $index($value['used_styles']);
        }
        unset($observation);
    }
    unset($node);
    $tree['schema'] = BoxTree::SCHEMA_COMPACT;
    $tree['styles'] = $styles;
    return $tree;
};
$v3 = $compact($v2);
$observations = 0;
foreach ($v2['nodes'] as $node) $observations += count($node['viewports']) + count($node['viewports']['desktop']['pseudo'] ?? array());
$assert(count($v3['styles']) < $observations && is_int($v3['nodes']['n5']['viewports']['desktop']['pseudo']['before']['used_styles']), 'Fixture v3 deduplicates node and pseudo styles into indexes.');

$read = static fn(array $tree): array => BoxTreeReport::read(array(
    array('path' => 'index.html', 'kind' => 'html', 'content' => $html),
    array('path' => 'box-tree/index.json', 'kind' => 'json', 'content' => json_encode($tree, JSON_THROW_ON_ERROR)),
));
$expected = $read($v2);
$assert(isset($expected['trees']['index.html']) && array() === $expected['diagnostics'], 'The v2 fixture is accepted evidence.');
$assert($expected === $read($v3), 'A v3 tree reads as the identical v2 trees and diagnostics.');

$compile = static fn(?array $tree): array => (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html) + (null === $tree ? array() : array('box-tree/index.json' => json_encode($tree, JSON_THROW_ON_ERROR)))))->toArray();
$fromV2 = $compile($v2);
$fromV3 = $compile($v3);
$assert($fromV2['serialized_blocks'] === $fromV3['serialized_blocks'] && $fromV2['source_reports']['box_tree'] === $fromV3['source_reports']['box_tree'] && $fromV2['diagnostics'] === $fromV3['diagnostics'], 'v3 and v2 compile to identical blocks, reports and diagnostics.');

$baseline = $compile(null);
$cases = array(
    'index out of range' => static function (array &$t): void { $t['nodes']['n6']['viewports']['mobile']['used_styles'] = count($t['styles']); },
    'negative index' => static function (array &$t): void { $t['nodes']['n6']['viewports']['mobile']['used_styles'] = -1; },
    'non-integer index' => static function (array &$t): void { $t['nodes']['n6']['viewports']['mobile']['used_styles'] = '0'; },
    'inline style object' => static function (array &$t): void { $t['nodes']['n6']['viewports']['mobile']['used_styles'] = $t['styles'][0]; },
    'pseudo index out of range' => static function (array &$t): void { $t['nodes']['n5']['viewports']['desktop']['pseudo']['before']['used_styles'] = 999; },
    'styles not a list' => static function (array &$t): void { $t['styles'] = array('a' => $t['styles'][0]); },
    'styles missing' => static function (array &$t): void { unset($t['styles']); },
    'style entry not an object' => static function (array &$t): void { $t['styles'][] = 'display:block'; },
    'style content invalid' => static function (array &$t): void { unset($t['styles'][0]['display']); },
);
foreach ($cases as $label => $mutate) {
    $bad = $v3;
    $mutate($bad);
    $read = BoxTreeReport::read(array(
        array('path' => 'index.html', 'kind' => 'html', 'content' => $html),
        array('path' => 'box-tree/index.json', 'kind' => 'json', 'content' => json_encode($bad, JSON_THROW_ON_ERROR)),
    ));
    $assert(array() === $read['trees'] && array('box_tree_styles_invalid') === array_column($read['diagnostics'], 'code') && 'index.html' === ($read['diagnostics'][0]['context']['source_path'] ?? null), "Malformed v3 ($label) is rejected with box_tree_styles_invalid for its route.");
    $rejected = $compile($bad);
    $assert(0 === $rejected['source_reports']['box_tree']['index.html']['nodes_with_evidence'] && $baseline['serialized_blocks'] === $rejected['serialized_blocks'], "Conversion continues without malformed v3 ($label).");
}
