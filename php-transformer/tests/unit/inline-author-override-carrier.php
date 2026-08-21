<?php
declare(strict_types=1);

/**
 * Contract for inline declarations that exist in order to OVERRIDE author CSS,
 * and for inherited `text-align` on a container.
 *
 * StyleAttributeMapper's premise is that a declaration mapping to no block
 * support can be dropped because the preserved `className` plus the carried
 * author CSS keeps the same styling. That premise inverts when the inline
 * declaration exists to override the class rule: dropping it does not fall back
 * to the same styling, it falls back to the OPPOSITE styling. A `.badge` reused
 * for its paint and neutralised inline with `position:static` reverts to
 * `position:absolute`, leaves the flow, and paints over the heading below it.
 *
 * Three carriers are asserted here, all in the NON-important tier:
 *   - an inline declaration conflicting with a matching author rule,
 *   - an inline `display` differing from the tag default with no author rule,
 *   - `text-align` on a container, for the inherited case only.
 * Plus the flex-alignment rescue for `<p>`/`<ul>`, which can never reach
 * cssOwnedFlexAttributes() because that path is gated on
 * ShellLandmarkPolicy::isFlowContainerTag().
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
    fwrite(STDERR, 'FAIL: ' . $message . ('' !== $detail ? ' - ' . $detail : '') . PHP_EOL);
};

$cssFor = static function (array $result, string $source): string {
    return implode("\n", array_map(
        static fn (array $asset): string => (string) ($asset['content'] ?? ''),
        array_values(array_filter(
            is_array($result['assets'] ?? null) ? $result['assets'] : array(),
            static fn (array $asset): bool => $source === ($asset['source'] ?? '')
        ))
    ));
};

$transform = static fn (string $html): array => ( new HtmlTransformer() )->transform($html, array())->toArray();

/**
 * Every generated geometry carrier rule, tagged with its priority tier. The
 * non-important tier is emitted as `:root .x{...}`; the important tier as
 * `.x{... !important}`. Tier is the whole risk surface for text-align, so the
 * tests below must be able to tell them apart.
 *
 * @return array<int, array{important: bool, body: string}>
 */
$tierRules = static function (string $css): array {
    if ( ! preg_match_all('/(:root\s+)?(?<![\w-])\.(be-inline-geometry-[a-f0-9-]+)\{([^}]*)\}/', $css, $matches, PREG_SET_ORDER) ) {
        return array();
    }

    return array_map(
        static fn (array $match): array => array(
            'important' => '' === trim((string) $match[1]),
            'body'      => (string) $match[3],
        ),
        $matches
    );
};

/** @param array<int, array{important: bool, body: string}> $rules */
$nonImportantWith = static function (array $rules, string $needle): string {
    foreach ( $rules as $rule ) {
        if ( ! $rule['important'] && str_contains($rule['body'], $needle) ) {
            return $rule['body'];
        }
    }

    return '';
};

/** @param array<int, array{important: bool, body: string}> $rules */
$importantWith = static function (array $rules, string $needle): string {
    foreach ( $rules as $rule ) {
        if ( $rule['important'] && str_contains($rule['body'], $needle) ) {
            return $rule['body'];
        }
    }

    return '';
};

/** @param array<int, array{important: bool, body: string}> $rules */
$anyWith = static function (array $rules, string $needle): string {
    foreach ( $rules as $rule ) {
        if ( str_contains($rule['body'], $needle) ) {
            return $rule['body'];
        }
    }

    return '';
};

// ---------------------------------------------------------------------------
// C1(a) — an inline declaration conflicting with a matching author rule is
// carried. The four service pills reuse `.badge` for its paint and neutralise
// its positioning inline; `position` and `box-shadow` map to no block support
// and were discarded while `.badge{position:absolute;z-index:3}` survived.
// ---------------------------------------------------------------------------
$pill = $transform(
    '<style>.badge{position:absolute;z-index:3;background:#f26b12;border-radius:20px;'
    . 'padding:0.8rem 1.05rem;box-shadow:0 0 0 5px #ffd400,0 12px 26px rgba(15,4,35,0.35);max-width:12.5rem}</style>'
    . '<section><article style="background:#5b18a6;border-radius:24px;padding:2rem;">'
    . '<p class="badge" style="position:static;max-width:none;display:inline-block;margin:0 0 1rem;'
    . 'padding:0.35rem 0.85rem;border-radius:999px;box-shadow:0 0 0 3px #ffd400;">01 &middot; Leadership</p>'
    . '<h3>Executive and leadership coaching</h3>'
    . '<p>For directors and founders who inherited a team and a mess on the same day.</p>'
    . '</article></section>'
);
$pillCss = $cssFor($pill, 'engine-support');
$pillRules = $tierRules($pillCss);
$pillRule = $nonImportantWith($pillRules, 'position:static');

$assert(
    '' !== $pillRule,
    'conflict carry: an inline position:static overriding .badge{position:absolute} is carried',
    $pillCss
);
$assert(
    '' !== $nonImportantWith($pillRules, 'box-shadow:0 0 0 3px #ffd400'),
    'conflict carry: the inline box-shadow overriding the .badge ring is carried',
    $pillCss
);
$assert(
    '' === $importantWith($pillRules, 'position:static'),
    'conflict carry: the conflict tier is non-important, so authored :hover and higher-specificity rules still win',
    $pillCss
);
$assert(
    str_contains($cssFor($pill, 'author-css'), 'position:absolute'),
    'conflict carry: the author rule stays materialized verbatim; the carrier overrides it rather than deleting it',
    $cssFor($pill, 'author-css')
);

// ---------------------------------------------------------------------------
// C1(b) — an inline `display` differing from the tag default is carried even
// when NO author rule declares `display`. Restoring position:static without
// this makes the pill a full-width flow block with a solid background and a
// 999px radius: a worse regression than the overlap it fixes.
// ---------------------------------------------------------------------------
$assert(
    '' !== $nonImportantWith($pillRules, 'display:inline-block'),
    'display default: inline display:inline-block on a <p> is carried though .badge declares no display',
    $pillCss
);

$noAuthorDisplay = $transform(
    '<style>.chip{background:#ffd400;border-radius:999px;padding:0.35rem 0.85rem}</style>'
    . '<section><article style="background:#fff;border-radius:24px;padding:2rem;">'
    . '<p class="chip" style="display:inline-block;">02 &middot; Transition</p>'
    . '<h3>Career change and direction</h3><p>You are good at a job you no longer want.</p>'
    . '</article></section>'
);
$noAuthorDisplayCss = $cssFor($noAuthorDisplay, 'engine-support');

$assert(
    '' !== $nonImportantWith($tierRules($noAuthorDisplayCss), 'display:inline-block'),
    'display default: the carrier does not require a conflicting author display rule',
    $noAuthorDisplayCss
);

// ---------------------------------------------------------------------------
// C1(c) — inherited `text-align` on a container reaches its descendants. The
// h2 and the lede have no text-align of their own; it lives on the grandparent,
// and createBlock()'s align path is element-scoped with no ancestor walk.
// ---------------------------------------------------------------------------
$hero = $transform(
    '<style>.hero-inner{display:grid;margin:0 auto;max-width:60rem}</style>'
    . '<section class="hero"><div class="hero-inner" style="display:block;text-align:center;">'
    . '<p class="eyebrow">Plymouth &middot; in person or online</p>'
    . '<h2>Book the call. Decide after.</h2>'
    . '<p>Thirty minutes, no pitch, no obligation to continue.</p>'
    . '</div></section>'
);
$heroCss = $cssFor($hero, 'engine-support');
$heroRules = $tierRules($heroCss);
$heroRule = $nonImportantWith($heroRules, 'text-align:center');

$assert(
    '' !== $heroRule,
    'inherited text-align: an inline text-align on a container is carried so the whole subtree inherits it',
    $heroCss
);
// C2 — a test that FAILS if text-align moves to the !important tier, where it
// would beat author @media text-align rules and class-owned centering.
$assert(
    '' === $importantWith($heroRules, 'text-align'),
    'C2 tier: text-align is never emitted in the !important tier',
    $heroCss
);
$assert(
    ! str_contains($heroRule, 'text-align:center !important'),
    'C2 tier: the carried text-align declaration itself carries no !important',
    '' !== $heroRule ? $heroRule : $heroCss
);

// ---------------------------------------------------------------------------
// C1(d) — inline `justify-content` on a flex <p> and a flex <ul> is carried.
// Author CSS supplies display:flex, the inline style supplies the alignment.
// The <div> form of this shape is rescued by cssOwnedFlexAttributes(); <p> and
// <ul> can never reach it, because it is gated on isFlowContainerTag().
// ---------------------------------------------------------------------------
$flexText = $transform(
    '<style>.eyebrow{display:flex;gap:0.5rem;align-items:center}'
    . '.chips{display:flex;flex-wrap:wrap;gap:0.5rem;list-style:none;padding:0}</style>'
    . '<section><div class="hero-inner">'
    . '<p class="eyebrow" style="justify-content:center;">Plymouth &middot; in person or online</p>'
    . '<h2>Book the call. Decide after.</h2>'
    . '<ul class="chips" style="justify-content:center;"><li>Executive</li><li>Teams</li><li>Personal</li></ul>'
    . '</div></section>'
);
$flexTextCss = $cssFor($flexText, 'engine-support');
$flexTextRules = $tierRules($flexTextCss);
$centeredFlex = array_filter(
    $flexTextRules,
    static fn (array $rule): bool => ! $rule['important'] && str_contains($rule['body'], 'justify-content:center')
);

$assert(
    2 === count($centeredFlex),
    'author-resolved flex: both the flex <p> and the flex <ul> carry their inline justify-content',
    $flexTextCss
);
$assert(
    '' === $importantWith($flexTextRules, 'justify-content:center'),
    'author-resolved flex: the rescued alignment rides the non-important tier so author @media rules still win',
    $flexTextCss
);
$assert(
    '' === $anyWith($flexTextRules, 'display:flex'),
    'author-resolved flex: the author keeps ownership of display; only the inline-present alignment is carried',
    $flexTextCss
);
$assert(
    '' === $anyWith($flexTextRules, 'align-items'),
    'author-resolved flex: a layout property absent inline is never synthesized onto the carrier',
    $flexTextCss
);

// ---------------------------------------------------------------------------
// D1 second arm — a leftover inline declaration with NO author rule behind it
// is carried for box-shadow only. The four service cards are classless
// <article>s whose inline box-shadow is their only ring; cards 2 and 3 render
// invisible white-on-white without it.
// ---------------------------------------------------------------------------
$card = $transform(
    '<section><article style="background:#fff;border-radius:24px;padding:2rem;'
    . 'box-shadow:inset 0 0 0 3px rgba(91,24,166,0.16),0 14px 34px rgba(15,4,35,0.08);">'
    . '<h3>Team performance work</h3><p>Half-day sessions for groups of four to twenty.</p>'
    . '</article></section>'
);
$cardCss = $cssFor($card, 'engine-support');

$assert(
    '' !== $nonImportantWith($tierRules($cardCss), 'box-shadow:inset 0 0 0 3px rgba(91,24,166,0.16),0 14px 34px rgba(15,4,35,0.08)'),
    'unmatched arm: an inline box-shadow with no author rule behind it is carried rather than silently dropped',
    $cardCss
);

// The arm is a deliberately narrow allowlist, not a general "carry every
// leftover declaration" rule: animation, filter and counter-reset have side
// effects and sit outside this defect family.
// The companion `max-width` is load-bearing: it mints a carrier, so these three
// assertions are proving the properties are ABSENT FROM a rule that exists,
// rather than passing vacuously against an empty carrier string.
$sideEffects = $transform(
    '<section><div style="animation:pulse 2s infinite;filter:blur(2px);counter-reset:step 0;max-width:40rem;">'
    . '<p>Copy inside a decorated wrapper.</p></div></section>'
);
$sideEffectsCss = $cssFor($sideEffects, 'engine-support');
$sideEffectRules = $tierRules($sideEffectsCss);

$assert(
    '' !== $anyWith($sideEffectRules, 'max-width:40rem'),
    'unmatched arm: the companion declaration mints a carrier, so the negative assertions below are non-vacuous',
    $sideEffectsCss
);
foreach ( array( 'animation', 'filter', 'counter-reset' ) as $property ) {
    $assert(
        '' === $anyWith($sideEffectRules, $property),
        'unmatched arm: ' . $property . ' is outside the allowlist and is not carried',
        $sideEffectsCss
    );
}

// ---------------------------------------------------------------------------
// C3 — metadata rejects button shadow support, so the inline winner must remain
// exclusively in the carrier rather than an ignored native style attribute.
// ---------------------------------------------------------------------------
$button = $transform(
    '<style>.btn{display:inline-block;background:#5b18a6;color:#fff;padding:0.8rem 1.2rem;'
    . 'border-radius:999px;box-shadow:0 4px 0 -2px rgba(0,0,0,0.3)}</style>'
    . '<section><div><a class="btn" href="#book" style="box-shadow:0 10px 0 -2px rgba(0,0,0,0.22);">Book the call</a></div></section>'
);
$buttonCss = $cssFor($button, 'engine-support');
$buttonSerialized = (string) ($button['serialized_blocks'] ?? '');

$assert(! str_contains($buttonSerialized, '"shadow"'), 'C3: metadata-rejected button shadow stays out of native block attributes', $buttonSerialized);
$assert('' !== $importantWith($tierRules($buttonCss), 'box-shadow:0 10px 0 -2px rgba(0,0,0,0.22)'), 'C3: the carrier preserves the inline button shadow', $buttonCss);

// ---------------------------------------------------------------------------
// C4 — no-op when the value equals the default.
// ---------------------------------------------------------------------------
$defaultDisplay = $transform(
    '<section><div style="display:block;"><p>Copy in a block-by-default div.</p></div>'
    . '<p style="display:block;">Copy in a block-by-default paragraph.</p></section>'
);
$defaultDisplayCss = $cssFor($defaultDisplay, 'engine-support');

$assert(
    '' === $anyWith($tierRules($defaultDisplayCss), 'display:block'),
    'C4: display:block on a <div>/<p> equals the tag default and produces no carrier',
    $defaultDisplayCss
);

$defaultAlign = $transform(
    '<section><div style="text-align:left;"><p>Copy in a start-aligned wrapper.</p></div></section>'
);
$defaultAlignCss = $cssFor($defaultAlign, 'engine-support');

$assert(
    '' === $anyWith($tierRules($defaultAlignCss), 'text-align'),
    'C4: text-align:left in an LTR document equals the inherited value and produces no carrier',
    $defaultAlignCss
);

// The skip compares against the RESOLVED INHERITED value, not a hardcoded
// "left is always the default". Under a centered ancestor, text-align:left is a
// genuine override and must survive. The companion max-width is what supplies
// the carrier: text-align rides an existing one and never mints its own.
$leftOverride = $transform(
    '<style>.wrap{text-align:center}</style>'
    . '<section><div class="wrap"><div style="max-width:40rem;text-align:left;"><p>Deliberately left inside a centered wrapper.</p></div></div></section>'
);
$leftOverrideCss = $cssFor($leftOverride, 'engine-support');

$assert(
    '' !== $nonImportantWith($tierRules($leftOverrideCss), 'text-align:left'),
    'inherited comparison: text-align:left under a centered ancestor is a real override and is carried',
    $leftOverrideCss
);

// A carrier class is what promotes an attribute-less wrapper into a core/group,
// so text-align must never mint one: doing so adds block-tree structure to every
// wrapper whose only inline declaration is an alignment.
$alignOnlyWrapper = $transform(
    '<style>.wrap{text-align:center}</style>'
    . '<section><div class="wrap"><div style="text-align:left;"><p>Alignment is the only inline declaration here.</p></div></div></section>'
);
$alignOnlyWrapperCss = $cssFor($alignOnlyWrapper, 'engine-support');

$assert(
    '' === $anyWith($tierRules($alignOnlyWrapperCss), 'text-align'),
    'no minted carrier: a wrapper whose only inline declaration is text-align gets no carrier of its own',
    $alignOnlyWrapperCss
);

// ---------------------------------------------------------------------------
// TIER PRESERVATION. The carrier predicate must NOT be used to pick a priority
// tier. An inline display that merely differs from the tag default, with no
// author display rule, must keep the forced !important tier it already had:
// demoting it to `:root .x` at (0,2,0) lets any author selector with three or
// more weighted tokens on the same element win, and the source's own inline
// value stops rendering.
// ---------------------------------------------------------------------------
$specificAuthor = $transform(
    '<style>.hero.big.x{gap:2rem;padding:1rem}</style>'
    . '<section><div class="hero big x" style="display:flex;flex-direction:column;gap:1rem">'
    . '<p>First column copy.</p><p>Second column copy.</p></div></section>'
);
$specificAuthorCss = $cssFor($specificAuthor, 'engine-support');
$specificAuthorRules = $tierRules($specificAuthorCss);

$assert(
    '' !== $importantWith($specificAuthorRules, 'gap:1rem'),
    'tier preservation: an inline gap beside an inline display keeps the !important tier, so a (0,3,0) author rule cannot beat it',
    $specificAuthorCss
);
$assert(
    '' === $nonImportantWith($specificAuthorRules, 'gap:1rem'),
    'tier preservation: that carrier is NOT demoted to the non-important tier',
    $specificAuthorCss
);

// ---------------------------------------------------------------------------
// A malformed inline value must never be carried. An unclosed `rgba(` would make
// the CSS parser consume this rule's closing brace hunting for the `)`, silently
// swallowing the NEXT carrier rule. box-shadow is parenthesis-heavy and reaches
// the carrier for the first time here, so the guard is asserted directly.
// ---------------------------------------------------------------------------
$malformed = $transform(
    '<section>'
    . '<article style="background:#fff;box-shadow:0 0 0 3px rgba(1,2,3,.4"><h3>Unbalanced</h3><p>First card copy.</p></article>'
    . '<article style="background:#fff;box-shadow:0 0 0 3px #123456"><h3>Well formed</h3><p>Second card copy.</p></article>'
    . '</section>'
);
$malformedCss = $cssFor($malformed, 'engine-support');
$malformedRules = $tierRules($malformedCss);

$assert(
    '' === $anyWith($malformedRules, 'rgba(1,2,3,.4'),
    'malformed value: an inline box-shadow with an unbalanced paren is dropped, not carried',
    $malformedCss
);
$assert(
    '' !== $nonImportantWith($malformedRules, 'box-shadow:0 0 0 3px #123456'),
    'malformed value: the well-formed sibling rule survives instead of being swallowed',
    $malformedCss
);

// An unbalanced paren is not the only way to leave the emitted rule's closing
// brace unreachable. An odd quote puts the brace inside a string, and a trailing
// backslash escapes it. Each was verified ONCE BY HAND in a headless browser to
// kill the FOLLOWING carrier rule as well as its own; that browser check is NOT
// re-run by this suite.
//
// The victim rule cannot be asserted by searching the CSS string, because under
// live corruption the victim IS still present in the string — the browser's
// parser is what discards it. Presence is not effect.
//
// A text-level brace-balance scan does not detect these shapes either: it was
// tried and stays balanced under live corruption, because the closing brace IS
// in the text, merely unreachable once a string or escape has captured it. (Such
// a scan would only catch a literal unescaped `}`, which the value guard's
// `[{}<>;]` check already rejects.) What DOES have teeth at text level is the
// count of emitted carrier rules: a swallowed terminator merges two rules into
// one, so the count drops. Each shape below therefore asserts two things, both
// confirmed to fail when the guard is removed: the value is not emitted, and
// exactly one carrier rule exists.
// A raw `"` cannot survive a double-quoted HTML style attribute — the parser
// truncates the declaration before the guard is ever consulted — so that shape
// is delivered through a single-quoted attribute instead. Without this it is
// unreachable, and an assertion against it passes whether the guard exists or not.
foreach ( array(
    'odd single quote'   => array( "0 0 0 3px '", '"' ),
    'odd double quote'   => array( '0 0 0 3px "', "'" ),
    'trailing backslash' => array( '0 0 0 3px \\', '"' ),
) as $label => $case ) {
    list( $payload, $quote ) = $case;
    $result = $transform(
        '<section>'
        . '<article style=' . $quote . 'background:#fff;box-shadow:' . $payload . $quote . '><h3>Malformed</h3><p>First card copy.</p></article>'
        . '<article style="background:#eee;box-shadow:0 0 0 9px #abcdef"><h3>Well formed</h3><p>Second card copy.</p></article>'
        . '</section>'
    );
    $resultCss = $cssFor($result, 'engine-support');
    $resultRules = $tierRules($resultCss);

    $assert(
        '' === $anyWith($resultRules, rtrim($payload)),
        'malformed value (' . $label . '): the value is dropped rather than emitted into a rule',
        $resultCss
    );
    $assert(
        1 === count($resultRules),
        'malformed value (' . $label . '): exactly one carrier rule is emitted - the well-formed card\'s - so the malformed one neither emitted nor consumed a rule',
        $resultCss
    );
}

// ---------------------------------------------------------------------------
// The skip must compare against the element's OWN author-declared text-align,
// not only the value inherited from ancestors. A class that centres the element
// and an inline style that left-aligns it have no ancestor alignment to compare
// against, so an ancestor-only walk resolves to the document default, matches
// `left`, skips the carrier, and lets the class rule render it centred where the
// source rendered it left.
// ---------------------------------------------------------------------------
$ownAuthorAlign = $transform(
    '<style>.card{text-align:center;max-width:40rem;padding:1rem}</style>'
    . '<section><div class="card" style="text-align:left;max-width:30rem;">'
    . '<p>Body copy the source deliberately left-aligns.</p></div></section>'
);
$ownAuthorAlignCss = $cssFor($ownAuthorAlign, 'engine-support');

$assert(
    '' !== $nonImportantWith($tierRules($ownAuthorAlignCss), 'text-align:left'),
    'own author alignment: an inline text-align overriding the element\'s OWN class text-align is carried',
    $ownAuthorAlignCss
);
$assert(
    str_contains($cssFor($ownAuthorAlign, 'author-css'), 'text-align:center'),
    'own author alignment: the class rule is still materialized, so the carrier is what decides the outcome',
    $cssFor($ownAuthorAlign, 'author-css')
);

// ---------------------------------------------------------------------------
// core/button re-emits the source control as its own chrome, so the source
// element's formatting context no longer describes the rendered markup. Every
// button therefore discards its inline flex declarations, and discards
// box-shadow when the native shadow support has claimed it. This is a behaviour
// change for all buttons, so it is pinned rather than left implicit.
// ---------------------------------------------------------------------------
$buttonChrome = $transform(
    '<style>.btn{background:#5b18a6;color:#fff;padding:0.8rem 1.2rem;border-radius:999px}</style>'
    . '<section><div><a class="btn" href="#go" style="display:inline-flex;align-items:center;justify-content:center;gap:0.5rem;">Go now</a></div></section>'
);
$buttonChromeCss = $cssFor($buttonChrome, 'engine-support');
$buttonChromeRules = $tierRules($buttonChromeCss);

foreach ( array( 'display:inline-flex', 'align-items', 'justify-content', 'gap' ) as $declaration ) {
    $assert(
        '' === $anyWith($buttonChromeRules, $declaration),
        'button chrome: ' . $declaration . ' is not carried onto a core/button wrapper',
        $buttonChromeCss
    );
}
$assert(
    str_contains((string) ($buttonChrome['serialized_blocks'] ?? ''), 'wp:button'),
    'button chrome: the control still converts to a native core/button',
    (string) ($buttonChrome['serialized_blocks'] ?? '')
);

// ---------------------------------------------------------------------------
// LIMITATION PIN. The conflict rescue can only see author declarations that
// survive safeVisualDeclarations(). `overflow` is not on that allowlist, so the
// author rule is invisible, the inline override is dropped, and the materialized
// stylesheet reasserts the opposite value. `position` IS on it and rescues
// correctly. This asserts the CURRENT limited behaviour on purpose: the day the
// rule set is collected unfiltered, this test fails and produces a signal
// instead of silence.
// ---------------------------------------------------------------------------
$unseen = $transform(
    '<style>.pane{overflow:hidden;padding:1rem}</style>'
    . '<section><div class="pane" style="overflow:visible;max-width:30rem;"><p>Panel copy.</p></div></section>'
);
$unseenCss = $cssFor($unseen, 'engine-support');

$assert(
    '' !== $anyWith($tierRules($unseenCss), 'max-width:30rem'),
    'limitation pin: the element does get a carrier, so the overflow assertion below is non-vacuous',
    $unseenCss
);
$assert(
    '' === $anyWith($tierRules($unseenCss), 'overflow'),
    'limitation pin: overflow is absent from safeVisualDeclarations, so the conflict rescue cannot see it (KNOWN LIMITATION - if this fails, the rule set became unfiltered and the limitation is closed)',
    $unseenCss
);

$seen = $transform(
    '<style>.pane{position:absolute;padding:1rem}</style>'
    . '<section><div class="pane" style="position:static;max-width:30rem;"><p>Panel copy.</p></div></section>'
);

$assert(
    '' !== $nonImportantWith($tierRules($cssFor($seen, 'engine-support')), 'position:static'),
    'limitation pin: position IS allowlisted, so the same shape rescues correctly - the gap is property-dependent, not total',
    $cssFor($seen, 'engine-support')
);

if ( $failures > 0 ) {
    fwrite(STDERR, "Inline author-override carrier contract: {$failures} failed, {$passes} passed\n");
    exit(1);
}

fwrite(STDOUT, "Inline author-override carrier contract passed: {$passes} assertions\n");
