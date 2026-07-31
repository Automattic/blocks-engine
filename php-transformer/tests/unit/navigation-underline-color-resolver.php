<?php
declare(strict_types=1);

/**
 * Unit tests for active navigation underline color resolution precedence.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\NavigationUnderlineColorResolver;

$failures = 0;
$passes   = 0;

$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }

    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ('' !== $detail ? ' - ' . $detail : '') . PHP_EOL);
};

$document = new DOMDocument();
$previousLibxmlState = libxml_use_internal_errors(true);
$document->loadHTML('<!doctype html><html><body><nav><ul><li class="current"><a class="active" href="/">Home</a></li></ul></nav></body></html>');
libxml_clear_errors();
libxml_use_internal_errors($previousLibxmlState);
$item = $document->getElementsByTagName('li')->item(0);
$anchor = $document->getElementsByTagName('a')->item(0);

if ( ! $item instanceof DOMElement || ! $anchor instanceof DOMElement ) {
    fwrite(STDERR, 'FAIL: test DOM did not initialize' . PHP_EOL);
    exit(1);
}

$resolver = new NavigationUnderlineColorResolver();

$assert(
    '#123456' === $resolver->resolve(
        $item,
        $anchor,
        static fn (DOMElement $element): array => $element->tagName === 'a' ? array( 'text-decoration-color' => '#123456', 'color' => '#abcdef' ) : array( 'border-color' => '#654321' ),
        array(),
        static fn (): bool => false
    ),
    '1: explicit anchor text-decoration-color wins before item border color'
);

$assert(
    'rgba(1, 2, 3, .4)' === $resolver->resolve(
        $item,
        $anchor,
        static fn (): array => array(),
        array(
            array(
                'selector'     => '.active::after',
                'pseudo'       => 'after',
                'declarations' => array( 'background-color' => 'rgba(1, 2, 3, .4)' ),
            ),
        ),
        static fn (DOMElement $element, string $selector): bool => $element->tagName === 'a' && '.active::after' === $selector
    ),
    '2: matching pseudo-element background-color is used when direct decoration colors are absent'
);

$assert(
    'currentColor' === $resolver->resolve(
        $item,
        $anchor,
        static fn (DOMElement $element): array => $element->tagName === 'a' ? array( 'color' => 'currentcolor' ) : array(),
        array(
            array(
                'selector'     => '.active::after',
                'pseudo'       => 'after',
                'declarations' => array( 'background-color' => 'transparent' ),
            ),
        ),
        static fn (): bool => true
    ),
    '3: transparent pseudo color is ignored and text color fallback normalizes currentcolor'
);

$assert(
    '' === $resolver->resolve(
        $item,
        $anchor,
        static fn (): array => array( 'color' => 'rgba(1,' ),
        array(),
        static fn (): bool => false
    ),
    '4: truncated functional color values are rejected'
);

$assert(
    '' === $resolver->resolve(
        $item,
        $anchor,
        static fn (): array => array( 'color' => '#111111' ),
        array(),
        static fn (): bool => false
    ),
    '5: ordinary text color does not invent an active underline without a decoration signal'
);

if ( $failures > 0 ) {
    fwrite(STDERR, "Navigation underline color resolver tests: {$failures} failed, {$passes} passed\n");
    exit(1);
}

fwrite(STDOUT, "Navigation underline color resolver tests: {$passes} passed\n");
