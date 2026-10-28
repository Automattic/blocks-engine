<?php
declare(strict_types=1);

/**
 * A provider choice group renders its accessible name as one legend, but the
 * source's visible group caption is a sibling element whose typography never
 * reached the importer: the legend kept provider-default styling instead of
 * the caption the source paints. The presentation graph now carries that
 * caption's resolved text facts as `group_captions` rows owned by the group's
 * first native choice input. See blocks-engine#2760.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\FormPresentationGraphBuilder;

$failures = 0;
$passes   = 0;

$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ( '' !== $detail ? ' - ' . $detail : '' ) . PHP_EOL);
};

$choices = static fn (string $name): string => implode('', array_map(
    static fn (string $value): string => '<button type="button" role="radio"><input type="radio" name="' . $name . '" value="' . $value . '" aria-hidden="true" tabindex="-1"><span>' . $value . '</span></button>',
    array( 'Yes', 'No', 'Maybe' )
));

$compile = static function (string $html): array {
    // These fixtures exercise provider graphs, not choice-only preference islands.
    $html = str_replace('</form>', '<input name="reference"></form>', $html);
    return ( new ArtifactCompiler() )->compile(array( 'entrypoint' => 'index.html', 'files' => array( 'index.html' => $html ) ))->toArray();
};
$graph = static function (array $result): array {
    foreach ( $result['source_reports']['wordpress_site_plan']['runtime_declarations'] ?? array() as $declaration ) {
        if ( 'forms' !== ( $declaration['type'] ?? null ) ) {
            continue;
        }
        return $declaration['payload']['entities'][0]['presentation_graph'] ?? array();
    }

    return array();
};
$rowFor = static function (array $graph, int $index): ?array {
    foreach ( $graph['group_captions'] ?? array() as $row ) {
        if ( ( $row['index'] ?? null ) === $index ) {
            return $row;
        }
    }

    return null;
};

$siblingCaption = $graph($compile('<!doctype html><html><head><style>.group-caption { color: rgb(20, 30, 40); letter-spacing: -0.01em; }</style></head><body><main>'
    . '<form method="post" action="/rsvp"><input type="text" name="name">'
    . '<div class="group-caption" style="font-family: Inter, &quot;Inter Placeholder&quot;, sans-serif; font-size: 14px; font-style: normal; font-weight: 400; line-height: 1.2em">Will you attend?</div>'
    . '<div role="radiogroup" aria-label="Will you attend?" style="display:flex;flex-flow:wrap;gap:12px">' . $choices('Attending') . '</div>'
    . '<button type="submit">Send</button></form></main></body></html>'));
$assert('generic/computed-form-presentation/v2' === ($siblingCaption['schema'] ?? null), 'a form with a group caption graph publishes the v2 envelope', json_encode($siblingCaption['schema'] ?? null));
$attendRow = $rowFor($siblingCaption, 2);
$assert(null !== $attendRow, 'the caption row owns the group first radio input flat index', json_encode($siblingCaption['group_captions'] ?? null));
$assert('rgb(20, 30, 40)' === ($attendRow['styles']['color'] ?? null) && '-0.01em' === ($attendRow['styles']['letter_spacing'] ?? null), 'the stylesheet rule reaches the caption row', json_encode($attendRow['styles'] ?? null));
$assert('Inter, "Inter Placeholder", sans-serif' === ($attendRow['styles']['font_family'] ?? null) && '14px' === ($attendRow['styles']['font_size'] ?? null) && '400' === ($attendRow['styles']['font_weight'] ?? null), 'inline caption typography is resolved row property by property', json_encode($attendRow['styles'] ?? null));
$assert(array() !== array_filter($attendRow['provenance'] ?? array(), static fn (array $fact): bool => 'inline-style' === $fact['source_path'] && in_array('font-size', $fact['properties'], true)) && array() !== array_filter($attendRow['provenance'] ?? array(), static fn (array $fact): bool => '.group-caption' === $fact['selector'] && in_array('color', $fact['properties'], true)), 'caption provenance names the source that declared the caption facts', json_encode($attendRow['provenance'] ?? null));

$labelledCaption = $graph($compile('<!doctype html><html><head><style>.titled { font-weight: 600; }</style></head><body><main>'
    . '<form method="post" action="/rsvp">'
    . '<h2 id="attend-heading" class="titled">Will you attend?</h2>'
    . '<div role="radiogroup" aria-label="ignored" aria-labelledby="attend-heading">' . $choices('Attending') . '</div>'
    . '<button type="submit">Send</button></form></main></body></html>'));
$labelledRow = $rowFor($labelledCaption, 1);
$assert(null !== $labelledRow && '600' === ($labelledRow['styles']['font_weight'] ?? null), 'an aria-labelledby title resolves the caption row from its referenced element', json_encode($labelledRow ?? null));

$missingReferenceCaption = $graph($compile('<!doctype html><body><main>'
    . '<form method="post" action="/rsvp"><div class="titled" id="one">Number of guests</div>'
    . '<span id="two" style="color:rgb(1,2,3)">Number of guests</span>'
    . '<div role="radiogroup" aria-label="Number of guests" aria-labelledby="gone two">' . $choices('Guests') . '</div>'
    . '</form></main></body></html>'));
$missingRow = $rowFor($missingReferenceCaption, 1);
$assert('rgb(1,2,3)' === ($missingRow['styles']['color'] ?? null), 'an unresolved aria-labelledby reference falls through to the first existing target', json_encode($missingRow ?? null));

$hiddenReferenceCaption = $graph($compile('<!doctype html><body><main>'
    . '<form method="post" action="/rsvp"><div role="radiogroup" aria-label="Number of guests" aria-labelledby="form-heading">'
    . $choices('Guests') . '</div></form>'
    . '</main><h2 id="form-heading" style="font-size:20px">Number of guests</h2></body></html>'));
$assert(array() === ($hiddenReferenceCaption['group_captions'] ?? array()), 'an aria-labelledby target outside the form owns no caption row', json_encode($hiddenReferenceCaption['group_captions'] ?? null));

$spanCarried = $graph($compile('<!doctype html><body><main>'
    . '<form method="post" action="/rsvp">'
    . '<div style="font-size:16px;color:rgb(1,2,3)">'
    . '<span style="font-size:14px;font-weight:500">Will you attend?</span></div>'
    . '<div role="radiogroup" aria-label="Will you attend?">' . $choices('Attending') . '</div>'
    . '</form></main></body></html>'));
$carriedRow = $rowFor($spanCarried, 1);
$assert('14px' === ($carriedRow['styles']['font_size'] ?? null) && '500' === ($carriedRow['styles']['font_weight'] ?? null), 'typography the caption text carrier declares wins property by property', json_encode($carriedRow['styles'] ?? null));
$assert('rgb(1,2,3)' === ($carriedRow['styles']['color'] ?? null), 'properties the carrier does not declare keep the caption element facts', json_encode($carriedRow['styles'] ?? null));
$spanH2 = $graph($compile('<!doctype html><body><main>'
    . '<form method="post" action="/rsvp">'
    . '<h2 style="font-size:16px"><span style="letter-spacing:-0.02em">Will you attend?</span></h2>'
    . '<div role="radiogroup" aria-label="Will you attend?">' . $choices('Attending') . '</div>'
    . '</form></main></body></html>'));
$assert('16px' === (($rowFor($spanH2, 1)['styles'] ?? array())['font_size'] ?? null) && '-0.02em' === (($rowFor($spanH2, 1)['styles'] ?? array())['letter_spacing'] ?? null), 'a caption heading keeps the element font size beside its carrier spacing', json_encode(($rowFor($spanH2, 1) ?? array())['styles'] ?? null));

$mismatchedCaption = $graph($compile('<!doctype html><body><main>'
    . '<form method="post" action="/rsvp"><input type="text" name="name">'
    . '<div style="font-size:14px">Which events will you attend?</div>'
    . '<div role="radiogroup" aria-label="Will you attend?">' . $choices('Attending') . '</div>'
    . '<button type="submit">Send</button></form></main></body></html>'));
$assert(array() === ($mismatchedCaption['group_captions'] ?? array()) && 'generic/computed-form-presentation/v1' === ($mismatchedCaption['schema'] ?? null), 'a sibling whose text is not the group name owns no caption row', json_encode($mismatchedCaption['group_captions'] ?? null));

$ownTextCaption = $graph($compile('<!doctype html><body><main>'
    . '<form method="post" action="/rsvp">'
    . '<div role="radiogroup" aria-label="Will you attend?" style="font-size:12px">' . $choices('Attending') . '</div>'
    . '</form></main></body></html>'));
$assert(array() === ($ownTextCaption['group_captions'] ?? array()), 'a group with no preceding caption candidate owns no caption row', json_encode($ownTextCaption['group_captions'] ?? null));

$groupNameEqualsChoice = $graph($compile('<!doctype html><body><main>'
    . '<form method="post" action="/rsvp">'
    . '<div role="radiogroup" aria-label="Yes">' . $choices('Attending') . '</div>'
    . '</form></main></body></html>'));
$assert(array() === ($groupNameEqualsChoice['group_captions'] ?? array()), 'a group name equal to its own choice label inside the group does not caption the group', json_encode($groupNameEqualsChoice['group_captions'] ?? null));

$captionWithControl = $graph($compile('<!doctype html><body><main>'
    . '<form method="post" action="/rsvp"><input type="text" name="name">'
    . '<div style="font-size:14px">Will you attend? <input type="text" name="extra"></div>'
    . '<div role="radiogroup" aria-label="Will you attend?">' . $choices('Attending') . '</div>'
    . '</form></main></body></html>'));
$assert(array() === ($captionWithControl['group_captions'] ?? array()), 'a preceding element that holds form controls is a field host, not a caption', json_encode($captionWithControl['group_captions'] ?? null));

$plainForm = $graph($compile('<!doctype html><body><main><form method="post" action="/rsvp"><input type="text" name="name"><button type="submit">Send</button></form></main></body></html>'));
$assert('generic/computed-form-presentation/v1' === ($plainForm['schema'] ?? null) && !array_key_exists('group_captions', $plainForm), 'an older v1 graph without the key stays valid', json_encode($plainForm['schema'] ?? null));

// The producer contract admits the key on v2 only and rejects malformed rows.
$accepts = static function (array $graph): bool {
    try {
        FormPresentationGraphBuilder::assertValid($graph);
        return true;
    } catch ( InvalidArgumentException ) {
        return false;
    }
};
$assert($accepts($siblingCaption), 'the emitted graph passes its own validation');
$v2WithoutCaptions = $siblingCaption;
unset($v2WithoutCaptions['group_captions']);
$assert(! $accepts($v2WithoutCaptions), 'a v2 graph always declares its group captions');
$v1WithKey = $plainForm;
$v1WithKey['group_captions'] = array();
$assert(! $accepts($v1WithKey), 'a v1 graph cannot carry group captions');
$oversized = $siblingCaption;
$oversized['group_captions'] = array_map(static fn (int $index): array => array( 'index' => $index % 128, 'styles' => array( 'color' => '#111' ), 'provenance' => array() ), range(0, 128));
$assert(! $accepts($oversized), 'the caption list is bounded');
$duplicateIndex = $siblingCaption;
$duplicateIndex['group_captions'][] = $duplicateIndex['group_captions'][0];
$assert(! $accepts($duplicateIndex), 'caption row validation rejects a duplicate row index');
$foreignProperty = $siblingCaption;
$foreignProperty['group_captions'][0]['styles']['border_width'] = '1px';
$assert(! $accepts($foreignProperty), 'caption row validation rejects a property outside the caption vocabulary');
$unknownKey = $siblingCaption;
$unknownKey['group_captions'][0]['element'] = 'div';
$assert(! $accepts($unknownKey), 'caption row validation rejects unknown row keys');
$emptyStyles = $siblingCaption;
$emptyStyles['group_captions'][0]['styles'] = array();
$emptyStyles['group_captions'][0]['provenance'] = array();
$assert(! $accepts($emptyStyles), 'caption row validation rejects a row with no styles');
$outOfRange = $siblingCaption;
$outOfRange['group_captions'][0]['index'] = 128;
$assert(! $accepts($outOfRange), 'caption row validation rejects an out-of-range index');

if ( 0 < $failures ) {
    fwrite(STDERR, "form group caption presentation FAILED: {$passes} passed, {$failures} failed\n");
    exit(1);
}
echo "form group caption presentation passed: {$passes} assertions\n";
