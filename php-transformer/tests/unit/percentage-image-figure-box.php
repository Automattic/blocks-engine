<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssRuleAnalyzer;

$result = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array('index.html' => '<style>.photo{max-width:100%;height:auto}@media(min-width:992px){.frame .photo{width:75%;float:right}}</style><main class="frame"><img class="photo" src="photo.jpg" alt="Photo"></main>'),
))->toArray();
$document = new DOMDocument();
@$document->loadHTML($result['serialized_blocks']);
$image = $document->getElementsByTagName('img')->item(0);
$figure = $document->getElementsByTagName('figure')->item(0);
if (!$image || !$figure || str_contains($result['serialized_blocks'], '<!-- wp:html')) {
    throw new RuntimeException('Expected editable native image');
}
$leafFills = false;
$figureSized = false;
foreach ($result['source_reports']['wordpress_site_plan']['assets'] ?? array() as $asset) {
    if ('css' !== ($asset['kind'] ?? '')) continue;
    (new CssStylesheetTransformer())->visitStyleRules($asset['content'], static function (string $prelude, string $body) use ($figure, $image, &$leafFills, &$figureSized): void {
        $width = CssRuleAnalyzer::declarations($body, array('width'))[0]['value'] ?? '';
        foreach (CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector) {
            $parsed = CssSelectorMatcher::parse($selector);
            if (CssSelectorMatcher::matches($figure, $parsed)['matches'] && '75%' === $width) $figureSized = true;
            if (CssSelectorMatcher::matches($image, $parsed)['matches'] && '100%' === $width) $leafFills = true;
        }
    });
}
if (!$figureSized || !$leafFills) throw new RuntimeException('Percentage-sized figure must be filled by its leaf, not apply the percentage twice');
echo "Percentage image figure box passed\n";
