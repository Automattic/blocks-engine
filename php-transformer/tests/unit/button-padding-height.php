<?php
declare(strict_types=1);

/** Authored block sizes stay with the padded core/button link. */
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$fixture = (string) file_get_contents(dirname(__DIR__) . '/fixtures/button-padding-height.html');
$failures = 0;
foreach ( array('height:2.25rem', 'min-height:2.25rem', 'max-height:2.25rem', 'height:var(--control-height,2.25rem)') as $size ) {
    $result = (new HtmlTransformer())->transform(str_replace('height:2.25rem', $size, $fixture))->toArray();
    $css = implode('', array_column(array_filter($result['assets'], static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '')), 'content'));
    $found = false;
    foreach ( explode('}', $css) as $rule ) {
        if ( str_contains($rule, $size) ) {
            $selector = strstr($rule, '{', true);
            if ( false === $selector || ! str_contains($selector, '.wp-block-button__link') ) {
                fwrite(STDERR, "FAIL: {$size} must not size padding-free wrappers: {$rule}\n");
                ++$failures;
            } else {
                $found = true;
            }
        }
    }
    if ( ! $found ) {
        fwrite(STDERR, "FAIL: {$size} must reach the padded link\n");
        ++$failures;
    }
}
if ( $failures > 0 ) {
    exit(1);
}
echo "Padded button height ownership: 4 cases passed\n";
