<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\CapturedCollectionFilterProjector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assert = static function(bool $condition, string $message): void { if (!$condition) { fwrite(STDERR, "FAIL: $message\n"); exit(1); } };
$source = '<html><body><main><input placeholder="Search" data-dla-collection-field="0"><div><button data-dla-collection-category-control="0" data-dla-collection-index="0">All</button><button data-dla-collection-category-control="0" data-dla-collection-index="1">First</button></div><div data-dla-collection="0"><div data-dla-collection-item="0" data-dla-collection-members="[0,1]"><button aria-expanded="false" aria-controls="one">Same question?</button><div id="one" role="region" hidden><p>Answer orchid.</p></div></div><div data-dla-collection-item="1" data-dla-collection-members="[0]"><button aria-expanded="false" aria-controls="two">Same question?</button><div id="two" role="region" hidden><p>Answer violet.</p></div></div></div><div data-dla-collection-empty="0" hidden><p>No matches.</p></div></main></body></html>';
$evidence = array('replay'=>'verified','restoration'=>'verified','predicate'=>'normalized-text-includes','network'=>array('dataRequests'=>'blocked'),'target'=>array('selector'=>'body > main > div:nth-of-type(2)'), 'items'=>array(array('key'=>'0','categories'=>array(0,1)),array('key'=>'1','categories'=>array(0))), 'initialCategory'=>0,'categories'=>array(array('activeHtml'=>'<button class="active">All</button>','inactiveHtml'=>'<button class="inactive">All</button>'),array('activeHtml'=>'<button class="active">First</button>','inactiveHtml'=>'<button class="inactive">First</button>')));
$files = array(array('path'=>'website/index.html','content'=>$source),array('path'=>'capture-receipt.json','content'=>json_encode(array('schema'=>'data-liberation/capture-receipt/v1','routes'=>array(array('url'=>'https://example.test','path'=>'website/index.html'))))),array('path'=>'interaction-states.json','content'=>json_encode(array('schema'=>'data-liberation/captured-interactions/v1','pages'=>array(array('sourceUrl'=>'https://example.test','states'=>array(array('kind'=>'typed-search','status'=>'captured','collectionFilter'=>$evidence))))))));
$projected = (new CapturedCollectionFilterProjector())->project($files);
$result = (new HtmlTransformer())->transform($projected[0]['content'])->toArray();
$markup = $result['serialized_blocks'];
file_put_contents(sys_get_temp_dir() . '/collection-filter-result.json', json_encode($result, JSON_PRETTY_PRINT));
$assert(str_contains($markup,'custom/collection-filter'), 'verified source evidence becomes an editable collection companion');
$assert(!str_contains($markup,'wp:search'), 'local filtering never becomes global WordPress search');
$assert(1 === substr_count($markup,'Answer orchid.') && 1 === substr_count($markup,'Answer violet.'), 'each distinct answer has one canonical editable copy');
$assert(2 === substr_count($markup,'<!-- wp:accordion-item '), 'answers are native accordion items');
$assert(1 === substr_count($markup,'<!-- wp:accordion '), 'all items share one native accordion tree');
$assert(str_contains($markup,'blocks-engine-collection-target'), 'the native collection is addressable after serialization');
$assert(str_contains($markup,'wp:paragraph'), 'answers remain native editor paragraphs');
$assert(str_contains($markup,'data-collection-empty="true"'), 'the external source empty state remains editable and local');
$files[2]['content'] = str_replace('"replay":"verified"','"replay":"unsupported"',$files[2]['content']);
$rejected = (new CapturedCollectionFilterProjector())->project($files);
$assert(!str_contains($rejected[0]['content'],'data-blocks-engine-collection='), 'unverified predicates are not promoted');
file_put_contents(sys_get_temp_dir() . '/collection-filter-view.mjs', $result['source_reports']['generated_blocks'][0]['view_js']);
file_put_contents(sys_get_temp_dir() . '/collection-filter-editor.js', $result['source_reports']['generated_blocks'][0]['assets']['index.js']);
fwrite(STDOUT,"PASS: verified canonical collection projection and native answer authoring\n");
