<?php
declare(strict_types=1);

/**
 * A page backdrop that preceded the chrome inside a shared layout ancestor
 * must still paint behind the chrome once the chrome is a template part.
 *
 * In the source, a positioned site wrapper encloses an absolutely positioned
 * page background and the header, content and footer. The background is
 * sized to that wrapper, so it paints behind the header too. Hoisted into
 * template parts, the header and footer render outside the wrapper, which
 * stays in post-content. The wrapper's box now starts below the header, so
 * the band behind the header falls back to the WordPress canvas (white).
 *
 * The template root (`.wp-site-blocks`) is what spans the chrome and the
 * content in WordPress. It takes over the containing-block role from the
 * wrapper ancestors that held the backdrop, and the chrome roots are kept
 * positioned so they still paint above that backdrop.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$failures = 0;
$passes = 0;
$assert = static function (bool $condition, string $message) use (&$failures, &$passes): void {
    if ($condition) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, "FAIL: {$message}\n");
};

$links = '<nav><a href="index.html">Home</a><a href="about.html">About</a><a href="shop.html">Shop</a></nav>';
$backdrop = '<div id="page-bg" class="page-bg" style="position:absolute;inset:0;background:#fffaf2"></div>';

/** @return array{parts: array<string,array<string,mixed>>, css: string} */
$compile = static function (string $header, string $before, bool $partitioned = true) use ($links): array {
    $document = static function (string $title, string $suffix) use ($header, $before, $links): string {
        $footer = '<footer id="site-footer' . $suffix . '" class="site-footer"><p>Dunbar footer</p>' . $links . '</footer>';
        return '<div id="site' . $suffix . '" class="site" style="position:relative">' . str_replace('id="page-bg"', 'id="page-bg' . $suffix . '"', $before)
            . '<div id="root' . $suffix . '" class="root" style="position:relative">' . str_replace(array('id="site-header"', '>Shop<'), array('id="site-header' . $suffix . '"', '' === $suffix ? '>Shop<' : '>Shop now<'), $header)
            . '<main><h1>' . $title . '</h1><p>Body copy.</p></main>' . $footer . '</div></div>';
    };
    $page = static fn(string $title): string => $partitioned
        ? '<div class="data-liberation-desktop-document">' . $document($title, '') . '</div><div class="data-liberation-mobile-document">' . $document($title, '--m') . '</div>'
        : $document($title, '');
    $plan = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
        'index.html' => $page('Home'),
        'about.html' => $page('About'),
        'shop.html' => $page('Shop'),
    )))->toArray()['source_reports']['wordpress_site_plan'];
    $parts = array();
    foreach ($plan['template_parts'] as $part) $parts[(string) ($part['area'] ?? '')] = $part;
    $css = implode("\n", array_map(
        static fn(array $asset): string => (string) ($asset['content'] ?? ''),
        array_filter($plan['assets'], static fn(array $asset): bool => 'css' === ($asset['kind'] ?? null) && str_contains((string) ($asset['path'] ?? ''), 'shared-chrome-context'))
    ));

    return array('parts' => $parts, 'css' => $css);
};

$header = '<header id="site-header" class="site-header"><p>Dunbar Oaks</p>' . $links . '</header>';

// 1. A backdrop precedes the header inside #site: #site gives its
// containing-block role to the template root, #root (which only holds the
// chrome and the content) keeps its own, and the chrome roots stay above.
$backed = $compile($header, $backdrop);
$headerPart = $backed['parts']['header'] ?? array();
$assert('shared_shell' === ($headerPart['placement']['kind'] ?? null) && !empty($headerPart['ancestor_context']['preceded']), '1a: the fixture hoists a preceded header into a shared part: ' . json_encode($headerPart['placement'] ?? null));
$assert(array('site', 'site--m') === ($headerPart['ancestor_context']['backdrop_ids'] ?? null), '1b: the header records the ancestors that held its backdrop, in every viewport variant: ' . json_encode($headerPart['ancestor_context'] ?? null));
$assert(str_contains($backed['css'], ':where(.wp-site-blocks){position:relative}'), '1c: the template root becomes the backdrop containing block: ' . $backed['css']);
$assert(str_contains($backed['css'], '.wp-site-blocks #site,.wp-site-blocks #site--m{position:static}'), '1d: the backdrop wrappers give up their containing-block role on the front end only: ' . $backed['css']);
$assert(!preg_match('/#root(?:--m)?[,{]/', $backed['css']), '1e: an ancestor that holds only chrome and content keeps its positioning: ' . $backed['css']);
$assert(str_contains($backed['css'], ':where(#site-header){z-index:1}'), '1f: the #2550 header root lift still holds: ' . $backed['css']);
$assert(1 === preg_match('/:where\([^)]*#site-header[,)][^{]*\{position:relative\}/', $backed['css']) && 1 === preg_match('/:where\([^)]*#site-footer[,)][^{]*\{position:relative\}/', $backed['css']), '1g: header and footer roots stay positioned above the backdrop: ' . $backed['css']);

// 2. Nothing precedes the header: no containing-block transfer.
$plain = $compile($header, '');
$assert('shared_shell' === ($plain['parts']['header']['placement']['kind'] ?? null), '2a: the unpreceded header still hoists.');
$assert(array() === ($plain['parts']['header']['ancestor_context']['backdrop_ids'] ?? array()), '2b: no backdrop ancestors without preceding content: ' . json_encode($plain['parts']['header']['ancestor_context'] ?? null));
$assert(!str_contains($plain['css'], 'wp-site-blocks'), '2c: no containing-block transfer without a backdrop: ' . $plain['css']);

// 3. A chrome root without an id cannot be kept above the backdrop: leave
// the source containment alone rather than paint the backdrop over it.
$unanchored = $compile('<header class="site-header"><p>Dunbar Oaks</p>' . $links . '</header>', $backdrop);
$assert('shared_shell' === ($unanchored['parts']['header']['placement']['kind'] ?? null), '3a: the unanchored header still hoists.');
$assert(!str_contains($unanchored['css'], 'wp-site-blocks'), '3b: no containing-block transfer when a chrome root cannot be lifted: ' . $unanchored['css']);

if ($failures > 0) {
    fwrite(STDERR, sprintf("%d failure(s), %d pass(es)\n", $failures, $passes));
    exit(1);
}
echo sprintf("shared chrome backdrop containing-block contract passed (%d assertions)\n", $passes);
