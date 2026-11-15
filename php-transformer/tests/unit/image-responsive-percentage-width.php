<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssRuleAnalyzer;

// The figure owns the responsive percentage. Inheriting it on its image
// resolves it a second time against the smaller containing block.
$result = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array('index.html' => '<style>@media(min-width:992px){.photo{width:75%;float:right}}</style><main><img class="photo" src="photo.jpg" alt="Photo"><p>Following content</p></main>'),
))->toArray();
$document = new DOMDocument();
@$document->loadHTML($result['serialized_blocks']);
$image = $document->getElementsByTagName('img')->item(0);
$figure = $document->getElementsByTagName('figure')->item(0);
if (!$image || !$figure || str_contains($result['serialized_blocks'], '<!-- wp:html')) {
    throw new RuntimeException('Expected an editable native image');
}
$fill = false;
$percentage = false;
foreach ($result['source_reports']['wordpress_site_plan']['assets'] ?? array() as $asset) {
    if ('css' !== ($asset['kind'] ?? '')) continue;
    (new CssStylesheetTransformer())->visitStyleRules($asset['content'], static function (string $prelude, string $body) use ($image, $figure, &$fill, &$percentage): void {
        $width = array_column(CssRuleAnalyzer::declarations($body, array('width')), 'value', 'name')['width'] ?? '';
        foreach (CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector) {
            $parsed = CssSelectorMatcher::parse($selector);
            if ('inherit' === $width && CssSelectorMatcher::matches($image, $parsed)['matches']) {
                throw new RuntimeException('Leaf must not inherit and reapply the figure percentage');
            }
            $fill = $fill || ('100%' === $width && CssSelectorMatcher::matches($image, $parsed)['matches']);
            $percentage = $percentage || ('75%' === $width && CssSelectorMatcher::matches($figure, $parsed)['matches']);
        }
    });
}
if (!$fill || !$percentage) throw new RuntimeException('Responsive figure must own percentage and leaf must fill it');
echo "Image responsive percentage width passed\n";
