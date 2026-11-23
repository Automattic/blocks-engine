<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueInspector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SourceDeclarations;

// A color utility does not paint a border until a border style is specified.
$html = '<style>.bdr{border-color:#020301}.bg{background-color:#e3eae6}h1{text-align:center}</style>'
    . '<main><h1><span class="bdr bg">2026.10</span></h1></main>';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
$plan = $result['source_reports']['wordpress_site_plan'];
$markup = $plan['pages'][0]['canonical_block_markup'];
if (1 !== preg_match('/<mark\b[^>]*style="([^"]*)"[^>]*>2026\.10<\/mark>/', $markup, $match)) {
    throw new RuntimeException('The heading keeps its editable highlighted text: ' . $markup);
}
$declarations = SourceDeclarations::parse(html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5));
if ('#e3eae6' !== CssValueInspector::declared($declarations, 'background-color')) {
    throw new RuntimeException('The carrier keeps the source background.');
}
// Core supplies html :where([style*="border-color"]){border-style:solid}.
// An inline none must beat that rule if the otherwise inert color is retained.
if (isset($declarations['border-color']) && 'none' !== CssValueInspector::declared($declarations, 'border-style')) {
    throw new RuntimeException('The carrier must not activate a border under Core styles: ' . $match[1]);
}

// Allow the browser harness to exercise the exact same public compilation.
if (false !== getenv('RICHTEXT_BORDER_ARTIFACT')) {
    file_put_contents(getenv('RICHTEXT_BORDER_ARTIFACT'), json_encode(array('source' => $html, 'native' => $markup), JSON_THROW_ON_ERROR));
}
echo "PASS: RichText border color does not activate an unpainted border.\n";
