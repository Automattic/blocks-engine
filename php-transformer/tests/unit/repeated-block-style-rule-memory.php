<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\RepeatedBlockStyles;

// Repeated block style proof reads every delivered declaration as a rule row.
// Route sheets repeat the shared sheet, and a rule carries several
// declarations, so rows vastly outnumber distinct selectors. Each row must
// share its selector's one parse rather than hold its own copy, even when the
// matcher's bounded cache is already full, as it is late in a site compose.
for ($i = 0; $i < 10000; ++$i) CssSelectorMatcher::parse('.cache-filler-' . $i . ' > .cache-filler-child');

$sheet = '';
for ($rule = 0; $rule < 100; ++$rule) {
    $sheet .= sprintf(':where([data-scope="document"],[data-variant="primary"]) .region-%1$d .card-%1$d > .title-%1$d:not(.is-hidden){color:red;padding-left:1px;border-top-width:0;opacity:1;letter-spacing:0;text-transform:none}', $rule);
}
$assets = array();
for ($copy = 0; $copy < 40; ++$copy) $assets[] = array('kind' => 'css', 'source_path' => 'route-' . $copy . '.css', 'content' => $sheet);
$documents = array('pages:0' => array('markup' => '<!-- wp:paragraph --><p>Neutral copy</p><!-- /wp:paragraph -->', 'strippable' => true));

gc_collect_cycles();
$start = memory_get_usage();
memory_reset_peak_usage();
$result = RepeatedBlockStyles::hoist($documents, array(), $assets);
$peak = memory_get_peak_usage() - $start;

// 24,000 declaration rows name 100 distinct selectors. A parse per row
// peaks near 300 MB here; one parse per selector stays near 32 MB.
if ($peak > 96 * 1024 * 1024) {
    fwrite(STDERR, sprintf("Repeated block style rules peaked at %d bytes for 100 distinct selectors; rows must share each selector's parse.\n", $peak));
    exit(1);
}
if (array() !== $result['markup'] || array() !== $result['blocks']) {
    fwrite(STDERR, "A lone paragraph without repeated styles must not be rewritten.\n");
    exit(1);
}
print json_encode(array('rows' => 24000, 'distinct_selectors' => 100, 'peak_bytes' => $peak), JSON_PRETTY_PRINT) . "\n";
