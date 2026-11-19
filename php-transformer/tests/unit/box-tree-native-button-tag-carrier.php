<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\BoxTreeStyleProjector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\LayoutGeometryState;

// A source `<button>` becomes a core/button whose tagName is `button`, so core
// saves its link as `<button class="wp-block-button__link">`. The box-tree
// carrier for that link must select a `<button>`: an `a.wp-block-button__link`
// rule matched nothing, and a skip link the source hides until focused
// (opacity:0, height:0) painted as core's dark pill on every page (#6445).
$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
$viewports = array('desktop' => array('width' => 1440, 'height' => 900));
$hidden = array('opacity' => '0', 'height' => '0px', 'width' => '48px', 'border-radius' => '24px', 'pointer-events' => 'none');
$observations = array('desktop' => array('used_styles' => $hidden));

// Every rule declaring the link's paint must end on a compound that matches
// the element core saves for this block.
$linkRules = static function (string $css): array {
    preg_match_all('/([^{}]+)\{([^{}]*opacity:0[^{}]*)\}/', $css, $rules);
    return array_map('trim', $rules[1]);
};
$matchesTag = static function (string $selector, string $tag): bool {
    $parts = preg_split('/\s*>\s*/', $selector) ?: array();
    $last = (string) end($parts);
    if (!str_contains($last, '.wp-block-button__link')) return false;
    $type = strstr($last, '.', true);
    return '' === $type || $tag === $type;
};

foreach (array('button' => 'button', '' => 'a') as $tagName => $saved) {
    $state = new LayoutGeometryState();
    $attrs = (new BoxTreeStyleProjector())->project('' === $tagName ? array() : array('tagName' => $tagName), $observations, $viewports, $state, array(), 'core/button', array(), array(), 'button' === $tagName ? 'button' : 'a');
    $css = $state->cssForSerializedBlocks((string) ($attrs['className'] ?? ''));
    $rules = $linkRules($css);
    $assert(array() !== $rules, 'The hidden link paint is projected: ' . $css);
    foreach ($rules as $selector) {
        $assert($matchesTag($selector, $saved), 'The link carrier selects the saved <' . $saved . '>: ' . $selector);
    }
}

// A core/buttons whose source is the `<button>` itself owns the link too.
$state = new LayoutGeometryState();
$attrs = (new BoxTreeStyleProjector())->project(array(), $observations, $viewports, $state, array(), 'core/buttons', array(), array(), 'button');
$css = $state->cssForSerializedBlocks((string) ($attrs['className'] ?? ''));
$rules = $linkRules($css);
$assert(array() !== $rules, 'The buttons row projects the hidden link paint: ' . $css);
foreach ($rules as $selector) {
    $assert($matchesTag($selector, 'button'), 'The buttons row carrier reaches a saved <button>: ' . $selector);
}

echo "Box-tree native button tag carrier passed\n";
