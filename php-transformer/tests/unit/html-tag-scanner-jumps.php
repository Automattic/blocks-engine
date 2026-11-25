<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Support\HtmlTagScanner;

$cases = array(
    '<head><link title="a > b" href=site.css><style title=unquoted\"example>a{color:red}</style></head>',
    '<!-- <script>fake</script> --><textarea><link href="fake"></textarea><script data-x=\'a > b\'>real()</script>',
    '<link data-long="' . str_repeat('x>', 10000) . '" href="long.css">',
    '<link href="unterminated>',
    '<link value=abc\"def href=real.css>',
);
foreach ($cases as $html) {
    $expected = array();
    foreach (array('style', 'script', 'link', '#comment', 'head') as $name) $expected[$name] = HtmlTagScanner::scan($html, $name);
    HtmlTagScanner::run(static function () use ($html, $expected): void {
        foreach ($expected as $name => $records) {
            if ($records !== HtmlTagScanner::scan($html, $name)) throw new RuntimeException('Cached scan changed ' . $name);
        }
    });
}
$link = HtmlTagScanner::scan($cases[2], 'link')[0];
if (strlen($cases[2]) !== $link['end_offset']) throw new RuntimeException('Quoted greater-than ended tag early.');
if (array() !== HtmlTagScanner::scan($cases[3], 'link')) throw new RuntimeException('Unterminated quote was accepted.');
try {
    HtmlTagScanner::run(static function (): void {
        HtmlTagScanner::scan('<link href="old.css">', 'link');
        throw new RuntimeException('Abort prepare');
    });
} catch (RuntimeException) {
    // Operation-local source bytes must not survive a failed prepare.
}
if (null !== (new ReflectionProperty(HtmlTagScanner::class, 'documents'))->getValue()) throw new RuntimeException('Scan cache survived failure.');
echo "HTML tag scanner jumps: passed\n";
