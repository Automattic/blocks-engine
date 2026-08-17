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
$sideEffects = $transform(
    '<section><div style="animation:pulse 2s infinite;filter:blur(2px);counter-reset:step 0;">'
    . '<p>Copy inside a decorated wrapper.</p></div></section>'
);
$sideEffectsCss = $cssFor($sideEffects, 'engine-support');
$sideEffectRules = $tierRules($sideEffectsCss);

foreach ( array( 'animation', 'filter', 'counter-reset' ) as $property ) {
    $assert(
        '' === $anyWith($sideEffectRules, $property),
        'unmatched arm: ' . $property . ' is outside the allowlist and is not carried',
        $sideEffectsCss
    );
}

// ---------------------------------------------------------------------------
// C3 — no double ownership. A box-shadow consumed by the core/button shadow
// support must not also get a carrier rule: carriers can outrank the
// editor-visible support value and desynchronise the editor from the front end.
// ---------------------------------------------------------------------------
$button = $transform(
    '<style>.btn{display:inline-block;background:#5b18a6;color:#fff;padding:0.8rem 1.2rem;'
    . 'border-radius:999px;box-shadow:0 4px 0 -2px rgba(0,0,0,0.3)}</style>'
    . '<section><div><a class="btn" href="#book" style="box-shadow:0 10px 0 -2px rgba(0,0,0,0.22);">Book the call</a></div></section>'
);
$buttonCss = $cssFor($button, 'engine-support');
$buttonSerialized = (string) ($button['serialized_blocks'] ?? '');

$assert(
    str_contains($buttonSerialized, '"shadow":"0 10px 0 -2px rgba(0,0,0,0.22)"'),
    'C3: the inline box-shadow still becomes the core/button shadow support',
    $buttonSerialized
);
$assert(
    '' === $anyWith($tierRules($buttonCss), 'box-shadow'),
    'C3: the property consumed by the shadow support gets no competing carrier rule',
    $buttonCss
);

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

if ( $failures > 0 ) {
    fwrite(STDERR, "Inline author-override carrier contract: {$failures} failed, {$passes} passed\n");
    exit(1);
}

fwrite(STDOUT, "Inline author-override carrier contract passed: {$passes} assertions\n");
