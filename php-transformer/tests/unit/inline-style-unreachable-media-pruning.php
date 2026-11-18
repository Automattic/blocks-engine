<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// A capture that serves one copy of a page stylesheet per viewport leaves the
// desktop copy (delivered at min-width:1024px) carrying the mobile copy's
// max-width:1023px blocks. They can never apply there, so they are not
// published; blocks the delivery media can still meet are kept.
$sheet = 'p{color:navy}'
    . '@media screen and (max-width: 1023px){p{color:salmon}}'
    . '@media screen and (max-width: 2147483647px){p{background-color:teal}}'
    . '@media (min-width:1200px){p{border-color:orchid}}'
    . '@media not screen and (max-width:1023px){p{outline-color:plum}}';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<!doctype html><html><head>'
        . '<style media="(min-width:1024px)">' . $sheet . '</style>'
        . '<style media="(max-width:1023px)">p{color:gold}@media (min-width:1024px){p{color:khaki}}@media (max-width:767px){p{color:coral}}</style>'
        . '</head><body><main><p>Hello</p></main></body></html>',
)))->toArray();
$css = implode("\n", array_column(array_filter($result['assets'], static fn(array $asset): bool => 'css' === ($asset['kind'] ?? '')), 'content'));
$expected = array('navy' => true, 'salmon' => false, 'teal' => true, 'orchid' => true, 'plum' => true, 'gold' => true, 'khaki' => false, 'coral' => true);
foreach ($expected as $value => $present) {
    if (str_contains($css, $value) !== $present) {
        fwrite(STDERR, 'FAIL: unexpected ' . ($present ? 'absence' : 'presence') . ' of ' . $value . "\n" . $css . "\n");
        exit(1);
    }
}
echo "Inline style unreachable media pruning passed\n";
