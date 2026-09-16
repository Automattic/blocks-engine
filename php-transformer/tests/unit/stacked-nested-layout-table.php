<?php
declare(strict_types=1);

/**
 * A headerless outer table that stacks layout rows around nested percent
 * columns is blog/chrome layout, not a data table. It must lower to native
 * blocks instead of core/html.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$html = <<<'HTML'
<table id="blogTable" class="wsite-not-footer" style="border:0;width:100%">
<tbody>
<tr><td valign="top"><div class="blog-body"><p>Post copy.</p>
<table class="wsite-multicol-table"><tr>
<td style="width:73%">Main</td>
<td style="width:27%">Side</td>
</tr></table>
</div></td></tr>
<tr><td colspan="2"><p>Footer chrome.</p></td></tr>
</tbody>
</table>
HTML;

$result = ( new HtmlTransformer() )->transform($html, array())->toArray();
$markup = (string) ($result['serialized_blocks'] ?? '');

$failures = 0;
$assert = static function (bool $ok, string $message) use (&$failures): void {
    if ( $ok ) {
        return;
    }
    ++$failures;
    fwrite(STDERR, "FAIL: {$message}\n");
};

$assert(! str_contains($markup, '<!-- wp:html'), 'stacked nested layout table does not fall back to core/html');
$assert(str_contains($markup, 'Post copy.'), 'post copy survives');
$assert(str_contains($markup, 'Main') && str_contains($markup, 'Side'), 'nested percent columns survive');
$assert(str_contains($markup, 'Footer chrome.'), 'stacked footer row survives');
$assert(str_contains($markup, '<!-- wp:columns') || str_contains($markup, '<!-- wp:group'), 'layout becomes native columns or groups');

if ( 0 < $failures ) {
    fwrite(STDERR, $markup . PHP_EOL);
    exit(1);
}
echo "stacked nested layout table passed\n";
