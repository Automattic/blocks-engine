<?php
declare(strict_types=1);

/**
 * A navigation link authored as a filled button keeps its box on the anchor.
 *
 * core/navigation-link renders the block's className on its `<li>`, where
 * core's own `.wp-block-navigation .wp-block-navigation-item` background rule
 * outranks the utility classes, and the rendered
 * `.wp-block-navigation-item__content` anchor — the element the source styled
 * — receives neither the fill nor the padding. The source anchor's resolved
 * box is therefore carried to the rendered anchor through a deterministic
 * marker class and an engine support rule, and the carried padding sides are
 * reset on the item so the box is not painted twice.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

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

$transform = static function (string $html): array {
    return ( new HtmlTransformer() )->transform($html, array())->toArray();
};

$assetsCss = static function (array $result): string {
    return implode("\n", array_map(
        static fn (array $asset): string => (string) ($asset['content'] ?? ''),
        is_array($result['assets'] ?? null) ? $result['assets'] : array()
    ));
};

$navigationLinkComment = static function (string $serialized, string $url): ?array {
    if ( ! preg_match_all('/<!--\s*wp:navigation-link\s*(\{.*?\})\s*\/-->/s', $serialized, $matches, PREG_SET_ORDER) ) {
        return null;
    }
    foreach ( $matches as $found ) {
        $attrs = json_decode($found[1], true);
        if ( is_array($attrs) && ( $attrs['url'] ?? '' ) === $url ) {
            return $attrs;
        }
    }

    return null;
};

$markerFor = static function (?array $attrs): string {
    foreach ( preg_split('/\s+/', trim((string) ( $attrs['className'] ?? '' ))) ?: array() as $class ) {
        if ( 1 === preg_match('/^blocks-engine-navigation-link-box-[0-9a-f]{64}$/', $class) ) {
            return $class;
        }
    }

    return '';
};

$utilities = '<style>'
    . ':root{--color-primary:#c2410c;--color-primary-foreground:#ffffff}'
    . '.bg-primary{background-color:var(--color-primary)}'
    . '.text-primary-foreground{color:var(--color-primary-foreground)}'
    . '.px-5{padding-left:1.25rem;padding-right:1.25rem}'
    . '.py-2\.5{padding-top:0.625rem;padding-bottom:0.625rem}'
    . '</style>';

$result = $transform(
    $utilities
    . '<nav class="flex items-center gap-8" aria-label="Main">'
    . '<a href="/">Home</a>'
    . '<a href="https://example.com/" class="bg-primary text-primary-foreground px-5 py-2.5"><span>Subscribe</span></a>'
    . '</nav>'
    . '<main><p>Body</p></main>'
);

$serialized = (string) ( $result['serialized_blocks'] ?? '' );
$ctaAttrs = $navigationLinkComment($serialized, 'https://example.com/');
$ctaMarker = $markerFor($ctaAttrs);
$assert(
    '' !== $ctaMarker,
    'the button-styled navigation link carries a deterministic box marker',
    $ctaAttrs === null ? 'navigation-link for https://example.com/ not found in: ' . $serialized : (string) ( $ctaAttrs['className'] ?? '' )
);

$css = $assetsCss($result);
$contentRule = '';
if ( '' !== $ctaMarker ) {
    $selector = '.wp-block-navigation-item.' . $ctaMarker . '>.wp-block-navigation-item__content';
    if ( preg_match('/' . preg_quote($selector, '/') . '\{([^}]*)\}/', $css, $ruleMatch) ) {
        $contentRule = $ruleMatch[0];
    }
}
$assert(
    '' !== $contentRule,
    'the carried box lands on the rendered anchor core generates',
    $css
);
$assert(
    str_contains($contentRule, 'background-color:') && ( str_contains($contentRule, 'var(--color-primary)') || str_contains($contentRule, '#c2410c') ),
    'the carried fill matches the source background color',
    $contentRule
);
$assert(
    str_contains($contentRule, 'padding-left:1.25rem')
    && str_contains($contentRule, 'padding-right:1.25rem')
    && str_contains($contentRule, 'padding-top:0.625rem')
    && str_contains($contentRule, 'padding-bottom:0.625rem'),
    'the carried padding matches the source box',
    $contentRule
);

$itemRule = '';
if ( '' !== $ctaMarker ) {
    if ( preg_match('/\.wp-block-navigation \.wp-block-navigation-item\.' . preg_quote($ctaMarker, '/') . '\{([^}]*)\}/', $css, $ruleMatch) ) {
        $itemRule = $ruleMatch[0];
    }
}
$assert(
    '' !== $itemRule
        && str_contains($itemRule, 'padding-left:0')
        && str_contains($itemRule, 'padding-right:0')
        && str_contains($itemRule, 'padding-top:0')
        && str_contains($itemRule, 'padding-bottom:0'),
    'the padding sides carried to the anchor are reset on the item so the box is not doubled',
    $itemRule === '' ? 'no item reset rule found in: ' . $css : $itemRule
);

$homeAttrs = null;
if ( preg_match_all('/<!--\s*wp:navigation-link\s*(\{.*?\})\s*\/-->/s', $serialized, $matches, PREG_SET_ORDER) ) {
    foreach ( $matches as $found ) {
        $attrs = json_decode($found[1], true);
        if ( is_array($attrs) && ( $attrs['url'] ?? '' ) === '/' ) {
            $homeAttrs = $attrs;
            break;
        }
    }
}
$assert(
    is_array($homeAttrs) && '' === $markerFor($homeAttrs),
    'a plain navigation link carries no box marker',
    is_array($homeAttrs) ? (string) ( $homeAttrs['className'] ?? '' ) : 'navigation-link for / not found'
);

// A source list item that owns its own padding keeps it: only the sides the
// anchor's box carried are reset, and to the item's own resolved winner.
$listResult = $transform(
    $utilities
    . '<style>.padded-item{padding-bottom:14px}</style>'
    . '<nav aria-label="Footer"><ul>'
    . '<li class="padded-item"><a href="https://example.org/" class="bg-primary px-5">Join</a></li>'
    . '</ul></nav>'
);
$listSerialized = (string) ( $listResult['serialized_blocks'] ?? '' );
$listMarker = $markerFor($navigationLinkComment($listSerialized, 'https://example.org/'));
$assert(
    '' !== $listMarker,
    'a button-styled link inside a source list item still carries its box',
    $listSerialized
);
$listCss = $assetsCss($listResult);
$listItemRule = '';
if ( '' !== $listMarker && preg_match('/\.wp-block-navigation \.wp-block-navigation-item\.' . preg_quote($listMarker, '/') . '\{([^}]*)\}/', $listCss, $ruleMatch) ) {
    $listItemRule = $ruleMatch[0];
}
$assert(
    '' !== $listItemRule
        && str_contains($listItemRule, 'padding-left:0')
        && str_contains($listItemRule, 'padding-right:0')
        && ! str_contains($listItemRule, 'padding-bottom'),
    'the item reset only touches the padding sides the anchor\'s box carried, leaving the source list item\'s own padding in place',
    $listItemRule === '' ? 'no item reset rule found in: ' . $listCss : $listItemRule
);

// A navigation link with no authored box is left untouched: no marker, no rule.
$plainResult = $transform(
    '<nav class="flex" aria-label="Main"><a href="/">Home</a><a href="/about">About</a></nav>'
);
$plainSerialized = (string) ( $plainResult['serialized_blocks'] ?? '' );
$plainMarker = $markerFor($navigationLinkComment($plainSerialized, '/about'));
$assert(
    '' === $plainMarker,
    'a navigation link without box styling carries no marker',
    $plainSerialized
);
$plainCss = $assetsCss($plainResult);
$assert(
    ! str_contains($plainCss, 'blocks-engine-navigation-link-box-'),
    'no box rule is emitted when no navigation link carries a box',
    $plainCss
);

if ( 0 < $failures ) {
    fwrite(STDERR, "navigation link box carry FAILED: {$passes} passed, {$failures} failed\n");
    exit(1);
}

fwrite(STDOUT, "navigation link box carry passed: {$passes} assertions\n");
