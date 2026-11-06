<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) throw new RuntimeException($message);
};
$findButtons = static function (array $blocks) use (&$findButtons): array {
    $buttons = array();
    foreach ($blocks as $block) {
        if ('core/button' === ($block['blockName'] ?? '')) $buttons[] = $block;
        $buttons = array_merge($buttons, $findButtons($block['innerBlocks'] ?? array()));
    }
    return $buttons;
};

// Issue #4593: the absorbed label's text highlight is not the control fill.
$fixture = <<<'HTML'
<style>
:root { --fill: #E4F2D4; --hl: transparent }
.btn { display: flex; background-color: var(--fill); padding: 8px 16px; border-radius: 99px }
.btn .label { background-color: var(--hl); white-space: pre-line }
</style>
<main><a class="btn" href="#c"><span class="label">Contact Us</span></a></main>
HTML;

foreach (array('transparent', 'none', 'rgba(0, 0, 0, 0)') as $highlight) {
    $html = str_replace('--hl: transparent', '--hl: ' . $highlight, $fixture);
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
    $buttons = $findButtons($result['blocks'] ?? array());
    $assert(1 === count($buttons), $highlight . ': fixture becomes one native button.');
    $background = $buttons[0]['attrs']['style']['color']['background'] ?? null;
    $assert('transparent' !== $background, $highlight . ': label must not make the button transparent.');
    $assert('#e4f2d4' === strtolower((string) $background), $highlight . ': anchor owns the fill; got ' . json_encode($background));
}

// Conditional paint stays in projected CSS. An inline label highlight must
// not defeat it, even when the native control attrs have no background.
foreach (array('background', 'background-color') as $property) {
    $html = str_replace(
        array('background-color: var(--fill);', '</style>'),
        array($property . ': var(--fill);', '@media (min-width: 600px) { .btn { ' . $property . ': #123456 } }</style>'),
        $fixture
    );
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
    $buttons = $findButtons($result['blocks'] ?? array());
    $assert(1 === count($buttons), $property . ': conditional fixture becomes one native button.');
    $style = $buttons[0]['attrs']['style'] ?? array();
    $assert(! isset($style['color']['background']), $property . ': conditional control paint must not acquire an inline label background.');
    $assert(str_contains(json_encode($result), '#123456'), $property . ': conditional paint remains in the output.');
}

// Without control paint, even an explicit transparent label fill is meaningful:
// it suppresses the theme default. Opaque whole-label paint must survive too.
foreach (array('transparent', 'rgba(255,255,255,0)', '#abcdef') as $highlight) {
    $html = str_replace(array('background-color: var(--fill);', '--hl: transparent'), array('', '--hl: ' . $highlight), $fixture);
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
    $buttons = $findButtons($result['blocks'] ?? array());
    $assert(1 === count($buttons), $highlight . ': unpainted control becomes one native button.');
    $background = $buttons[0]['attrs']['style']['color']['background'] ?? null;
    $assert($highlight === $background, $highlight . ': label supplies the missing control background; got ' . json_encode($background));
}

echo "Button label background ownership contract passed.\n";
