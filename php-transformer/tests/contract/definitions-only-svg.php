<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$html = (string) file_get_contents(dirname(__DIR__) . '/fixtures/contract/definitions-only-svg.html');
foreach (array($html, str_replace('overflow:hidden;', 'overflow:hidden;--shape:url("#mask-a");', str_replace('clip-path:url("#mask-a")', 'clip-path:var(--shape)', $html)), str_replace('class="frame"', 'class="frame" style="clip-path:url(#mask-a)"', $html), str_replace('<svg ', '<svg aria-hidden="true" ', $html)) as $source) {
    $result = (new HtmlTransformer())->transform($source)->toArray();
    $markup = (string) $result['serialized_blocks'];
    $assert(!str_contains($markup, '<!-- wp:html'), 'Definitions-only SVG must not fall back to core/html.');
    $assert(array() === $result['fallbacks'], 'Definitions and consumer have no unsupported loss.');
    $assert(str_contains($markup, '<!-- wp:image'), 'The clipped image stays editable core/image.');
    $assert('pass' === ($result['source_reports']['wp_block_validity']['status'] ?? null), 'Serialized blocks remain valid.');
    // Runtime's standalone renderer renders static blocks only. Expand the
    // audited svg-artwork renderer's content attribute before rendering the tree.
    $expand = static function (array $blocks) use (&$expand): array {
        foreach ($blocks as &$block) {
            if ('custom/svg-artwork' === ($block['blockName'] ?? null)) {
                $block['innerContent'] = array($block['attrs']['svg']);
            }
            $block['innerBlocks'] = $expand($block['innerBlocks'] ?? array());
        }
        return $blocks;
    };
    $rendered = (new Runtime())->renderBlocks($expand((new Runtime())->parseBlocks($markup)));
    $css = implode("\n", array_column(array_filter($result['assets'], static fn (array $asset): bool => 'css' === ($asset['kind'] ?? null)), 'content'));
    $assert(1 === preg_match('/#block-a \\.frame\\{[^}]*clip-path:(?:url\\("#mask-a"\\)|var\\(--shape\\))/', $css), 'The image frame retains its authored clip-path rule.');
    $dom = new DOMDocument();
    @$dom->loadHTML($rendered);
    $xpath = new DOMXPath($dom);
    $assert(1 === $xpath->query('//*[@id="mask-a"]')->length && 1 === $xpath->query('//*[@id="block-a"]//*[contains(concat(" ", @class, " "), " frame ")]//img')->length, 'The styled image consumer and unique fragment target resolve in the rendered document.');
    $assert(str_contains($rendered, '<clipPath id="mask-a" clipPathUnits="objectBoundingBox">') && str_contains($rendered, '<img'), 'Rendered image and correctly cased clip definition share the page document.');
    $assert(str_contains($rendered, 'width="0" height="0"'), 'The definition carrier retains its zero-size box.');
}
$compiled = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
$assert('passed' === ($compiled['source_reports']['editability_policy']['status'] ?? null), 'The reduced fixture passes the compiler editability policy without exemptions.');
$assert(!str_contains($compiled['serialized_blocks'], '<!-- wp:html'), 'Artifact compilation also avoids core/html.');
// Original #1534 source: no drawable sibling is added to the definition SVG.
$maskedVideoSource = '<div class="masked-video"><svg viewBox="0 0 600 120"><defs><clipPath id="clip-masked-video"><text x="0" y="0">LET&apos;S TALK</text></clipPath></defs></svg><div class="fill-layers-wrapper" style="clip-path:url(&quot;#clip-masked-video&quot;)"><video src="footer.mp4" autoplay muted loop></video></div></div>';
foreach (array($maskedVideoSource, '<style>.fill-layers-wrapper{clip-path:url("#clip-masked-video")}</style>' . str_replace(' style="clip-path:url(&quot;#clip-masked-video&quot;)"', '', $maskedVideoSource)) as $source) {
    $result = (new HtmlTransformer())->transform($source)->toArray();
    $expandMedia = static function (array $blocks) use (&$expandMedia): array {
        foreach ($blocks as &$block) {
            if ('custom/responsive-media' === $block['blockName']) {
                $block['innerContent'] = array($block['attrs']['content']);
            } elseif ('custom/svg-artwork' === $block['blockName']) {
                $block['innerContent'] = array($block['attrs']['svg']);
            }
            $block['innerBlocks'] = $expandMedia($block['innerBlocks'] ?? array());
        }
        return $blocks;
    };
    $rendered = (new Runtime())->renderBlocks($expandMedia($result['blocks']));
    $rendered .= '<style>' . implode("\n", array_column(array_filter($result['assets'], static fn(array $asset): bool => 'css' === ($asset['kind'] ?? null)), 'content')) . '</style>';
    $assert(str_contains(strtolower($rendered), 'id="clip-masked-video"') && 1 === preg_match('/clip-path:\\s*url\\([^)]*#clip-masked-video/', html_entity_decode($rendered)), 'Original masked video renders the definition and consumer clip-path in the same document.');
    $assert(!str_contains($result['serialized_blocks'], 'assets/materialized-svg/'), 'Definition carriers are never externalized, including the media path.');
}
echo "Definitions-only SVG contract passed.\n";
