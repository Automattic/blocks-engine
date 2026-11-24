<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

// An atlas photo paints through an inline background over a transparent 1x1
// placeholder src. The painted image, and the position and size that crop it,
// belong to the <img> box. Dropping the background-image leaves an empty box;
// carrying it on the synthetic figure paints the wrong box.
$placeholder = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGNgYGBgAAAABQABpfZFQAAAAABJRU5ErkJggg==';
$html = '<link rel="stylesheet" href="site.css"><main><section class="shop"><a href="/lamp/" class="card"><div class="spot">'
    . '<img class="atlas" src="' . $placeholder . '" alt="Lamp" style="background-image: url(&quot;/media/atlas.jpg&quot;);background-position: 17% 91%;background-size: 355% 433%;aspect-ratio: 0.813559 / 1">'
    . '</div><h3>Lamp</h3></a></section></main>';
$css = '.atlas{display:block;width:100%;aspect-ratio:1;background-repeat:no-repeat;background-size:400% 200%;background-position:0 0}';
$result = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'website/index.html',
    'files' => array(
        'website/index.html' => $html,
        'website/site.css' => $css,
        'website/media/atlas.jpg' => "\xFF\xD8\xFF\xE0atlas",
    ),
))->toArray();

$document = new DOMDocument();
@$document->loadHTML('<?xml encoding="utf-8"?><html><body>' . $result['serialized_blocks'] . '</body></html>');
$image = $document->getElementsByTagName('img')->item(0);
$figure = $image instanceof DOMElement ? $image->parentNode : null;
if ( ! $image instanceof DOMElement || ! $figure instanceof DOMElement || 'figure' !== $figure->tagName ) {
    throw new RuntimeException('expected the atlas photo as a core/image figure');
}

$painted = array( 'image' => array(), 'figure' => array() );
foreach ( $result['source_reports']['wordpress_site_plan']['assets'] ?? array() as $asset ) {
    if ( 'css' !== ($asset['kind'] ?? '') ) continue;
    (new CssStylesheetTransformer())->visitStyleRules((string) $asset['content'], static function (string $prelude, string $body) use ($image, $figure, &$painted): void {
        if ( ! preg_match('/background-image\s*:\s*url\(\s*["\']?([^"\')]+)/i', $body, $match) ) return;
        foreach ( CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector ) {
            $parsed = CssSelectorMatcher::parse($selector);
            foreach ( array( 'image' => $image, 'figure' => $figure ) as $box => $element ) {
                if ( CssSelectorMatcher::matches($element, $parsed)['matches'] ) {
                    $painted[$box][] = $match[1] . ' {' . $body . '}';
                }
            }
        }
    });
}

if ( array() === $painted['image'] ) {
    throw new RuntimeException('the inline background-image must paint the converted image leaf');
}
if ( ! str_contains(implode("\n", $painted['image']), 'atlas.jpg') ) {
    throw new RuntimeException('the leaf background must reference the copied atlas asset: ' . implode("\n", $painted['image']));
}
foreach ( array( 'background-position:17% 91%', 'background-size:355% 433%' ) as $crop ) {
    if ( ! str_contains(str_replace(' !important', '', implode("\n", $painted['image'])), $crop) ) {
        throw new RuntimeException('the atlas crop must ride the leaf with its image: missing ' . $crop);
    }
}
if ( array() !== $painted['figure'] ) {
    throw new RuntimeException('the figure box must not paint the image background: ' . implode("\n", $painted['figure']));
}

echo "Image inline background paint rides the leaf passed\n";
