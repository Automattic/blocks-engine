<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$source = file_get_contents(dirname(__DIR__) . '/fixtures/empty-flex-item-spacer.html');
$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
$transform = static fn (string $html): array => (new HtmlTransformer())->transform($html)->toArray();
$result = $transform($source);
$markup = $result['serialized_blocks'];
$assert(1 === preg_match('/<div[^>]*class="[^"]*jw-mobile-menu__button--dummy[^\"]*"[^>]*>\s*<\/div>/', $markup), 'The empty dummy survives as a minimal class-bearing carrier.');
$css = implode("\n", array_column(array_filter($result['assets'], static fn (array $asset): bool => 'css' === $asset['kind']), 'content'));
$assert(1 === preg_match('/[^{}]*\.blocks-engine-subject-class-[^{}]*\{[^}]*width:44px;[^}]*flex:0 99999 auto/', $css), 'The scoped rule retains both width and flex on the carrier.');
$assert('pass' === ($result['source_reports']['wp_block_validity']['status'] ?? ''), 'The carrier remains editor-valid.');
$assert(!str_contains($markup, '<!-- wp:html'), 'The carrier uses native blocks, not raw HTML.');

// The policy handles both flex axes, without retaining grid or inert leaves.
foreach (array(
    array('display:flex', 'width:44px', true),
    array('display:inline-flex;flex-direction:column', 'height:44px', true),
    array('display:flex', 'flex-basis:44px', true),
    array('display:flex', 'flex:0 44px', true),
    array('display:flex', 'width:25%', true),
    array('display:flex', 'flex:1 1 0%', true),
    array('display:grid', 'width:44px', false),
    array('display:inline-grid', 'height:44px', false),
    array('display:flex', 'width:0;flex:0 1 auto', false),
    array('display:flex', 'height:44px', false),
    array('display:flex', 'width:44px;position:absolute', false),
    array('display:flex', 'width:44px;display:none', false),
    array('display:block', 'width:44px', false),
) as [$parent, $child, $keep]) {
    $case = $transform('<div style="' . $parent . '"><span class="test-spacer" style="' . $child . '"></span><div>Title</div></div>');
    $assert($keep === str_contains($case['serialized_blocks'], 'test-spacer'), 'Empty item policy: ' . $parent . ' / ' . $child);
}

$responsiveSource = file_get_contents(dirname(__DIR__) . '/fixtures/responsive-empty-flex-item-spacer.html');
$responsive = $transform($responsiveSource);
$assert(str_contains($responsive['serialized_blocks'], 'dummy'), 'A media-only flex parent keeps its empty spacer.');
$assert('pass' === ($responsive['source_reports']['wp_block_validity']['status'] ?? ''), 'The responsive spacer remains editor-valid.');
$responsiveCss = implode("\n", array_column(array_filter($responsive['assets'], static fn (array $asset): bool => 'css' === $asset['kind']), 'content'));

foreach (array(
    array('.bar{display:flex}.dummy{width:44px;flex:0 99999 auto}', true),
    array('.bar{display:inline-flex;flex-direction:column}.dummy{height:44px}', true),
    array('.bar{display:flex}.dummy{flex:1 1 0%}', true),
    array('.bar{display:flex}.dummy{width:0;flex:0 1 auto}', false),
    array('.bar{display:flex}.dummy{width:44px;position:absolute}', false),
    array('.bar{display:flex}.dummy{width:44px;display:none}', false),
    array('.bar{display:grid}.dummy{width:44px}', false),
) as [$rules, $keep]) {
    $case = $transform('<style>.bar{display:none}@media(max-width:767px){' . $rules . '}</style><div class="bar"><span class="dummy"></span><div>Title</div></div>');
    $assert($keep === str_contains($case['serialized_blocks'], 'dummy'), 'Conditional empty item policy: ' . $rules);
}
$gated = $transform(str_replace(array('.bar{display:flex;padding:10px}', '<body>'), array('.capture-phone .bar{display:flex;padding:10px}', '<body class="capture-phone">'), $responsiveSource));
$assert(str_contains($gated['serialized_blocks'], 'dummy'), 'A responsive class gate keeps its empty flex spacer.');
$separate = $transform('<style>.bar{display:none}@media(max-width:767px){.bar{display:flex}}@media(min-width:768px){.dummy{width:44px}}</style><div class="bar"><span class="dummy"></span><div>Title</div></div>');
$assert(!str_contains($separate['serialized_blocks'], 'dummy'), 'Different responsive states do not manufacture a spacer footprint.');

if ($path = getenv('EMPTY_FLEX_ITEM_ARTIFACT')) {
    file_put_contents($path, json_encode(array('source' => $source, 'native' => '<style>' . $css . '</style>' . $markup, 'responsive' => array('source' => $responsiveSource, 'native' => '<style>' . $responsiveCss . '</style>' . $responsive['serialized_blocks'])), JSON_THROW_ON_ERROR));
}
echo "Empty flex item spacer passed.\n";
