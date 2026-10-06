<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// Wix's login bar sizes its 50x50 avatar SVG down to 24px from rules on a
// wrapper (`.ZNcrb1.cz21gI svg`). Responsive captures put those rules inside
// @media. The SVG becomes an <img> that no `svg` selector reaches, and the
// wrapper is flattened, so the carrier must restate the size under the same
// media condition instead of leaving it to fall back to its intrinsic 50x50.
$assertions = 0;
$failures = array();
$assert = static function (bool $condition, string $message) use (&$assertions, &$failures): void {
    ++$assertions;
    if (!$condition) $failures[] = $message;
};
$transform = static fn (string $html): array => (new HtmlTransformer())->transform($html, array())->toArray();
$css = static fn (array $result): string => implode("\n", array_map(
    static fn (array $asset): string => (string) ($asset['content'] ?? ''),
    array_filter($result['assets'], static fn (array $asset): bool => 'css' === ($asset['kind'] ?? ''))
));
$geometryRule = static function (string $markup, string $stylesheet): array {
    if (!preg_match('/<img [^>]*class="[^"]*\b(be-inline-geometry-[a-f0-9-]+)/', $markup, $match)) return array('', '');
    preg_match_all('/(?:@media[^{]*\{)?\.' . preg_quote($match[1], '/') . '(?:>img)?\{[^}]*\}\}?/', $stylesheet, $rules);
    return array($match[1], implode("\n", $rules[0]));
};
$rules = '.ZNcrb1 img,.ZNcrb1 svg{display:block;width:var(--icon-size,26px)!important;height:var(--icon-size,26px)!important;position:static!important}'
    . '.ZNcrb1.cz21gI img,.ZNcrb1.cz21gI svg{width:var(--logged-out-icon-size,26px)!important;height:var(--logged-out-icon-size,26px)!important}'
    . '.ZNcrb1{display:block;flex-shrink:0;overflow:hidden}'
    . '.O4eQsz{display:flex;align-items:center;padding:6px 7px}'
    . '#comp-login{display:flex;--icon-size:24px;--logged-out-icon-size:24px}';
$markup = '<header><div id="comp-login"><button class="O4eQsz"><div class="ZNcrb1 cz21gI"><div class="NIALMs"><div class="iL7Pq5"><svg width="50" height="50" viewBox="0 0 50 50"><circle cx="25" cy="25" r="24"/></svg></div></div></div><span>Sign In</span></button></div></header>';

// 1. Media-scoped wrapper sizing reaches the materialized icon, under its media query.
$scoped = $transform('<style>@media (min-width:768px){' . $rules . '}</style>' . $markup);
[$class, $carrier] = $geometryRule($scoped['serialized_blocks'], $css($scoped));
$assert('' !== $class, 'the icon is materialized with a geometry carrier');
$assert(1 === preg_match('/@media \(min-width:768px\)\{\.' . preg_quote($class, '/') . '\{[^}]*--logged-out-icon-size:24px[^}]*width:var\(--logged-out-icon-size,26px\)!important[^}]*height:var\(--logged-out-icon-size,26px\)!important/', $carrier), 'the 24px size is restated on the carrier inside the source media query: ' . $carrier);
$assert(1 === preg_match('/\.' . preg_quote($class, '/') . '\{display:inline;vertical-align:baseline;width:auto;height:auto\}/', $carrier), 'outside the media query the icon keeps its intrinsic size, as in the source');
$assert(!str_contains($carrier, 'var(--icon-size'), 'the more specific logged-out rule wins over the generic icon rule, as in the source');

// 2. Different sizes per viewport stay per viewport.
$split = $transform('<style>@media (min-width:768px){.w svg{width:24px;height:24px}}@media (max-width:767px){.w svg{width:32px;height:32px}}</style><main><button class="b"><span class="w"><svg width="50" height="50" viewBox="0 0 50 50"><circle cx="25" cy="25" r="24"/></svg></span>Go</button></main>');
[$splitClass, $splitCarrier] = $geometryRule($split['serialized_blocks'], $css($split));
$assert(1 === preg_match('/@media \(min-width:768px\)\{\.' . preg_quote($splitClass, '/') . '\{width:24px;height:24px\}\}/', $splitCarrier), 'desktop size stays scoped to desktop: ' . $splitCarrier);
$assert(1 === preg_match('/@media \(max-width:767px\)\{\.' . preg_quote($splitClass, '/') . '\{width:32px;height:32px\}\}/', $splitCarrier), 'mobile size stays scoped to mobile');

// 3. Unscoped sizing is unchanged: no media rules are added.
$plain = $transform('<style>' . $rules . '</style>' . $markup);
$assert(str_contains($plain['serialized_blocks'], 'width:var(--logged-out-icon-size,26px)!important'), 'unscoped sizing still rides the inline style');
$assert(!preg_match('/@media[^{]*\{\.be-inline-geometry-/', $css($plain)), 'no media-scoped carrier is emitted without media-scoped sizing');

// 4. Media rules that do not size the icon change nothing.
$unrelated = $transform('<style>@media (min-width:768px){.w svg{fill:red}.b{padding:4px}}</style><main><button class="b"><span class="w"><svg width="20" height="20" viewBox="0 0 20 20"><circle cx="10" cy="10" r="9"/></svg></span>Go</button></main>');
$assert(!preg_match('/@media[^{]*\{\.be-inline-geometry-/', $css($unrelated)), 'a media rule without box properties adds no carrier rule');

// 5. A static size that a media query also sets keeps today's static handling.
$mixed = $transform('<style>.w svg{width:20px;height:20px}@media (max-width:767px){.w svg{width:30px;height:30px}}</style><main><button class="b"><span class="w"><svg width="50" height="50" viewBox="0 0 50 50"><circle cx="25" cy="25" r="24"/></svg></span>Go</button></main>');
$assert(!preg_match('/@media[^{]*\{\.be-inline-geometry-/', $css($mixed)), 'axes with a resting size are left to the existing path');

$priority = $transform('<style>@media (min-width:768px){' . $rules . '#comp-login{--logged-out-icon-size:24px!important}.later{--logged-out-icon-size:40px}}</style>' . str_replace('id="comp-login"', 'id="comp-login" class="later"', $markup));
[$priorityClass, $priorityCarrier] = $geometryRule($priority['serialized_blocks'], $css($priority));
$assert(str_contains($priorityCarrier, '--logged-out-icon-size:24px!important') && !str_contains($priorityCarrier, '--logged-out-icon-size:40px'), 'media-scoped variable definitions retain importance and specificity');
$nested = $transform('<style>@media (min-width:768px){' . $rules . '#comp-login{--logged-out-icon-size:var(--unit-size);--unit-size:24px}}</style>' . $markup);
[$nestedClass, $nestedCarrier] = $geometryRule($nested['serialized_blocks'], $css($nested));
$assert(str_contains($nestedCarrier, '--logged-out-icon-size:var(--unit-size)') && str_contains($nestedCarrier, '--unit-size:24px'), 'nested media-scoped variable dependencies remain resolvable on the rebuilt carrier');

if ($failures) { fwrite(STDERR, implode("\n", $failures) . "\n"); exit(1); }
echo "Media-scoped SVG icon size passed: {$assertions} assertions\n";
