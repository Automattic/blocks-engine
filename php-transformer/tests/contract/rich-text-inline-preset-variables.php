<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<!doctype html><html><head><link rel="stylesheet" href="site.css"></head><body><main>'
        . '<p>Body copy establishes the presets.</p><p>More body copy establishes the presets.</p>'
        . '<p>Before <span class="author-text" data-note="keep" style="color:rgb(47, 46, 46);font-family:Example Light, Example, sans-serif">inline span</span> after.</p>'
        . '<h2>Before <mark class="author-text" data-note="keep" style="--blocks-engine-richtext-marker:neutral-marker;color:rgb(47, 46, 46);font-family:Example Light, Example, sans-serif">inline mark</mark> after.</h2>'
        . '</main></body></html>',
    'site.css' => 'body{color:#2f2e2e;background:#fff;font-family:Example Light,Example,sans-serif;font-size:16px}h2{font-size:32px}',
)))->toArray()['source_reports']['wordpress_site_plan'];

$theme = array();
foreach ($plan['writes'] as $write) {
    if ('theme.json' === $write['target_path']) $theme = json_decode($write['payload']['data'], true, 512, JSON_THROW_ON_ERROR);
}
$colors = array_column($theme['settings']['color']['palette'], 'color', 'slug');
$families = array_column($theme['settings']['typography']['fontFamilies'], 'fontFamily', 'slug');
$color = array_search('#2f2e2e', $colors, true);
$normalizeFamily = static fn(string $value): string => strtolower((string) preg_replace('/\s*,\s*/', ',', trim($value)));
$family = array_search('example light,example,sans-serif', array_map($normalizeFamily, $families), true);
$assert(is_string($color) && is_string($family), 'The source emits matching colour and font-family presets.');
$markup = $plan['pages'][0]['canonical_block_markup'];
$assert(2 === preg_match_all('/<(?:span|mark)\b[^>]*data-note="keep"[^>]*>/', $markup, $tags), 'Both authored inline elements retain their data attributes.');
foreach ($tags[0] as $tag) {
    $assert(str_contains($tag, 'author-text'), 'Author classes survive binding.');
    $assert(str_contains($tag, 'color:var(--wp--preset--color--' . $color . ')'), 'Attributed rich text reads the colour preset inline: ' . $tag);
    $assert(str_contains($tag, 'font-family:var(--wp--preset--font-family--' . $family . ')'), 'Attributed rich text reads the font-family preset inline: ' . $tag);
    // Resolve the emitted variables against theme.json: the initial rendered
    // values are the original colour and stack, including comma normalization.
    $assert('#2f2e2e' === $colors[$color] && 'example light,example,sans-serif' === $normalizeFamily($families[$family]), 'Binding preserves the values before a Global Styles restyle.');
}
$assert(str_contains($markup, '--blocks-engine-richtext-marker:'), 'Conversion rich-text markers survive binding.');
$runtime = new Runtime();
$assert($markup === $runtime->serializeBlocks($runtime->parseBlocks($markup)), 'Rich-text attributes round-trip without changing stored markup.');
$assert('pass' === $runtime->validateBlockSerialization($markup)['status'], 'Stored markup equals the blocks save output.');

echo "Rich-text inline preset variables contract passed.\n";
