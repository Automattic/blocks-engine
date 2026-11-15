<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$result = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array('index.html' => '<style>.button{display:flex;align-items:center;justify-content:center;padding:10px;background:black;color:white}.icon{display:inline-flex;width:24px;margin-left:10px}.icon svg{width:100%;height:auto}</style><main><a class="button" href="/about"><span class="label">Read More</span><div class="icon"><div class="drawing"><svg viewBox="0 0 20 20" width="20" height="20"><path d="M0 10H20"/></svg></div></div></a></main>'),
))->toArray();
$markup = $result['serialized_blocks'];
$document = new DOMDocument();
@$document->loadHTML($markup);
$xpath = new DOMXPath($document);
if (!str_contains($markup, '<!-- wp:button ') || str_contains($markup, '<!-- wp:html')) {
    throw new RuntimeException('Expected an editable native button');
}
$icon = $xpath->query('//a/span[contains(concat(" ",normalize-space(@class)," ")," icon ")]')->item(0);
if (!$icon || 1 !== $xpath->query('./span[@class="drawing"]/img', $icon)->length) {
    throw new RuntimeException('Decorative icon must retain its nested layout boxes as inline wrappers');
}
if ('Read More' !== trim($document->getElementsByTagName('a')->item(0)->textContent)) {
    throw new RuntimeException('Button label must survive');
}
echo "Button decoration wrapper passed\n";
