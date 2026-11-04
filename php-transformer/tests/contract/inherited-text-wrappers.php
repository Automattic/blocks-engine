<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assert = static function (bool $ok, string $message): void {
    if (! $ok) throw new RuntimeException($message);
};
$fixture = (string) file_get_contents(dirname(__DIR__) . '/fixtures/inherited-text-wrappers.html');
foreach (array($fixture, str_replace('white-space:pre-wrap', 'white-space:pre-wrap;width:100%;height:100%', $fixture), str_replace('style="white-space:pre-wrap"', 'class="text"', str_replace('</style>', '.text{white-space:pre-wrap}</style>', $fixture))) as $source) {
    $result = (new HtmlTransformer())->transform($source)->toArray();
    $markup = $result['serialized_blocks'];
    $css = implode("\n", array_column(array_filter($result['assets'], static fn (array $asset): bool => 'css' === ($asset['kind'] ?? null)), 'content'));
    preg_match_all('/<!-- (\/)?wp:([^ >]+)[^>]*-->/', $markup, $comments, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
    $depth = 0;
    $paragraphDepth = -1;
    foreach ($comments as $comment) {
        if ('/' === $comment[1][0]) --$depth;
        else {
            if ('paragraph' === $comment[2][0]) $paragraphDepth = $depth;
            if (! str_ends_with($comment[0][0], '/-->')) ++$depth;
        }
    }
    $assert($paragraphDepth >= 0 && $paragraphDepth <= 2, 'Paragraph depth must be <= 2, got ' . $paragraphDepth . ': ' . $markup);
    $assert(! str_contains($css, 'whitespace:'), 'Invalid declarations never become carriers.');
    $document = new DOMDocument();
    @$document->loadHTML($markup);
    $paragraph = $document->getElementsByTagName('p')->item(0);
    $preserved = false;
    for ($node = $paragraph; $node instanceof DOMElement; $node = $node->parentNode) {
        foreach (explode(' ', $node->getAttribute('class')) as $class) {
            if ('' !== $class && preg_match('/\.' . preg_quote($class, '/') . '\{[^}]*white-space:pre-wrap(?:!important)?[;}]/', $css)) $preserved = true;
        }
    }
    $assert($preserved, 'Rendered paragraph inherits pre-wrap: ' . $css);
    $assert('pass' === ($result['source_reports']['wp_block_validity']['status'] ?? ''), 'Block validity passes.');
}
$negative = (new HtmlTransformer())->transform('<div style="white-space:normal;padding:8px"><p>Box</p></div>')->toArray();
$assert(str_contains($negative['serialized_blocks'], '<!-- wp:group'), 'Padding keeps a real group.');
foreach (array('id="anchor"', 'role="region"', 'tabindex="0"', 'data-runtime-target="x"') as $attribute) {
    $boundary = (new HtmlTransformer())->transform('<div ' . $attribute . ' style="white-space:normal"><p>Boundary</p></div>')->toArray();
    $assert(str_contains($boundary['serialized_blocks'], '<!-- wp:group'), 'Semantic/runtime attributes keep a boundary: ' . $attribute);
}
$invalid = (new HtmlTransformer())->transform('<p style="whitespace:inherit">Invalid declaration</p>')->toArray();
$invalidCss = implode('', array_column($invalid['assets'], 'content'));
$assert(! str_contains($invalidCss, 'whitespace:') && ! str_contains($invalid['serialized_blocks'], 'be-inline-geometry-'), 'The invalid whitespace spelling does not allocate a carrier on leaves either.');
$author = (new HtmlTransformer())->transform('<style>.x{-webkit-font-smoothing:antialiased}</style><p class="x">Author CSS</p>')->toArray();
$authorCss = implode('', array_column($author['assets'], 'content'));
$assert(str_contains($authorCss, '-webkit-font-smoothing:antialiased'), 'Author vendor declarations survive into generated CSS.');
echo "inherited-text-wrappers: ok (depth 10 -> {$paragraphDepth})\n";
