<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

// Page-builder call-to-action buttons whose inline text colours equal palette
// tokens (`#fff` is `#ffffff`) while a hover rule restyles them, beside
// groups whose inline colours are tokens too, one of them hover-restyled.
// A preset class is !important and would mask the hover rule, so each
// literal must still read its token, as an inline preset variable.
$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<!doctype html><html><head><link rel="stylesheet" href="site.css"></head><body><main>'
        . '<section class="band" style="background-color:#f3efe8;color:#1a1a1a"><h2>Collection</h2><p>Copy on the base.</p><p>More copy.</p></section>'
        . '<section class="dark" style="background-color:#1a1a1a;color:#ffffff"><p>Dark band copy.</p></section>'
        . '<a class="cta" href="/enquire/" style="display:inline-block;padding:12px 24px;background-color:#1a1a1a;color:#fff">Enquire about this collection</a>'
        . '<a class="cta-alt" href="https://wa.me/1" style="display:inline-block;padding:12px 24px;background-color:#1a1a1a;color:#f3efe8">WhatsApp Business</a>'
        . '</main></body></html>',
    'site.css' => 'body{color:#1a1a1a;background:#f3efe8}.cta:hover,.cta-alt:hover{color:#000;background:#fff}.band:hover{background:#fff}',
)))->toArray()['source_reports']['wordpress_site_plan'];

$palette = array();
foreach ($plan['writes'] as $write) {
    if ('theme.json' === $write['target_path']) $palette = array_column(json_decode($write['payload']['data'], true, 512, JSON_THROW_ON_ERROR)['settings']['color']['palette'], 'slug', 'color');
}
$white = $palette['#ffffff'] ?? null;
$base = $palette['#f3efe8'] ?? null;
$assert(is_string($white) && is_string($base), 'The source colours are palette tokens.');
$markup = $plan['pages'][0]['canonical_block_markup'];

$assert(2 === preg_match_all('/<!-- wp:button (\{.*?\}) --><div[^>]*>(<a[^>]*>)/s', $markup, $buttons, PREG_SET_ORDER), 'The calls to action are native buttons.');
foreach (array($white, $base) as $index => $slug) {
    $attributes = json_decode($buttons[$index][1], true, 512, JSON_THROW_ON_ERROR);
    $link = $buttons[$index][2];
    $assert('var:preset|color|' . $slug === ($attributes['style']['color']['text'] ?? null), 'A hover-restyled button text colour equal to a preset stores the preset.');
    $assert(1 === preg_match('/style="color:var\(--wp--preset--color--' . preg_quote($slug, '/') . '\);/', $link), 'The link reads the preset in the same inline declaration slot.');
    $assert(!str_contains($link, 'has-' . $slug . '-color'), 'No !important preset class masks the hover rule.');
}

$assert(1 === preg_match('/<!-- wp:group (\{"className":"band".*?\}) -->/', $markup, $band), 'The base band is a group.');
$bandAttributes = json_decode($band[1], true, 512, JSON_THROW_ON_ERROR);
$assert('var:preset|color|' . $base === ($bandAttributes['style']['color']['background'] ?? null) || $base === ($bandAttributes['backgroundColor'] ?? null), 'A hover-restyled group background equal to a preset stores the preset.');
$assert(!preg_match('/color:#(?:fff|ffffff|f3efe8)\b/i', $markup), 'No block keeps a literal equal to a preset.');

$metrics = $plan['theme']['design_system']['metrics'];
$assert(0 === ($metrics['literal_preset_color_count'] ?? null), 'No colour attribute stores a literal equal to a preset (' . json_encode($metrics['literal_preset_color_count'] ?? null) . ').');

echo "Preset colour binding contract passed.\n";
