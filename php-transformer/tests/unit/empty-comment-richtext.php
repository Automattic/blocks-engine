<?php
declare(strict_types=1);

/** Empty comments have no RichText content or text-run carrier. */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\CommentTextRunBoundaries;

$failures = 0;
$passes = 0;
$assert = static function (bool $ok, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $ok ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ( '' !== $detail ? ' - ' . $detail : '' ) . PHP_EOL);
};

// Reduced fixture from https://github.com/Automattic/blocks-engine/issues/3174.
$html = <<<'HTML'
<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Comment Probe</title>
<style>body{font-family:sans-serif;padding:32px}.btn{display:inline-block;padding:12px 20px;border:1px solid #333;border-radius:6px}</style></head>
<body><h1>Call us</h1><p>Phone: <!---->612-500-6917</p><a href="tel:6125006917"><button class="btn">Call <!---->612-500-6917</button></a></body></html>
HTML;
$result = ( new HtmlTransformer() )->transform($html)->toArray();
$serialized = (string) ($result['serialized_blocks'] ?? '');
$find = static function (array $blocks, string $name) use (&$find): ?array {
    foreach ( $blocks as $block ) {
        if ( $name === ($block['blockName'] ?? '') ) {
            return $block;
        }
        $found = $find($block['innerBlocks'] ?? array(), $name);
        if ( null !== $found ) {
            return $found;
        }
    }
    return null;
};
$button = $find($result['blocks'] ?? array(), 'core/button');
$paragraph = $find($result['blocks'] ?? array(), 'core/paragraph');
$assert('Call 612-500-6917' === ($button['attrs']['text'] ?? null), 'button RichText is plain text', (string) ($button['attrs']['text'] ?? 'missing'));
$assert('tel:6125006917' === ($button['attrs']['url'] ?? null), 'button retains its telephone link');
$assert('Phone: 612-500-6917' === ($paragraph['attrs']['content'] ?? null), 'paragraph has no comment carrier', (string) ($paragraph['attrs']['content'] ?? 'missing'));
$assert(str_contains($serialized, '>Call 612-500-6917</a>'), 'saved button has no comment', $serialized);
$assert(str_contains($serialized, '<p>Phone: 612-500-6917</p>'), 'saved paragraph has no carrier', $serialized);

foreach ( array(
    'A<!----><!---->B' => 'AB',
    '<!---->A<!---->' => 'A',
    'A<strong>B<!---->C</strong>D' => 'A<strong>BC</strong>D',
    'A<!---->B<!-- -->C' => 'AB<mark style="background-color:transparent;color:inherit" role="none">C</mark>',
) as $source => $expected ) {
    $actual = CommentTextRunBoundaries::apply($source);
    $assert($expected === $actual, 'empty comments do not create boundaries: ' . $source, $actual);
}

if ( 0 < $failures ) {
    fwrite(STDERR, "empty comment RichText FAILED: {$passes} passed, {$failures} failed\n");
    exit(1);
}
echo "empty comment RichText passed: {$passes} assertions\n";
