<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\DocumentHeadContext;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$artifact = require dirname(__DIR__) . '/fixtures/head-script-retirement.php';
$assert = static function(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$compiler = new ArtifactCompiler();
$shared = $compiler->prepareShared($artifact);
$pages = $compiler->preparePages($artifact, $shared);
$receipts = $compiler->compilePreparedPages($shared, $pages);
$assert(3 === count($receipts), 'All three routes compile before composition');
$result = $compiler->compose($shared, $receipts)->toArray();
$plan = $result['source_reports']['wordpress_site_plan'];
WordPressSitePlan::assertValid($plan);
$assert(3 === count($plan['pages']), 'All three routes survive canonical composition');
$head = DocumentHeadContext::fromPlan($plan, 'website/index.html');
$dom = new DOMDocument();
$dom->loadHTML('<html><head>' . $head . '</head><body></body></html>');
$assert(!str_contains($head, 'window.captureGallery'), 'Only the proven native gallery replacement retires its helper');
$assert('application/json' === $dom->getElementById('route-data')->getAttribute('type') && '{"mode":"gallery"}' === $dom->getElementById('route-data')->textContent, 'Inert data script survives with its authored body/type');
$assert($dom->getElementById('retained-first')->textContent === $dom->getElementById('retained-second')->textContent && str_contains($dom->getElementById('retained-first')->textContent, 'setAttribute'), 'Equal-body helpers retain both distinct DOM occurrences and payloads');
$assert(4 === $dom->getElementsByTagName('script')->length, 'Data, two inline helpers, and one external asset remain in source order');
$assert(1 === substr_count(DocumentHeadContext::fromPlan($plan, 'website/other.html'), 'id="other-helper"'), 'Sibling route owns its own inline helper');
$assert(str_contains(DocumentHeadContext::fromPlan($plan, 'website/third.html'), '{"mode":"third"}'), 'Third route retains its independent data');
$whole = (new ArtifactCompiler())->compile($artifact)->toArray();
$assert(DocumentHeadContext::fromPlan($whole['source_reports']['wordpress_site_plan'], 'website/index.html') === $head, 'Whole and staged compilation emit the same ordered head');

// Files intake may transport HTML as base64. Projection must replace the
// canonical bytes, not leave the old transport payload to revive retired tags.
$encoded = $artifact;
foreach ($encoded['files'] as $path => &$file) if (str_ends_with($path, '.html')) $file = array('content_base64' => base64_encode($file));
unset($file);
$encodedShared = $compiler->prepareShared($encoded);
$encodedReceipts = $compiler->compilePreparedPages($encodedShared, $compiler->preparePages($encoded, $encodedShared));
$encodedResult = $compiler->compose($encodedShared, $encodedReceipts)->toArray();
$assert(DocumentHeadContext::fromPlan($encodedResult['source_reports']['wordpress_site_plan'], 'website/index.html') === $head, 'Base64 files intake preserves the projected canonical HTML and occurrence bindings across normalization');

$unbound = $artifact;
$unbound['files']['website/index.html'] = str_replace('/script.js', '/missing-real.js', $unbound['files']['website/index.html']);
$missing = (new ArtifactCompiler())->compile($unbound)->toArray();
$assert(!isset($missing['source_reports']['wordpress_site_plan']) && in_array('wordpress_site_plan_not_self_contained', array_column($missing['diagnostics'], 'code'), true) && str_contains(json_encode($missing['diagnostics']), 'missing-real.js'), 'Genuinely unbound external script still fails with its exact reference');
try {
    DocumentHeadContext::assertValid(array('schema' => DocumentHeadContext::SCHEMA, 'elements' => array(array('tag' => 'script', 'attributes' => array(), 'inline' => false, 'url' => 'missing-real.js'))));
    throw new RuntimeException('Unbound canonical external declaration was accepted');
} catch (InvalidArgumentException $error) {
    $assert(str_contains($error->getMessage(), 'missing-real.js'), 'Canonical declaration validation remains strict');
}
echo "Head script retirement contract passed: three routes, inline/data/empty occurrences, strict external rejection\n";
