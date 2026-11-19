<?php
declare(strict_types=1);

/**
 * Context-free RichText arms omit the implied subject base when naming a
 * subject tag or class, without changing their zero specificity or carriers.
 * Universal subjects and other roles retain the established base contract.
 * The carriers keep the base for runtime source-class replay.
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

// Public transform output must keep semantic geometry selectors compatible
// with the positioned-inline parity contract, not just preserve the pixels.
$semantic = $css(( new HtmlTransformer() )->transform(
    '<style>.photo{position:relative}.badge{position:absolute;top:.75rem;left:.75rem;z-index:2}</style>'
    . '<div class="photo"><img src="/photo.jpg" alt="Photo"><span class="badge"><em>New</em></span></div>'
)->toArray());
if ( ! str_contains($semantic, ':where(.blocks-engine-role-semantic.blocks-engine-subject.blocks-engine-subject-class-') ) {
    $failures[] = 'semantic geometry arms retain the subject-base selector contract';
}

if ( array() !== $failures ) {
    fwrite(STDERR, 'FAIL: ' . implode('; ', $failures) . PHP_EOL . $stylesheet . PHP_EOL);
    exit(1);
}
echo "RichText arms omit the implied base; semantic arms preserve their contract\n";
