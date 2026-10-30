<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\BlockValidityValidator;

$source = file_get_contents(__DIR__ . '/fixtures/linked-flex-images.html');
$out = (new HtmlTransformer())->transform($source)->toArray();
$markup = $out['serialized_blocks'];
$css = implode("\n", array_column($out['assets'], 'content'));
$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
$assert(3 === substr_count($markup, '<!-- wp:image ') && !str_contains($markup, '<!-- wp:html'), 'Three editable native images, not an HTML fallback.');
$assert('pass' === (new BlockValidityValidator())->validateBlocks($out['blocks'])['status'], 'Native save shapes remain valid.');
preg_match_all('/<figure\b[^>]*class="([^"]*)"[^>]*>(.*?)<\/figure>/s', $markup, $figures, PREG_SET_ORDER);
$assert(3 === count($figures), 'Each native image has a figure geometry host.');
foreach ($figures as $index => $figure) {
    $rules = '';
    foreach (explode(' ', $figure[1]) as $class) {
        preg_match_all('/(?<![\w-])\.' . preg_quote($class, '/') . '\{([^{}]*)\}/', $css, $matches);
        $rules .= implode(';', $matches[1]);
    }
    $assert(str_contains($rules, 'flex:1 1 180px') && str_contains($rules, 'min-width:0'), 'The new flex item owns the source link flex basis and minimum width.');
    $assert(!str_contains($rules, 'width:100%') && !str_contains($rules, 'aspect-ratio:'), 'Percentage width and square crop must not size the figure against the entire row.');
    $assert(str_contains($figure[2], 'https://example.com/' . ($index + 1)), 'Native image retains its destination.');
    $assert(str_contains($figure[2], 'width:100%') && str_contains($figure[2], 'aspect-ratio:1/1'), 'Native image keeps its full-width square crop.');
    preg_match('/<a[^>]*class="([^"]*)"/', $figure[2], $anchor);
    $assert(isset($anchor[1]) && preg_match('/\.' . preg_quote($anchor[1], '/') . '\{display:block/', $css) === 1, 'The rebuilt link remains block-level, so the percentage image has a containing width.');
}
// Optional browser evidence uses the actual generated artifact, not a model of its CSS.
if ($path = getenv('LINKED_FLEX_ARTIFACT')) {
    file_put_contents($path, json_encode(array('source' => $source, 'native' => '<style>' . $css . '</style>' . preg_replace('/<!--.*?-->/s', '', $markup)), JSON_THROW_ON_ERROR));
}
echo "Linked flex image geometry contract passed.\n";
