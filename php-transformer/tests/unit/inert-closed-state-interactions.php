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
 * (`core/accordion`, `core/details`, `core/navigation-submenu`). Otherwise the
 * closed state is materialized visible/static rather than retained as inert
 * markup.
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

$cssContent = static function (array $result, ?string $placement = null, ?string $target = null): string {
    $chunks = array();
    foreach ( is_array($result['assets'] ?? null) ? $result['assets'] : array() as $asset ) {
        if ( 'css' !== ($asset['kind'] ?? '') ) {
            continue;
        }
        if ( null !== $placement && $placement !== ($asset['stylesheet_placement'] ?? '') ) {
            continue;
        }
        if ( null !== $target && $target !== ($asset['stylesheet_target'] ?? 'both') ) {
            continue;
        }
        $chunks[] = (string) ($asset['content'] ?? '');
    }

    return implode("\n", $chunks);
};

$faq = <<<'HTML'
<div data-hook="widget-accordion-wrapper">
  <div role="region">
    <div>
      <div>
        <button aria-controls="a1" aria-expanded="false"><h4>What is a chiropractor?</h4></button>
      </div>
      <div style="height:0;overflow:hidden" class="rah-static rah-static--height-zero">
        <div style="opacity:0;display:none">
          <div id="a1" role="region" aria-hidden="true"><p>A chiropractor adjusts the spine.</p></div>
        </div>
      </div>
    </div>
    <div>
      <div>
        <button aria-controls="a2" aria-expanded="true"><h4>How long will I need care?</h4></button>
      </div>
      <div style="height:auto">
        <div id="a2" role="region" aria-hidden="false"><p>Care length varies by person.</p></div>
      </div>
    </div>
  </div>
</div>
HTML;

$faqResult = ( new HtmlTransformer() )->transform($faq)->toArray();
$faqMarkup = (string) ($faqResult['serialized_blocks'] ?? '');
$faqCss = $cssContent($faqResult);

$assert(
    str_contains($faqMarkup, '<!-- wp:accordion') || str_contains($faqMarkup, '<!-- wp:details'),
    'a structural disclosure list becomes native accordion or details rather than dead buttons',
    $faqMarkup
);
$assert(
    ! str_contains($faqMarkup, '<!-- wp:button'),
    'disclosure toggles are not left as inert core/button controls',
    $faqMarkup
);
$assert(
    ! str_contains($faqMarkup, 'aria-expanded="false"'),
    'imported FAQ markup does not keep source-closed aria-expanded controls',
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

$nav = <<<'HTML'
<style>
.dropdown { visibility: hidden; display: none; }
.item:hover .dropdown { visibility: visible; display: block; }
</style>
<nav>
  <ul>
    <li><a href="/">Home</a></li>
    <li>
      <div class="_itemWrapper">
        <div class="item _labelContainer">
          <a href="/conditions">Conditions</a>
          <button aria-expanded="false" aria-label="More Conditions pages"></button>
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
$navAfterAuthor = $cssContent($navResult, 'after-author', 'both');

$assert(
    str_contains($navMarkup, '<!-- wp:navigation '),
    'a landmark with nested dropdown items promotes to core/navigation',
    $navMarkup
);
$assert(
    str_contains($navMarkup, '<!-- wp:navigation-submenu')
        && str_contains($navMarkup, '"url":"/back-pain-relief"')
        && str_contains($navMarkup, 'Back Pain Relief')
        && str_contains($navMarkup, '"url":"/sciatica"'),
    'a nested closed dropdown becomes a native submenu with reachable child links',
    $navMarkup
);
$assert(
    str_contains($navAfterAuthor, 'visibility:visible') || ! str_contains($navMarkup, 'class="dropdown'),
    'closed dropdown CSS does not remain the only way to reach submenu links',
    $navMarkup . "\n" . $navAfterAuthor
);

$hiddenAnswer = <<<'HTML'
<style>
.answer { display: none !important; visibility: hidden; height: 0; overflow: hidden; }
</style>
<p class="answer">This answer must remain readable after import.</p>
HTML;

$hiddenAnswerResult = ( new HtmlTransformer() )->transform($hiddenAnswer)->toArray();
$hiddenAnswerMarkup = (string) ($hiddenAnswerResult['serialized_blocks'] ?? '');
$hiddenAnswerAfterAuthor = $cssContent($hiddenAnswerResult, 'after-author', 'both');
$assert(
    str_contains($hiddenAnswerMarkup, 'This answer must remain readable after import.'),
    'closed-state stylesheet content is preserved in the document',
    $hiddenAnswerMarkup
);
$assert(
    str_contains($hiddenAnswerAfterAuthor, 'visibility:visible')
        || str_contains($hiddenAnswerAfterAuthor, 'display:revert')
        || str_contains($hiddenAnswerAfterAuthor, 'height:auto'),
    'author closed-state CSS is repaired on the frontend after behavior is stripped',
    $hiddenAnswerAfterAuthor
);

$responsiveDocument = <<<'HTML'
<style>
.mobile-document { display: none !important; }
@media (max-width: 600px) {
  .desktop-document { display: none !important; }
  .mobile-document { display: contents !important; }
}
</style>
<div class="desktop-document"><p>Desktop document</p></div>
<div class="mobile-document"><p>Mobile document</p></div>
HTML;

$responsiveDocumentResult = ( new HtmlTransformer() )->transform($responsiveDocument)->toArray();
$responsiveDocumentAfterAuthor = $cssContent($responsiveDocumentResult, 'after-author', 'both');
$assert(
    ! str_contains($responsiveDocumentAfterAuthor, '.mobile-document{display:revert'),
    'a media-revealed responsive document does not receive a global closed-state display repair',
    $responsiveDocumentAfterAuthor
);

$inactiveDialog = <<<'HTML'
<style>
#menu-portal { visibility: hidden; opacity: 0; }
</style>
<div id="menu-portal" role="dialog" aria-modal="true" data-visible="false">
  <nav><a href="/about">About</a></nav>
</div>
HTML;

$inactiveDialogResult = ( new HtmlTransformer() )->transform($inactiveDialog)->toArray();
$inactiveDialogAfterAuthor = $cssContent($inactiveDialogResult, 'after-author', 'both');
$assert(
    ! str_contains($inactiveDialogAfterAuthor, '#menu-portal{opacity:1')
        && ! str_contains($inactiveDialogAfterAuthor, '#menu-portal{visibility:visible'),
    'an explicitly inactive runtime state does not receive a global visibility repair',
    $inactiveDialogAfterAuthor
);

$products = <<<'HTML'
<div class="products">
  <div class="item"><h3>Product A</h3><p>Nice chair.</p></div>
  <div class="item"><h3>Product B</h3><p>Nice table.</p></div>
</div>
HTML;

$productsMarkup = (string) ( ( new HtmlTransformer() )->transform($products)->toArray()['serialized_blocks'] ?? '' );
$assert(
    ! str_contains($productsMarkup, '<!-- wp:accordion'),
    'ordinary item grids without disclosure controls are not converted to accordion',
    $productsMarkup
);

$nativeDetails = <<<'HTML'
<div class="questions">
  <details open><summary>How does this work?</summary><p>The first answer.</p></details>
  <details><summary>Can I inspect it?</summary><p>The second answer.</p></details>
</div>
HTML;

$nativeDetailsMarkup = (string) ( ( new HtmlTransformer() )->transform($nativeDetails)->toArray()['serialized_blocks'] ?? '' );
$assert(
    2 === substr_count($nativeDetailsMarkup, '<!-- wp:details')
        && ! str_contains($nativeDetailsMarkup, '<!-- wp:accordion'),
    'an ordinary group of native details keeps independently styleable details markup',
    $nativeDetailsMarkup
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
