<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$failures = array();
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if ( ! $condition ) {
        $failures[] = $message;
    }
};
$findBlock = static function (array $blocks, string $name) use (&$findBlock): ?array {
    foreach ( $blocks as $block ) {
        if ( $name === ($block['blockName'] ?? '') ) {
            return $block;
        }
        $nested = $findBlock($block['innerBlocks'] ?? array(), $name);
        if ( null !== $nested ) {
            return $nested;
        }
    }
    return null;
};

// Neutral reduced fixture from #3253: animation comes from page CSS, not the SVG.
$css = '.loading-overlay{display:none}.is-loading .loading-overlay{display:block}'
    . '.spinner-path{stroke-dasharray:280;animation:dash 1.4s infinite ease}@keyframes dash{50%{stroke-dashoffset:70}}';
$svg = '<svg class="spinner" viewBox="0 0 66 66" aria-hidden="true"><circle class="spinner-path" fill="none" stroke-width="6" cx="33" cy="33" r="30" stroke="#151515"/></svg>';
$compile = static function (string $body, string $styles): array {
    $html = '<!doctype html><html><head><title>Collection</title><style>' . $styles . '</style></head><body>' . $body . '</body></html>';
    return (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
};
$fixture = static fn (string $icon, string $tag = 'h4'): string => '<' . $tag . '>Filter <span class="count"><span class="label">12 products</span>' . $icon . '</span></' . $tag . '>';
$hasHtmlFinding = static function (array $result): bool {
    // The compiler exposes the quality failure as a raw_html_block signal.
    foreach ( $result['source_reports']['editability_report']['signals'] ?? array() as $finding ) {
        if ( 'raw_html_block' === ($finding['kind'] ?? '') ) {
            return true;
        }
    }
    return false;
};

foreach ( array(
    'page CSS display:none' => '<span class="loading-overlay">' . $svg . '</span>',
    'hidden attribute' => '<span hidden><span>' . $svg . '</span></span>',
    'zero-width box' => '<span style="width:0;overflow:hidden"><span>' . $svg . '</span></span>',
    'zero-height box' => '<span style="height:0;overflow:hidden">' . $svg . '</span>',
) as $name => $icon ) {
    foreach ( array('h4' => 'core/heading', 'p' => 'core/paragraph') as $tag => $blockName ) {
        $result = $compile($fixture($icon, $tag), $css);
        $block = $findBlock($result['blocks'] ?? array(), $blockName);
        $content = (string) ($block['attrs']['content'] ?? '');
        $markup = (string) ($result['serialized_blocks'] ?? '');
        $assert(null !== $block && str_contains($content, 'Filter') && str_contains($content, '12 products'), "$name ($tag): native text block retains both labels");
        $assert(null === $findBlock($result['blocks'] ?? array(), 'core/html') && ! $hasHtmlFinding($result), "$name ($tag): no HTML block or core_html_block finding");
        $assert(! str_contains($markup, '<svg') && ! str_contains($markup, '<img'), "$name ($tag): runtime-only SVG is dropped");
        $assert(array() === array_filter($result['assets'] ?? array(), static fn (array $asset): bool => 'inline-svg' === ($asset['source'] ?? '')), "$name ($tag): no materialized SVG asset");
    }
}

// aria-hidden alone is not a collapsed box: visible animated paint still falls back.
$visible = $compile($fixture($svg), $css);
$assert(null !== $findBlock($visible['blocks'] ?? array(), 'core/html'), 'Visible animated SVG keeps core/html fallback');
$assert($hasHtmlFinding($visible), 'Visible animated SVG keeps core_html_block finding');

// A visible passive SVG still becomes a native RichText image.
$passive = $compile($fixture($svg), '');
$heading = $findBlock($passive['blocks'] ?? array(), 'core/heading');
$assert(null !== $heading && str_contains((string) ($heading['attrs']['content'] ?? ''), '<img'), 'Visible passive SVG remains a RichText image');
$assert(null === $findBlock($passive['blocks'] ?? array(), 'core/html') && ! $hasHtmlFinding($passive), 'Visible passive SVG has no HTML fallback');

if ( array() !== $failures ) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
echo "Rich text hidden animated SVG passed.\n";
