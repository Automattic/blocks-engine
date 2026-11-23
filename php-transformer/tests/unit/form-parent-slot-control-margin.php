<?php
declare(strict_types=1);

/**
 * A margin a parent gives its child slots (Tailwind v4's layered
 * `:where(.space-y-4 > :not(:last-child))`) belongs to whatever box fills the
 * slot. A provider form puts its field wrapper there and the materialized
 * container keeps the parent's classes, so the author stylesheet spaces the
 * wrapper. The control's own presentation must not carry the margin as well,
 * or every field gains the spacing twice.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$failures = 0;
$fail = static function (string $message) use (&$failures): void {
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
};

$source = '<style>@layer utilities { :where(.space-y-4>:not(:last-child)) { margin-block-start:0; margin-block-end:16px } }'
    . ' * { margin:0 } .field { padding:12px; border:1px solid #333 } .lead > input { margin-block-end:8px }</style>'
    . '<main><form class="space-y-4"><p>Booking request</p><input class="field" placeholder="Full name">'
    . '<select class="field"><option>One</option></select><textarea class="field" placeholder="Notes"></textarea>'
    . '<button type="submit">Book</button></form>'
    . '<form class="lead"><input class="field" placeholder="Email"><button type="submit">Join</button></form></main>';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'booking.html', 'files' => array('booking.html' => $source)))->toArray();

$controls = static function (array $form): array {
    return array_column($form['presentation_graph']['controls'] ?? array(), null, 'index');
};
$stack = $controls($result['fallbacks'][0] ?? array());
foreach ( array( 0, 1, 2 ) as $index ) {
    $styles = $stack[$index]['control']['styles'] ?? null;
    if ( ! is_array($styles) || '12px' !== ( $styles['padding'] ?? null ) ) {
        $fail('control ' . $index . ' lost its own presentation: ' . json_encode($styles));
        continue;
    }
    foreach ( array( 'margin_block_end', 'margin_block_start', 'margin_bottom' ) as $property ) {
        if ( isset($styles[$property]) ) {
            $fail('control ' . $index . ' carries its parent slot margin ' . $property . ': ' . json_encode($styles));
        }
    }
    foreach ( $stack[$index]['control']['provenance'] ?? array() as $entry ) {
        if ( str_contains((string) ( $entry['selector'] ?? '' ), 'space-y-4') ) {
            $fail('control ' . $index . ' provenance still cites the slot rule: ' . json_encode($entry));
        }
    }
}

// A child rule that names the control's own type is the control's margin.
$lead = $controls($result['fallbacks'][1] ?? array());
if ( '8px' !== ( $lead[0]['control']['styles']['margin_block_end'] ?? null ) ) {
    $fail('a type-qualified child margin was dropped: ' . json_encode($lead[0]['control']['styles'] ?? null));
}

if ( $failures > 0 ) {
    exit(1);
}
echo 'PASS: parent slot margins stay off wrapped form controls' . PHP_EOL;
