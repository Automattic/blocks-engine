<?php
declare(strict_types=1);

/**
 * A multi-step form ships as one form whose pages sit side by side in a
 * clipped, non-wrapping flex row. The declared cascade alone proves it: each
 * non-shrinking page fills the clipping box, so one page shows at a time. The
 * form entity declares that track as `generic/form-steps/v1` so a provider can
 * materialize native steps. See blocks-engine#2760.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

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

$page = static function (string $css): string {
    return '<!doctype html><html><head><style>* { box-sizing: border-box; } ' . $css . '</style></head><body><main>'
        . '<form class="rsvp" method="post" action="/rsvp">'
        . '<div class="track">'
        . '<div class="page one"><label><p>Full name</p><input type="text" name="name"></label>'
        . '<label><p>Phone</p><input type="tel" name="phone"></label></div>'
        . '<div class="page two"><label><p>Guests</p><input type="number" name="guests"></label>'
        . '<label><p>Message</p><textarea name="message"></textarea></label></div>'
        . '<div class="page done"><h2>Thank you</h2><p>We cannot wait to celebrate with you.</p></div>'
        . '</div>'
        . '<div class="nav"><p>Next</p></div>'
        . '</form></main></body></html>';
};
$base = '.rsvp { display: flex; flex-flow: column; width: 432px; padding: 16px; overflow: var(--clip, clip); } '
    . '.track { display: flex; flex-flow: row; gap: 32px; width: 100%; } '
    . '.page { display: flex; flex-direction: column; flex: 0 0 auto; width: 400px; } ';

$compile = static function (string $html): array {
    return ( new ArtifactCompiler() )->compile(array( 'entrypoint' => 'index.html', 'files' => array( 'index.html' => $html ) ))->toArray();
};
$entity = static function (array $result): array {
    foreach ( $result['source_reports']['wordpress_site_plan']['runtime_declarations'] ?? array() as $declaration ) {
        if ( 'forms' === ( $declaration['type'] ?? null ) ) {
            return $declaration['payload']['entities'][0] ?? array();
        }
    }
    return array();
};
$nodeTags = static function (array $form): array {
    $tags = array();
    foreach ( $form['layout_graph']['nodes'] ?? array() as $node ) {
        $tags[$node['id']] = $node['source']['classes'] ?? array();
    }
    return $tags;
};

$paged = $entity($compile($page($base)));
$steps = $paged['steps'] ?? null;
$classes = $nodeTags($paged);
$assert(
    is_array($steps) && 'generic/form-steps/v1' === ( $steps['schema'] ?? null ) && 2 === count($steps['steps'] ?? array()),
    'a clipped non-wrapping row of panels that each fill the clipping box declares two steps',
    json_encode($steps)
);
$assert(
    is_array($steps) && array( 'track' ) === ( $classes[$steps['track']] ?? null )
        && array( 'page', 'one' ) === ( $classes[$steps['steps'][0]['wrapper']] ?? null )
        && array( 'page', 'two' ) === ( $classes[$steps['steps'][1]['wrapper']] ?? null ),
    'the declared track and steps name the source track and its control-bearing panels in source order',
    json_encode(array( 'steps' => $steps, 'classes' => $classes ))
);
$assert(
    is_array($steps) && array( 'page', 'done' ) === ( $classes[$steps['completion'] ?? ''] ?? null ),
    'the trailing copy-only panel is declared as the completion panel',
    json_encode($steps)
);
$assert(
    is_array($steps) && array( 'nav' ) === ( $classes[$steps['navigation'] ?? ''] ?? null ),
    'the copy-only form-level box after the track is declared as the step navigation',
    json_encode($steps)
);

// A percentage-width panel fills a track that spans the clipping box.
$percent = $entity($compile($page(str_replace('width: 400px;', 'width: 100%;', $base))));
$assert(2 === count($percent['steps']['steps'] ?? array()), 'full-width panels in a full-width track also page', json_encode($percent['steps'] ?? null));

// A track that clips itself pages without a clipping form.
$selfClipped = $entity($compile($page(str_replace(array( 'overflow: var(--clip, clip);', 'width: 100%; } ', 'width: 400px;' ), array( '', 'width: 100%; overflow: hidden; } ', 'width: 100%;' ), $base))));
$assert(2 === count($selfClipped['steps']['steps'] ?? array()), 'a track that clips its own overflow pages full-width panels', json_encode($selfClipped['steps'] ?? null));

$declines = array(
    'a wrapping row lays every panel out' => str_replace('flex-flow: row;', 'flex-flow: row wrap;', $base),
    'a column track stacks every panel' => str_replace('.track { display: flex; flex-flow: row;', '.track { display: flex; flex-flow: column;', $base),
    'without a clipping ancestor every panel is visible' => str_replace('overflow: var(--clip, clip);', 'overflow: visible;', $base),
    'panels that do not fill the clipping box show side by side' => str_replace('width: 400px;', 'width: 180px;', $base),
    'shrinking panels fit the track together' => str_replace('flex: 0 0 auto;', 'flex: 0 1 auto;', $base),
    'a responsive rule that unpages the track is not proven at every width' => $base . '@media (max-width: 600px) { .track { flex-direction: column; } }',
);
foreach ( $declines as $reason => $css ) {
    $declined = $entity($compile($page($css)));
    $assert(array() !== $declined && ! isset($declined['steps']), 'no steps are declared: ' . $reason, json_encode($declined['steps'] ?? null));
}

// One page of controls is a single form, not a step track.
$single = $entity($compile(str_replace('<div class="page two"><label><p>Guests</p><input type="number" name="guests"></label><label><p>Message</p><textarea name="message"></textarea></label></div>', '', $page($base))));
$assert(array() !== $single && ! isset($single['steps']), 'a single control-bearing panel declares no steps', json_encode($single['steps'] ?? null));

if ( 0 < $failures ) {
    fwrite(STDERR, "form step track FAILED: {$passes} passed, {$failures} failed\n");
    exit(1);
}
echo "form step track passed: {$passes} assertions\n";
