<?php
declare(strict_types=1);

// Shared source ids in two neutral device documents and two page projections.
return static function (bool $commented = true): array {
    $files = array();
    $head = '';
    foreach (array('desktop' => 980, 'mobile' => 320) as $device => $width) {
        $gate = ':where([data-blocks-engine-capture-device-document="' . $device . '"]) ';
        $head .= '<link rel="stylesheet" href="' . $device . '.css" media="not all" data-blocks-engine-capture-source-media="all" data-blocks-engine-capture-device-style="' . $device . '">';
        $files[$device . '.css'] = ($commented && 'mobile' === $device ? '/* Device layout */' : '')
            . $gate . '#shell{width:' . $width . 'px;padding:10px}'
            . $gate . '#shell{width:' . $width . 'px}'
            . $gate . '#shell:hover{background:#ddd}';
    }
    foreach (array('index.html' => 'Notes', 'about.html' => 'About') as $path => $title) {
        $html = '<!doctype html><html><head><title>' . $title . '</title>' . $head . '</head><body>';
        foreach (array('desktop', 'mobile') as $device) {
            $html .= '<div data-blocks-engine-capture-document-scope="" data-blocks-engine-capture-device-document="' . $device . '">'
                . '<div id="shell"><header><p>Heading</p></header><main><h1>' . $title . '</h1><p>Neutral copy.</p></main><footer><p>Footer</p></footer></div></div>';
        }
        $files[$path] = $html . '</body></html>';
    }
    return array('entrypoint' => 'index.html', 'files' => $files);
};
