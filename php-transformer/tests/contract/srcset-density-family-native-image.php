<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A plain img srcset lists renditions of one image. A descriptorless candidate
// is the 1x candidate, and an x family whose src is its 1x candidate renders at
// the src's own size, so it lowers to editable core/image and keeps the
// authored selection for render. Picture art direction keeps the carrier.
$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$compile = static function (string $image): array {
    $html = '<html><body><style>.c1-31{width:100%}</style><main><div class="wrap">' . $image . '</div><p>Body text.</p></main></body></html>';
    $markup = (string) ((new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray()['serialized_blocks'] ?? '');
    preg_match('/<!-- wp:(image|custom\/responsive-media)\b(.*?) -->/', $markup, $match);

    return array($match[1] ?? '', json_decode(trim($match[2] ?? '') ?: '{}', true));
};

$native = array(
    '<img src="/media/a-2.webp" data-ux="Image" class="x-el c1-31" style="aspect-ratio: 1 / 1">' => '',
    '<img src="/media/a-2.webp" srcset="/media/a-2.webp, /media/a-3.webp 2x">' => '/media/a-2.webp, /media/a-3.webp 2x',
    '<img src="/media/a-2.webp" srcset="/media/a-2.webp 1x, /media/a@2x.webp 2x">' => '/media/a-2.webp 1x, /media/a@2x.webp 2x',
    '<img src="/media/a-2.webp" srcset="/media/a-2.webp, /media/a.png 2x, /media/a.png 3x">' => '/media/a-2.webp, /media/a.png 2x, /media/a.png 3x',
    '<img src="/media/a.png" srcset="/media/a.png, /media/a-big.png 2x">' => '/media/a.png, /media/a-big.png 2x',
    '<img class="c1-31" src="/media/a-2.webp" srcset="/media/a-2.webp 400w, /media/a.png 1200w" sizes="100vw">' => '/media/a-2.webp 400w, /media/a.png 1200w',
);
foreach ($native as $image => $srcset) {
    [$block, $attrs] = $compile($image);
    $assert('image' === $block, 'A single-image srcset family lowers to core/image: ' . $image);
    $assert($srcset === (string) ($attrs['metadata']['blocksEngineImageSelection']['srcset'] ?? ''), 'The lowered image keeps its authored srcset for render: ' . $image);
}

foreach (array(
    '<img src="/media/fallback.webp" srcset="/media/a-2.webp 1x, /media/a-3.webp 2x">' => 'An x family whose 1x candidate is not src keeps the carrier without a display width.',
    '<picture><source media="(max-width: 450px)" srcset="/media/b-3.webp"><source media="(min-width: 451px)" srcset="/media/b-4.webp"><img src="/media/b-4.webp" alt="x"></picture>' => 'Media-conditioned picture sources stay art direction.',
) as $image => $message) {
    $assert('custom/responsive-media' === $compile($image)[0], $message);
}

fwrite(STDOUT, "srcset-density-family-native-image: ok\n");
