<?php
declare(strict_types=1);

/**
 * The editor restatement of an authored child combinator must tolerate any
 * editor-only inner-blocks list, not only Blocks Engine's own layout-shell
 * layer (Automattic/blocks-engine#3171).
 *
 * Gutenberg marks every inner-blocks list `block-editor-block-list__layout`.
 * When that list is not itself a block wrapper (Jetpack's contact form, for
 * one, renders `div.jetpack-contact-form.block-editor-block-list__layout`
 * inside its block wrapper) it sits between the authored parent and its
 * children only in the editor, so `.stack > * + *` stopped spacing the fields.
 * A block whose wrapper is the list (`.block-editor-block-list__layout
 * .block-editor-block-list__block`) has no extra layer, and the restatement
 * must not reach through it to a grandchild.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\EditorShellBoundary;

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

/** Split at top-level commas (commas inside `:where()`/`:not()` stay). */
$splitList = static function (string $list): array {
    $parts = array();
    $depth = 0;
    $current = '';
    foreach ( str_split($list) as $character ) {
        if ( '(' === $character ) {
            ++$depth;
        } elseif ( ')' === $character ) {
            --$depth;
        } elseif ( ',' === $character && 0 === $depth ) {
            $parts[] = trim($current);
            $current = '';
            continue;
        }
        $current .= $character;
    }
    $parts[] = trim($current);

    return $parts;
};

/**
 * Flatten `prelude{&>a,&>L>a{…{decl}}}` into the plain selectors the nested
 * rule stands for, by substituting each `&` with every parent alternative.
 *
 * @return list<string>
 */
$flatten = static function (array $rule) use ($splitList): array {
    $selectors = array( $rule['prelude'] );
    $body = $rule['body'];
    while ( false !== ( $open = strpos($body, '{') ) && str_contains(substr($body, 0, $open), '&') ) {
        $next = array();
        foreach ( $selectors as $parent ) {
            foreach ( $splitList(substr($body, 0, $open)) as $arm ) {
                $next[] = str_replace('&', $parent, $arm);
            }
        }
        $selectors = $next;
        $body = substr($body, $open + 1);
    }

    return $selectors;
};

$rule = EditorShellBoundary::rule('.stack > * + *', 'margin-top:.75rem');
$assert(null !== $rule, 'an authored child combinator gets an editor restatement');
$css = EditorShellBoundary::css($rule);
$assert(
    str_contains($css, ':where(.blocks-engine-layout-shell-editor-inner-blocks,.block-editor-block-list__layout:not(.block-editor-block-list__block))>'),
    'the restated step names both the layout-shell layer and a non-block inner-blocks list',
    $css
);
$selectors = $flatten((array) $rule);
$assert(2 === count($selectors), 'one boundary stands for two placements', implode(' | ', $selectors));

$matches = static function (DOMElement $element) use ($selectors): bool {
    foreach ( $selectors as $selector ) {
        $parsed = CssSelectorMatcher::parse($selector);
        $result = CssSelectorMatcher::matches($element, $parsed);
        if ( $result['supported'] && $result['matches'] ) {
            return true;
        }
    }

    return false;
};
$supported = static function () use ($selectors): bool {
    foreach ( $selectors as $selector ) {
        if ( ! ( CssSelectorMatcher::parse($selector)['supported'] ?? false ) ) {
            return false;
        }
    }

    return true;
};
$assert($supported(), 'the selector matcher evaluates every restated arm', implode(' | ', $selectors));

$document = new DOMDocument();
$document->loadHTML(
    '<!DOCTYPE html><html><body><div class="editor-styles-wrapper">'
    // A form-like block: its wrapper carries `.stack`, its edit renders a list layer.
    . '<div class="stack wp-block block-editor-block-list__block" id="form">'
    . '<div class="jetpack-contact-form block-editor-block-list__layout">'
    . '<div class="block-editor-block-list__block" id="field-1"></div>'
    . '<div class="block-editor-block-list__block" id="field-2"></div>'
    . '</div></div>'
    // A container block whose wrapper is itself the list: its children are
    // grandchildren of `.stack` and must not be spaced.
    . '<div class="stack wp-block block-editor-block-list__block">'
    . '<div class="block-editor-block-list__layout block-editor-block-list__block">'
    . '<div class="block-editor-block-list__block" id="nested-1"></div>'
    . '<div class="block-editor-block-list__block" id="nested-2"></div>'
    . '</div></div>'
    . '</div></body></html>',
    LIBXML_NOERROR
);
$byId = static fn (string $id): DOMElement => $document->getElementById($id) ?? throw new RuntimeException('missing #' . $id);

$assert($matches($byId('field-2')), 'a field inside a non-block inner-blocks list under the .stack wrapper is spaced', implode(' | ', $selectors));
$assert(! $matches($byId('field-1')), 'the first field keeps `* + *` semantics and is not spaced');
$assert(! $matches($byId('nested-2')), 'the generic arm does not reach through a list that is itself a block wrapper');

// The same holds for Tailwind's `space-y-*` spelling.
$tailwind = $flatten((array) EditorShellBoundary::rule('.stack > :not([hidden]) ~ :not([hidden])', 'margin-top:.75rem'));
$tailwindMatches = static function (DOMElement $element) use ($tailwind): bool {
    foreach ( $tailwind as $selector ) {
        $result = CssSelectorMatcher::matches($element, CssSelectorMatcher::parse($selector));
        if ( $result['supported'] && $result['matches'] ) {
            return true;
        }
    }

    return false;
};
$assert($tailwindMatches($byId('field-2')), 'space-y spelling: a field inside a non-block list is spaced', implode(' | ', $tailwind));
$assert(! $tailwindMatches($byId('nested-2')), 'space-y spelling: a list that is a block wrapper is not a layer');

// Specificity stays tied with the authored selector.
foreach ( $selectors as $selector ) {
    $assert(
        CssSelectorMatcher::specificityCounts(CssSelectorMatcher::parse($selector)) === CssSelectorMatcher::specificityCounts(CssSelectorMatcher::parse('.stack > * + *')),
        'the restated arm weighs what the authored selector weighs',
        $selector
    );
}

if ( $failures > 0 ) {
    fwrite(STDERR, sprintf('Editor generic inner-blocks layer: %d failed, %d passed', $failures, $passes) . PHP_EOL);
    exit(1);
}

echo sprintf('Editor generic inner-blocks layer passed: %d assertions', $passes) . PHP_EOL;
