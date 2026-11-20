<?php
declare(strict_types=1);

/**
 * Shared footer chrome with a fixed-position anchor sibling after `<footer>`
 * and a "Book Now" buttons wrapper inside the footer (base44-imb shape,
 * #7479). Pins the output that the WrapperDecision migration (#7471) must
 * keep byte-identical:
 *
 * - every page shares one footer part, which holds the floating link right
 *   after `</footer>`; no page gets a footer variant and the link never lands
 *   in page content;
 * - the footer's `core/buttons` wrapper and its `core/button` keep the same
 *   control class, and the flex column around them stays its own group.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void {
    if ( ! $condition ) {
        throw new RuntimeException($message);
    }
};

$footer = '<footer class="site-footer"><div class="footer-grid"><div class="footer-col"><h4 class="footer-label">Book Now</h4>'
    . '<p class="footer-copy">Ready to plan your event? Get in touch today.</p><a class="footer-cta" href="/booking.html">Book Now</a></div></div>'
    . '<div class="footer-base"><div class="footer-legal">© 2026 IMB Catering.</div></div></footer>'
    . '<a href="https://wa.me/60173678753" target="_blank" rel="noreferrer" aria-label="Chat with us on WhatsApp" class="whatsapp-float">'
    . '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"></path></svg></a>';
$page = static fn (string $title, string $body): string => '<!doctype html><html><head><title>' . $title . '</title><link rel="stylesheet" href="/style.css"></head><body><div id="root">'
    . '<header class="site-header"><nav aria-label="Main"><a href="/index.html">Home</a><a href="/contact.html">Contact</a></nav></header>'
    . '<main><section class="hero"><h1>' . $title . '</h1><p>' . $body . '</p></section></main>' . $footer . '</div></body></html>';
$css = '.site-footer{background:#123;color:#fff}'
    . '.footer-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:2.5rem;padding:3.5rem 1rem}'
    . '.footer-col{display:flex;flex-direction:column}'
    . '.footer-cta{display:inline-flex;align-items:center;justify-content:center;margin-top:1rem;padding:.5rem 1rem;border-radius:.375rem;background:#fff;color:#123;font-size:.875rem}'
    . '.footer-base{border-top:1px solid rgba(255,255,255,.15)}.footer-legal{padding:1.25rem 1rem;text-align:center;font-size:.75rem}'
    . '.whatsapp-float{position:fixed;bottom:1.25rem;right:1.25rem;z-index:50;display:flex;width:3.5rem;height:3.5rem;align-items:center;justify-content:center;border-radius:9999px;background:#25D366;color:#fff}';

$result = ( new ArtifactCompiler() )->compile(array(
    'entrypoint' => 'index.html',
    'files'      => array(
        'index.html'   => $page('Home', 'Welcome.'),
        'contact.html' => $page('Contact', 'Reach us.'),
        'style.css'    => $css,
    ),
))->toArray();
$plan = ( new WordPressSitePlan() )->fromResult($result);

$footers = array_values(array_filter($plan['template_parts'], static fn (array $part): bool => 'footer' === ( $part['area'] ?? null )));
$assert(1 === count($footers), 'expected one shared footer part, found ' . count($footers));
$assert('footer' === $footers[0]['slug'], 'the shared footer part must be the canonical footer, not a variant');
$markup = $footers[0]['canonical_block_markup'];

// The fixed-position anchor sibling stays in the shared chrome, right after </footer>.
$close = strpos($markup, '</footer><!-- /wp:group -->');
$link  = strpos($markup, '<!-- wp:custom/accessible-link {"href":"https://wa.me/60173678753"');
$assert(false !== $close && false !== $link, 'the footer part must hold the footer and the floating link');
$assert($link === $close + strlen('</footer><!-- /wp:group -->'), 'the floating link must directly follow the footer group');
$assert(1 === substr_count($markup, '<!-- wp:custom/accessible-link '), 'the floating link must be emitted once');

// The "Book Now" buttons wrapper and its button share one control class.
$assert(1 === preg_match('/<!-- wp:buttons \{[^\n]*?"className":"(blocks-engine-control-[0-9a-f]+-0)"\} -->/', $markup, $buttons), 'the footer buttons wrapper must carry its control class');
$control = $buttons[1];
$assert(str_contains($markup, '<div class="wp-block-buttons ' . $control . '"'), 'the buttons wrapper must save its control class');
$assert(str_contains($markup, '"backgroundColor":"base","className":"' . $control . '"} --><div class="wp-block-button ' . $control . '">'), 'the button must carry the same control class as its wrapper');
$assert(str_contains($markup, 'href="/booking.html">Book Now</a>'), 'the button must keep its link');
$assert(str_contains($markup, '<!-- wp:group {"className":"footer-col blocks-engine-css-owned-layout"} -->'), 'the flex column around the buttons must stay its own group');

foreach ( $plan['templates'] as $template ) {
    $assert(! str_contains($template['canonical_block_markup'], 'wa.me/'), "template {$template['slug']} must not inline the floating link");
}
foreach ( $plan['pages'] as $entry ) {
    $page = $entry['canonical_block_markup'];
    $assert(! str_contains($page, 'wa.me/') && ! str_contains($page, 'Book Now'), 'footer chrome must leave page content');
}

fwrite(STDOUT, "Footer fixed link and buttons chrome tests passed\n");
