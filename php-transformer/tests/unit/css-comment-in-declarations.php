<?php
declare(strict_types=1);

/**
 * Comments inside declaration blocks survive projection balanced or not at all.
 *
 * Plain-PHP test script — no PHPUnit. A stylesheet may carry `/* … *\/` inside a
 * declaration block (a disabled declaration is the common case). Projection
 * re-serializes some rule bodies from a property map; when the splitter that
 * builds the map does not know about comments, the `;` inside the comment ends
 * the "declaration" `/*border: 5px solid red`, the `*\/` remainder is dropped,
 * and the emitted sheet comments out every rule up to the next `*\/`. The
 * checks below run the whole transformer and read every emitted CSS string.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$passes = 0;

$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ( '' !== $detail ? ' - ' . $detail : '' ) . PHP_EOL);
};

$css = <<<'CSS'
/* leading block comment */
#box { width: 900px; margin: 1em auto; background-color: white; padding: 30px; /*border: 5px solid red;*/ }
#left, #right { float: left; width: 257px; margin-right: 4em; /* note: {braces} ; colons: and * stars */ }
#hero { /* first */ background: #96b79f /* url(images/bg.png) */; margin-bottom: 10px; /* last */ }
@media screen and (min-width: 600px) {
  #hero { margin-top: 20px; /*color: red;*/ padding: 10px; }
}
#after { color: #333; margin: 0 /* ; */ }
/* trailing block comment */
CSS;

$html = <<<'HTML'
<!doctype html><html><head><meta charset="utf-8"><title>Comment fixture</title></head>
<body><div id="box"><h1>Title</h1><div id="hero"><p>Hero text for the fixture page.</p></div>
<div id="left"><h2>Left</h2><p>Left column text.</p></div><div id="right"><h2>Right</h2><p>Right column text.</p></div>
<p id="after" style="color: #111; /* margin: 0; */ padding: 4px">After text.</p></div></body></html>
HTML;

$result = ( new HtmlTransformer() )->transform($html, array( 'static_css' => $css ))->toArray();
$assert('success' === ($result['status'] ?? null), 'the fixture transforms', json_encode($result['status'] ?? null));

/** Every string in the result that looks like CSS with a comment, keyed by its path. */
$emitted = array();
$walk = static function ($value, string $path) use (&$walk, &$emitted): void {
    if ( is_array($value) ) {
        foreach ( $value as $key => $item ) {
            $walk($item, $path . '/' . $key);
        }
        return;
    }
    if ( is_string($value) && ( str_contains($value, '/*') || str_contains($value, '*/') ) ) {
        $emitted[ $path ] = $value;
    }
};
$walk($result, '');

// 1. No emitted string opens a comment it does not close (or closes one it never opened).
$assert(array() !== $emitted, 'the fixture emits at least one stylesheet carrying a comment');
foreach ( $emitted as $path => $value ) {
    $open = substr_count($value, '/*');
    $close = substr_count($value, '*/');
    $assert($open === $close, "comment tokens are balanced in {$path}", "open={$open} close={$close}");
}

// 2. The declarations after a comment, and the rules after the rule, are still live CSS.
//    A browser drops everything inside `/* … */`, so read the sheet the way it will.
$live = static fn (string $value): string => (string) preg_replace('~/\*.*?\*/~s', '', $value);
$assets = array();
foreach ( $result['assets'] ?? array() as $asset ) {
    if ( is_string($asset['content'] ?? null) && str_contains($asset['content'], '#box') ) {
        $assets[] = $live($asset['content']);
    }
}
$assert(array() !== $assets, 'the author stylesheet is emitted as an asset');
foreach ( $assets as $index => $content ) {
    $compact = (string) preg_replace('/\s+/', '', $content);
    $assert(str_contains($compact, 'width:900px'), "asset {$index}: the box width survives next to the disabled border");
    $assert(str_contains($compact, 'margin:1emauto'), "asset {$index}: the box margin is still emitted");
    $assert(str_contains($compact, 'float:left'), "asset {$index}: the column float after the comment-bearing rule survives");
    $assert(str_contains($compact, 'background:#96b79f'), "asset {$index}: a value followed by a comment keeps its declaration");
    $assert(str_contains($compact, 'margin-bottom:10px'), "asset {$index}: a declaration after a comment in the middle of the block survives");
    $assert(str_contains($compact, 'padding:10px'), "asset {$index}: a declaration after a disabled one inside @media survives");
    $assert(str_contains($compact, 'color:#333'), "asset {$index}: the rule after a `;` hidden in a comment survives");
    $assert(! str_contains($compact, 'border:5pxsolidred'), "asset {$index}: the disabled border is not re-enabled");
    $assert(! str_contains($compact, 'color:red'), "asset {$index}: the disabled @media color is not re-enabled");
    $assert(! preg_match('~[{;]\s*\*/~', $content) && ! str_contains($content, '/*'), "asset {$index}: no stray comment delimiter remains after comment removal");
}

// 3. An inline style with a comment keeps the declaration after it, and the
//    comment never reaches the block markup as a property or a remainder.
//    (Source provenance keeps the authored attribute verbatim; that is not
//    emitted CSS and is covered by the balance check above.)
$serialized = (string) ( $result['serialized_blocks'] ?? '' );
$assert(str_contains($serialized, '4px'), 'the inline padding after an inline comment reaches the block', substr($serialized, 0, 400));
$assert(! str_contains($serialized, '/*') && ! str_contains($serialized, '*/'), 'no comment delimiter reaches the block markup');

if ( $failures > 0 ) {
    fwrite(STDERR, "CSS comment-in-declarations tests: {$failures} failed, {$passes} passed\n");
    exit(1);
}
fwrite(STDOUT, "CSS comment-in-declarations tests: {$passes} passed\n");
