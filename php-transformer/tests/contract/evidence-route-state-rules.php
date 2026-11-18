<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\EvidenceRouteStylesheets;

/*
 * ADR 0005 slice 3b: retiring an evidence route's author sheet keeps the
 * rules carriers cannot replace (keyframes and non-resting state rules).
 */
$assert = static function (bool $ok, string $message, string $detail = ''): void {
    if (!$ok) throw new RuntimeException($message . ('' !== $detail ? ': ' . $detail : ''));
    echo "PASS: $message\n";
};
$entrance = htmlspecialchars((string) json_encode(array('attributes' => array('data-interact-enter' => array('before' => 'pending', 'after' => 'done')), 'threshold' => array(0))), ENT_QUOTES);
$page = array(
    'source_path' => 'index.html',
    'document_metadata' => array('engine_owned_styles' => true),
    'canonical_block_markup' => '<!-- wp:paragraph {"className":"x"} --><p class="x" id="comp-a" ' . CaptureEvidence::VIEWPORT_ENTRANCE . '="' . $entrance . '">Hi</p><!-- /wp:paragraph -->',
);
$retire = static function (string $css) use (&$page): string {
    $asset = array('kind' => 'css', 'source' => 'author', 'content' => $css, 'scopes' => array(array('kind' => 'page', 'source_path' => 'index.html')));
    $pages = array($page);
    return (EvidenceRouteStylesheets::retire(array($asset), $pages)[0]['content'] ?? '');
};

$kept = $retire('@font-face{font-family:F;src:url(f.woff2)}.x{color:blue}@keyframes fade{from{opacity:0}to{opacity:1}}@keyframes unused{to{opacity:0}}.x:hover{color:red}.x[data-interact-enter="done"]{animation:fade 1s}');
$assert(str_contains($kept, '@font-face{font-family:F'), 'font faces survive retirement', $kept);
$assert(str_contains($kept, '@keyframes fade{'), 'referenced keyframes survive retirement', $kept);
$assert(!str_contains($kept, 'unused'), 'unreferenced keyframes are dropped', $kept);
$assert(str_contains($kept, '.x:hover{color:red}'), 'interaction-state rules survive retirement', $kept);
$assert(str_contains($kept, '.x[data-interact-enter="done"]{animation:fade 1s}'), 'runtime state attribute rules survive retirement', $kept);
$assert(!str_contains($kept, 'color:blue'), 'resting author rules are retired', $kept);

// Conditional context, list members, negated state attributes, custom
// property animation bindings and unobserved ancestor wrappers (Wix).
$kept = $retire('.y,.x:focus-visible{outline:1px solid}'
    . '@media (prefers-reduced-motion:no-preference){[data-interact-key="k/comp-a"] :is(.x):is(#comp-a):not([data-interact-enter]){visibility:hidden}}'
    . '[data-interact-key="k/comp-a"] :is(.x):is(#comp-a):not([data-interact-enter="done"]){--anm-0:motion-fadeIn 1200ms backwards 1 paused}'
    . '.x{color:blue;animation:var(--anm-0)}'
    . '@keyframes motion-fadeIn{0%{opacity:0}100%{opacity:var(--comp-opacity,1)}}'
    . '@media (min-width:1px){.y{color:green}}@layer a,b;');
$assert(str_contains($kept, '.x:focus-visible{outline:1px solid}') && !str_contains($kept, '.y,'), 'only the state members of a selector list are kept', $kept);
$assert(str_contains($kept, '@media (prefers-reduced-motion:no-preference){:is(.x):is(#comp-a):not([data-interact-enter]){visibility:hidden}}'), 'negated state rules keep their conditional context and drop unobserved ancestor wrappers', $kept);
$assert(str_contains($kept, ':is(.x):is(#comp-a):not([data-interact-enter="done"]){--anm-0:motion-fadeIn'), 'custom property state bindings are kept', $kept);
$assert(str_contains($kept, '.x{animation:var(--anm-0)}'), 'resting animation consumers keep only their animation declarations', $kept);
$assert(str_contains($kept, '@keyframes motion-fadeIn{'), 'keyframes reached through custom properties are kept', $kept);
$assert(!str_contains($kept, 'green') && !str_contains($kept, '@media (min-width:1px)'), 'groups with no kept rules are dropped', $kept);
$assert(str_contains($kept, '@layer a,b;'), 'cascade layer order statements are kept', $kept);

// Without viewport entrance evidence, no attribute is a runtime state.
$page['canonical_block_markup'] = '<p class="x">Hi</p>';
$kept = $retire('.x[data-interact-enter="done"]{color:red}');
$assert('' === $kept, 'state attributes come from capture evidence, not a fixed list', $kept);

// Resting scroll-driven bindings (Wix parallax): the consumer reads custom
// properties a resting rule defines; the definitions, the view timeline and
// the custom properties the kept keyframes read are closed over.
$page['canonical_block_markup'] = '<div id="comp-p" class="container">P</div><div id="comp-q" class="container">Q</div>';
$kept = $retire('@property --anm-0{syntax:"*";inherits:false;initial-value:none}'
    . '@keyframes motion-parallax{0%{transform:translateY(calc(-1 * var(--motion-parallax-to)))}100%{transform:translateY(var(--motion-parallax-to))}}'
    . '[data-interact-key="k/comp-p"] :is(.container):is(#comp-p,[id^="comp-p__"]){--vw-tmln-0:--trigger-1;color:blue}'
    . '@media (prefers-reduced-motion:no-preference){[data-interact-key="k/comp-p"] :is(.container):is(#comp-p,[id^="comp-p__"]){--motion-parallax-to:-7.5vh;--anm-0:motion-parallax auto linear both 1;--anm-tmln-0:--trigger-1;width:10px}}'
    . '[data-interact-key="k/comp-p"] :is(.container):is(#comp-p,[id^="comp-p__"]),[data-interact-key="k/comp-gone"] :is(.container):is(#comp-gone,[id^="comp-gone__"]){animation:var(--anm-0);animation-timeline:var(--anm-tmln-0);view-timeline:var(--vw-tmln-0);color:red}'
    . '#comp-gone:hover{color:red}#comp-q:hover{color:green}');
$assert(str_contains($kept, ':is(.container):is(#comp-p,[id^="comp-p__"]){animation:var(--anm-0);animation-timeline:var(--anm-tmln-0);view-timeline:var(--vw-tmln-0)}'), 'resting animation consumers keep their motion declarations', $kept);
$assert(str_contains($kept, '{--vw-tmln-0:--trigger-1}') && !str_contains($kept, 'color:blue'), 'view timeline definitions are kept without resting declarations', $kept);
$assert(str_contains($kept, '@media (prefers-reduced-motion:no-preference){:is(.container):is(#comp-p,[id^="comp-p__"]){--motion-parallax-to:-7.5vh;--anm-0:motion-parallax auto linear both 1;--anm-tmln-0:--trigger-1}}'), 'resting animation bindings and the properties their keyframes read are kept in context', $kept);
$assert(str_contains($kept, '@keyframes motion-parallax{') && str_contains($kept, '@property --anm-0{'), 'bound keyframes and registered properties are kept', $kept);
$assert(!str_contains($kept, 'comp-gone') && str_contains($kept, '#comp-q:hover{color:green}'), 'rules whose subject names an id the route never renders are dropped', $kept);

// Editor static state sheets project the author rules for the editor; the
// author sheet already delivers the kept rules.
$editor = array('kind' => 'css', 'source' => 'editor-static-state', 'content' => '@font-face{font-family:G;src:url(g.woff2)}#comp-q:hover{color:green}', 'scopes' => array(array('kind' => 'page', 'source_path' => 'index.html')));
$pages = array($page);
$kept = EvidenceRouteStylesheets::retire(array($editor), $pages)[0]['content'];
$assert('@font-face{font-family:G;src:url(g.woff2)}' === $kept, 'editor static state sheets keep only font faces on evidence routes', $kept);
