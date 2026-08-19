<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\AssetAnalysis\SrcsetParser;

$wixOne = 'https://static.wixstatic.com/media/icon.png/v1/fill/w_25,h_25,al_c,q_85/icon.png';
$wixTwo = 'https://static.wixstatic.com/media/icon.png/v1/fill/w_50,h_50,al_c,q_85/icon.png';
$srcset = $wixOne . ' 1x, ' . $wixTwo . ' 2x';
$candidates = SrcsetParser::parse($srcset);

if (array($wixOne, $wixTwo) !== array_column($candidates, 'url') || array('1x', '2x') !== array_column($candidates, 'descriptor')) {
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
