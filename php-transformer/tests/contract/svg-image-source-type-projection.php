<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SourceDeclarations;

// #7424: the carousel img rule never selected these five source SVG stars.
$html = '<style>.owl-carousel .owl-item img{display:block}.cc-rating-stars--inactive{display:block}'
    . '.cc-rating-stars__svg{vertical-align:middle}svg{opacity:.8}</style>'
    . '<main><div class="owl-carousel"><div class="owl-item"><img src="photo.png" alt="Product">'
    . '<span class="cc-rating-stars--inactive">'
    . str_repeat('<svg class="cc-rating-stars__svg" width="13" height="13" viewBox="0 0 13 13"><path d="M0 0h13v13H0z"/></svg>', 5)
    . '</span></div></div></main>';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
$document = new DOMDocument();
@$document->loadHTML('<html><body>' . $result['serialized_blocks'] . '</body></html>');
$xpath = new DOMXPath($document);
$stars = $xpath->query('//img[contains(@src,"inline-svg-")]');
if (5 !== $stars->length) throw new RuntimeException('All five stars must lower to images.');
$css = '';
foreach ($result['assets'] as $asset) {
    if ('css' === ($asset['kind'] ?? '')) $css .= $asset['content'];
}
$rules = array();
(new CssStylesheetTransformer())->transformStyleRules($css, static function (string $prelude, string $body) use (&$rules): string {
    $rules[] = array($prelude, SourceDeclarations::parse($body));
    return $prelude . '{' . $body . '}';
});
$cascaded = static function (DOMElement $element, string $property) use ($rules): ?string {
    $winner = null;
    $weight = -1;
    foreach ($rules as [$prelude, $declarations]) {
        if (! isset($declarations[$property])) continue;
        foreach (CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector) {
            $parsed = CssSelectorMatcher::parse(trim($selector));
            if (! $parsed['supported'] || ! CssSelectorMatcher::matches($element, $parsed)['matches']) continue;
            $specificity = CssSelectorMatcher::specificity($parsed);
            if ($specificity >= $weight) {
                $winner = $declarations[$property];
                $weight = $specificity;
            }
        }
    }
    return $winner;
};
foreach ($stars as $star) {
    if (! in_array($cascaded($star, 'display'), array('inline', 'inline-block'), true)) {
        throw new RuntimeException('SVG stars must remain on one line, not acquire source img display:block.');
    }
    if ('middle' !== $cascaded($star, 'vertical-align') || '.8' !== $cascaded($star, 'opacity')) {
        throw new RuntimeException('SVG replacements must retain their own class and svg type rules.');
    }
}
$photo = $xpath->query('//img[@src="photo.png"]')->item(0);
if (! $photo instanceof DOMElement || 'block' !== $cascaded($photo, 'display')) {
    throw new RuntimeException('The carousel img rule must still apply to the source img.');
}
echo "SVG image source type projection contract passed.\n";
