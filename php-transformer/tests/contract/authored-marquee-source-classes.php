<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$html = <<<'HTML'
<!doctype html><html><head><link rel="stylesheet" href="style.css"></head><body>
<div class="sqs-block" data-definition-name="website.components.scrolling">
<div class="Marquee" data-marquee-items='[{"text":"Featured Products"}]' data-animation-direction="left" data-animation-speed="1" data-wave-intensity="0">
<div class="Marquee-display"><div class="Marquee-measure" style="visibility:hidden">
<h1 class="Marquee-item Marquee-item--text">Featured Products</h1>
</div><h1><svg class="Marquee-svg" aria-hidden="true"><text><textPath>Featured Products</textPath></text></svg></h1><div class="Marquee-track"></div></div>
</div></div></body></html>
HTML;
$css = <<<'CSS'
.sqs-block[data-definition-name="website.components.scrolling"] :is(h1.Marquee-item, p.Marquee-item){font-size:calc(var(--marquee-font-size-value,2)*1rem)}
@media screen and (max-width:767px) and (orientation:portrait){.Marquee h1,.Marquee p{font-size:calc((var(--marquee-font-size-value) - 1) * calc(.012 * min(100vh, 900px)) + 1rem)!important}}
@media screen and (min-width:768px), screen and (orientation:landscape){.Marquee h1,.Marquee p{font-size:min(calc((var(--marquee-font-size-value) - 1) * 1.2vw + 1rem), 10rem)!important}}
CSS;
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => $html,
    'style.css' => $css,
)))->toArray();
$plan = (new WordPressSitePlan())->fromCompilerResult($result);
$markup = $result['serialized_blocks'] ?? '';
// Read the serialized HTML, not classes stored only in block comment attributes.
$document = new DOMDocument();
@$document->loadHTML(preg_replace('/<!--.*?-->/s', '', $markup));
$xpath = new DOMXPath($document);
$wrapper = '//*[contains(concat(" ", normalize-space(@class), " "), " blocks-engine-authored-marquee ")]';
$assert(1 === $xpath->query($wrapper . '[contains(concat(" ", normalize-space(@class), " "), " Marquee ")]')->length, 'The serialized marquee wrapper retains its source Marquee class.');
$assert(2 === $xpath->query($wrapper . '[contains(concat(" ", normalize-space(@class), " "), " Marquee ")]//h1[contains(concat(" ", normalize-space(@class), " "), " Marquee-item ")]')->length, 'Both saved item sequences are descendants matched by .Marquee h1.');
$stylesheet = implode("\n", array_column($plan['assets'], 'content'));
$assert(2 === substr_count($stylesheet, '.Marquee h1'), 'Both emitted responsive author rules retain the matching .Marquee h1 selector.');
$assert(str_contains($stylesheet, 'orientation:portrait') && str_contains($stylesheet, 'orientation:landscape'), 'The emitted stylesheet retains both media contexts.');

fwrite(STDOUT, "Authored marquee source classes contract passed\n");
