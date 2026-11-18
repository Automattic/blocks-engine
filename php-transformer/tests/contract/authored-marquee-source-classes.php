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

// A p item is the case that loses: its author type selector projects to a
// source-tag marker that the emitted item must carry.
foreach (array('h1', 'p') as $tag) {
    $html = <<<'HTML'
    <!doctype html><html><head><link rel="stylesheet" href="style.css"></head><body>
    <div class="sqs-block" data-definition-name="website.components.scrolling">
    <div class="Marquee" data-marquee-items='[{"text":"Featured Products"}]' data-animation-direction="left" data-animation-speed="1" data-wave-intensity="0">
    <div class="Marquee-display"><div class="Marquee-measure" style="visibility:hidden">
    <{TAG} class="Marquee-item Marquee-item--text">Featured Products</{TAG}>
    </div><h1><svg class="Marquee-svg" aria-hidden="true"><text><textPath>Featured Products</textPath></text></svg></h1><div class="Marquee-track"></div></div>
    </div></div></body></html>
    HTML;
    $html = str_replace('{TAG}', $tag, $html);
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
    $assert(2 === $xpath->query($wrapper . '[contains(concat(" ", normalize-space(@class), " "), " Marquee ")]//' . $tag . '[contains(concat(" ", normalize-space(@class), " "), " Marquee-item ")]')->length, "Both saved item sequences are {$tag} descendants of .Marquee.");
    $stylesheet = implode("\n", array_column($plan['assets'], 'content'));
    $assert(str_contains($stylesheet, 'orientation:portrait') && str_contains($stylesheet, 'orientation:landscape'), 'The emitted stylesheet retains both media contexts.');
    // Every responsive `.Marquee <tag>` rule must still select the saved items.
    preg_match_all('/@media[^{]*orientation:(?:portrait|landscape)\)\s*\{([^{]*)\{/', $stylesheet, $rules);
    $assert(2 === count($rules[1]), "Both responsive {$tag} author rules are emitted.");
    foreach ($rules[1] as $selectorList) {
        $branch = '';
        foreach (explode(',', $selectorList) as $candidate) {
            if (str_starts_with(trim($candidate), '.Marquee ') && (str_contains($candidate, 'source-' . $tag . '-') || $tag === trim(substr(trim($candidate), 9)))) $branch = trim($candidate);
        }
        $assert('' !== $branch, "A {$tag} branch remains in: {$selectorList}");
        preg_match_all('/\.(blocks-engine-source-[a-z0-9-]+)/', $branch, $markers);
        $items = $xpath->query($wrapper . '//' . $tag);
        $assert(2 === $items->length, "Both saved {$tag} item sequences are emitted.");
        foreach ($items as $item) foreach ($markers[1] as $marker) {
            $assert(in_array($marker, preg_split('/\s+/', $item->getAttribute('class')), true), "The saved {$tag} item carries {$marker} named by {$branch}.");
        }
    }
}

fwrite(STDOUT, "Authored marquee source classes contract passed\n");
