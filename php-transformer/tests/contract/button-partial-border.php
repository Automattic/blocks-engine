<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$fixture = '<style>.text-link{display:inline-flex;font-size:11px;font-weight:700;color:#0b5e4b;border-bottom:1px solid #baa674;padding:0 0 5px}</style>
<div class="heading"><h2>Before you send an enquiry.</h2><a class="text-link" href="/contact/">Contact the team</a></div>';

foreach (array(
    'issue fixture' => array($fixture, array('bottom'), false),
    'bottom-border-only button' => array($fixture, array('bottom'), false),
    'named outline with partial border' => array(str_replace('text-link', 'outline', $fixture), array('bottom'), false),
    'two bordered sides' => array(str_replace('border-bottom:', 'border-left:1px solid #baa674;border-bottom:', $fixture), array('bottom', 'left'), false),
    'all sides bordered' => array(str_replace('border-bottom:', 'border:', $fixture), array('top', 'right', 'bottom', 'left'), true),
    'one side reset' => array(str_replace('border-bottom:1px solid #baa674', 'border:1px solid #baa674;border-top:0', $fixture), array('right', 'bottom', 'left'), false),
) as $name => [$html, $painted, $outline]) {
    // The exact issue fixture is now correctly recognized as a text link.
    // Exercise the button presentation path as well via explicit author intent.
    if ('issue fixture' !== $name) {
        $html = str_replace('<a ', '<a role="button" ', $html);
    }
    $result = (new ArtifactCompiler())->compile(array('files' => array(
        array('path' => 'index.html', 'kind' => 'html', 'content' => $html),
    )))->toArray();
    $markup = $result['serialized_blocks'];
    $document = new DOMDocument();
    @$document->loadHTML($markup);
    $xpath = new DOMXPath($document);
    $link = $xpath->query('//a[contains(concat(" ", normalize-space(@class), " "), " wp-block-button__link ")]')->item(0);
    $wrapper = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " wp-block-button ")]')->item(0);
    if ('issue fixture' === $name) {
        if ($link instanceof DOMElement || !str_contains($markup, 'border-bottom-width:1px') || str_contains($markup, 'is-style-outline')) {
            throw new RuntimeException('The issue fixture must remain an underline-only text link. ' . $markup);
        }
        continue;
    }
    if (!$link instanceof DOMElement || !$wrapper instanceof DOMElement) {
        throw new RuntimeException($name . ': fixture must lower to core/button. ' . $markup);
    }
    if ($outline !== in_array('is-style-outline', explode(' ', $wrapper->getAttribute('class')), true)) {
        throw new RuntimeException($name . ': only a complete border box may acquire outline defaults. ' . $markup);
    }
    $style = $link->getAttribute('style');
    foreach (array('top', 'right', 'bottom', 'left') as $side) {
        if (!in_array($side, $painted, true) && !preg_match('/(?:^|;)\s*border-' . $side . '-width:\s*0(?:px)?(?:;|$)/', $style)) {
            throw new RuntimeException($name . ': unpainted ' . $side . ' must have zero width. ' . $markup);
        }
    }
    if (!str_contains($style, 'border-bottom-width:1px') && !str_contains($style, 'border-width:1px')) {
        throw new RuntimeException($name . ': authored border width must render. ' . $markup);
    }
    if (!str_contains($style, '#baa674') || !str_contains($style, 'solid')) {
        throw new RuntimeException($name . ': authored border color and style must render. ' . $markup);
    }
}

echo "Button partial border contract passed.\n";
