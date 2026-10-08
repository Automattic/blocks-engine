<?php
declare(strict_types=1);

/**
 * Author rules that name an image by class or id follow it onto its figure.
 *
 * Plain-PHP test script — no PHPUnit. core/image saves the source image's
 * classes and id on the <figure> it renders, never on the <img>. An author
 * rule whose subject is `img.pic` or `img#photo` therefore matched nothing
 * once the image became a block: emitted verbatim, it was dead, and the box
 * it drew (a float with a margin, say) was gone. The projected rule has to
 * target the figure, which the image bridge already treats as the box the
 * <img> fills.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
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

$html = '<!doctype html><html><head><meta charset="utf-8"><title>Figure fixture</title></head><body>'
    . '<div id="hero"><img class="pic" src="/p.png" width="250" height="200" alt="Pic"><h2>Heading</h2><p>Copy beside the picture.</p></div>'
    . '<div id="linked"><a href="/full"><img class="pic" src="/l.png" width="250" height="200" alt="Linked"></a><p>More copy.</p></div>'
    . '<div id="pair"><img class="pic other" src="/o.png" width="100" height="80" alt="Other"><p>Pair copy.</p></div>'
    . '<div id="named"><img class="pic" id="photo" src="/q.png" width="120" height="90" alt="Named"><p>Named copy.</p></div>'
    . '</body></html>';
$css = '#hero { height: 245px; overflow: hidden; }'
    . ' #hero img.pic { float: left; width: 250px; height: 200px; border: 1px solid #ccc; margin: 2.3em; padding: 3px; background: #eee; }'
    . ' #linked img.pic { float: right; margin: 1em; }'
    . ' #linked a > img.pic { opacity: .8; }'
    . ' #pair img.pic, #pair img.other { display: inline-block; margin-bottom: 5px; }'
    . ' #named img#photo { float: left; margin-right: 12px; }'
    . ' .pic { opacity: .9; }';

$result = ( new HtmlTransformer() )->transform($html, array( 'static_css' => $css ))->toArray();
$assert('success' === ($result['status'] ?? null), 'the fixture transforms', json_encode($result['status'] ?? null));

$markup = (string) ( $result['serialized_blocks'] ?? '' );
$assert(4 === preg_match_all('/<figure[^>]*class="[^"]*\bwp-block-image\b/', $markup), 'every source image becomes a core/image figure', substr($markup, 0, 300));
$assert(! preg_match('/<img[^>]*class="[^"]*\bpic\b/', $markup), 'the <img> inside the figure does not carry the source class');
$assert(1 === preg_match('/<figure[^>]*class="[^"]*\bpic\b[^"]*"[^>]*>\s*<img/', $markup), 'the figure carries the source class');
$assert(str_contains($markup, '<figure id="photo"'), 'the source image id becomes the figure anchor');

// Front-end author CSS only: the editor static-state sheet is a separate,
// id-anchored copy that is not under test here.
$frontEnd = '';
foreach ( $result['assets'] ?? array() as $asset ) {
    $path = (string) ( $asset['path'] ?? '' );
    if ( is_string($asset['content'] ?? null) && 'css' === ($asset['kind'] ?? '') && ! str_contains($path, 'editor-static-state') ) {
        $frontEnd .= "\n" . $asset['content'];
    }
}
$assert('' !== $frontEnd, 'a front-end author stylesheet is emitted');

/** Selectors (one per entry of a rule's selector list) matching $selectorPattern whose rule body contains every declaration. */
$rules = static function (string $css, string $selectorPattern, array $declarations): array {
    $found = array();
    if ( preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $matches, PREG_SET_ORDER) ) {
        foreach ( $matches as $match ) {
            $body = (string) preg_replace('/\s+/', '', $match[2]);
            foreach ( $declarations as $declaration ) {
                if ( ! str_contains($body, $declaration) ) {
                    continue 2;
                }
            }
            foreach ( CssStylesheetTransformer::splitSelectorList(trim($match[1])) ?? array( trim($match[1]) ) as $selector ) {
                $selector = trim($selector);
                if ( preg_match($selectorPattern, $selector) ) {
                    $found[] = $selector;
                }
            }
        }
    }
    return $found;
};

// 1. No rule keeps a dead `img.pic` / `img#photo` subject.
$dead = $rules($frontEnd, '/img\.(?:pic|other)\b|img#photo\b/', array());
$assert(array() === $dead, 'no emitted rule keeps an img.class or img#id subject the markup cannot match', implode(' | ', $dead));

// 2. The float, the margin and the box reach the figure that carries the class…
$figure = '/^#hero\s+:where\(figure\)[^{,]*\.pic\.wp-block-image(?::not\([^)]*\))*$/';
$assert(array() !== $rules($frontEnd, $figure, array( 'float:left', 'width:250px', 'height:200px', 'border:1pxsolid#ccc', 'padding:3px', 'background:#eee' )), 'the box and paint of the class rule land on the figure that carries the class', $frontEnd);
$assert(array() !== $rules($frontEnd, $figure, array( 'margin:2.3em' )), 'the margin of the class rule lands on the figure');
// …and the <img> inside keeps filling that box through the existing bridge.
$assert(array() !== $rules($frontEnd, '/^#hero\s+:where\(figure\)[^{,]*\.pic\.wp-block-image\s*>\s*img/', array( 'display:block', 'width:100%', 'height:100%' )), 'the image bridge still makes the <img> fill the figure box');

// 3. A linked image: the figure now holds the link, so the rule names the figure the same way.
$assert(array() !== $rules($frontEnd, '/^#linked\s+:where\(figure\)[^{,]*\.pic\.wp-block-image/', array( 'float:right' )), 'a class rule on a linked image reaches its figure');
$assert(array() !== $rules($frontEnd, '/^#linked\s+:where\(figure\)[^{,]*\.pic\.wp-block-image/', array( 'margin:1em' )), 'the margin of a linked image rule reaches its figure');
// A bare `a >` right before the subject named the link the figure absorbed; it is dropped.
$assert(array() !== $rules($frontEnd, '/^#linked\s+:where\(figure\)[^{,]*\.pic\.wp-block-image/', array( 'opacity:.8' )), 'a bare link compound above the subject is dropped once the figure absorbs the link');

// 4. A selector list is handled per selector.
$assert(array() !== $rules($frontEnd, '/^#pair\s+:where\(figure\)\S*\.pic\.wp-block-image$/', array( 'display:inline-block' ))
    && array() !== $rules($frontEnd, '/^#pair\s+:where\(figure\)\S*\.other\.wp-block-image$/', array( 'display:inline-block' )), 'each selector of a list follows its class to the figure', implode(' | ', $rules($frontEnd, '/#pair/', array())));

// 5. An id on the image becomes the figure anchor, so `img#photo` follows it too.
$assert(array() !== $rules($frontEnd, '/^#named\s+:where\(figure\)[^{,]*#photo[^{,]*\.wp-block-image/', array( 'float:left' )), 'an id-qualified image subject follows the anchor to the figure', implode(' | ', $rules($frontEnd, '/#named/', array())));

// 6. A class-only subject already matched the figure; it must keep doing so.
$assert(array() !== $rules($frontEnd, '/\.pic\b/', array( 'opacity:.9' )), 'a class-only image rule still reaches the figure');

// 7. Descendant and child link compounds, with and without a state suffix,
//    become one `:has(> a)` condition glued to the figure compound; an image
//    that stays an inline <img> (inside a paragraph) keeps the authored subject
//    next to the figure form; a link that carries an id keeps its own marker route.
$mixed = ( new HtmlTransformer() )->transform(
    '<div id="linked"><a href="/full"><img class="pic" src="/l.png" width="250" height="200" alt="L"></a><p>More.</p></div>'
    . '<p>Inline <img class="pic" src="/i.png" width="16" height="16" alt="i"> text.</p>'
    . '<a id="thumb" href="/print"><img class="pic" src="/t.png" width="90" height="60" alt="T"></a>',
    array( 'static_css' => 'a img.pic { border: 0; } #linked a > img.pic:hover { opacity: .8; } img.pic { max-width: 100%; height: auto; } a#thumb > img.pic { border-radius: 12px; }' )
)->toArray();
$mixedCss = '';
foreach ( $mixed['assets'] ?? array() as $asset ) {
    if ( 'css' === ($asset['kind'] ?? '') && ! str_contains((string) ($asset['path'] ?? ''), 'editor-static-state') ) {
        $mixedCss .= "\n" . (string) ($asset['content'] ?? '');
    }
}
$assert(array() !== $rules($mixedCss, '/^:where\(figure\)\S*\.pic\.wp-block-image:has\(> a\)$/', array( 'border:0' )), 'a descendant link compound becomes :has(> a) on the figure, with no space before it', implode(' | ', $rules($mixedCss, '/has/', array())));
$assert(array() !== $rules($mixedCss, '/^#linked\s+:where\(figure\)\S*\.pic\.wp-block-image:has\(> a\):hover$/', array( 'opacity:.8' )), 'a child link compound with a state suffix keeps the suffix after :has(> a)', implode(' | ', $rules($mixedCss, '/hover/', array())));
$assert(array() !== $rules($mixedCss, '/^img\.pic(?::not\(.*\))?$/', array( 'max-width:100%' )), 'an image that stayed an inline <img> keeps the authored subject in the list', implode(' | ', $rules($mixedCss, '/img\.pic/', array())));
$assert(array() !== $rules($mixedCss, '/^:where\(figure\)\S*\.pic\.wp-block-image$/', array( 'max-width:100%' )), 'the same rule also reaches the figures of the lowered images', implode(' | ', $rules($mixedCss, '/wp-block-image/', array( 'max-width:100%' ))));
$assert(array() !== $rules($mixedCss, '/^:where\(\.blocks-engine-semantic-[a-z0-9-]+\)/', array( 'border-radius:12px' )) && array() === $rules($mixedCss, '/a#thumb\s*>\s*:where\(figure\)/', array()), 'an image behind an id-carrying link keeps its semantic marker route', implode(' | ', $rules($mixedCss, '/border-radius/', array())));

// 8. A type-only subject keeps matching the <img> inside the figure, as before.
$plain = ( new HtmlTransformer() )->transform(
    '<div id="head"><img src="/logo.png" width="243" height="56" alt="Logo"><p>Nav</p></div>',
    array( 'static_css' => '#head img { float: left; }' )
)->toArray();
$plainCss = implode("\n", array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), $plain['assets'] ?? array()));
$assert(array() !== $rules($plainCss, '/^#head\s+img$/', array( 'float:left' )), 'a type-only image rule is left on the <img>');

if ( $failures > 0 ) {
    fwrite(STDERR, "image class figure carrier tests: {$failures} failed, {$passes} passed\n");
    exit(1);
}
fwrite(STDOUT, "image class figure carrier tests: {$passes} passed\n");
