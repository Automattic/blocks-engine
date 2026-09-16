<?php
declare(strict_types=1);

/**
 * WordPress flow groups are flex containers. A source `float:right` on an
 * image wrapper is ignored unless the parent group is a block formatting
 * context. The geometry carrier must restore that.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$html = '<div class="content">'
    . '<p>Copy that should wrap beside the photo.</p>'
    . '<span style="display:table;width:166px;position:relative;float:right;clear:right">'
    . '<a href="/full.jpg"><img src="/thumb.jpg" class="pic" width="166" height="200"></a>'
    . '</span>'
    . '<p>More copy under the wrap.</p>'
    . '</div>';

$result = ( new HtmlTransformer() )->transform($html, array())->toArray();
$css = implode("\n", array_map(
    static fn (array $asset): string => (string) ($asset['content'] ?? ''),
    is_array($result['assets'] ?? null) ? $result['assets'] : array()
));

$failures = 0;
$assert = static function (bool $ok, string $message) use (&$failures): void {
    if ( $ok ) {
        return;
    }
    ++$failures;
    fwrite(STDERR, "FAIL: {$message}\n");
};

$assert(str_contains($css, 'float:right'), 'floated image wrapper still carries float:right');
$assert(
    1 === preg_match('/\.wp-block-group:has\(> \.be-inline-geometry-[a-f0-9]+\)\{display:block !important\}/', $css),
    'parent WordPress flow group becomes a block formatting context so the float can wrap copy'
);

if ( 0 < $failures ) {
    exit(1);
}
echo "floated image in flow group passed\n";
