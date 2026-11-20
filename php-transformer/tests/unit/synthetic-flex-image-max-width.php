<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$html = file_get_contents(dirname(__DIR__) . '/fixtures/synthetic-flex-image-max-width.html');
$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};
foreach (array('img', '.photo', '#photo', '@media (max-width:600px){img') as $selector) {
    $rule = $selector . '{max-width:100%}' . (str_starts_with($selector, '@media') ? '}' : '');
    $source = str_replace('img{max-width:100%}', $rule, $html);
    $source = str_replace('<img ', '<img class="photo" id="photo" ', $source);
    $out = (new HtmlTransformer())->transform($source)->toArray();
    $assert(str_contains($out['serialized_blocks'], 'blocks-engine-author-max-width-image'), "{$selector} protects the author's max-width");
    $css = implode("\n", array_column($out['assets'], 'content'));
    $assert(str_contains($css, ':not(.blocks-engine-author-max-width-image)'), 'the reset excludes author-constrained images');
}
$out = (new HtmlTransformer())->transform(str_replace('img{max-width:100%}', '', $html))->toArray();
$assert(str_contains($out['serialized_blocks'], 'blocks-engine-synthetic-flex-image-figure'), 'unconstrained image retains transparent flex figure');
$assert(! str_contains($out['serialized_blocks'], 'blocks-engine-author-max-width-image'), 'unconstrained image retains min-content reset');
echo "synthetic flex image max-width: passed\n";
