<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

/*
 * A fluid font size a block carries as a literal (a layered `clamp()` the
 * heading keeps inline so it still outranks the theme's heading element
 * style) gets one exact font-size preset, and every block that renders it
 * binds to that preset instead of keeping the literal outside the editable
 * design system.
 */
$html = <<<'HTML'
<!doctype html><html><head><style>
@layer utilities{.section-heading{letter-spacing:-.07em;font-size:clamp(42px,6.5vw,84px);font-weight:700;line-height:.94}}
</style></head><body><main>
<section class="about"><h2 class="section-heading">A moment to reset.</h2><p>Take a little time out.</p></section>
<section class="speaking"><h2 class="section-heading">Come questioning.</h2><p>Leave with a plan.</p></section>
</main></body></html>
HTML;
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray()['source_reports']['wordpress_site_plan'];
foreach ($plan['writes'] as $write) {
    if ('theme.json' === $write['target_path']) $theme = json_decode($write['payload']['data'], true, 512, JSON_THROW_ON_ERROR);
}
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$markup = implode('', array_column($plan['pages'], 'canonical_block_markup'));
$sizes = array_column($theme['settings']['typography']['fontSizes'], 'size', 'slug');
$slug = array_search('clamp(42px,6.5vw,84px)', $sizes, true);
$assert(is_string($slug), 'The fluid block font size must have an exact font-size preset: ' . json_encode($sizes));
$assert(!str_contains($markup, '"fontSize":"clamp('), 'No heading may keep the fluid font size as a literal: ' . $markup);
$assert(2 === substr_count(str_replace('\\u002d', '-', $markup), '"fontSize":"var(--wp--preset--font-size--' . $slug . ')"'), 'Both headings must bind the fluid preset: ' . $markup);
$assert(2 === substr_count($markup, 'font-size:var(--wp--preset--font-size--' . $slug . ')'), 'Both headings must render the preset variable inline: ' . $markup);
$names = array_column($theme['settings']['typography']['fontSizes'], 'name', 'slug');
$assert(1 === preg_match('/\(42-84px\)$/', (string) $names[$slug]), 'The fluid preset name must state its range: ' . $names[$slug]);

echo "fluid block font size preset: ok\n";
