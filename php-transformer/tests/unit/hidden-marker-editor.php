<?php
declare(strict_types=1);

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$compile = static fn (string $html): array => (new ArtifactCompiler())->compile(array(
    'schema' => ArtifactCompiler::INPUT_SCHEMA,
    'entrypoint' => 'index.html',
    'files' => array(array('path' => 'index.html', 'content' => $html)),
))->toArray();
$result = $compile('<style>.target span{display:none}</style><main><div id="top" class="target"><span>top of page</span></div><h1>Visible title</h1><a href="#top">Back to top</a></main>');
$scripts = $result['source_reports']['companion_plugin_payload']['editor_scripts'] ?? array();
$module = array_values(array_filter($scripts, static fn (array $script): bool => 'blocks-engine-hidden-markers' === ($script['handle'] ?? '')))[0] ?? null;
if (null === $module) {
    throw new RuntimeException('The public compiler must ship editor handling for hidden source labels.');
}
if (array() !== array_diff(array('wp-hooks', 'wp-element'), $module['dependencies'] ?? array())) {
    throw new RuntimeException('The companion must load the editor dependencies before the filter.');
}
if (!str_contains($result['serialized_blocks'], 'top of page') || !str_contains($result['serialized_blocks'], 'Visible title')) {
    throw new RuntimeException('Hidden source state and visible editable content must both persist.');
}

// Exercise the emitted filter through the public compiler payload. Hidden
// edit views must not mount at all: disabled mode alone still mounts RichText.
$probe = <<<'JS'
var filter, mounted = [];
function BlockEdit( props ) { mounted.push( props.name ); return props; }
var window = { wp: {
    hooks: { addFilter: function( hook, name, callback ) { filter = callback; } },
    element: { createElement: function( component, props ) { return component( props ); } },
    blockEditor: { useBlockEditingMode: function() {} }
} };
JS;
$probe .= "\n" . $module['content'] . <<<'JS'

var edit = filter( BlockEdit );
[ 'core/paragraph', 'core/heading', 'core/group' ].forEach( function( name ) {
    var props = { name: name, attributes: { className: 'blocks-engine-hidden-richtext-marker blocks-engine-synthetic-paragraph', content: 'Source state' } };
    var before = JSON.stringify( props );
    if ( edit( props ) !== null ) throw new Error( 'Hidden source carriers must not mount an edit view.' );
    if ( JSON.stringify( props ) !== before ) throw new Error( 'Hidden saved attributes must remain intact.' );
} );
[ 'visible', 'blocks-engine-hidden-richtext-marker-other' ].forEach( function( className ) {
    var props = { name: 'core/heading', attributes: { className: className } };
    if ( edit( props ) !== props ) throw new Error( 'Visible edit views must be preserved.' );
} );
if ( JSON.stringify( mounted ) !== JSON.stringify( [ 'core/heading', 'core/heading' ] ) ) {
    throw new Error( 'Only visible source carriers may mount RichText.' );
}
JS;
$file = tempnam(getenv('TMPDIR') ?: sys_get_temp_dir(), 'hidden-editor-');
try {
    file_put_contents($file, $probe);
    passthru('node ' . escapeshellarg($file), $status);
    if (0 !== $status) throw new RuntimeException('Emitted editor module failed.');
} finally {
    unlink($file);
}
$plain = $compile('<main><h1>Visible title</h1></main>');
foreach ($plain['source_reports']['companion_plugin_payload']['editor_scripts'] ?? array() as $script) {
    if ('blocks-engine-hidden-markers' === ($script['handle'] ?? '')) throw new RuntimeException('Ordinary documents need no hidden-marker script.');
}
echo "Hidden marker editor regression passed.\n";
