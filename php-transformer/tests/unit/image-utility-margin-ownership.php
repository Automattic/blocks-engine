<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

$result = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array('index.html' => '<style>.space-after{margin-bottom:1rem!important}@media(min-width:992px){.space-after{margin-bottom:0!important}}</style><main><img class="space-after" src="photo.jpg" alt="Photo"><p>Following content</p></main>'),
))->toArray();
$document = new DOMDocument();
@$document->loadHTML($result['serialized_blocks']);
$figure = $document->getElementsByTagName('figure')->item(0);
$image = $document->getElementsByTagName('img')->item(0);
if (!$figure || !$image || str_contains($result['serialized_blocks'], '<!-- wp:html')) {
    throw new RuntimeException('Expected an editable native image');
}
$leafMargin = false;
foreach ($result['source_reports']['wordpress_site_plan']['assets'] ?? array() as $asset) {
    if ('css' !== ($asset['kind'] ?? '')) continue;
    (new CssStylesheetTransformer())->visitStyleRules($asset['content'], static function (string $prelude, string $body) use ($figure, $image, &$leafMargin): void {
        if (!preg_match('/(?:^|;)\s*margin-bottom\s*:\s*1rem/', $body)) return;
        foreach (CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector) {
            $parsed = CssSelectorMatcher::parse($selector);
            if (CssSelectorMatcher::matches($figure, $parsed)['matches']) {
                throw new RuntimeException('Image utility margin must not also space its synthetic figure');
            }
            $leafMargin = $leafMargin || CssSelectorMatcher::matches($image, $parsed)['matches'];
        }
    });
}
if (!$leafMargin) throw new RuntimeException('Authored utility margin must survive on the image leaf');
echo "Image utility margin ownership passed\n";
