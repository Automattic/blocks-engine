<?php
declare(strict_types=1);

/**
 * A field's own helper/description copy — "Link to your design work…" under
 * a Portfolio URL input — is neither the field's label nor a control, so
 * nothing in the control manifest carried it and it vanished silently when a
 * div pseudo-form's converted subtree was replaced by a provider's materialized
 * form. See #718's follow-up regression: the label survived onto the control,
 * but non-control descendant text sitting beside it did not.
 *
 * Record it on the control it describes — mappable onto a provider's native
 * per-field description affordance (Jetpack's `helpText`, for one) — read the
 * same way a positional label is read: only from a wrapper the control
 * exclusively owns, so the text cannot actually belong to a sibling field.
 * More than one candidate in that wrapper is reported as ambiguous rather
 * than guessed at.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$passes = 0;
$assert = static function (bool $ok, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $ok ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ( '' !== $detail ? ' - ' . $detail : '' ) . PHP_EOL);
};

/** Pull the first fallback finding's controls, keyed by name. */
$controlsByName = static function (string $html): array {
    $result = ( new HtmlTransformer() )->transform($html, array())->toArray();
    $fallback = current(array_filter($result['fallbacks'] ?? array(), static fn (array $fallback): bool => 'html_form_fallback' === ($fallback['diagnostic_code'] ?? null)));
    if ( ! is_array($fallback) ) {
        return array();
    }
    return array_column($fallback['controls'] ?? array(), null, 'name');
};

// The real regression: a description paragraph sits inside the same
// exclusively-owned field wrapper as its control, after the control.
$controls = $controlsByName(
    '<main><div class="signup"><div class="field"><label>Portfolio URL</label>'
    . '<input type="text" name="portfolio" placeholder="https://example.com">'
    . '<p class="text-xs text-muted-foreground">Link to your design work (Behance, Dribbble, personal site, etc.)</p>'
    . '</div><div class="field"><label>Email</label><input type="email" name="email"></div>'
    . '<button>Subscribe</button></div></main>'
);
$assert(
    'Link to your design work (Behance, Dribbble, personal site, etc.)' === ( $controls['portfolio']['description'] ?? null ),
    'a field description sitting after its control in the same exclusive wrapper is captured on that control',
    json_encode($controls['portfolio'] ?? null)
);
$assert(
    ! isset($controls['email']['description']),
    'a description is not duplicated onto an unrelated sibling field',
    json_encode($controls['email'] ?? null)
);
$assert(
    ! array_key_exists('_unresolved_description_candidates', $controls['portfolio'] ?? array()),
    'an unambiguous description leaves no ambiguity marker behind'
);

// A field with no nearby copy at all reports no description and no ambiguity.
$plainControls = $controlsByName(
    '<main><div class="signup"><div class="field"><label>Email</label><input type="email" name="email"></div>'
    . '<button>Subscribe</button></div></main>'
);
$assert(
    ! isset($plainControls['email']['description']) && ! array_key_exists('_unresolved_description_candidates', $plainControls['email'] ?? array()),
    'a field with no nearby copy reports neither a description nor an ambiguity marker',
    json_encode($plainControls['email'] ?? null)
);

// Two disjoint, independently text-bearing siblings in the same exclusive
// wrapper cannot be safely attributed to the one control they surround.
$ambiguousControls = $controlsByName(
    '<main><div class="signup"><div class="field"><label>Portfolio URL</label>'
    . '<input type="text" name="portfolio"><p>Link to your design work</p><span>Optional but recommended</span></div>'
    . '<button>Subscribe</button></div></main>'
);
$assert(
    ! isset($ambiguousControls['portfolio']['description']),
    'two disjoint description candidates are not guessed at',
    json_encode($ambiguousControls['portfolio'] ?? null)
);
$assert(
    array( 'Link to your design work', 'Optional but recommended' ) === ( $ambiguousControls['portfolio']['_unresolved_description_candidates'] ?? null ),
    'both disjoint candidates are named so a caller can diagnose the loss',
    json_encode($ambiguousControls['portfolio'] ?? null)
);

// A description nested inside a wrapper shared with another control cannot
// be attributed to either one, so neither field reports it.
$sharedWrapperControls = $controlsByName(
    '<main><div class="signup"><div class="row">'
    . '<input type="text" name="first"><input type="text" name="last">'
    . '<p>Both names are required</p>'
    . '</div><button>Subscribe</button></div></main>'
);
$assert(
    ! isset($sharedWrapperControls['first']['description']) && ! isset($sharedWrapperControls['last']['description']),
    'copy in a wrapper shared by two controls is not attributed to either one',
    json_encode($sharedWrapperControls)
);

// A wrapping <label> that contains the visible name, the control, and helper
// copy after the control must not concatenate those two strings. The helper
// is the field description; the label is only the name.
$wrappingLabelControls = $controlsByName(
    '<main><form method="post" action="/join">'
    . '<label class="block"><span>Occupation / business</span>'
    . '<input maxlength="120" name="occupation">'
    . '<span class="mt-1 block text-xs">Helps the trade committee connect members.</span></label>'
    . '<button type="submit">Send</button></form></main>'
);
$assert(
    'Occupation / business' === ( $wrappingLabelControls['occupation']['label'] ?? null ),
    'a wrapping label keeps only the visible name as the control label',
    json_encode($wrappingLabelControls['occupation'] ?? null)
);
$assert(
    'Helps the trade committee connect members.' === ( $wrappingLabelControls['occupation']['description'] ?? null ),
    'helper copy after the control inside a wrapping label is the field description',
    json_encode($wrappingLabelControls['occupation'] ?? null)
);
$assert(
    ! str_contains( (string) ( $wrappingLabelControls['occupation']['label'] ?? '' ), 'Helps' ),
    'the description is not concatenated onto the wrapping label string'
);

// Classic wrapping labels put the visible name after the control.
// That trailing copy is the label, not a description.
$trailingNameControls = $controlsByName(
    '<main><form method="post" action="/join">'
    . '<label><input type="radio" name="format" value="in-person"> In-person meeting</label>'
    . '<button type="submit">Send</button></form></main>'
);
$assert(
    'In-person meeting' === ( $trailingNameControls['format']['label'] ?? null ),
    'a wrapping label whose name follows the control keeps that copy as the label',
    json_encode($trailingNameControls['format'] ?? null)
);
$assert(
    ! isset($trailingNameControls['format']['description']),
    'trailing wrapping-label copy is not treated as a field description',
    json_encode($trailingNameControls['format'] ?? null)
);

if ( 0 < $failures ) {
    fwrite(STDERR, "form field description FAILED: {$passes} passed, {$failures} failed\n");
    exit(1);
}
echo "form field description passed: {$passes} assertions\n";
