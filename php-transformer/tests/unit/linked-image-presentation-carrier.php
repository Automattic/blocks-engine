<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

// A deep repeated card list whose thumbnails are `<a><div class="thumb"><img></div></a>`
// stays native: each thumbnail is a linked core/image whose figure keeps the box's
// class, so author rules on `.thumb` still apply (issues #4357, #4401). core/image cannot
// store a link tabindex, so `tabindex="-1"` on an image-only link is accepted as dropped.
$cases = array(
    'div' => static fn (int $i): string => '<a href="/post-' . $i . '/"><div class="thumb"><img alt="Thumb" src="https://example.com/a.jpg"></div></a>',
    'custom image host' => static fn (int $i): string => '<a href="/post-' . $i . '/"><div class="thumb"><wow-image><img alt="Thumb" src="https://example.com/a.jpg"></wow-image></div></a>',
    'unfocusable link' => static fn (int $i): string => '<a href="/post-' . $i . '/" tabindex="-1"><div class="thumb"><img alt="Thumb" src="https://example.com/a.jpg"></div></a>',
);
$compile = static function (callable $thumbnail): string {
    $cards = '';
    for ($i = 1; $i <= 3; ++$i) {
        $cards .= '<article>' . $thumbnail($i) . '<h3><a href="/post-' . $i . '/">Post ' . $i . '</a></h3><p>Summary ' . $i . '.</p></article>';
    }
    $html = '<style>.thumb{width:120px;border-radius:12px;overflow:hidden}</style>'
        . str_repeat('<div class="w">', 15) . '<div style="display:grid">' . $cards . '</div>' . str_repeat('</div>', 15);
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
    return (string) ((new WordPressSitePlan())->fromCompilerResult($result)['pages'][0]['canonical_block_markup'] ?? '');
};

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
foreach ($cases as $name => $thumbnail) {
    $markup = $compile($thumbnail);
    $assert(1 !== preg_match('/wp:custom\/(gallery|collection)-|responsive-media/', $markup), $name . ': no generated or responsive-media blocks.');
    $assert(3 === preg_match_all('/<!-- wp:image /', $markup), $name . ': each thumbnail is one core/image.');
    if ('custom image host' === $name) {
        // An accepted custom host keeps the trunk custom-host contract: the link
        // and box ride in a layout shell around the native image.
        for ($i = 1; $i <= 3; ++$i) {
            $assert(1 === preg_match('#<a href="/post-' . $i . '/"><div class="thumb"><!-- wp:custom/layout-shell#', $markup), $name . ': thumbnail ' . $i . ' keeps its link and box around the image.');
        }
        continue;
    }
    preg_match_all('/<!-- wp:image (\{.*?\}) -->\s*<figure class="([^"]*)"><a href="([^"]*)"[^>]*><img /', $markup, $images, PREG_SET_ORDER);
    $assert(3 === count($images), $name . ': each thumbnail is one linked core/image.');
    foreach ($images as $index => $image) {
        $attrs = json_decode($image[1], true);
        $assert('/post-' . ($index + 1) . '/' === $image[3] && 'custom' === ($attrs['linkDestination'] ?? null), $name . ': thumbnail ' . ($index + 1) . ' keeps its link.');
        $assert(in_array('thumb', explode(' ', $image[2]), true), $name . ': thumbnail ' . ($index + 1) . ' figure keeps its box class.');
    }
}
$focusable = $compile(static fn (int $i): string => '<a href="/post-' . $i . '/" tabindex="2"><div class="thumb"><img alt="Thumb" src="https://example.com/a.jpg"></div></a>');
$assert(1 === preg_match('/wp:custom\/(gallery|collection)-|responsive-media/', $focusable), 'A positive tabindex keeps the non-native path.');

echo "Linked image presentation carrier tests passed\n";
