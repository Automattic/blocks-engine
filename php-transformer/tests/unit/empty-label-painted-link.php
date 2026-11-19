<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

// #6886: an empty label must not erase the painted box behind the heading.
$html = file_get_contents(dirname(__DIR__) . '/fixtures/empty-label-painted-link.html');
$result = (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array('index.html' => $html),
))->toArray();
$markup = $result['serialized_blocks'];
$document = new DOMDocument();
$document->loadHTML('<?xml encoding="UTF-8">' . $markup, LIBXML_NOERROR | LIBXML_NOWARNING);
$xpath = new DOMXPath($document);
$link = $xpath->query('//a[@href="/x"][contains(@class,"wp-block-button__link")]')->item(0);
if (!$link instanceof DOMElement) {
    throw new RuntimeException('The painted link must render inside core/buttons: ' . $markup);
}
if ('' === $link->textContent) {
    throw new RuntimeException('Core must receive non-empty RichText so it keeps the painted button.');
}
$style = $link->getAttribute('style');
foreach (array('background-color:#333', 'border-radius:15px') as $declaration) {
    if (!str_contains($style, $declaration)) {
        throw new RuntimeException('The painted link must keep ' . $declaration . ': ' . $markup);
    }
}
$css = implode("\n", array_column($result['assets'], 'content'));
foreach (array(':where(.wp-block-buttons){width:400px;height:60px}', ':where(.wp-block-button){height:100%}', ':where(.wp-block-button__link):not(be-t-0){height:100%}') as $rule) {
    if (!str_contains($css, $rule)) {
        throw new RuntimeException('The painted link must retain its projected dimensions: ' . $css);
    }
}
foreach ($xpath->query('//*[contains(concat(" ",normalize-space(@class)," ")," wp-block-buttons ")]') as $buttons) {
    if (0 === $xpath->query('.//*[contains(concat(" ",normalize-space(@class)," ")," wp-block-button ")]', $buttons)->length) {
        throw new RuntimeException('An empty core/buttons must never be emitted: ' . $markup);
    }
}
if (1 !== $xpath->query('//h2/a[@href="mailto:x@example.com"]')->length) {
    throw new RuntimeException('The overlaid heading must survive: ' . $markup);
}
// Existing class-based recognition and accessible-name handling stay intact.
foreach (array('', 'aria-label="Open"') as $name) {
    $source = '<a class="btn" style="width:400px;height:60px;background:#333" ' . $name . ' href="/x"><span></span></a>';
    $output = (new ArtifactCompiler())->compile(array(
        'entrypoint' => 'index.html',
        'files' => array('index.html' => $source),
    ))->toArray()['serialized_blocks'];
    if (!str_contains($output, 'wp-block-button__link') || !str_contains($output, '' === $name ? '&#8203;' : 'Open')) {
        throw new RuntimeException('An empty control must keep its surface and any accessible name: ' . $output);
    }
}
// Placeholder icon links must keep their existing native link host, even
// when their dimensions and paint look like the destination-bearing pill.
foreach (array('', '#') as $href) {
    $source = '<style>.pill{display:block;width:400px;height:60px;background:#333;border-radius:15px}</style><a class="pill" href="' . $href . '"><span class="label"></span></a>';
    $output = (new ArtifactCompiler())->compile(array(
        'entrypoint' => 'index.html',
        'files' => array('index.html' => $source),
    ))->toArray()['serialized_blocks'];
    if (str_contains($output, '<!-- wp:buttons') || !str_contains($output, 'href="' . $href . '"')) {
        throw new RuntimeException('A placeholder icon link must keep its native link host: ' . $output);
    }
}
echo "Empty-label painted link passed\n";
