<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;

$source = '<style>img{max-width:100%} .logo a img{max-width:165px}</style><div class="logo"><a href="/"><img src="logo.png" width="1200" height="200" alt="Logo"></a></div>';
$result = (new HtmlTransformer())->transform($source, array())->toArray();
$css = implode("\n", array_column(array_filter($result['assets'], static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '')), 'content'));
$blocks = $result['serialized_blocks'];
// The linked projection must move the source anchor inside the figure and
// retain both its authored type and the generated link's baseline type rank.
if ( ! preg_match('/(\.logo :where\(figure\):not\(([^)]+)\)[^{} ,]* > a:not\(\2\) > img)[^{]*\{[^}]*max-width:165px/', $css, $match) ) {
    throw new RuntimeException('Missing inner-link cascade projection: ' . $css);
}
$dom = new DOMDocument();
@$dom->loadHTML($blocks);
$image = $dom->getElementsByTagName('img')->item(0);
$matcher = new CssSelectorMatcher();
if ( ! $image instanceof DOMElement || ! $matcher->matches($image, $matcher->parse($match[1]))['matches'] ) {
    throw new RuntimeException('Projected authored selector must match the emitted image: ' . $blocks);
}
// Compare against the generic reset that reaches this same inner image.
$reset = ':where(figure):not(' . $match[2] . '):where(.blocks-engine-image).wp-block-image > a > img';
if ( ! str_contains($css, $reset) || $matcher->specificity($matcher->parse($match[1])) <= $matcher->specificity($matcher->parse($reset)) ) {
    throw new RuntimeException('Authored linked-image projection must outrank the generic reset');
}
if ( in_array('--json', $argv, true) ) {
    echo json_encode(array('source' => $source, 'blocks' => $blocks, 'css' => $css));
} else {
    echo "Linked image authored cascade passed\n";
}
