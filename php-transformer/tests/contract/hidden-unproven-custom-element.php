<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void {
    if ( ! $condition ) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};
$cases = array(
    'hidden' => array('style' => 'display: none !important; width: 95px; height: 95px; position: fixed !important', 'unproven' => false),
    'visible' => array('style' => '', 'unproven' => true),
);
foreach ($cases as $name => $case) {
    $html = <<<HTML
    <!doctype html><html><body><main><h1>Hello</h1><p>Body text.</p><x-status-widget status="offline" style="{$case['style']}"></x-status-widget></main></body></html>
    HTML;
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
    $plan = $result['source_reports']['wordpress_site_plan'] ?? array();
    $markup = (string) ($plan['pages'][0]['canonical_block_markup'] ?? '');
    $diagnostics = $plan['diagnostics'] ?? array();
    $hasUnproven = (bool) array_filter($diagnostics, static fn (array $row): bool => 'html_custom_element_runtime_unproven' === ($row['code'] ?? null));
    $assert($case['unproven'] === $hasUnproven, "{$name} custom element classification remains correct.");
    $assert(str_contains($markup, '<h1 class="wp-block-heading">Hello</h1>') && str_contains($markup, 'Body text.'), "{$name} page text survives.");
    if ('hidden' === $name) {
        $assert(!str_contains($markup, 'unsupported_html_fallback'), 'Hidden widget emits no unsupported fallback.');
        WordPressSitePlan::assertValid($plan);
    }
}
echo "hidden-unproven-custom-element contract passed\n";
