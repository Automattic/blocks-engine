<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) throw new RuntimeException($message);
};
$compile = static function (string $html): string {
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
    return (string) $result['serialized_blocks'];
};
$html = file_get_contents(dirname(__DIR__) . '/fixtures/class-owned-native-buttons.html');
foreach (array($html, str_replace(array('wp-block-buttons', 'wp-block-button__link', 'wp-block-button', 'wp-element-button'), array('actions', 'action-link', 'action-shell', 'action'), $html)) as $fixture) {
    $markup = $compile($fixture);
    $assert(1 === preg_match_all('/<!-- wp:buttons(?: |-->)/', $markup), 'The pair has one core/buttons owner.');
    preg_match_all('/<!-- wp:button (\{.*?\}) -->/', $markup, $matches);
    $assert(2 === count($matches[1]), 'Both class-painted links become core/button.');
    foreach ($matches[1] as $json) {
        $attrs = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $style = $attrs['style'] ?? array();
        $assert('#f1634c' === ($style['color']['background'] ?? null), 'Background is native paint.');
        $assert('#fff' === ($style['color']['text'] ?? null), 'Text colour is native paint.');
        $assert('30px' === ($style['border']['radius'] ?? null), 'Radius is native paint.');
        $assert(array('top' => '10px', 'right' => '25px', 'bottom' => '10px', 'left' => '25px') === ($style['spacing']['padding'] ?? null), 'Padding is native paint.');
    }
    $document = new DOMDocument();
    @$document->loadHTML($markup);
    $links = $document->getElementsByTagName('a');
    $assert(2 === $links->length, 'Exactly two links render.');
    foreach (array(array('#services', 'View services'), array('/contact/', 'Contact')) as $index => [$href, $label]) {
        $link = $links->item($index);
        $assert($href === $link->getAttribute('href') && $label === trim($link->textContent), 'Label and href survive.');
        foreach (array('background-color:#f1634c', 'border-radius:30px', 'padding-top:10px', 'padding-right:25px', 'color:#fff') as $paint) {
            $assert(str_contains($link->getAttribute('style'), $paint), 'Rendered link retains ' . $paint);
        }
    }
}
// A positioned fragment link is deliberately declined by ButtonsPattern. Its
// class-owned paint must still survive preprocessing and RichText lowering.
$declined = $compile(str_replace('background:#f1634c', 'position:absolute;background:#f1634c', $html));
$document = new DOMDocument();
@$document->loadHTML($declined);
$link = (new DOMXPath($document))->query('//a[@href="#services"]')->item(0);
$assert($link instanceof DOMElement && str_contains($link->getAttribute('class'), 'wp-block-button__link') && str_contains($link->getAttribute('class'), 'wp-element-button'), 'Declined link keeps its author selector classes.');
echo "Class-owned native buttons contract passed.\n";
