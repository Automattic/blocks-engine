<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Issue #4447: a wrapped label flattened into core/button keeps its inherited
// white-space, so the CTA stays on one line inside a min-content column.
$cases = array(
    'wrapper shorthand' => array('.cta .label{white-space:pre}', 'white-space:pre!important'),
    'inner longhand wins' => array('.cta .label{white-space:normal}.cta p{text-wrap:nowrap}', 'white-space:normal!important;text-wrap:nowrap!important'),
    // Framer: the text leaf declares an unresolved text-wrap custom property.
    'unresolved var is skipped' => array('.cta .label{white-space:pre}.cta p{text-wrap:var(--text-wrap-override,var(--text-wrap))}', 'white-space:pre!important}'),
    'inner shorthand resets' => array('.cta .label{text-wrap:nowrap}.cta p{white-space:pre}', 'white-space:pre!important}'),
);
foreach ($cases as $name => [$labelCss, $expected]) {
    $html = '<style>.col{display:flex;flex-direction:column;width:min-content}.cta{display:flex;padding:16px 24px;background:#c6ff4d;text-decoration:none}' . $labelCss . '</style>'
        . '<div class="col"><a class="cta" href="/kontakt/"><div class="label"><p>Projekt besprechen</p></div></a></div>';
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
    preg_match('/"className":"[^"]*(blocks-engine-control-[a-f0-9]+-\d+)/', $result['serialized_blocks'], $control);
    $css = implode("\n", array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), $result['source_reports']['wordpress_site_plan']['assets'] ?? array()));
    $rule = '.' . ($control[1] ?? '?') . '.' . ($control[1] ?? '?') . '>.wp-block-button__link{';
    if (!str_contains($result['serialized_blocks'], '<!-- wp:button ') || !str_contains($css, $rule . $expected)) {
        throw new RuntimeException($name . ": flattened label white-space must reach the button link.\n" . $css);
    }
}
echo "Flattened button label white-space passed\n";
