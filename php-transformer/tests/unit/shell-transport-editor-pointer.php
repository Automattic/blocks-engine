<?php
declare(strict_types=1);

/**
 * Issue #5920: the shell transport wrapping a template part is boxless on the
 * frontend (display:contents) but keeps its box in the editor, where Gutenberg
 * gives every block pointer-events:auto. A transport inside a pinned header
 * layer (position:fixed, inset:0, authored pointer-events:none) stretches over
 * the whole canvas in the editor and intercepts every click meant for the
 * images beneath. The editor must let pointers through the transport's own box
 * while its blocks keep taking them, and the frontend must be untouched.
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

$page = static fn (string $title): string => '<style>.pinned{position:fixed;inset:0;display:grid;pointer-events:none;z-index:50}.pinned header{pointer-events:auto;height:40px}</style>'
    . '<div class="pinned"><header id="site-header"><a href="index.html">Home</a> <a href="about.html">About</a></header></div>'
    . '<main><h1>' . $title . '</h1><img src="hero.png" alt="Hero"></main>';
$plan = ( new ArtifactCompiler() )->compile(array(
    'entrypoint' => 'index.html',
    'files' => array(
        'index.html' => $page('Home'),
        'about.html' => $page('About'),
        'hero.png' => array( 'content_base64' => base64_encode('image'), 'mime_type' => 'image/png' ),
    ),
))->toArray()['source_reports']['wordpress_site_plan'] ?? array();

$functions = '';
foreach ( $plan['writes'] ?? array() as $write ) {
    if ( 'functions.php' === ($write['target_path'] ?? null) ) {
        $functions = (string) ($write['payload']['data'] ?? '');
    }
}
$markup = implode('', array_map(static fn (array $part): string => (string) ($part['block_markup'] ?? $part['canonical_block_markup'] ?? ''), $plan['template_parts'] ?? array()))
    . implode('', array_map(static fn (array $template): string => (string) ($template['block_markup'] ?? $template['canonical_block_markup'] ?? ''), $plan['templates'] ?? array()));
$frontendCss = implode("\n", array_map(static fn (array $asset): string => 'css' === ($asset['kind'] ?? '') && 'editor' !== ($asset['stylesheet_target'] ?? 'both') ? (string) ($asset['content'] ?? '') : '', $plan['assets'] ?? array()));

$assert(str_contains($functions . $markup, 'blocks-engine-shell-transport'), 'the shared header is referenced through a shell transport', substr($functions, 0, 400));

// The editor styles registered through block_editor_settings_all.
$editorCss = '';
if ( 1 === preg_match("/add_filter\\( 'block_editor_settings_all', static function \\( array \\\$settings \\): array \\{ \\\$settings\\['styles'\\]\\[\\] = array\\( 'css' => '((?:[^'\\\\]|\\\\.)*)'/", $functions, $match) ) {
    $editorCss = stripslashes($match[1]);
}
$transport = '.block-editor-block-list__block.wp-block-template-part.blocks-engine-shell-transport';
$assert(1 === preg_match('/' . preg_quote($transport, '/') . '\{pointer-events:none\}/', $editorCss), "the transport's own editor box lets pointers through to the canvas beneath", $editorCss);
$assert(1 === preg_match('/' . preg_quote($transport, '/') . '>\.block-editor-block-list__block\{pointer-events:auto\}/', $editorCss), "the template part's blocks still take pointers, so clicking its content selects it", $editorCss);

$frontend = substr($functions, 0, (int) strpos($functions, "add_filter( 'block_editor_settings_all'"));
$assert(!str_contains($frontend, $transport) && !str_contains($frontendCss, $transport), 'the frontend enqueue and stylesheets carry no editor transport pointer rule');

if ( 0 < $failures ) {
    fwrite(STDERR, "shell transport editor pointer FAILED: {$passes} passed, {$failures} failed\n");
    exit(1);
}
echo "shell transport editor pointer passed: {$passes} assertions\n";
