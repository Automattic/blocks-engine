<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactNormalizer;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\PayloadReader;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$receipt = '{"schema":"blocks-engine-capture/capture-receipt/v1","websiteRoot":"website"}';
$artifact = array(
    'entrypoint' => 'website/index.html',
    'reports' => array('box-tree/large.json', 'capture-receipt.json'),
    'compiler_limits' => array('max_report_total_bytes' => strlen($receipt)),
    'files' => array(
        array('path' => 'box-tree/large.json', 'content' => str_repeat(' ', 100) . '{}'),
        array('path' => 'website/box-tree/medium.json', 'content' => str_repeat(' ', 60) . '{}'),
        array('path' => 'box-tree/small.json', 'content' => str_repeat(' ', 30) . '{}'),
        array('path' => 'capture-receipt.json', 'content' => $receipt),
        array('path' => 'website/index.html', 'content' => '<!doctype html><html><body><main><h1>Garden notes</h1><p>A quiet afternoon.</p></main></body></html>'),
    ),
);
$normalizer = new ArtifactNormalizer();
$normalized = $normalizer->normalize($artifact);
$assert(in_array('capture-receipt.json', array_column($normalized['files'], 'path'), true), 'The declared receipt must be admitted before optional sidecars.');
$assert(3 === $normalized['rejected_count'], 'All three sidecars must be dropped.');
$drops = array_values(array_filter($normalized['diagnostics'], static fn(array $row): bool => 'artifact_report_total_too_large' === $row['code']));
$assert(3 === count($drops), 'Each dropped sidecar must have its own diagnostic.');
$expected = array();
foreach (array_slice($artifact['files'], 0, 3) as $file) $expected[$file['path']] = strlen($file['content']);
$actual = array();
foreach ($drops as $drop) $actual[$drop['context']['path']] = $drop['context']['bytes'];
ksort($actual);
ksort($expected);
$assert($expected === $actual, 'Drop diagnostics must name each sidecar and its bytes.');
$pages = array_values(array_filter($normalized['files'], static fn(array $file): bool => 'html' === $file['kind']));

$withoutSidecars = $artifact;
$withoutSidecars['files'] = array_slice($artifact['files'], 3);
$baseline = (new ArtifactCompiler())->compile($withoutSidecars)->toArray();
$result = (new ArtifactCompiler())->compile($artifact)->toArray();
$assert(in_array($result['status'], array('success', 'success_with_warnings'), true), 'Compilation must succeed with dropped sidecars.');
$assert($baseline['serialized_blocks'] === $result['serialized_blocks'], 'Dropping a sidecar must compile like absent evidence.');

// With room for one sidecar, keep the smallest even when it is listed last.
$partial = $artifact;
$partial['compiler_limits']['max_report_total_bytes'] += 65;
$paths = array_column($normalizer->normalize($partial)['files'], 'path');
$assert(in_array('box-tree/small.json', $paths, true) && !in_array('website/box-tree/medium.json', $paths, true) && !in_array('box-tree/large.json', $paths, true), 'Budget pressure must drop the largest sidecars first.');

// Reference transport must not hydrate evidence normalization will drop, or
// charge that evidence to the parsed-source budget instead of the report one.
$payloads = array_column($artifact['files'], 'content', 'path');
$reader = new class($payloads) implements PayloadReader {
    public array $reads = array();
    public function __construct(private array $payloads) {}
    public function read(array $reference): string
    {
        $this->reads[] = $reference['id'];
        return $this->payloads[$reference['id']];
    }
};
$referenced = $artifact;
$referenced['compiler_limits']['max_total_bytes'] = strlen($payloads['website/index.html']);
foreach ($referenced['files'] as &$file) {
    $file['payload_reference'] = array('schema' => 'blocks-engine/payload-reference/v1', 'id' => $file['path'], 'bytes' => strlen($file['content']), 'sha256' => hash('sha256', $file['content']));
    unset($file['content']);
}
unset($file);
$compiler = new ArtifactCompiler();
$shared = $compiler->prepareShared($referenced, $reader);
$page = $compiler->compilePage($referenced, $shared, 'website/index.html', $reader);
$staged = $compiler->compose($shared, array($page), $reader)->toArray();
$assert(in_array($staged['status'], array('success', 'success_with_warnings'), true), 'Reference-backed compilation must admit the same bundle.');
$assert($baseline['serialized_blocks'] === $staged['serialized_blocks'], 'Reference-backed dropped evidence must not change page output.');
$assert(array() === array_intersect(array_keys($expected), $reader->reads), 'Dropped sidecars must never be hydrated.');
echo "artifact sidecar report budget: ok\n";
