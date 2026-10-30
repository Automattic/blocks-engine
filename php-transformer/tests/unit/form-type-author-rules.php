<?php
declare(strict_types=1);

/**
 * A form provider renders the readable form tree inside its own container, and
 * the block editor renders no <form> at all. Author rules whose selectors name
 * the form type (`form .half{float:left}`) must therefore address the block
 * converted from the source <form> through its source marker, or the field
 * layout they describe is lost wherever the element is not a <form>.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$source = '<style>form .half{float:left;width:50%}</style><main><form>'
    . '<div class="half"><label for="a">A</label><input id="a" name="a"></div>'
    . '<button type="submit">Send</button></form></main>';
$compiled = ( new ArtifactCompiler() )->compile(array( 'entrypoint' => 'contact.html', 'files' => array( 'contact.html' => $source ) ))->toArray();

$root = $compiled['fallbacks'][0]['readable_blocks'][0] ?? array();
$marker = 1 === preg_match('/\b(blocks-engine-source-form-[a-z0-9-]+)\b/', (string) ( $root['attrs']['className'] ?? '' ), $match) ? $match[1] : '';
$css = implode("\n", array_map(static fn (array $asset): string => (string) $asset['content'], array_filter($compiled['assets'] ?? array(), static fn (array $asset): bool => 'css' === ( $asset['kind'] ?? null ))));
preg_match('/[^{}]*\.half\{[^}]*\}/', $css, $rule);

$failures = array();
if ( '' === $marker ) {
    $failures[] = 'the readable form root carries no source form marker: ' . json_encode($root['attrs'] ?? null);
}
if ( '' === $marker || ! str_contains($rule[0] ?? '', '.' . $marker) || 1 === preg_match('/(^|[\s,>+~])form[\s.:#\[>]/', $rule[0] ?? '') ) {
    $failures[] = 'the form-type rule does not address the source form marker: ' . ( $rule[0] ?? '(no rule)' );
}

if ( array() !== $failures ) {
    fwrite(STDERR, 'FAIL: ' . implode(PHP_EOL . 'FAIL: ', $failures) . PHP_EOL);
    exit(1);
}
echo 'PASS: form-type author rules address the source form marker' . PHP_EOL;
