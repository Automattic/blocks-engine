<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

foreach (array(
    '<p>Download the <!-- -->Four Seasons<!-- --> catalogue.</p>' => '<p>Download the Four Seasons catalogue.</p>',
    '<p><span>0<!-- -->4</span></p>' => '<span>04</span>',
) as $html => $expected) {
    $result = (new HtmlTransformer())->transform($html)->toArray();
    $markup = (string) $result['serialized_blocks'];
    if (str_contains($markup, 'core/html') || str_contains($markup, '<mark')) {
        fwrite(STDERR, "Unexpected fallback/format markup:\n{$markup}\n");
        exit(1);
    }
    if (!str_contains($markup, $expected)) {
        fwrite(STDERR, "Expected {$expected} in emitted blocks:\n{$markup}\n");
        exit(1);
    }
}
echo "empty-html-comment-text-runs: passed\n";
