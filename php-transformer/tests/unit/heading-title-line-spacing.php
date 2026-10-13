<?php
declare(strict_types=1);

/**
 * A page titled from its first <h1> must keep the line break between a heading
 * and a block-display inline child. `<h1>First<span style="display:block">
 * Second</span></h1>` has no whitespace in its markup, so flattening it to text
 * produced "FirstSecond". The document <title> spells the same words with the
 * spacing the author rendered and is preferred when it matches modulo spacing.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$failures = 0;
$assert = static function (bool $ok, string $message, string $detail = '') use (&$failures): void {
    if ( ! $ok ) {
        ++$failures;
        fwrite(STDERR, 'FAIL: ' . $message . ( '' !== $detail ? ' - ' . $detail : '' ) . PHP_EOL);
    }
};

$title = static function (string $head, string $h1): string {
    $html = '<html><head>' . $head . '</head><body><main>' . $h1 . '<p>Copy</p></main></body></html>';
    $plan = ( new ArtifactCompiler() )->compile(array(
        'entrypoint' => 'index.html',
        'files' => array( 'index.html' => $html ),
    ))->toArray()['source_reports']['wordpress_site_plan'] ?? array();

    return (string) ( $plan['pages'][0]['title'] ?? '' );
};

$stacked = '<h1>First<span style="display:block">Second</span></h1>';
$assert('First Second' === $title("<title>\n First Second\n</title>", $stacked), 'a stacked heading takes the spaced document title', $title("<title>First Second</title>", $stacked));
$assert('First Second' === $title('<title>Unrelated</title>', $stacked), 'an unrelated document title is not adopted, and the lines stay spaced');
$assert('Plain heading' === $title('<title>Site</title>', '<h1>Plain heading</h1>'), 'an ordinary heading still titles the page');

if ( $failures > 0 ) {
    fwrite(STDERR, "Heading title line spacing failed ({$failures})\n");
    exit(1);
}
fwrite(STDOUT, "Heading title line spacing passed\n");
