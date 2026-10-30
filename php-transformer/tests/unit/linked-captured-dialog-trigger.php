<?php
declare(strict_types=1);

/**
 * A link wrapping a captured dialog-trigger button converts natively.
 *
 * Real sources ship the invalid interactive nesting of an `<a href>` around a
 * captured dialog-trigger `<button>`. The control itself has a native editable
 * conversion — the runtime authored-button the same element takes without a
 * link — so the anchor lowers to the saved link wrapper around that native
 * control instead of one core/html island. The control keeps its dialog
 * binding attributes and the saved anchor keeps the link target. A link
 * wrapping a control with no native conversion keeps the wrapped-button
 * preservation.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\BlockValidityValidator;

$failures = 0;
$assert = static function (bool $condition, string $message, string $context = '') use (&$failures): void {
	if ($condition) return;
	++$failures;
	fwrite(STDERR, "FAIL: {$message}\n" . ('' !== $context ? substr($context, 0, 1600) . "\n" : ''));
};
$findBlock = static function (array $blocks, callable $match) use (&$findBlock): array {
	foreach ($blocks as $block) {
		if (!is_array($block)) continue;
		if ($match($block)) return $block;
		$found = $findBlock(is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : array(), $match);
		if (array() !== $found) return $found;
	}
	return array();
};

$trigger = '<button class="action-btn" aria-label="Listen" data-blocks-engine-capture-dialog-trigger="data-blocks-engine-capture-dialog-0" aria-controls="data-blocks-engine-capture-dialog-0" aria-haspopup="dialog" aria-expanded="false"><svg width="24" height="24" viewBox="0 0 24 24"><path d="M3 3h18v18H3z"/></svg><span>Listen</span></button>';
$panel = '<div class="data-blocks-engine-capture-dialog" role="dialog" hidden id="data-blocks-engine-capture-dialog-0" data-blocks-engine-capture-dialog-panel="data-blocks-engine-capture-dialog-0" aria-label="Sign in"><a href="/signin">Sign in</a></div>';

// The reduced neutral shape: a link around the trigger button beside its panel.
$fragment = (new HtmlTransformer())->transform(
	'<div class="actions"><a class="action" href="/signin?next=/story">' . $trigger . '</a></div>' . $panel,
	array('runtime_dom_selectors' => array('[data-blocks-engine-capture-dialog-trigger]', '[data-blocks-engine-capture-dialog-panel]'))
)->toArray();
$markup = (string) ($fragment['serialized_blocks'] ?? '');
$control = $findBlock($fragment['blocks'] ?? array(), static fn (array $block): bool => 'custom/authored-button' === ($block['blockName'] ?? ''));
$assert(array() !== $control, 'the linked dialog trigger converts to a native authored button instead of core/html', $markup);
$assert(!str_contains($markup, '<!-- wp:html'), 'no core/html island remains for the linked trigger', $markup);
$assert(array() === ($fragment['fallbacks'] ?? array()), 'the linked trigger loses no fallback', json_encode($fragment['fallbacks'] ?? array()));
$controlMarkup = (string) ($control['innerHTML'] ?? '');
$assert(str_contains($controlMarkup, 'data-blocks-engine-capture-dialog-trigger="data-blocks-engine-capture-dialog-0"')
	&& str_contains($controlMarkup, 'aria-controls="data-blocks-engine-capture-dialog-0"')
	&& str_contains($controlMarkup, 'aria-haspopup="dialog"')
	&& str_contains($controlMarkup, 'aria-expanded="false"')
	&& str_contains($controlMarkup, 'aria-label="Listen"'),
	'the native control keeps its dialog bindings and accessible name', $controlMarkup);
$assert(str_contains($controlMarkup, '<span>Listen</span>') && str_contains($controlMarkup, '<svg'), 'the label text and artwork stay inside the native control', $controlMarkup);
$shell = $findBlock($fragment['blocks'] ?? array(), static fn (array $block): bool => str_ends_with((string) ($block['blockName'] ?? ''), '/layout-shell')
	&& array() !== array_filter(is_array($block['attrs']['wrappers'] ?? null) ? $block['attrs']['wrappers'] : array(), static fn (array $wrapper): bool => 'a' === ($wrapper['tagName'] ?? '') && '/signin?next=/story' === ($wrapper['attributes']['href'] ?? '')));
$assert(array() !== $shell, 'the saved link keeps the source target as the wrapper around the control', $markup);
$assert(str_contains($markup, '<a class="action" href="/signin?next=/story">'), 'the saved link keeps its source class', $markup);
$assert('pass' === ((new BlockValidityValidator())->validateBlocks($fragment['blocks'] ?? array())['status'] ?? ''), 'the linked trigger markup stays Gutenberg-valid');

// The trigger without a link (the existing conversion) is unchanged for parity.
$unlinked = (new HtmlTransformer())->transform('<div class="actions">' . $trigger . '</div>' . $panel, array('runtime_dom_selectors' => array('[data-blocks-engine-capture-dialog-trigger]', '[data-blocks-engine-capture-dialog-panel]')))->toArray();
$unlinkedControl = $findBlock($unlinked['blocks'] ?? array(), static fn (array $block): bool => 'custom/authored-button' === ($block['blockName'] ?? ''));
$assert(array() !== $unlinkedControl && ($unlinkedControl['attrs'] ?? array()) === ($control['attrs'] ?? array()), 'the link changes nothing about the control the button becomes', $markup);

// A link around a control no runtime drives keeps the wrapped-button preservation.
$undriven = (new HtmlTransformer())->transform('<div class="actions"><a class="action" href="/menu"><button class="toggle" aria-controls="menu" aria-expanded="false">Menu</button></a></div><div id="menu" hidden><a href="#contact">Contact</a></div>')->toArray();
$undrivenMarkup = (string) ($undriven['serialized_blocks'] ?? '');
$assert(str_contains($undrivenMarkup, '<!-- wp:html -->') && str_contains($undrivenMarkup, '<a class="action" href="/menu"><button class="toggle" aria-controls="menu"'),
	'a link around a button with no native conversion stays preserved markup', $undrivenMarkup);

// A link around a form-submit button keeps the wrapped-button preservation.
$submit = (new HtmlTransformer())->transform('<div class="actions"><a class="action" href="/join"><button type="submit" class="send">Join</button></a></div>')->toArray();
$submitMarkup = (string) ($submit['serialized_blocks'] ?? '');
$assert(str_contains($submitMarkup, '<!-- wp:html -->') && !str_contains($submitMarkup, 'custom/authored-button'),
	'a link around a submit control stays preserved markup', $submitMarkup);

// The reduced neutral page: the inline capture runtime names the selectors, so
// the compiled story keeps the native trigger inside its saved link.
$helper = <<<'JS'
(function(){function triggers(){return document.querySelectorAll('[data-blocks-engine-capture-dialog-trigger]');}function panel(trigger){var id=trigger.getAttribute('aria-controls');return id?document.getElementById(id):null;}function apply(trigger){var target=panel(trigger);if(!target)return;target.hidden=trigger.getAttribute('aria-expanded')!=='true';}function toggle(trigger){var open=trigger.getAttribute('aria-expanded')==='true';trigger.setAttribute('aria-expanded',open?'false':'true');apply(trigger);if(open)trigger.focus();}document.addEventListener('click',function(event){var trigger=event.target.closest&&event.target.closest('[data-blocks-engine-capture-dialog-trigger]');if(!trigger||!panel(trigger))return;event.preventDefault();toggle(trigger);});triggers().forEach(apply);})();
JS;
$page = '<!doctype html><html><head><title>Story</title><script data-blocks-engine-capture-disclosure-runtime="true">' . $helper . '</script></head><body>'
	. '<main><h1>Story</h1><div class="actions"><a class="action" href="/signin?next=/story">' . $trigger . '</a></div></main>'
	. $panel
	. '</body></html>';
$derived = (new ArtifactCompiler())->runtimeContextForSource($page, 'website/index.html', array(array('path' => 'website/index.html', 'content' => $page)));
$assert(in_array('[data-blocks-engine-capture-dialog-trigger]', $derived['runtime_dom_selectors'] ?? array(), true), 'the page runtime names the trigger selector: ' . json_encode($derived['runtime_dom_selectors'] ?? array()));
$compiled = (new ArtifactCompiler())->compile(array('files' => array('website/index.html' => $page)))->toArray();
$compiledMarkup = (string) ($compiled['serialized_blocks'] ?? '');
$compiledControl = $findBlock($compiled['blocks'] ?? array(), static fn (array $block): bool => 'custom/authored-button' === ($block['blockName'] ?? ''));
$assert(array() !== $compiledControl
	&& str_contains((string) ($compiledControl['innerHTML'] ?? ''), 'data-blocks-engine-capture-dialog-trigger="data-blocks-engine-capture-dialog-0"')
	&& str_contains((string) ($compiledControl['innerHTML'] ?? ''), 'aria-controls="data-blocks-engine-capture-dialog-0"'),
	'the compiled story keeps the native trigger with its dialog bindings', $compiledMarkup);
$assert(str_contains($compiledMarkup, 'href="/signin?next=/story"') && !str_contains($compiledMarkup, '<!-- wp:html -->'),
	'the compiled story keeps the link target and drops no core/html island', $compiledMarkup);
$assert(array() === ($compiled['fallbacks'] ?? array()), 'the compiled story adds no fallback', json_encode($compiled['fallbacks'] ?? array()));
$assert('pass' === ((new BlockValidityValidator())->validateBlocks($compiled['blocks'] ?? array())['status'] ?? ''), 'the compiled story stays Gutenberg-valid');

if (0 !== $failures) exit(1);
echo "linked-captured-dialog-trigger: ok\n";
