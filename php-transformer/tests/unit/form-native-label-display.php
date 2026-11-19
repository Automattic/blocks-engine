<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// Inline labels participate in their parent's 24px line box even when their
// own line-height is 14px. Losing the UA display to a provider's block caption
// compresses every field (including the second column of a responsive grid).
$html = '<form><div class="fields"><div><label for="name">Name</label><input id="name"></div>'
    . '<div><label for="phone">Phone</label><input id="phone"></div></div><button>Send</button></form>';
$css = 'form{font:16px/24px sans-serif}.fields{display:grid;grid-template-columns:repeat(2,1fr);gap:16px}'
    . 'label{font-size:14px;line-height:14px}input{display:flex;height:36px}';
$transformer = new HtmlTransformer();
$labels = static function (array $result): array {
    return array_values(array_filter(array_column($result['fallbacks'][0]['presentation_graph']['controls'] ?? array(), 'label')));
};
$assert = static function (bool $ok, string $message): void {
    if (! $ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};
$base = $transformer->transform($html, array('static_css' => $css))->toArray();
$rows = $labels($base);
$assert(count($rows) === 2, 'both grid columns retain their labels');
foreach ($rows as $row) {
    $assert(($row['styles']['display'] ?? null) === 'inline', 'native inline display crosses the provider boundary');
    $assert(($row['styles']['line_height'] ?? null) === '14px', 'label typography is not inflated to imitate the parent line box');
}
$authored = $transformer->transform($html, array('static_css' => $css . 'label{display:block}@media(min-width:640px){label{display:inline-flex}}'))->toArray();
foreach ($labels($authored) as $row) {
    $assert(($row['styles']['display'] ?? null) === 'block', 'explicit author display wins over the UA default');
}
$graph = $authored['fallbacks'][0]['presentation_graph'] ?? array();
$variants = array_filter($graph['variants'] ?? array(), static fn (array $variant): bool => 'label' === ($variant['role'] ?? null) && 'inline-flex' === ($variant['style_patch']['display'] ?? null));
$assert(count($variants) === 2, 'responsive author display still reaches both labels');
echo "Native label display tests passed\n";
