<?php
declare(strict_types=1);

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\GeneratedSupportStylesheetState;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlCompilation;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$assert = static function (bool $condition, string $message): void {
    if ( ! $condition ) {
        throw new RuntimeException($message);
    }
};

$candidate = new GeneratedSupportStylesheetState();
$candidate->registerNativeButton('accepted-button', '.accepted-button{background-color:transparent}');
$candidate->registerResponsiveTypography(
    'accepted-responsive',
    '16px',
    array('@media (min-width: 720px)' => '22px')
);
$candidate->registerAccordionIconPresentation('accepted-state', array(
    'closed' => 'transform:rotate(0deg)',
    'open' => 'transform:rotate(180deg)',
));
$candidate->registerDisclosureControlConditionalPresentation('accepted-responsive', array(
    '@media (max-width: 600px)' => 'padding:8px',
));
$candidate->registerResponsiveTypography(
    'declined-responsive',
    '14px',
    array('@media (min-width: 720px)' => '18px')
);
$candidate->registerAccordionIconPresentation('declined-state', array(
    'closed' => 'transform:rotate(0deg)',
    'open' => 'transform:rotate(90deg)',
));

$accepted = new GeneratedSupportStylesheetState();
$accepted->commitAcceptedFrom($candidate, array(
    array('blockName' => 'core/button', 'attrs' => array('className' => 'accepted-button')),
    array('blockName' => 'core/paragraph', 'attrs' => array('className' => 'accepted-responsive')),
    array('blockName' => 'core/details', 'attrs' => array('className' => 'accepted-state')),
));
$serializedAccepted = 'accepted-button accepted-responsive accepted-state';
$css = implode("\n", array_map(
    static fn ($rule): string => $rule->css,
    $accepted->conditionalAfterAuthorCss($serializedAccepted)
));
$buttonCss = implode("\n", array_map(
    static fn ($rule): string => $rule->css,
    $accepted->buttonAfterAuthorCss()
));

$assert(str_contains($buttonCss, 'accepted-button'), 'accepted native-button support record is retained');
$assert(str_contains($css, '@media (min-width: 720px)'), 'responsive support record keeps its condition');
$assert(str_contains($css, '@media (max-width: 600px)'), 'conditional presentation keeps its media condition');
$assert(str_contains($css, '[aria-expanded="true"]'), 'accordion support retains its expanded state selector');
$assert(str_contains($css, 'rotate(180deg)'), 'accordion support retains its open-state declarations');
$assert(! str_contains($css, 'declined-'), 'records owned by blocks absent from the accepted result are not committed');

$rejected = new GeneratedSupportStylesheetState();
// A declined fragment has no accepted block identities, so its exploratory
// records remain isolated and cannot create assets in the owning compilation.
$rejected->commitAcceptedFrom($candidate, array());
$rejectedCss = implode("\n", array_map(
    static fn ($rule): string => $rule->css,
    $rejected->conditionalAfterAuthorCss('')
));
$assert('' === $rejectedCss && array() === $rejected->buttonAfterAuthorCss(), 'declined fragment contributes no generated support assets');

// Exercise the real accepted-fragment boundary with unrelated existing support
// families. This is also the regression that fails on the previous compiler:
// the old transport extracted only a button CSS substring and dropped the
// disclosure support record from the accepted fragment.
$compilation = new HtmlCompilation(new Runtime());
$compilation->transform('<p>Initialize fragment session.</p>');
$safeFragment = new ReflectionMethod($compilation, 'safeFallbackFragmentBlocks');
$fragmentBlocks = $safeFragment->invoke(
    $compilation,
    '<a href="/care/" role="button" style="display:flex;gap:4px">Brand</a>'
        . '<details><summary style="background-color:rgb(1, 2, 3);padding:4px">Services</summary><p>Care</p></details>'
);
$assert(is_array($fragmentBlocks) && array() !== $fragmentBlocks, 'native fragment is accepted');
$supportAccessor = new ReflectionMethod($compilation, 'generatedSupportStyles');
$support = $supportAccessor->invoke($compilation);
$fragmentMarkup = (new Runtime())->serializeBlocks($fragmentBlocks);
$fragmentCss = implode("\n", array_map(
    static fn ($rule): string => $rule->css,
    $support->conditionalAfterAuthorCss($fragmentMarkup)
));
$fragmentButtonCss = implode("\n", array_map(
    static fn ($rule): string => $rule->css,
    $support->buttonAfterAuthorCss()
));
$assert(str_contains($fragmentCss, '.wp-block-details.blocks-engine-disclosure-summary-'), 'accepted fragment retains disclosure support record');
$assert(str_contains($fragmentCss, 'background-color:rgb(1, 2, 3)'), 'accepted disclosure record retains its declarations');
$assert(str_contains($fragmentButtonCss, 'background-color:transparent!important'), 'accepted fragment retains native-button support record');
$acceptedCssBeforeDecline = $fragmentCss . $fragmentButtonCss;
$declinedBlocks = $safeFragment->invoke(
    $compilation,
    '<a href="/declined/" role="button" style="display:flex">Declined</a><form><input name="q"></form>'
);
$assert(null === $declinedBlocks, 'unsafe fragment is declined');
$afterDecline = implode("\n", array_map(
    static fn ($rule): string => $rule->css,
    $support->conditionalAfterAuthorCss($fragmentMarkup)
)) . implode("\n", array_map(
    static fn ($rule): string => $rule->css,
    $support->buttonAfterAuthorCss()
));
$assert($acceptedCssBeforeDecline === $afterDecline, 'declined fragment leaves accepted generated support unchanged');

echo "OK: generated support fragment ownership passed\n";
