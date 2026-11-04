<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

// A deep repeated card list whose thumbnails are `<a><div class="thumb"><img></div></a>`
// stays native: each thumbnail is a linked core/image whose figure keeps the box's
// class, so author rules on `.thumb` still apply (issue #4357).
$cards = '';
for ($i = 1; $i <= 3; ++$i) {
    $cards .= '<article><a href="/post-' . $i . '/"><div class="thumb"><img alt="Thumb" src="https://example.com/a.jpg"></div></a>'
        . '<h3><a href="/post-' . $i . '/">Post ' . $i . '</a></h3><p>Summary ' . $i . '.</p></article>';
}
$html = '<style>.thumb{width:120px;border-radius:12px;overflow:hidden}</style>'
    . str_repeat('<div class="w">', 15) . '<div style="display:grid">' . $cards . '</div>' . str_repeat('</div>', 15);
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
$markup = (string) ((new WordPressSitePlan())->fromCompilerResult($result)['pages'][0]['canonical_block_markup'] ?? '');

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$assert(1 !== preg_match('/wp:custom\/(gallery|collection)-|responsive-media/', $markup), 'The card list compiles to native blocks only.');
preg_match_all('/<!-- wp:image (\{.*?\}) -->\s*<figure class="([^"]*)"><a href="([^"]*)"[^>]*><img /', $markup, $images, PREG_SET_ORDER);
$assert(3 === count($images), 'Each card thumbnail is one core/image: ' . count($images));
foreach ($images as $index => $image) {
    $attrs = json_decode($image[1], true);
    $assert('/post-' . ($index + 1) . '/' === $image[3] && 'custom' === ($attrs['linkDestination'] ?? null), 'Thumbnail ' . ($index + 1) . ' keeps its link.');
    $assert(in_array('thumb', explode(' ', $image[2]), true), 'Thumbnail ' . ($index + 1) . ' figure keeps the box class.');
}

echo "Linked image presentation carrier tests passed\n";
