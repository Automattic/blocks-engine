<?php
declare(strict_types=1);

/**
 * Design usage reads text styles that only media rules declare.
 *
 * A responsive capture scopes the desktop stylesheet to a min-width query and
 * gives phones a scoped copy, so a wrapper's font family, colour and
 * background are all conditional although one of them applies at every
 * width. Text inheriting from that wrapper renders in the declared families,
 * so the design system must see them, while a mobile-first base value keeps
 * its unconditional reading.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\DesignSystem;

$failures = 0;
$passes = 0;
$assert = static function (bool $condition, string $message) use (&$failures, &$passes): void {
    if ($condition) { ++$passes; return; }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
};

$css = ':root{--font-body:"Nunito Sans","Nunito Sans Fallback";--font-display:"Shantell Sans","Shantell Sans Fallback";--brand:#121f34}'
    . 'body{font-family:system-ui,sans-serif}'
    . '@media (min-width:1201px){.page{font-family:var(--font-body);color:var(--brand);background-color:#f0f5f9}.page h1,.page h2{font-family:var(--font-display)}}'
    . '@media (max-width:1200px){.page{font-family:var(--font-body);color:var(--brand);background-color:#f0f5f9}.page h1,.page h2{font-family:var(--font-display)}}'
    . '.mobile-first{font-size:1.5rem}@media (min-width:768px){.mobile-first{font-size:3rem}}';
$sections = '';
foreach (array('Plan', 'Build', 'Ship') as $step) {
    $sections .= '<section><h2>' . $step . ' with care</h2><p>Every step of the process gets its own paragraph of body copy that is long enough to carry real text weight in the design usage.</p></section>';
}
$html = '<!doctype html><html><head><style>' . $css . '</style></head><body><div class="page"><h1>Studio for small teams</h1>' . $sections
    . '<p class="mobile-first">A large lead paragraph.</p></div></body></html>';

$result = (new ArtifactCompiler())->compile(array('files' => array('index.html' => $html)))->toArray();
$usage = $result['source_reports']['compiled_site']['pages'][0]['design_usage'] ?? array();
$assert(array() !== ($usage['text'] ?? array()), 'the page reports text usage');

$familyFor = static function (string $role) use ($usage): array {
    $families = array();
    foreach ($usage['text'] ?? array() as $row) if ($role === $row['role']) $families[] = $row['font_family'];
    return array_values(array_unique($families));
};
$assert(array('"Nunito Sans","Nunito Sans Fallback"') === $familyFor('body'), 'body text inherits the media-scoped wrapper family with its custom property resolved (' . json_encode($familyFor('body')) . ')');
$assert(array('"Shantell Sans","Shantell Sans Fallback"') === $familyFor('heading'), 'headings read the media-scoped heading family (' . json_encode($familyFor('heading')) . ')');
$colors = array_values(array_unique(array_column($usage['text'] ?? array(), 'color')));
$assert(array('#121f34') === $colors, 'text carries the media-scoped brand colour (' . json_encode($colors) . ')');
$assert(in_array('#f0f5f9', array_column($usage['backgrounds'] ?? array(), 'color'), true), 'the media-scoped wrapper background is a used background');
$lead = array_values(array_filter($usage['text'] ?? array(), static fn(array $row): bool => 'body' === $row['role'] && 24 === (int) round((float) $row['font_size_px'])));
$assert(1 === count($lead) && in_array('font-size', $lead[0]['conditional'] ?? array(), true), 'a mobile-first size keeps its base value and stays conditional');

$report = DesignSystem::fromUsage(array('index.html' => $usage))->report();
$families = array_column($report['font_families'], 'name', 'slug');
$assert(array('heading' => 'Shantell Sans', 'body' => 'Nunito Sans') === $families, 'the design system names the rendered families (' . json_encode($families) . ')');
$assert(in_array('#121f34', array_column($report['palette'], 'color'), true), 'the palette holds the rendered brand colour');

echo "Design usage from media-scoped styles: $passes passed, $failures failed" . PHP_EOL;
exit($failures > 0 ? 1 : 0);
