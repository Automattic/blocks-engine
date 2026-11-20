<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

// An inline `aspect-ratio` on an unlinked image outranks every normal author
// rule in the source, including a class rule that sets a different ratio. The
// leaf carrier restating it must keep that precedence over the projected
// author rule (`.photo.wp-block-image>img`), whose specificity exceeds a plain
// `.carrier img` and which loads after the generated support stylesheet.
$html = '<link rel="stylesheet" href="site.css"><main><section class="shop"><div class="spot">'
    . '<img class="photo" src="lamp.png" alt="Lamp" style="background-position:17% 91%;aspect-ratio:0.813559 / 1">'
    . '</div><h3>Lamp</h3></section></main>';
$css = '.photo{display:block;width:100%;aspect-ratio:1;object-fit:fill}';
$result = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'website/index.html',
    'files' => array('website/index.html' => $html, 'website/site.css' => $css),
))->toArray();
$document = new DOMDocument();
@$document->loadHTML('<?xml encoding="utf-8"?><html><body>' . $result['serialized_blocks'] . '</body></html>');
$image = $document->getElementsByTagName('img')->item(0);
if ( ! $image instanceof DOMElement ) {
    throw new RuntimeException('expected a rendered image');
}

$weight = static fn (array $counts): array => array( $counts['ids'], $counts['classes'], $counts['types'] );
$carrier = null;
$author = null;
foreach ( $result['source_reports']['wordpress_site_plan']['assets'] ?? array() as $asset ) {
    if ( 'css' !== ($asset['kind'] ?? '') ) continue;
    (new CssStylesheetTransformer())->visitStyleRules((string) $asset['content'], static function (string $prelude, string $body) use ($image, $weight, &$carrier, &$author): void {
        if ( ! preg_match('/(?:^|;)\s*aspect-ratio\s*:\s*([^;!]+)/', $body, $match) ) return;
        $inline = str_starts_with(trim($match[1]), '0.813559');
        foreach ( CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector ) {
            $parsed = CssSelectorMatcher::parse($selector);
            if ( ! CssSelectorMatcher::matches($image, $parsed)['matches'] ) continue;
            $specificity = $weight(CssSelectorMatcher::specificityCounts($parsed));
            if ( $inline ) {
                $carrier = null === $carrier || ($specificity <=> $carrier) > 0 ? $specificity : $carrier;
            } else {
                $author = null === $author || ($specificity <=> $author) > 0 ? $specificity : $author;
            }
        }
    });
}
if ( null === $carrier ) {
    throw new RuntimeException('inline aspect-ratio must survive on the painting image');
}
if ( null === $author ) {
    throw new RuntimeException('expected the projected author aspect-ratio rule to reach the image');
}
if ( ($carrier <=> $author) <= 0 ) {
    throw new RuntimeException(sprintf(
        'inline aspect-ratio carrier (%s) must outrank the projected author rule (%s)',
        implode(',', $carrier),
        implode(',', $author)
    ));
}
echo "Inline image aspect-ratio outranks author class passed\n";
