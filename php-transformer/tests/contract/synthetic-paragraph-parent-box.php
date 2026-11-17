<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$fixtures = array();
foreach (array('margin' => 'margin-block-end:8px', 'padding' => 'padding-bottom:8px', 'color' => 'color:navy', 'unmatched' => 'margin-block-end:8px') as $name => $declaration) {
    $parent = 'unmatched' === $name ? '.other' : '.contact-stack';
    $html = '<!doctype html><style>body{font-size:16px;line-height:24px}*{margin:0}address{font-style:normal}.block{display:block}.w-fit{width:fit-content}:where(' . $parent . '>:not(:last-child)){' . $declaration . '}</style>'
        . '<div class="contact-stack"><a href="mailto:hello@example.com" class="block w-fit">hello@example.com</a><address>1 Example St<br>City</address><a href="tel:+15550100" class="block w-fit">+1 555 0100</a></div>';
    $result = (new HtmlTransformer())->transform($html)->toArray();
    $markup = $result['serialized_blocks'] ?? '';
    $css = implode("\n", array_column($result['assets'] ?? array(), 'content'));
    $dom = new DOMDocument();
    @$dom->loadHTML($markup);
    $xpath = new DOMXPath($dom);
    $paragraph = $xpath->query('//p[a[starts-with(@href,"mailto:")]]')->item(0);
    if (!$paragraph instanceof DOMElement || !str_contains($paragraph->getAttribute('class'), 'blocks-engine-synthetic-paragraph')) {
        throw new RuntimeException('Contact link must use a synthetic paragraph.');
    }
    $hasBox = str_contains($paragraph->getAttribute('class'), 'blocks-engine-source-box-paragraph');
    if ($hasBox !== in_array($name, array('margin', 'padding'), true)) {
        throw new RuntimeException($name . ': only matching parent-driven box styling keeps a paragraph box.');
    }
    if (!str_contains($css, ':not(.blocks-engine-source-box-paragraph):has(>a)){display:contents}')) {
        throw new RuntimeException('Unstyled synthetic links must retain the transparent carrier reset.');
    }
    $fixtures[$name] = array('source' => $html, 'native' => '<!doctype html><style>' . $css . '</style>' . $markup);
}
if ($path = getenv('SYNTHETIC_PARENT_BOX_ARTIFACT')) {
    file_put_contents($path, json_encode($fixtures, JSON_THROW_ON_ERROR));
}
echo "Synthetic paragraph parent box contract passed.\n";
