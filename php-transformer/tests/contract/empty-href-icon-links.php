<?php
declare(strict_types=1);

/**
 * `href=""` is a same-document link, not a missing destination: a text-less
 * link that uses it keeps its anchor, its destination and its decoration in
 * the native RichText host that `href="#"` already uses.
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\BlockValidityValidator;

$assertions = 0;
$assert = static function (bool $condition, string $label, string $detail = '') use (&$assertions): void {
    ++$assertions;
    if ( ! $condition ) {
        throw new RuntimeException($label . ( '' !== $detail ? ': ' . $detail : '' ));
    }
};

$page = (string) file_get_contents(dirname(__DIR__) . '/fixtures/empty-href-icon-links.html');
$transform = static fn (string $html): array => (new HtmlTransformer())->transform($html)->toArray();

$result = $transform($page);
$markup = $result['serialized_blocks'];
$assert(array() === $result['fallbacks'], 'icon links record no fallback', (string) json_encode($result['fallbacks']));
$assert(! str_contains($markup, '<!-- wp:html'), 'icon links stay native', $markup);
$assert(1 === substr_count($markup, 'class="prev" href=""') && 1 === substr_count($markup, 'class="next" href=""'), 'each link keeps its class and same-document destination once', $markup);
$assert(2 === substr_count($markup, 'class="icon"'), 'each link keeps its painted icon', $markup);
$assert('pass' === (new BlockValidityValidator())->validateBlocks($result['blocks'])['status'], 'output has valid native save grammar');

$compiled = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $page)))->toArray();
$assert(str_contains($compiled['serialized_blocks'], 'class="prev" href=""'), 'the artifact compiler keeps the link', $compiled['serialized_blocks']);

$sameAsHash = static function (string $source) use ($transform): array {
    return array($transform(str_replace('HREF', 'href=""', $source)), $transform(str_replace('HREF', 'href="#"', $source)));
};
foreach ( array(
    'painted link without children' => '<style>.painted{display:block;width:24px;height:24px;background:#333}</style><main><div><a class="painted" HREF></a></div></main>',
    'externally labelled link' => '<main><h2 id="name">Previous</h2><div><a class="labelled" HREF aria-labelledby="name"></a></div></main>',
    'link with text' => '<main><div><a class="worded" HREF>Previous</a></div></main>',
) as $label => $source ) {
    [$empty, $hash] = $sameAsHash($source);
    $assert(array() === $empty['fallbacks'], "$label records no fallback", (string) json_encode($empty['fallbacks']));
    $assert(
        str_replace('href=""', 'href="#"', $empty['serialized_blocks']) === $hash['serialized_blocks'],
        "$label lowers like its href=\"#\" counterpart",
        $empty['serialized_blocks'] . ' <> ' . $hash['serialized_blocks']
    );
}

$blank = $transform('<main><div><a class="blank" href=" "><i class="icon"></i></a></div></main>');
$assert(str_contains($blank['serialized_blocks'], 'class="blank" href=""'), 'a whitespace-only href is the same empty reference', $blank['serialized_blocks']);

$unsafe = $transform('<main><p><a class="unsafe" href="javascript:alert(1)">Run</a></p></main>');
$assert(! str_contains($unsafe['serialized_blocks'], 'href='), 'an unsafe destination is still removed', $unsafe['serialized_blocks']);
$absent = $transform('<main><div><a class="widget"></a></div></main>');
$assert(! str_contains($absent['serialized_blocks'], '<a '), 'an empty link with no href is still not carried', $absent['serialized_blocks']);

echo "OK: empty-href icon links ({$assertions} assertions)\n";
