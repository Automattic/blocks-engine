<?php
declare(strict_types=1);

/**
 * A hosted builder can move its native submit off-screen with inline styles
 * and paint a captioned sibling in its place. The form layout and presentation
 * graphs describe that visible sibling at the submit's control index, so a
 * provider button reproduces the box a visitor sees instead of a 1px input.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\FormControlClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueInspector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\FormLayoutGraphBuilder;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\FormPresentationGraphBuilder;

$failures = 0;
$passes = 0;
$assert = static function (bool $ok, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $ok ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ( '' !== $detail ? ' - ' . $detail : '' ) . PHP_EOL);
};

$css = '.btn { display: inline-block; background-color: #f5a623; padding: 0; } .btn .inner { color: #ffffff; font-family: Montserrat; font-weight: 700; }';
$hidden = 'position:absolute;top:0;left:-9999px;width:1px;height:1px';
$form = static function (string $submit, string $siblings) : DOMElement {
    $document = new DOMDocument();
    $document->loadHTML('<?xml encoding="utf-8" ?><body><form class="f" method="post"><input type="text" name="name"><div class="actions">' . $submit . $siblings . '</div></form></body>', LIBXML_NOERROR | LIBXML_NOWARNING);
    return $document->getElementsByTagName('form')->item(0);
};
$presenter = '<a class="btn"><span class="inner">Send</span></a>';

// The off-screen submit is presented by its captioned sibling.
$source = $form('<input type="submit" style="' . $hidden . '" value="Send">', $presenter);
$controls = FormControlClassifier::controlElements($source);
$presented = FormControlClassifier::presentedControlElements($source);
$assert(2 === count($controls) && 'input' === $controls[1]->tagName, 'control semantics keep the native submit', (string) count($controls));
$assert(2 === count($presented) && 'a' === $presented[1]->tagName && $presented[0] === $controls[0], 'presentation describes the visible sibling at the same index');

$layout = array_column((new FormLayoutGraphBuilder())->build($source, array(), $css)['nodes'], null, 'id');
$assert('a' === ($layout['control-1']['source']['tag'] ?? null) && 'inline-block' === ($layout['control-1']['layout']['display'] ?? null), 'the layout graph sizes the presenter box', json_encode($layout['control-1'] ?? null));

$presentation = (new FormPresentationGraphBuilder())->build($source, array(), $css);
$styles = array_column($presentation['controls'], null, 'index')[1]['control']['styles'] ?? array();
$assert('#f5a623' === ($styles['background_color'] ?? null), 'the presenter paint reaches the submit', json_encode($styles));
$assert('#ffffff' === ($styles['color'] ?? null) && 'Montserrat' === ($styles['font_family'] ?? null) && '700' === ($styles['font_weight'] ?? null), 'the presenter caption typography reaches the submit', json_encode($styles));
$assert(! isset($styles['left']) && ! isset($styles['width']), 'the hidden input geometry stays off the submit', json_encode($styles));

// A visible submit, a captionless match, an ambiguous match, and a real link all keep the native control.
$cases = array(
    'visible submit' => $form('<input type="submit" value="Send">', $presenter),
    'clip-only hiding' => $form('<input type="submit" style="position:absolute;clip:rect(0 0 0 0)" value="Send">', $presenter),
    'different caption' => $form('<input type="submit" style="' . $hidden . '" value="Send">', '<a class="btn">Cancel</a>'),
    'two presenters' => $form('<input type="submit" style="' . $hidden . '" value="Send">', $presenter . $presenter),
    'navigating link' => $form('<input type="submit" style="' . $hidden . '" value="Send">', '<a class="btn" href="/send">Send</a>'),
    'non-submit control' => $form('<input type="text" name="other" style="' . $hidden . '" value="Send">', $presenter),
);
foreach ( $cases as $name => $case ) {
    $assert(FormControlClassifier::controlElements($case) === FormControlClassifier::presentedControlElements($case), $name . ' paints itself');
}

$button = $form('<button type="submit" style="position:absolute; left:-10000px">Send</button>', '<span class="btn">Send</span>');
$assert('span' === (FormControlClassifier::presentedControlElements($button)[1]->tagName ?? null), 'a hidden button is presented by its captioned sibling');

$assert(CssValueInspector::isMovedOffscreenBox(array( 'position' => 'absolute', 'top' => '-9999px' )), 'a negative top offset moves the box off-screen');
$assert(! CssValueInspector::isMovedOffscreenBox(array( 'position' => 'relative', 'left' => '-9999px' )), 'a relative box stays in flow');
$assert(! CssValueInspector::isMovedOffscreenBox(array( 'position' => 'absolute', 'left' => '-20px' )), 'a small negative offset is still on-screen');

if ( 0 < $failures ) {
    fwrite(STDERR, "form off-screen submit presenter FAILED: {$passes} passed, {$failures} failed\n");
    exit(1);
}
echo "form off-screen submit presenter passed: {$passes} assertions\n";
