<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SourceBlockAttributeProjector;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\AbsentCarrierSelectors;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

// A flex row's sized icon becomes a transparent flex image figure. Projection
// restates its box rule for every carrier such an image could become,
// including a listing card's core/post-featured-image. This site has no
// listing, so no route renders a featured image, and arms that require one
// would ship in the theme stylesheet without ever matching.
// The materialization layer spells out the engine carrier classes; they must
// stay the ones conversion emits.
foreach (array(SourceBlockAttributeProjector::SYNTHETIC_FLEX_IMAGE_FIGURE_CLASS, SourceBlockAttributeProjector::SYNTHETIC_INLINE_IMAGE_FIGURE_CLASS) as $carrier) {
    $assert(isset(AbsentCarrierSelectors::CARRIERS[$carrier]), 'Absent-carrier pruning knows the ' . $carrier . ' carrier.');
}

$css = '.row{display:flex;align-items:center;gap:8px}.icon{width:24px;height:24px;flex-shrink:0;margin-left:6px}';
$page = static fn (string $title): string => '<!doctype html><html><head><title>' . $title . '</title><link rel="stylesheet" href="site.css"></head><body>'
    . '<main><div class="row"><img class="icon" src="icon.png" width="24" height="24" alt=""><p>' . $title . ' copy.</p></div></main></body></html>';

$plan = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array(
        'site.css' => $css,
        'icon.png' => base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='),
        'index.html' => $page('Home'),
        'about.html' => $page('About'),
    ),
))->toWordPressSitePlanView()['wordpress_site_plan'];

$markup = '';
foreach (array_merge($plan['pages'] ?? array(), $plan['template_parts'] ?? array(), $plan['templates'] ?? array()) as $unit) {
    $markup .= (string) ($unit['canonical_block_markup'] ?? '');
}
$assert(str_contains($markup, 'blocks-engine-synthetic-flex-image-figure'), 'The icon is a transparent flex image figure.');
$assert(!str_contains($markup, 'wp:post-featured-image'), 'No route renders a featured image.');

$sheets = implode("\n", array_map(static fn (array $asset): string => 'css' === ($asset['kind'] ?? null) ? (string) ($asset['content'] ?? '') : '', $plan['assets'] ?? array()));
$assert(1 === preg_match('/\.icon\.wp-block-image:where\(\.blocks-engine-synthetic-flex-image-figure\)[^{]*\{[^}]*width:24px/', $sheets), 'The live image figure keeps its projected box rule.');
$assert(!str_contains($sheets, 'wp-block-post-featured-image'), 'Theme stylesheets ship no selector requiring a featured image no route renders.');

echo "Absent carrier selectors passed\n";
