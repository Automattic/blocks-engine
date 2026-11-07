<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$html = '<form role="search" method="get" class="form-inline form-search" action="/"><div class="form-group">
<label class="screen-reader-text sr-only" for="s">Resultado da Busca por:</label>
<div class="input-group">
<input type="text" class="form-control search-query" placeholder="Pesquisa no site" name="s">
<div class="input-group-btn"><button type="submit" class="btn btn-default" id="searchsubmit" value="Buscar"><span class="glyphicon glyphicon-search"></span></button></div>
</div></div></form>';
$css = '.input-group{display:table;border-collapse:separate;width:100%}
.input-group .form-control{display:table-cell;width:100%}
.input-group-btn{display:table-cell;width:1%;white-space:nowrap;vertical-align:middle}
.form-control{height:34px;padding:6px 12px;font-size:14px;line-height:1.42857;border:1px solid #ccc;border-radius:4px}
.btn{padding:6px 12px;font-size:14px;line-height:1.42857;border:1px solid transparent;border-radius:4px}
.btn-default{background-color:#da4453;border-color:#da4453}
.btn#searchsubmit{background-color:#7ecaec;border-color:#7ecaec;margin-top:0}';
$result = (new HtmlTransformer())->transform($html, array('static_css' => $css))->toArray();
$stylesheet = implode("\n", array_column(array_filter($result['assets'], static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '')), 'content'));
if (in_array('--json', $argv, true)) {
    echo json_encode(array('source' => $html, 'css' => $css, 'result' => $result, 'stylesheet' => $stylesheet), JSON_THROW_ON_ERROR);
    exit;
}
if (in_array('--dump', $argv, true)) {
    echo $result['serialized_blocks'] . "\n" . $stylesheet;
    exit;
}
if (!str_contains($result['serialized_blocks'], 'wp:search')) throw new RuntimeException('Expected native search block');
if (1 !== preg_match('/wp-block-search__button[^{}]*\{[^}]*background-color:#7ecaec/', $stylesheet)) throw new RuntimeException('ID-compound button paint lost');
if (1 !== preg_match('/wp-block-search__input[^{}]*\{[^}]*height:34px/', $stylesheet)) throw new RuntimeException('Input height lost');
echo "Search input-group presentation passed.\n";
