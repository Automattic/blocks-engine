<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

foreach (array('footer', 'cite') as $tag) {
    $result = (new ArtifactCompiler())->compile(array(
        'entrypoint' => 'index.html',
        'files' => array('index.html' => '<main><blockquote><p>A quotation</p><' . $tag . ' class="credit"><strong class="person">Ada</strong></' . $tag . '></blockquote></main><style>.credit{font-style:normal}.person{color:red}</style>'),
    ))->toArray();
    $markup = $result['serialized_blocks'];
    $document = new DOMDocument();
    $document->loadHTML($markup, LIBXML_NOERROR | LIBXML_NOWARNING);
    $citation = $document->getElementsByTagName('cite')->item(0);
    if (!$citation instanceof DOMElement || 'Ada' !== trim($citation->textContent)) {
        throw new RuntimeException('Public compiler must retain citation text');
    }
    $person = $citation->firstElementChild;
    if (!$person instanceof DOMElement || 'strong' !== $person->tagName) {
        throw new RuntimeException('Citation content must remain direct children of the native cite, without a synthetic source-class wrapper');
    }
    if (!str_contains($person->getAttribute('class'), 'person')) {
        throw new RuntimeException('Citation descendants must retain their source style identity');
    }
}
$result = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array('index.html' => '<main><blockquote><p>A quotation</p><footer class="credit"><span>—</span><div><p>Ada</p><p>Director</p></div></footer></blockquote></main><style>.credit{display:flex;gap:16px;margin-top:40px}</style>'),
))->toArray();
$document = new DOMDocument();
$document->loadHTML($result['serialized_blocks'], LIBXML_NOERROR | LIBXML_NOWARNING);
$footer = (new DOMXPath($document))->query('//div[contains(concat(" ", @class, " "), " credit ")]')->item(0);
if (!$footer instanceof DOMElement || 2 !== $footer->childElementCount || $document->getElementsByTagName('cite')->length) {
    throw new RuntimeException('Structured citation must retain its layout group instead of becoming RichText or a site footer');
}
echo "Citation source topology passed\n";
