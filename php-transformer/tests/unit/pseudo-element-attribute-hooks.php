<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = array();
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};
$result = (new HtmlTransformer())->transform(
    '<style>.panel{width:200px;height:80px;position:relative}.panel[data-accent="true"]::after{content:"";position:absolute;inset:0;border-top:5px solid #39b4e1;pointer-events:none}.panel::before{content:"plain"}</style>'
    . '<div class="panel" data-accent="true"><p>Card</p></div>', array()
)->toArray();
$css = implode("\n", array_column($result['assets'], 'content'));
$markup = $result['serialized_blocks'];
$assert(1 === preg_match('/blocks-engine-attribute-[a-z0-9-]+/', $markup, $marker), 'the native block receives its source attribute identity');
$assert(isset($marker[0]) && str_contains($css, ':where(.' . $marker[0] . ')') && str_contains($css, '::after{content:""'), 'carried after paint addresses a saved block marker');
$assert(!str_contains($css, '[data-accent="true"]::after'), 'no after rule remains stranded on an attribute core cannot save');
$assert(str_contains($css, '.panel::before{content:"plain"}'), 'ordinary class-owned pseudo-elements retain their original selector');
$assert(!str_contains($markup, 'border-top:5px'), 'pseudo-element paint is not baked onto the host box');
$host = CssSelectorMatcher::pseudoElementHost('.panel[data-accent]:before');
$assert(null !== $host && ':before' === $host['suffix'] && '.panel[data-accent]' === $host['selector'], 'legacy pseudo-elements retain their suffix');
$assert(null === CssSelectorMatcher::pseudoElementHost('.panel:hover::after'), 'dynamic host state is not asserted as a static match');
$assert(null === CssSelectorMatcher::pseudoElementHost('.panel::first-letter'), 'unmodeled pseudo-elements remain outside the host projection');

if ($failures) { fwrite(STDERR, implode("\n", $failures) . "\n"); exit(1); }
echo "Pseudo-element attribute hooks passed: 8 assertions\n";
