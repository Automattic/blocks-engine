<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SvgDefinitionClips;

$path = 'M0,0.5 A0.5 0.5, 0 0 1, 1 0.5 M1,0.5 A0.5 0.5, 0 0 1, 0 0.5 Z';
$rows = array(
    'issue 4434' => array('<path d="' . $path . '"/>', 'ellipse(50% 50% at 50% 50%)'),
    'unclosed path with source whitespace' => array('<path d="M0,0.5 A0.5 0.5, 0 0 1, 1 0.5 M1,0.5 A0.5 0.5, 0 0 1, 0 0.5 Z">' . " \n\n  \n " . '</path>', 'ellipse(50% 50% at 50% 50%)', 'objectBoundingBox', 'data-image-mask-id="m1"'),
    'data-attribute carrier' => array('<path d="' . $path . '"/>', 'ellipse(50% 50% at 50% 50%)', 'objectBoundingBox', 'data-image-mask-id="m1"'),
    'neutral carrier attributes' => array('<path d="' . $path . '"/>', 'ellipse(50% 50% at 50% 50%)', 'objectBoundingBox', 'data-mask="m1" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" id="mask-carrier" class="mask-defs" role="presentation"'),
    'none-role carrier' => array('<path d="' . $path . '"/>', 'ellipse(50% 50% at 50% 50%)', 'objectBoundingBox', 'role="none"'),
    'semantic role retains carrier' => array('<path d="' . $path . '"/>', 'url("#m1")', 'objectBoundingBox', 'role="img"'),
    'unrecognized transformed path' => array('<path transform="rotate(25 .5 .5)" d="M0 0 L1 0 L0 1 Z"/>', 'url("#m1")'),
    'bbox polygon' => array('<polygon points="0,0 1,0 0,1"/>', 'polygon(0% 0%, 100% 0%, 0% 100%)'),
    'mixed clips retain whole carrier' => array('<circle cx=".5" cy=".5" r=".5"/></clipPath><clipPath id="unsupported" clipPathUnits="objectBoundingBox"><path d="M0 0 C0 1 1 0 1 1 Z"/>', 'url("#m1")'),
    'bbox circle' => array('<circle cx=".5" cy=".5" r=".5"/>', 'ellipse(50% 50% at 50% 50%)'),
    'adjacent semicircular arcs' => array('<path d="M0 .5 A.5 .5 0 0 1 1 .5 A.5 .5 0 0 1 0 .5Z"/>', 'ellipse(50% 50% at 50% 50%)'),
    'user-space path' => array('<path d="M0 0 L100 0 L0 100 Z"/>', 'url("#m1")', 'userSpaceOnUse'),
    'bbox curve cannot use pixel path' => array('<path d="M0 0 C0 1 1 0 1 1 Z"/>', 'url("#m1")'),
    'issue 5788 inert clip' => array('<path class="clip-path" d="M0,0"></path>', 'none', 'objectBoundingBox', 'class="clip"', true),
    'visible icon' => array(null, null),
);
foreach ($rows as $name => $row) {
    foreach (array(false, true) as $inline) {
        list($shape, $expected) = $row;
        if ($inline && 'url("#m1")' === $expected) continue;
        $units = $row[2] ?? 'objectBoundingBox';
        $svg = null === $shape ? '<svg width="24" height="24" viewBox="0 0 24 24"><path d="M0 0 L24 0 L0 24 Z"/></svg>' : '<svg width="0" height="0" style="position: absolute" ' . ($row[3] ?? '') . '><defs><clipPath id="m1" clipPathUnits="' . $units . '">' . $shape . '</clipPath></defs></svg>';
        if ($row[4] ?? false) {
            $svg = str_replace(array('width="0" height="0" style="position: absolute"', '<defs>', '</defs>'), array('style="display: none"', '', ''), $svg);
        }
        $html = '<!doctype html><html><body><div class="fluid"><div class="wrap"><img src="https://example.com/a.jpg" alt="I">' . (($row[4] ?? false) ? '<p>Section content</p>' : '') . '</div>' . $svg . '</div><style>.fluid .wrap { clip-path: url("#m1"); overflow: hidden }</style></body></html>';
        if ($inline) {
            $html = str_replace('class="wrap"', 'class="wrap" style="clip-path:url(&quot;#m1&quot;);overflow:hidden"', $html);
            $html = str_replace('clip-path: url("#m1");', '', $html);
        }
        $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
        $markup = $result['serialized_blocks'];
        $retained = 'url("#m1")' === $expected;
        if (!str_contains($markup, '<!-- wp:image')
            || ($retained ? !str_contains($markup, 'svg-artwork') : (str_contains($markup, 'svg-artwork') || str_contains($markup, '<!-- wp:html')))) {
            throw new RuntimeException($name . ': expected native image(s), no HTML or SVG companion');
        }
        $assets = $result['source_reports']['wordpress_site_plan']['assets'] ?? array();
        if (($row[4] ?? false) && (!str_contains($markup, '<!-- wp:paragraph')
            || !str_contains($markup, 'Section content')
            || preg_match('/clip-path\s*:\s*url\(/i', $markup . json_encode($assets, JSON_UNESCAPED_SLASHES)))) {
            throw new RuntimeException($name . ': paragraph must convert with no dead clip reference in markup or generated CSS');
        }
        if (null === $expected) {
            if (!str_contains($markup, 'blocks-engine-synthetic-svg-paragraph') || !array_filter($assets, static fn ($asset) => str_contains($asset['content'] ?? '', 'M0 0 L24 0 L0 24 Z'))) {
                throw new RuntimeException($name . ': visible SVG must still materialize as an image asset');
            }
            continue;
        }
        $carrierMarkup = '';
        $collect = static function (array $blocks) use (&$collect, &$carrierMarkup): void {
            foreach ($blocks as $block) {
                if ('custom/svg-artwork' === ($block['blockName'] ?? null)) {
                    $carrierMarkup .= $block['attrs']['svg'];
                }
                $collect($block['innerBlocks'] ?? array());
            }
        };
        $collect((new Runtime())->parseBlocks($markup));
        if ($retained && (!str_contains($carrierMarkup, 'id="m1"') || !str_contains($carrierMarkup, 'width="0" height="0"'))) {
            throw new RuntimeException($name . ': unsupported clips must keep their in-document carrier, never an external resource');
        }
        $dom = new DOMDocument();
        @$dom->loadHTML($markup);
        $matched = false;
        foreach ($assets as $asset) {
            if ('css' !== ($asset['kind'] ?? '')) continue;
            (new CssStylesheetTransformer())->visitStyleRules($asset['content'], static function ($selector, $body) use ($expected, $dom, &$matched): void {
                if (!str_contains($body, $expected) && !('url("#m1")' === $expected && preg_match('/clip-path:\\s*url\\([\'"]?#m1[\'"]?\\)/', $body))) return;
                foreach ($dom->getElementsByTagName('*') as $element) {
                    if (str_contains(' ' . $element->getAttribute('class') . ' ', ' wrap ') && CssSelectorMatcher::matches($element, CssSelectorMatcher::parse($selector))['matches']) {
                        $matched = true;
                    }
                }
            });
        }
        if (!$matched) throw new RuntimeException($name . ': missing exact crop on wrapper selector');
    }
}
// Check neutrality at every child boundary without HTML's self-closing-tag recovery.
foreach (array('svg', 'defs', 'clipPath', 'path') as $parent) {
    foreach (array(" \n\n  \n <!-- source formatting -->" => true, 'meaningful text' => false, '<g/>' => false) as $content => $expected) {
        $source = '<svg width="0" height="0"><defs><clipPath id="m1" clippathunits="objectBoundingBox"><path d="' . $path . '"></path></clipPath></defs></svg>';
        $source = str_replace('</' . $parent . '>', $content . '</' . $parent . '>', $source);
        $dom = new DOMDocument();
        $dom->loadXML($source);
        if ($expected !== SvgDefinitionClips::isCarrier($dom->documentElement)) {
            throw new RuntimeException($parent . ': only whitespace and comments may be ignored');
        }
    }
}
echo "Definitions-only SVG compiler contracts passed\n";
