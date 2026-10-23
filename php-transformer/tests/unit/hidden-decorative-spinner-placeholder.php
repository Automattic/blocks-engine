<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

$assert = static function (bool $condition, string $label): void {
    if (! $condition) throw new RuntimeException($label);
};
$check = static function (array $blocks) use (&$check, $assert): void {
    foreach ($blocks as $block) {
        $assert('core/html' !== ($block['blockName'] ?? null), 'No HTML fallback');
        $assert(null !== ($block['blockName'] ?? null) || '' === trim($block['innerHTML'] ?? ''), 'No freeform residue');
        $check($block['innerBlocks'] ?? array());
    }
};
$transform = static fn (string $html): array => (new HtmlTransformer())->transform($html)->toArray();

// Exact abbreviated source from #3288, including its ellipses and iframe.
$source = <<<'HTML'
<div class="likes-widget-placeholder post-likes-widget-placeholder" style="height: 55px; display: none;">
  <span class="button"><span>Like</span></span>
  <span class="loading"><svg class="jetpack-spinner" width="18" height="18" viewBox="0 0 100 100" aria-hidden="true" focusable="false">
    <circle …/><path …><animateTransform attributeName="transform" type="rotate" … repeatCount="indefinite"/></path>
  </svg><span class="screen-reader-text">Loading…</span></span>
</div>
<iframe class="post-likes-widget jetpack-likes-widget" src="https://widgets.wp.com/likes/…">
HTML;
$result = $transform('<main><p>Before the widget.</p>' . $source . '</iframe><p>After the widget.</p></main>');
$markup = $result['serialized_blocks'];
$check((new Runtime())->parseBlocks($markup));
$assert(!str_contains($markup, 'Loading') && !str_contains($markup, 'jetpack-spinner') && !str_contains($markup, 'placeholder'), 'Hidden placeholder is omitted, not made visible');
$assert(str_contains($markup, 'Before the widget.') && str_contains($markup, 'After the widget.'), 'Post paragraphs survive');

// Valid, neutral SVG isolates SMIL from hidden-at-rest scaffolding.
$svg = '<svg width="18" height="18" viewBox="0 0 100 100" aria-hidden="true" focusable="false"><path d="M50 5a45 45 0 0 1 45 45"><animateTransform attributeName="transform" type="rotate" from="0 50 50" to="360 50 50" dur="1s" repeatCount="indefinite"/></path></svg>';
foreach (array($svg, str_replace('<animateTransform attributeName="transform" type="rotate" from="0 50 50" to="360 50 50" dur="1s" repeatCount="indefinite"/>', '', $svg)) as $icon) {
    $visible = $transform('<main><p>Visible icon ' . $icon . '</p></main>');
    $check((new Runtime())->parseBlocks($visible['serialized_blocks']));
    $assert(str_contains($visible['serialized_blocks'], '<img'), 'Visible artwork materializes as before');
}
$animated = $transform('<main>' . $svg . '</main>');
$assets = implode('\n', array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), $animated['assets'] ?? array()));
$assert(str_contains($assets, 'animateTransform') && str_contains($assets, 'repeatCount'), 'Native image retains canonical SMIL animation');

foreach (array($svg, '<span>Waiting</span>') as $content) {
    $hidden = $transform('<main><div style="display:none">' . $content . '</div><p>Body</p></main>');
    $check((new Runtime())->parseBlocks($hidden['serialized_blocks']));
    $assert(!str_contains($hidden['serialized_blocks'], '<img') && !str_contains($hidden['serialized_blocks'], 'Waiting'), 'Hidden presentation is inert with or without animation');
}

$document = new DOMDocument();
@$document->loadHTML('<svg><path><animateTransform attributeName="transform" type="rotate" href="#other"/></path></svg>');
$assert(!\Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SvgMaterializer::isPassiveSvgMarkup($document->getElementsByTagName('svg')->item(0)), 'Explicit SMIL targets do not enter the passive image path');

// A referenced panel must not be mistaken for disposable scaffolding.
$panel = $transform('<main><button aria-controls="panel" aria-expanded="false">Open</button><div id="panel" style="display:none"><span>Panel content</span></div></main>');
$assert(str_contains($panel['serialized_blocks'], 'Panel content'), 'Addressed hidden panel remains preserved');
echo "Hidden decorative spinner placeholder passed.\n";
