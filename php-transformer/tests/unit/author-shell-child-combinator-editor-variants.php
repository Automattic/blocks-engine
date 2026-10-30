<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\LayoutShellBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\EditorShellBoundary;

$files = array();
foreach (array('index', 'about') as $page) {
    $files[] = array('path' => "website/$page.html", 'content' => '<html><head><title>' . $page . '</title><style>.a > .b{color:red}</style></head><body><main><div class="outer"><section class="middle"><div class="a"><p class="b">Copy</p><p>Second</p></div></section></div></main></body></html>');
}
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'website/index.html', 'files' => $files))->toWordPressSitePlanView();
$plan = $result['wordpress_site_plan'];
$check = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
$check(2 === count($plan['pages']), 'the regression compiles both pages');
foreach ($plan['pages'] as $page) {
    $markup = $page['canonical_block_markup'];
    $check(str_contains($markup, '/layout-shell'), 'the fixture must emit a layout shell');
    $check(str_contains($markup, '<div class="wp-block-group a"><!-- wp:paragraph'), 'a is the innermost shell wrapper and b is its child block');
}
$cssBytes = 0;
foreach ($plan['assets'] ?? array() as $asset) {
    if ('text/css' !== ($asset['mime_type'] ?? '')) continue;
    $css = (string) ($asset['content'] ?? '');
    $cssBytes += strlen($css);
    $check(!str_contains($css, EditorShellBoundary::layer()), 'shell-only pages emit no boundary restatement');
    $check(!str_contains((string) ($asset['target_path'] ?? ''), '.editor.css'), 'shell-only pages emit no editor stylesheet copy');
}
$check($cssBytes > 0, 'the compiled plan retains authored CSS');
$script = (new LayoutShellBlockGenerator())->definition('blocks-engine/layout-shell')['assets']['index.js'];
$check(!str_contains($script, 'layout-shell-editor-inner-blocks'), 'no extra inner div');
$check(str_contains($script, 'save: function( props ) { return savedContent( props.attributes.wrappers ); }'), 'saved content contract is unchanged');
// Only boundaries whose parent can actually be the emitted form get widened.
$markup = '<!-- wp:jetpack/contact-form --><div class="wp-block-jetpack-contact-form stack"><p>Field</p></div><!-- /wp:jetpack/contact-form -->';
$check(null !== EditorShellBoundary::rule('.stack > p', 'color:red', markup: $markup), 'emitted Jetpack form retains its restatement');
$check(null === EditorShellBoundary::rule('.unrelated > p', 'color:red', markup: $markup), 'unrelated boundaries are not restated');
echo "Shell editor DOM regression passed; two-page CSS bytes: $cssBytes\n";
