<?php
declare(strict_types=1);

/**
 * Contract for the menu chrome core's own markup cannot carry.
 *
 * Two menu surfaces are rebuilt by core rather than emitted from the source, so
 * the source element that carried the author classes stops existing:
 *
 * - `core/navigation-link` stores its label as an attribute, so the label markup
 *   never reaches the block tree and the projected marker classes the rewritten
 *   author rules target are never stamped on it.
 * - `core/details` renders `<summary>` with no attributes at all, so a disclosure
 *   toggle's own classes are dropped outright.
 *
 * In both cases the author rule survives into the stylesheet addressing something
 * that no longer exists, so the menu silently drops to the destination theme's
 * defaults on the front end while still looking correct in the editor, which
 * reads the separate editor-static-state projection.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$passes = 0;

$assert = static function (bool $condition, string $message, string $detail = ''): void {
    global $failures, $passes;
    if ( $condition ) {
        ++$passes;
        return;
    }

    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ('' !== $detail ? ' - ' . $detail : '') . PHP_EOL);
};

$transform = static function (string $html): array {
    $result = ( new HtmlTransformer() )->transform($html, array())->toArray();
    $frontend = '';
    foreach ( (is_array($result['assets'] ?? null) ? $result['assets'] : array()) as $asset ) {
        if ( ! is_array($asset) || 'editor' === (string) ($asset['stylesheet_target'] ?? '') ) {
            continue;
        }
        if ( 'editor-static-state' === (string) ($asset['source'] ?? '') ) {
            continue;
        }
        $frontend .= (string) ($asset['content'] ?? '');
    }

    return array( 'blocks' => (string) ($result['serialized_blocks'] ?? ''), 'frontend' => $frontend );
};

// -- A menu item label keeps the marker its rewritten author rule addresses.
$menu = $transform(
    '<style>#comp-nav .menuRoot .Menu__item .Item__label'
    . '{color:#FF99FE;font-family:montserrat,sans-serif;font-size:14px}</style>'
    . '<div id="comp-nav"><nav class="menuRoot Menu__menu"><ul>'
    . '<li class="Item__wrap"><a href="/about/" class="Item__root Menu__item">'
    . '<div class="Item__container"><span class="Item__label">About</span></div></a></li>'
    . '<li class="Item__wrap"><a href="/work/" class="Item__root Menu__item">'
    . '<div class="Item__container"><span class="Item__label">Work</span></div></a></li>'
    . '</ul></nav></div><p>Body copy.</p>'
);

preg_match_all('/blocks-engine-semantic-[0-9a-f]+-\d+/', $menu['frontend'], $ruleMarkers);
$ruleMarkers = array_values(array_unique($ruleMarkers[0]));
// The label rides inside the block's JSON attributes, so its quotes are escaped.
$labelled = array_values(array_filter(
    $ruleMarkers,
    static fn (string $marker): bool => (bool) preg_match(
        '/Item__label\b[^<>]{0,80}?\b' . preg_quote($marker, '/') . '\b/',
        $menu['blocks']
    )
));

$assert(
    array() !== $labelled,
    'a navigation label carries a marker the frontend stylesheet addresses',
    substr($menu['blocks'], 0, 300)
);
$assert(
    str_contains($menu['frontend'], '#FF99FE'),
    'the label presentation reaches a frontend stylesheet, not only the editor'
);

// -- A disclosure toggle's own box is restated where core renders it.
$disclosure = $transform(
    '<style>#comp-tog .style-btn__root'
    . '{border-radius:50px;background:#1A1A1A;width:107px;height:40px}'
    . '#comp-tog .style-btn__root .Btn__label{color:#EFFE8B;font-size:18px}</style>'
    . '<div id="comp-tog"><nav class="Ham__nav"><details class="dla-disclosure">'
    . '<summary class="style-btn__root"><span class="Btn__label">Menu</span></summary>'
    . '<div class="dla-dialog" role="dialog"><ul><li><a href="/a/">A</a></li>'
    . '<li><a href="/b/">B</a></li></ul></div>'
    . '</details></nav></div><p>Body copy.</p>'
);

preg_match('/\.wp-block-details\.(blocks-engine-disclosure-summary-[0-9a-f]+)>summary\{([^}]*)\}/', $disclosure['frontend'], $rule);

$assert(
    array() !== $rule,
    'a disclosure toggle emits a summary presentation rule',
    substr($disclosure['frontend'], -300)
);
if ( array() !== $rule ) {
    $assert(
        str_contains($disclosure['blocks'], $rule[1]),
        'the details block carries the marker the rule addresses'
    );
    $assert(
        str_contains($rule[2], 'background:#1A1A1A') && str_contains($rule[2], 'border-radius:50px'),
        'the toggle paint is restated',
        $rule[2]
    );
    $assert(
        str_contains($rule[2], 'color:#EFFE8B') && str_contains($rule[2], 'font-size:18px'),
        'the label type rides along, since it lost the same ancestor',
        $rule[2]
    );
    $assert(
        str_contains($rule[2], 'width:107px') && str_contains($rule[2], 'height:40px'),
        'the toggle box is restated, since core gives summary none',
        $rule[2]
    );
}
$assert(
    ! preg_match('/<summary[^>]+>/', $disclosure['blocks']),
    'the emitted summary stays attribute-free, matching core\'s save shape'
);

// -- A disclosure with no authored toggle presentation emits no rule.
$plain = $transform(
    '<details><summary>More</summary><p>Detail.</p></details><p>Body copy.</p>'
);
$assert(
    ! str_contains($plain['frontend'], 'blocks-engine-disclosure-summary-'),
    'an unstyled disclosure emits no summary rule',
    substr($plain['frontend'], -200)
);

if ( $failures > 0 ) {
    fwrite(STDERR, "Menu chrome presentation carry contract: {$failures} failed, {$passes} passed\n");
    exit(1);
}

echo "Menu chrome presentation carry contract passed: {$passes} assertions\n";
