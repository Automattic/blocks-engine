<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// Exercise the public compiler: normalize only the inert bare hashbang,
// not a real section, hashbang route, or an authored target named "!".
foreach (array(false, true) as $hasTarget) {
    $html = '<main><a href="#!">Visit</a><a href="#section">Section</a><a href="#!/catalog">Catalog</a><p id="section">Content</p>'
        . ($hasTarget ? '<p id="!">Target</p>' : '') . '</main>';
    $result = (new ArtifactCompiler())->compile(array(
        'entrypoint' => 'index.html',
        'files' => array('index.html' => $html),
    ))->toArray();
    $dom = new DOMDocument();
    @$dom->loadHTML($result['serialized_blocks']);
    $links = $dom->getElementsByTagName('a');
    $expected = array($hasTarget ? '#!' : '#', '#section', '#!/catalog');
    foreach ($expected as $index => $href) {
        if ($links->item($index)?->getAttribute('href') !== $href) {
            throw new RuntimeException('Compiler lost placeholder or authored fragment semantics: ' . $result['serialized_blocks']);
        }
    }
}
echo "Hashbang placeholder link passed\n";
