<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Support\EngineMarker;

$assert = static function (bool $ok, string $message): void {
    if ( ! $ok ) throw new RuntimeException($message);
};
// static-site-importer#2507: both controls inherit Courier New from .box.
$source = '<style>*{margin:0;font-family:Georgia,serif}.cta{font-family:inherit;display:inline-block;padding:10px 20px;background:blue;color:white}.box{font-family:"Courier New",monospace}</style>'
    . '<div class="box"><p>Box text</p><a class="cta" href="https://example.com/">Go</a></div>'
    . '<form class="box" action="https://example.com/s" method="post"><input type="email" name="email"><button class="cta" type="submit">Submit</button></form>';
$result = (new ArtifactCompiler())->compile(array('files' => array('index.html' => $source)))->toArray();
$finding = array_values(array_filter($result['fallbacks'], static fn (array $row): bool => 'html_form_fallback' === ($row['diagnostic_code'] ?? '')))[0] ?? array();
$classes = $finding['controls'][1]['control_carrier_classes'] ?? array();
$assert(array_is_list($classes) && count($classes) >= 5, 'Submit exports its registered carrier class list');
$assert(1 === preg_match('/^blocks-engine-control-/', $classes[0]), 'Carrier retains exact control marker');
foreach (array('blocks-engine-role-control', 'blocks-engine-subject', 'blocks-engine-subject-tag-button', EngineMarker::subjectClassClass('cta')) as $class) {
    $assert(in_array($class, $classes, true), 'Missing carrier class: ' . $class);
}
$css = implode("\n", array_column(array_filter($result['assets'], static fn (array $asset): bool => 'inline-style' === ($asset['source'] ?? '')), 'content'));
// The universal font rule must skip both inserted wrappers, not merely
// recognize a marker string in an unused selector arm.
$selector = substr($css, 0, strpos($css, '{'));
$assert('*:not(:where(.blocks-engine-role-control)),:where(.blocks-engine-role-control.blocks-engine-subject)> :where(.wp-block-button__link):not(be-t-0)' === $selector, 'Universal font rule excludes control wrappers and addresses their link surface');
$linkClasses = array();
$visit = static function (array $node) use (&$visit, &$linkClasses): void {
    if ('core/button' === ($node['blockName'] ?? $node['name'] ?? null)) {
        $candidate = preg_split('/\\s+/', $node['attrs']['className'] ?? '') ?: array();
        if (in_array('blocks-engine-subject-tag-a', $candidate, true)) $linkClasses = $candidate;
    }
    foreach ($node as $value) if (is_array($value)) $visit($value);
};
$visit($result);
$assert(array() !== $linkClasses, 'Fixture retains the existing link-button carrier');
foreach (array('blocks-engine-role-control', 'blocks-engine-subject') as $class) {
    $assert(in_array($class, $classes, true) && in_array($class, $linkClasses, true), 'Universal projection treats submit and link carriers identically: ' . $class);
}
$assert(str_contains($css, ':where(.' . $classes[0] . '):not(.be-c-0)'), 'Exact .cta projection addresses the exported submit marker');
\Automattic\BlocksEngine\PhpTransformer\Contract\ConversionFindingContract::assertFinding($finding);
$invalid = $finding;
$invalid['controls'][1]['control_carrier_classes'] = 'not a token list';
try {
    \Automattic\BlocksEngine\PhpTransformer\Contract\ConversionFindingContract::assertFinding($invalid);
    throw new RuntimeException('Metadata validation must reject a non-list carrier field');
} catch (InvalidArgumentException) {
    // The additive field is a token array, not an authored class string.
}
$assert(! isset($finding['controls'][0]['control_carrier_classes']), 'Data-entry fields do not acquire button carriers');
echo "Form submit control projection passed\n";
