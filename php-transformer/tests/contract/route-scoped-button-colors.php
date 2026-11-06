<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$result = (new ArtifactCompiler())->compile(require dirname(__DIR__) . '/fixtures/route-scoped-button-colors.php')->toArray();
$plan = $result['source_reports']['wordpress_site_plan'];
$button = null;
foreach ($plan['pages'] as $page) {
    preg_match_all('/<!-- wp:button (.*?) -->/', $page['canonical_block_markup'], $matches);
    foreach ($matches[1] as $json) {
        $attrs = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if ('button' === ($attrs['tagName'] ?? '') && in_array('btn--primary', explode(' ', $attrs['className'] ?? ''), true)) $button = $attrs;
    }
}
$assert(null !== $button, 'The second route must contain the native primary core/button.');
$assert(!isset($button['style']['color']['background']) && !isset($button['style']['color']['text']), 'Class-painted native buttons must not freeze resting colours as literal block styles.');
$assert(!isset($button['backgroundColor']) && !isset($button['textColor']), 'Resting preset classes must not override the authored hover colours.');
$css = implode("\n", array_column(array_filter($plan['assets'], static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '')), 'content'));
$assert(str_contains($css, ':where(:root:not(.be-r-') && str_contains($css, ':where(:root:is(.be-r-'), 'The fixture must exercise both route-scoped author and control projections.');
foreach (array('--color-primary', '--color-white') as $variable) {
    $assert(1 === preg_match('/' . preg_quote($variable, '/') . ':\s*var\(--wp--preset--color-/', $css), 'Resting custom properties must remain palette-bound: ' . $variable);
}
$assert(1 === preg_match('/\.btn--primary[^{}]*\{background:var\(--wp--preset--color-[^{}]+;color:var\(--wp--preset--color-/', $css), 'The resting author class must paint through palette bindings.');
$assert(1 === preg_match('/\.btn--primary[^{}]*:hover[^{}]*\{background:var\(--color-primary-dark\);color:var\(--wp--preset--color-/', $css), 'Hover must retain the authored background and palette-bound text, without a resting preset class masking it.');
echo "Route-scoped button colours passed\n";
