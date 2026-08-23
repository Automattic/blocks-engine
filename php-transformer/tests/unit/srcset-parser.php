<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\AssetAnalysis\SrcsetParser;

$commaUrlOne = 'https://images.example.test/transform/width_25,height_25,quality_85/icon.png';
$commaUrlTwo = 'https://images.example.test/transform/width_50,height_50,quality_85/icon.png';
$srcset = $commaUrlOne . ' 1x, ' . $commaUrlTwo . ' 2x';
$candidates = SrcsetParser::parse($srcset);

if (array($commaUrlOne, $commaUrlTwo) !== array_column($candidates, 'url') || array('1x', '2x') !== array_column($candidates, 'descriptor')) {
    throw new RuntimeException('URL-internal commas must not split srcset candidates.');
}

$data = 'data:image/svg+xml,%3Csvg%3E,%3C/svg%3E';
$mixed = SrcsetParser::parse($data . ' 1x, image-2x.png 2x');
if (array($data, 'image-2x.png') !== array_column($mixed, 'url')) {
    throw new RuntimeException('Data URL commas must remain within their srcset candidate.');
}

$bounded = SrcsetParser::parse(', image.png type(image/png, avif),, image-wide.png 1200w,');
if (
    array('image.png', 'image-wide.png') !== SrcsetParser::urls(', image.png type(image/png, avif),, image-wide.png 1200w,')
    || array('type(image/png, avif)', '1200w') !== array_column($bounded, 'descriptor')
) {
    throw new RuntimeException('Separators and descriptor-internal commas must preserve ordered candidates.');
}

$rewritten = SrcsetParser::rewrite($srcset, static fn(string $url): string => 'asset:' . hash('sha256', $url));
if (2 !== substr_count($rewritten, 'asset:') || !str_contains($rewritten, ' 1x, ') || !str_ends_with($rewritten, ' 2x')) {
    throw new RuntimeException('Srcset rewriting must preserve candidate descriptors.');
}

$policyCalls = array();
$policyRewrite = SrcsetParser::rewrite('one.png, two.png 2x', static function (string $url) use (&$policyCalls): string {
    $policyCalls[] = $url;
    return '/assets/' . $url;
});
if (array('one.png', 'two.png') !== $policyCalls || '/assets/one.png, /assets/two.png 2x' !== $policyRewrite) {
    throw new RuntimeException('Srcset rewriting must delegate URL policy once per ordered candidate.');
}

fwrite(STDOUT, "Srcset parser contract passed\n");
