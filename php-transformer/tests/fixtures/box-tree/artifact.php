<?php
declare(strict_types=1);

// Evidence-route fixture shared by the contract and real WordPress browser test.
$html = file_get_contents(__DIR__ . '/index.html');
$tree = json_decode(file_get_contents(__DIR__ . '/index.json'), true, 32, JSON_THROW_ON_ERROR);
foreach ($tree['viewports'] as $viewport => $_) {
    $tree['nodes']['n6']['viewports'][$viewport]['used_styles']['opacity'] = 'desktop' === $viewport ? '0.75' : '1';
    $tree['nodes']['n6']['viewports'][$viewport]['used_styles']['color'] = 'desktop' === $viewport ? 'rgb(0, 0, 0)' : 'rgb(0, 0, 180)';
}
return array('entrypoint' => 'index.html', 'files' => array('index.html' => $html, 'box-tree/index.json' => json_encode($tree, JSON_THROW_ON_ERROR)));
