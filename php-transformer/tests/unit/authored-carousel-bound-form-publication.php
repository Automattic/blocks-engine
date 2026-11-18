<?php
declare(strict_types=1);

// Publishing an authored carousel as a core host must not detach a runtime
// entity binding (a form) later in the same document: only the carousel shell
// changes, so the plan still builds, the form's block keeps its bytes and its
// binding follows it (issue #6451).

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$html = '<!DOCTYPE html><html><head></head><body><div><div class="sqs-gallery sqs-gallery-design-stacked">'
    . '<div class="slide content-fill sqs-gallery-design-stacked-slide loaded sqs-active-slide"><img src="/media/one.webp"></div>'
    . '<div class="slide content-fill sqs-gallery-design-stacked-slide loaded"><img src="/media/two.webp"></div>'
    . '</div><a aria-label="Next Slide"></a></div>'
    . '<form action="/subscribe" method="post"><label for="email">Email</label><input id="email" type="email" name="email"><button type="submit">Sign Up</button></form>'
    . '</body></html>';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
$plan = $result['source_reports']['wordpress_site_plan'] ?? null;
if (! is_array($plan)) {
    fwrite(STDERR, 'FAIL: the site plan builds: ' . json_encode($result['source_reports']['wordpress_site_plan_diagnostics'] ?? null) . "\n");
    exit(1);
}
$failures = array();
$markup = (string) ($plan['pages'][0]['canonical_block_markup'] ?? '');
if (str_contains($markup, '/authored-carousel') || ! str_contains($markup, '<!-- wp:gallery {"metadata":{"blocksEngineCarousel"')) $failures[] = 'the carousel publishes as a core gallery';
$searches = array();
array_walk_recursive($plan['runtime_declarations'], static function ($value, $key) use (&$searches): void { if ('search_block_markup' === $key && is_string($value)) $searches[] = $value; });
foreach (array_merge($plan['runtime_records'] ?? array(), $plan['runtime_entity_records'] ?? array()) as $record) array_walk_recursive($record, static function ($value, $key) use (&$searches): void { if ('search_block_markup' === $key && is_string($value)) $searches[] = $value; });
if (array() === $searches) $failures[] = 'the form is a runtime entity binding';
foreach ($searches as $search) if (! str_contains($markup, $search)) $failures[] = 'the bound form block survives byte for byte';
if ($failures) {
    fwrite(STDERR, 'FAIL: ' . implode("\nFAIL: ", $failures) . "\n");
    exit(1);
}
echo "Authored carousel publication keeps a bound form anchored\n";
