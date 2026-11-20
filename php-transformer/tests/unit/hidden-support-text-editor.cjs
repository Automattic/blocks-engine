const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const path = require('node:path');
// Exercise the editor module emitted by the public site compiler.
const fixture = JSON.parse(execFileSync('php', ['-r', `
require $argv[1] . '/vendor/autoload.php';
$plan = (new Automattic\\BlocksEngine\\PhpTransformer\\ArtifactCompiler\\ArtifactCompiler())->compile(['files'=>['index.html'=>'<!doctype html><html><head><title>About</title></head><body><main><p class="sr-only" style="visibility:hidden">Top of page</p><h1>About</h1></main></body></html>']])->toArray()['source_reports']['wordpress_site_plan'];
foreach ($plan['writes'] as $write) {
 if ($write['target_path'] !== 'functions.php') continue;
 foreach (token_get_all($write['payload']['data']) as $token) {
  if (is_array($token) && T_CONSTANT_ENCAPSED_STRING === $token[0] && str_contains($token[1], 'editor.BlockListBlock')) {
   echo json_encode(['script'=>eval('return '.$token[1].';'), 'pages'=>$plan['pages']]); exit;
  }
 }
}
exit(1);
`, path.resolve(__dirname, '../..')], {encoding:'utf8'}));
let filter;
const wp = {
 hooks: {addFilter: (name, namespace, callback) => { if (name === 'editor.BlockEdit') filter = callback; }},
 element: {createElement: (type, props) => ({type, props})},
 data: {}, compose: {createHigherOrderComponent: hoc => hoc},
};
new Function('window', fixture.script)({wp});
assert.equal(typeof filter, 'function', 'compiled theme handles hidden RichText at rest');
const edit = filter('NativeEdit');
const hidden = {name:'core/paragraph', attributes:{className:'blocks-engine-hidden-richtext-marker blocks-engine-synthetic-paragraph', content:'Top of page'}};
assert.equal(edit(hidden), null, 'hidden support text exposes no unfocusable RichText at rest');
assert.equal(edit({...hidden, isSelected:true}).type, 'NativeEdit', 'List View selection restores editing');
assert.equal(edit({name:'core/paragraph', attributes:{content:'Visible'}}).type, 'NativeEdit');
assert.equal(edit({name:'core/group', attributes:hidden.attributes}).type, 'NativeEdit', 'containers are outside the defect');
const markup = fixture.pages.map(page => page.canonical_block_markup).join('');
assert.match(markup, /wp:paragraph/);
assert.match(markup, /Top of page/);
assert.equal((markup.match(/<!-- wp:group/g) || []).length, 1, 'only the authored main container is a Group');
console.log('Hidden support text editor regression passed.');
