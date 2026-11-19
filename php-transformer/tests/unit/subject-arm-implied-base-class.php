<?php
declare(strict_types=1);

/**
 * A context-free subject arm addresses its carriers by role and subject
 * classes. Every carrier with a subject tag or class also carries the subject
 * base class, so an arm that names a subject tag or class does not restate
 * the base: it selects the same carriers, at the same (zero) weight, in fewer
 * bytes. Only a universal subject, which has no other subject class, keeps
 * the base. The carriers themselves still carry the base class, which the
 * runtime that replays source class tokens relies on.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$css = static function (array $result): string {
    $out = '';
    foreach ( $result['assets'] ?? array() as $asset ) {
        if ( 'author-css' === ($asset['source'] ?? '') ) {
            $out .= (string) ($asset['content'] ?? '');
        }
    }
    return $out;
};

$result = ( new HtmlTransformer() )->transform(
    '<style>.lead{letter-spacing:.2em}*{margin:0}</style>'
    . '<p>Intro <span class="lead">Apex</span> <span class="lead">Atelier</span></p>'
)->toArray();
$stylesheet = $css($result);
$markup = (string) ($result['serialized_blocks'] ?? '');

$failures = array();
if ( 1 !== preg_match('/:where\(\.blocks-engine-role-richtext\.blocks-engine-subject-class-[a-f0-9]{12}\)/', $stylesheet) ) {
    $failures[] = 'a class subject is addressed by its role and subject class alone';
}
if ( 1 === preg_match('/\.blocks-engine-subject\.blocks-engine-subject-(?:class|tag)-/', $stylesheet) ) {
    $failures[] = 'no arm restates the subject base beside a subject tag or class';
}
if ( ! str_contains($stylesheet, ':where(.blocks-engine-role-richtext.blocks-engine-subject)') ) {
    $failures[] = 'a universal subject keeps the subject base';
}
if ( 1 !== preg_match('/class="[^"]*\bblocks-engine-subject\b[^"]*\bblocks-engine-subject-class-[a-f0-9]{12}\b/', $markup) ) {
    $failures[] = 'carriers keep the subject base beside their subject classes';
}

if ( array() !== $failures ) {
    fwrite(STDERR, 'FAIL: ' . implode('; ', $failures) . PHP_EOL . $stylesheet . PHP_EOL);
    exit(1);
}
echo "Subject arms name the subject base only where nothing else implies it\n";
