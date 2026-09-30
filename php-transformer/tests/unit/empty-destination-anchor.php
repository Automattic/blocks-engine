<?php
declare(strict_types=1);

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\BlockValidityValidator;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$assertions = 0;
$assert = static function (bool $condition, string $label) use (&$assertions): void {
    ++$assertions;
    if (! $condition) throw new RuntimeException($label);
};
$cases = array(
    'redundant whitespace sibling' => '<div class="media"><a href="https://example.com/plant"><img src="plant.jpg" alt="Pencil Plant"></a><a class="blank" href="https://example.com/plant">\n \n</a></div>',
    'accessible overlay' => '<div class="notice"><span id="notice-text">Tickets available</span><a class="overlay" href="https://example.com/tickets" aria-labelledby="notice-text"></a></div>',
    'positive geometry' => '<a class="hitbox" href="https://example.com/plant"></a>',
    'unique destination' => '<a id="unique-link" data-purpose="destination" class="blank" href="https://example.com/unique"> </a>',
);
$cases['redundant whitespace sibling'] = str_replace('\\n', "\n", $cases['redundant whitespace sibling']);
$css = '<style>.media a,.blank{display:block;line-height:0;width:100%}.notice{position:relative;width:300px;height:40px}.overlay{position:absolute;inset:0}.hitbox{display:block;width:120px;height:48px}</style>';
foreach ($cases as $label => $source) {
    $result = (new HtmlTransformer())->transform($css . '<main>' . $source . '</main>')->toArray();
    $markup = $result['serialized_blocks'];
    $assert(array() === $result['fallbacks'], "$label has no unsupported fallback");
    $assert(! str_contains($markup, '<!-- wp:html'), "$label stays native");
    $assert(str_contains($markup, '<a ') && str_contains($markup, 'href="https://example.com/'), "$label retains its destination");
    $assert('pass' === (new BlockValidityValidator())->validateBlocks($result['blocks'])['status'], "$label has valid native save grammar");
    if ('redundant whitespace sibling' === $label) {
        $assert(str_contains($markup, '<!-- wp:image') && str_contains($markup, 'Pencil Plant'), 'linked image remains natively editable with its alternative text');
        $assert(2 === substr_count($markup, 'href="https://example.com/plant"'), 'image and empty sibling both retain their shared destination');
    }
    if ('accessible overlay' === $label) {
        $assert(str_contains($markup, 'aria-labelledby="notice-text"') && str_contains($markup, 'id="notice-text"'), 'overlay accessible-name relationship survives');
    }
    if ('unique destination' === $label) {
        $assert(1 === substr_count($markup, 'id="unique-link"') && str_contains($markup, 'data-purpose="destination"'), 'empty link identity remains on one native anchor');
    }
}
$unsafe = (new HtmlTransformer())->transform('<main><a href="javascript:alert(1)"> </a></main>')->toArray();
$assert(! str_contains($unsafe['serialized_blocks'], 'href="javascript:'), 'unsafe empty destination does not become a native link');
$unsupported = (new HtmlTransformer())->transform('<main><applet>Unsupported content</applet></main>')->toArray();
$assert('html_unsupported_element' === ($unsupported['fallbacks'][0]['diagnostic_code'] ?? ''), 'unrelated unsupported content still reports its loss');
echo "OK: empty destination anchors ({$assertions} assertions)\n";
