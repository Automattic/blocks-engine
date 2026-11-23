<?php
declare(strict_types=1);

// Text a block paints in a near shade the palette omits (#151515 beside
// `contrast` #111111) is owned by the block's native colour support: it names
// a derived token whose value restates the shade from its representative
// preset, whether an author class or a context selector paints it. Without
// it the text stays painted by imported CSS that neither the block's colour
// support nor Global Styles reaches (#7228). The palette gains no independent
// colour, and the default render stays exact.

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$copy = str_repeat('Body copy in the main text colour. ', 12);
$html = '<!doctype html><html><head><style>
body{margin:0;background:#fff;color:#111;font-family:sans-serif}
.note{color:#151515;font-style:italic}
.footer p{color:#151515}
.band{background:#151515;color:#fff;padding:24px}
</style></head><body><main>
<p>' . $copy . '</p><p>' . $copy . '</p>
<p class="note">Class-painted note.</p>
<div class="footer"><p>Context-painted footer.</p></div>
<div class="band"><p>Band copy.</p></div>
</main></body></html>';

$plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray()['source_reports']['wordpress_site_plan'];
$palette = array();
foreach ($plan['writes'] as $write) {
    if ('theme.json' === $write['target_path']) $palette = array_column(json_decode($write['payload']['data'], true, 512, JSON_THROW_ON_ERROR)['settings']['color']['palette'] ?? array(), 'color', 'slug');
}
$shade = 'contrast-shade-bfbfbf';
$derivation = 'rgb(from var(--wp--preset--color--contrast) calc(r + 4) calc(g + 4) calc(b + 4))';
$assert('#111111' === ($palette['contrast'] ?? null), 'The fixture paints contrast #111111 (' . json_encode($palette) . ').');
$assert($derivation === ($palette[$shade] ?? null), 'The near shade enters the palette only as a derivation of its representative (' . json_encode($palette) . ').');
$assert(array() === array_diff(array_keys($palette), array('base', 'contrast', $shade)), 'The palette gains no independent colour (' . json_encode($palette) . ').');

$markup = implode("\n", array_column(array_merge($plan['pages'], $plan['template_parts']), 'canonical_block_markup'));
foreach (array('Class-painted note.', 'Context-painted footer.') as $text) {
    $assert(1 === preg_match('/<!-- wp:paragraph (\{(?:(?!-->).)*\}) --><p([^>]*)>' . preg_quote($text, '/') . '<\/p>/', $markup, $match), $text . ' stays a native paragraph.');
    $attributes = json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
    $assert($shade === ($attributes['textColor'] ?? null) && !isset($attributes['style']['color']['text']), $text . ' names the derived near-shade token, not a literal (' . $match[1] . ').');
    $assert(str_contains($match[2], 'has-text-color') && str_contains($match[2], 'has-' . $shade . '-color') && !str_contains($match[2], 'color:'), $text . ' is owned by its preset class (' . $match[2] . ').');
}
// A background shade keeps its stylesheet derivation: only text binds.
$assert(!str_contains($markup, 'shade-bfbfbf-background-color'), 'A near-shade background does not bind to the text token.');
$assert(!str_contains($markup, 'rgb(from'), 'No block markup carries a relative colour.');

echo "Near shade block text ownership passed.\n";
