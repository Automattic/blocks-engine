<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

// A one-axis responsive width moves to core/image's figure. Inheriting its
// percentage on the leaf resolves it a second time against that smaller box.
$result = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array(
        'index.html' => '<html><head><link rel="stylesheet" href="style.css"></head><body><main><div class="intro"><img class="photo" src="photo.jpg" alt="Photo"><p>Copy beside the floated photo.</p></div></main></body></html>',
        'style.css' => '.photo{max-width:100%;height:auto}@media(min-width:992px){.intro .photo{width:75%;float:right}}',
    ),
))->toArray();
$dom = new DOMDocument();
@$dom->loadHTML($result['serialized_blocks']);
$image = $dom->getElementsByTagName('img')->item(0);
if (! $image instanceof DOMElement) throw new RuntimeException('Missing native image');
$found = false;
$figureWidthPreserved = false;
foreach ($result['assets'] as $asset) {
    if ('css' !== ($asset['kind'] ?? '')) continue;
    (new CssStylesheetTransformer())->transformStyleRules($asset['content'], static function (string $selectors, string $body) use ($image, &$found, &$figureWidthPreserved): string {
        foreach (CssStylesheetTransformer::splitSelectorList($selectors) ?? array() as $selector) {
            if (CssSelectorMatcher::matches($image->parentNode, CssSelectorMatcher::parse($selector))['matches']
                && preg_match('/(?:^|;)width:75%(?:;|$)/', $body)) {
                $figureWidthPreserved = true;
            }
            if (! str_contains($selector, '> img') || ! CssSelectorMatcher::matches($image, CssSelectorMatcher::parse($selector))['matches']) continue;
            if (preg_match('/(?:^|;)width:([^;]+)/', $body, $width)) {
                if ('100%' !== $width[1]) throw new RuntimeException('Percentage-sized figure must be filled, not sized again: ' . $width[1]);
                $found = true;
            }
        }
        return $selectors . '{' . $body . '}';
    });
}
if (! $found) throw new RuntimeException('Missing projected image width bridge');
if (! $figureWidthPreserved) throw new RuntimeException('The figure must retain the authored percentage width');
// A resting class percentage already sizes the figure the class moved to.
// The leaf must fill it, not restate the percentage as a native width.
$resting = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array('index.html' => '<style>.stack{position:relative;width:400px}.over{position:absolute;width:78%}</style><div class="stack"><img class="over" src="b.png" width="700" height="797" alt="B"></div>'),
))->toArray()['serialized_blocks'];
if (! preg_match('/<figure class="wp-block-image[^"]*\bover\b/', $resting) || preg_match('/"width":"78%"|width:\s*78%/', $resting)) {
    throw new RuntimeException('A figure-owned percentage width must apply once: ' . $resting);
}
echo "Percentage image width bridge passed\n";
