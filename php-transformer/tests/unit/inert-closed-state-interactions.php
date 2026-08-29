<?php
declare(strict_types=1);

/**
 * Closed interactive widgets must not import as dead hidden controls.
 *
 * Wix FAQ accordions and hover dropdowns hide their panels with closed-state
 * CSS (`height:0;overflow:hidden`, `visibility:hidden`) and reveal them from
 * script. Import strips that script. Leaving the closed CSS in place freezes
 * answers and submenu links as unreachable.
 *
 * Native operability wins when the structure can be represented
 * (`core/accordion`, `core/navigation-submenu`). Otherwise the closed state is
 * materialized visible/static rather than retained as inert markup.
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
    fwrite(STDERR, 'FAIL: ' . $message . ( '' !== $detail ? ' - ' . $detail : '' ) . PHP_EOL);
};

$faq = <<<'HTML'
<div data-hook="widget-accordion-wrapper">
  <div role="region">
    <div>
      <button aria-controls="a1" aria-expanded="false"><h4>What is a chiropractor?</h4></button>
      <div style="height:0;overflow:hidden" class="rah-static rah-static--height-zero">
        <div style="opacity:0;display:none">
          <div id="a1" role="region" aria-hidden="true"><p>A chiropractor adjusts the spine.</p></div>
        </div>
      </div>
    </div>
    <div>
      <button aria-controls="a2" aria-expanded="true"><h4>How long will I need care?</h4></button>
      <div style="height:auto">
        <div id="a2" role="region" aria-hidden="false"><p>Care length varies by person.</p></div>
      </div>
    </div>
  </div>
</div>
HTML;

$faqResult = ( new HtmlTransformer() )->transform($faq)->toArray();
$faqMarkup = (string) ($faqResult['serialized_blocks'] ?? '');
$faqCss = implode("\n", array_map(
    static fn (array $asset): string => (string) ($asset['content'] ?? ''),
    array_values(array_filter(
        is_array($faqResult['assets'] ?? null) ? $faqResult['assets'] : array(),
        static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '')
    ))
));

$assert(
    str_contains($faqMarkup, '<!-- wp:accordion'),
    'a structural disclosure list becomes a native accordion rather than dead buttons',
    $faqMarkup
);
$assert(
    ! str_contains($faqMarkup, '<!-- wp:button'),
    'disclosure toggles are not left as inert core/button controls',
    $faqMarkup
);
$assert(
    str_contains($faqMarkup, 'A chiropractor adjusts the spine.')
        && str_contains($faqMarkup, 'Care length varies by person.'),
    'closed and open disclosure panels both keep their answers',
    $faqMarkup
);
$assert(
    ! str_contains($faqCss, 'height:0') && ! str_contains($faqMarkup, 'height:0'),
    'collapsed height:0 closed state is not frozen into geometry',
    $faqCss
);
$assert(
    'pass' === ($faqResult['source_reports']['wp_block_validity']['status'] ?? ''),
    'the native FAQ outcome remains Gutenberg-valid',
    json_encode($faqResult['source_reports']['wp_block_validity'] ?? array())
);

$nav = <<<'HTML'
<style>
._horizontalDropdown { visibility: hidden; display: none !important; }
._itemWrapper[data-open="true"] ._horizontalDropdown { visibility: visible; display: grid !important; }
</style>
<nav>
  <ul>
    <li><a href="/">Home</a></li>
    <li>
      <div class="_itemWrapper">
        <div class="item _labelContainer">
          <a href="/conditions">Conditions</a>
          <button aria-expanded="false" aria-label="More Conditions pages"></button>
          <button aria-expanded="false" aria-label="More Conditions pages" class="_srOnly"></button>
        </div>
        <div class="dropdown _horizontalDropdown">
          <div role="group" aria-label="Conditions">
            <div class="submenu">
              <ul>
                <li><a class="dropdown-item" href="/back-pain-relief">Back Pain Relief</a></li>
                <li><a class="dropdown-item" href="/sciatica">Sciatica</a></li>
              </ul>
            </div>
          </div>
        </div>
      </div>
    </li>
  </ul>
</nav>
HTML;

$navResult = ( new HtmlTransformer() )->transform($nav)->toArray();
$navMarkup = (string) ($navResult['serialized_blocks'] ?? '');
$navAfterAuthor = implode("\n", array_map(
    static fn (array $asset): string => (string) ($asset['content'] ?? ''),
    array_values(array_filter(
        is_array($navResult['assets'] ?? null) ? $navResult['assets'] : array(),
        static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '')
            && 'after-author' === ($asset['stylesheet_placement'] ?? '')
            && 'editor' !== ($asset['stylesheet_target'] ?? 'both')
    ))
));

$assert(
    str_contains($navMarkup, '<!-- wp:navigation '),
    'a landmark with nested dropdown items promotes to core/navigation',
    $navMarkup
);
$assert(
    str_contains($navMarkup, '<!-- wp:navigation-submenu')
        && str_contains($navMarkup, '"url":"/back-pain-relief"')
        && str_contains($navMarkup, 'Back Pain Relief'),
    'a nested closed dropdown becomes a native submenu with reachable child links',
    $navMarkup
);
$assert(
    ! str_contains($navMarkup, 'class="dropdown') || str_contains($navAfterAuthor, 'visibility:visible'),
    'closed dropdown CSS does not remain the only way to reach submenu links',
    $navMarkup . "\n" . $navAfterAuthor
);
$assert(
    'pass' === ($navResult['source_reports']['wp_block_validity']['status'] ?? ''),
    'the native dropdown outcome remains Gutenberg-valid',
    json_encode($navResult['source_reports']['wp_block_validity'] ?? array())
);

$staticFallback = ( new HtmlTransformer() )->transform(
    '<style>.runtime-panel{display:none!important;visibility:hidden!important}</style>'
    . '<div><div class="runtime-panel"><p>Static fallback content.</p></div></div>'
)->toArray();
$staticCss = implode("\n", array_map(
    static fn (array $asset): string => (string) ($asset['content'] ?? ''),
    array_values(array_filter(
        is_array($staticFallback['assets'] ?? null) ? $staticFallback['assets'] : array(),
        static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '')
            && 'after-author' === ($asset['stylesheet_placement'] ?? '')
            && 'editor' !== ($asset['stylesheet_target'] ?? 'both')
    ))
));
$assert(
    str_contains((string) ($staticFallback['serialized_blocks'] ?? ''), 'Static fallback content.')
        && str_contains($staticCss, '.runtime-panel{display:revert!important;visibility:visible!important}'),
    'an unrepresented runtime-hidden region is exposed visibly on the frontend',
    $staticCss
);

$emptyCollapsed = <<<'HTML'
<div>
  <div>
    <button aria-controls="empty-a" aria-expanded="false"><h4>Is treatment safe?</h4></button>
    <div style="height:0;overflow:hidden">
      <div id="empty-a" role="region" aria-hidden="true"><div></div></div>
    </div>
  </div>
  <div>
    <button aria-controls="empty-b" aria-expanded="false"><h4>Does treatment hurt?</h4></button>
    <div style="height:0;overflow:hidden">
      <div id="empty-b" role="region" aria-hidden="true"><div></div></div>
    </div>
  </div>
</div>
HTML;

$emptyResult = ( new HtmlTransformer() )->transform($emptyCollapsed)->toArray();
$emptyMarkup = (string) ($emptyResult['serialized_blocks'] ?? '');
$assert(
    ! str_contains($emptyMarkup, '<!-- wp:button'),
    'empty closed disclosures do not keep dead toggle buttons',
    $emptyMarkup
);
$assert(
    str_contains($emptyMarkup, 'Is treatment safe?') && str_contains($emptyMarkup, 'Does treatment hurt?'),
    'empty closed disclosures still expose their questions',
    $emptyMarkup
);

if ( $failures > 0 ) {
    fwrite(STDERR, "inert closed-state interactions: {$failures} failed, {$passes} passed\n");
    exit(1);
}

fwrite(STDOUT, "inert closed-state interactions: {$passes} passed\n");
