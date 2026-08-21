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

$rewritten = SrcsetParser::rewrite($srcset, static fn(string $url): string => 'asset:' . hash('sha256', $url));
if (2 !== substr_count($rewritten, 'asset:') || !str_contains($rewritten, ' 1x, ') || !str_ends_with($rewritten, ' 2x')) {
    throw new RuntimeException('Srcset rewriting must preserve candidate descriptors.');
}

fwrite(STDOUT, "Srcset parser contract passed\n");
