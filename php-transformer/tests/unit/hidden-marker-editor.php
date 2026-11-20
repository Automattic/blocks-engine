<?php
declare(strict_types=1);

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

// Test the public compiler's generated theme, the path used by site imports.
$result = (new ArtifactCompiler())->compile(array('files' => array('index.html' => '<!doctype html><html><head><title>About</title></head><body><main><p class="sr-only" style="visibility:hidden">Top of page</p><h1>About</h1></main></body></html>')))->toArray();
$plan = $result['source_reports']['wordpress_site_plan'];
$script = null;
foreach ($plan['writes'] as $write) {
    if ('functions.php' !== $write['target_path']) continue;
    foreach (token_get_all($write['payload']['data']) as $token) {
        if (is_array($token) && T_CONSTANT_ENCAPSED_STRING === $token[0] && str_contains($token[1], 'editor.BlockListBlock')) {
            $script = eval('return ' . $token[1] . ';');
        }
    }
}
if (null === $script) throw new RuntimeException('Generated theme must enqueue the editor runtime.');
$markup = implode('', array_column($plan['pages'], 'canonical_block_markup'));
if (!str_contains($markup, 'Top of page') || !str_contains($markup, '<!-- wp:paragraph') || !str_contains($markup, 'blocks-engine-hidden-richtext-marker')) {
    throw new RuntimeException('Hidden source text and native saved identity must persist.');
}
$probe = <<<'JS'
var filter;
var window = { wp: {
    hooks: { addFilter: function( hook, name, callback ) { if ( hook === 'editor.BlockEdit' ) filter = callback; } },
    element: { createElement: function( component, props ) { return { component: component, props: props }; } },
    data: {}, compose: { createHigherOrderComponent: function( hoc ) { return hoc; } }
} };
JS;
$probe .= "\n" . $script . <<<'JS'

if ( typeof filter !== 'function' ) throw new Error( 'Theme must handle hidden RichText.' );
var edit = filter( 'NativeEdit' );
[ 'core/paragraph', 'core/heading' ].forEach( function( name ) {
    var props = { name: name, attributes: { className: 'blocks-engine-hidden-richtext-marker blocks-engine-synthetic-paragraph', content: 'Source state' } };
    var before = JSON.stringify( props );
    if ( edit( props ) !== null ) throw new Error( 'Hidden text must not mount RichText at rest.' );
    if ( JSON.stringify( props ) !== before ) throw new Error( 'Saved attributes must remain intact.' );
    if ( edit( Object.assign( {}, props, { isSelected: true } ) ).component !== 'NativeEdit' ) throw new Error( 'List View selection must restore editing.' );
} );
[ ['core/group', 'blocks-engine-hidden-richtext-marker'], ['core/heading', 'visible'], ['core/paragraph', 'blocks-engine-hidden-richtext-marker-other'] ].forEach( function( sample ) {
    var props = { name: sample[0], attributes: { className: sample[1] } };
    if ( edit( props ).props !== props ) throw new Error( 'Other edit views must be unchanged.' );
} );
JS;
$file = tempnam(getenv('TMPDIR') ?: sys_get_temp_dir(), 'hidden-editor-');
try {
    file_put_contents($file, $probe);
    passthru('node ' . escapeshellarg($file), $status);
    if (0 !== $status) throw new RuntimeException('Generated theme editor regression failed.');
} finally {
    unlink($file);
}
echo "Hidden marker editor regression passed.\n";
