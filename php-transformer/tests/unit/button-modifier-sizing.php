<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$html = (string) file_get_contents(dirname(__DIR__) . '/fixtures/button-modifier-sizing.html');
foreach (array($html, str_replace('min-height:var(--h)', 'min-height:40px', $html)) as $source) {
    $result = (new HtmlTransformer())->transform($source)->toArray();
    $css = implode("\n", array_column(array_filter($result['assets'] ?? array(), static fn(array $asset): bool => 'css' === ($asset['kind'] ?? '')), 'content'));
    if (!str_contains($result['serialized_blocks'], 'wp-block-button__link')
        // The inline-flex anchor participates inline, so its link owns the size.
        || !preg_match('/[^{}]*wp-block-button__link[^{}]*\{min-height:40px\}/', $css)
    ) {
        fwrite(STDERR, "FAIL: modifier-sized and literal buttons must carry 40px to the lowered link\n" . $css . "\n");
        exit(1);
    }
}

// A shared rule must resolve separately for each control, not freeze the first
// modifier's value across every button. A non-control keeps its authored scope.
$multiple = str_replace(
    array('.btn--md{--h:var(--h-md)}', '</main>'),
    array('.btn--md{--h:var(--h-md)} .btn--lg{--h:60px}', '<div><a class="btn btn--lg" href="/other">Other</a></div><section class="btn" style="--h:80px">Panel</section></main>'),
    $html
);
$result = (new HtmlTransformer())->transform($multiple)->toArray();
$css = implode("\n", array_column($result['assets'], 'content'));
if (!str_contains($css, 'min-height:40px') || !str_contains($css, 'min-height:60px') || !str_contains($css, 'min-height:var(--h)')) {
    fwrite(STDERR, "FAIL: shared sizing must retain each source element's variable scope\n" . $css . "\n");
    exit(1);
}
echo "PASS: button sizing resolves same-element modifiers like literal lengths\n";
