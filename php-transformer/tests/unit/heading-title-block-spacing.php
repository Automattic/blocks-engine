<?php
declare(strict_types=1);

/**
 * A page titled from its first <h1> must keep a space between lines that the
 * author stacked with block-display inline elements. `<h1><span class="block">
 * First</span><span class="block">Second</span></h1>` has no whitespace in its
 * markup, so flattening it to text produced "FirstSecond".
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

$title = static function (string $h1): string {
    $html = '<html><head><title>Unrelated | Example</title></head><body><main>' . $h1 . '<p>Copy</p></main></body></html>';
    $plan = ( new ArtifactCompiler() )->compile(array(
        'entrypoint' => 'index.html',
        'files' => array( 'index.html' => $html ),
    ))->toArray()['source_reports']['wordpress_site_plan'] ?? array();

    return (string) ( $plan['pages'][0]['title'] ?? '' );
};

$cases = array(
    'utility class block spans' => array( '<h1><span class="block">First line</span><span class="block italic"><a href="/a">Alpha</a><span> &amp; </span><a href="/b">Beta</a></span></h1>', 'First line Alpha & Beta' ),
    'inline style display:block' => array( '<h1><span style="color:red">One</span><span style="display: block">Two</span></h1>', 'One Two' ),
    'block span followed by text' => array( '<h1><span class="block">One</span>Two</h1>', 'One Two' ),
    'list items' => array( '<h1><ul><li>One</li><li>Two</li></ul></h1>', 'One Two' ),
    'inline spans stay joined' => array( '<h1>Care<span class="accent">ful</span> words</h1>', 'Careful words' ),
    'class containing block as a substring' => array( '<h1>Un<span class="blocky">der</span>line</h1>', 'Underline' ),
);
foreach ( $cases as $name => $case ) {
    $got = $title($case[0]);
    $assert($case[1] === $got, $name, "expected '{$case[1]}', got '{$got}'");
}

if ( $failures > 0 ) {
    fwrite(STDERR, "Heading title block spacing failed ({$failures})\n");
    exit(1);
}
fwrite(STDOUT, "Heading title block spacing passed\n");
