<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

// A textless custom element whose light DOM is only a captured iframe renders
// without its script, like captured img/video hosts. Configuration data-*
// attributes parameterize that existing content rather than prove a runtime.
$cases = array(
    'custom element wrapping iframe' => '<section><div><cal-inline data-layout="month_view" style="display:flex;width:100%"><iframe src="https://app.cal.com/example/30min?embed=true" title="Book a call" style="width:100%;height:600px"></iframe></cal-inline></div></section>',
    'embed lifecycle attribute on host' => '<section><div><cal-inline data-layout="month_view" loading="done" style="display:flex;width:100%"><iframe src="https://app.cal.com/example/30min?embed=true" title="Book a call" style="width:100%;height:600px"></iframe></cal-inline></div></section>',
);
foreach ( $cases as $name => $html ) {
    $result = ( new HtmlTransformer() )->transform($html)->toArray();
    $iframes = array();
    $walk = static function (array $blocks) use (&$walk, &$iframes): void {
        foreach ( $blocks as $block ) {
            if ( 'custom/visual-iframe' === ($block['blockName'] ?? '') ) {
                $iframes[] = $block;
            }
            $walk($block['innerBlocks'] ?? array());
        }
    };
    $walk($result['blocks'] ?? array());
    $unsupported = array_filter($result['fallbacks'] ?? array(), static fn (array $finding): bool => 'html_unsupported_element' === ($finding['diagnostic_code'] ?? ''));
    if ( array() !== $unsupported || 1 !== count($iframes) || 'https://app.cal.com/example/30min?embed=true' !== ($iframes[0]['attrs']['src'] ?? '') ) {
        fwrite(STDERR, "FAIL: {$name}: expected no unsupported-element finding and one visual iframe with the original source\n");
        exit(1);
    }
}
echo "Custom element child content tests passed\n";
