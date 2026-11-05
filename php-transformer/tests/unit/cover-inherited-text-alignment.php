<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$html = '<!doctype html><html><head><style>.hero{padding:96px 0;text-align:center;background:#eee url(bg.png) center/cover no-repeat}.container{max-width:960px;margin:0 auto;padding:0 24px}</style></head><body><main><section class="hero"><div class="container"><h1>Centered title</h1><p>Centered lead paragraph.</p></div></section></main></body></html>';
$out = (new HtmlTransformer())->transform($html)->toArray();
$blocks = (string) ($out['serialized_blocks'] ?? '');
foreach (array('Centered title', 'Centered lead paragraph.') as $text) {
    $start = strpos($blocks, $text);
    $blockStart = strrpos(substr($blocks, 0, $start), '<!-- wp:');
    $blockEnd = strpos($blocks, '-->', $blockStart);
    $comment = substr($blocks, $blockStart, $blockEnd - $blockStart + 3);
    if (!str_contains($comment, '"align":"center"')) {
        fwrite(STDERR, "Expected centered alignment attribute for {$text}: {$comment}\n");
        exit(1);
    }
}
echo "Cover inherited text alignment regression passed\n";
