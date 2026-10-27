<?php
declare(strict_types=1);

// A responsive editorial catalogue: repeated cards, shared navigation, nested
// conditions and competing type/class/id rules. All source identities are neutral.
$css = '*{box-sizing:border-box}body{margin:0}a{color:#234}p{margin:0 0 12px}'
    . '.card{padding:16px;background:#fff}.card p{margin:8px 0}'
    . '#catalogue .card a{color:#456}.card a{color:#678}'
    . 'a.action{display:inline-flex;padding:8px 16px;background:#eee}'
    . '.action:hover{background:#ddd}.card:nth-child(2n){border:1px solid #ccc}'
    . '@media(min-width:800px){.catalogue{display:grid;grid-template-columns:repeat(3,1fr);gap:24px}}';
$html = '';
foreach (array('desktop', 'mobile') as $profile) {
    $scope = ':where([data-blocks-engine-capture-device-document="' . $profile . '"]) ';
    $css .= $scope . 'p{color:#345}' . $scope . '.card p{margin:10px 0}'
        . $scope . '#catalogue a{font-weight:600}';
    $html .= '<div data-blocks-engine-capture-document-scope="' . $profile . '" data-blocks-engine-capture-device-document="' . $profile . '">'
        . '<header><nav aria-label="Primary"><ul><li><a href="/">Home</a></li><li><a href="/archive">Archive</a></li></ul></nav></header>'
        . '<main id="catalogue"><h1>Field notes</h1><section class="catalogue">';
    for ($i = 0; $i < 80; ++$i) {
        $html .= '<article class="card"><h2>Entry ' . $i . '</h2><p>A short description of this entry.</p><a class="action" href="/entry/' . $i . '">Read entry</a></article>';
    }
    $html .= '</section></main><footer><p>Editorial archive</p></footer></div>';
}
return array('files' => array('index.html' => '<!doctype html><html><head><link rel="stylesheet" href="catalogue.css"></head><body>' . $html . '</body></html>', 'catalogue.css' => $css));
