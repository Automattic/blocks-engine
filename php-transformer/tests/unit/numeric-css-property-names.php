<?php
declare(strict_types=1);

// Some generators write broken declarations such as `0: none`. A browser
// drops them: a declaration name must be an identifier, and `0` starts like a
// number. PHP stores a name like `0` as an int array key, so a declaration map
// keyed by name handed an int to string code, and the transform died with a
// TypeError under strict_types. Every parser that builds such a map now skips
// these names, and the result is the same as without the broken declaration.

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Css\CssPropertyName;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformerAnalysisCache;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\ButtonStyleResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\CoverStyleResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\RevealAnimationSettler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolutionContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use Automattic\BlocksEngine\PhpTransformer\Support\RenderEquivalentMarkup;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ResponsiveBreakpoints;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\ThemeJsonProjection;

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
};
$run = static function (string $label, callable $callback) use ($assert): mixed {
    try {
        return $callback();
    } catch (Throwable $error) {
        $assert(false, $label . ' threw ' . get_class($error) . ': ' . $error->getMessage());
        return null;
    }
};
$stringKeys = static function (mixed $value) use (&$stringKeys): bool {
    if (!is_array($value)) {
        return true;
    }
    foreach ($value as $key => $item) {
        if (is_int($key) && !array_is_list($value)) {
            return false;
        }
        if (!$stringKeys($item)) {
            return false;
        }
    }
    return true;
};

// The name rule: every name PHP stores as an int key starts like a number,
// and no property or custom property name does.
$run('name rule', static function () use ($assert): void {
    foreach (array('0', '12', '-1', '+1', '.5', '-.5', '1px', '00', '-0') as $name) {
        $assert(CssPropertyName::startsLikeNumber($name), '"' . $name . '" starts like a number');
    }
    foreach (array('color', '-webkit-box-flex', '--0', '--12', '_zoom', 'margin-block', '') as $name) {
        $assert(!CssPropertyName::startsLikeNumber($name), '"' . $name . '" is not number-like');
    }
    foreach (array('0', '12', '-1', '512', '9223372036854775807') as $name) {
        $assert(is_int(array_key_first(array($name => true))) && CssPropertyName::startsLikeNumber($name), 'int key "' . $name . '" is number-like');
    }
});

// Style resolver declaration maps (inline styles and every stylesheet rule).
$session = new HtmlTransformerSession(new Runtime(), static fn (DOMElement $node): array => array());
$context = null;
$context = new StyleResolutionContext(
    $session,
    static fn (DOMElement $node): int => 0,
    static fn (string $value): string => $value,
    static function (string $selector) use (&$context): array {
        return $context->sourceStyles()->parsedSelector($selector);
    },
    static fn (string $className): string => $className,
    static fn (string $url): string => $url,
    static fn (DOMElement $node): bool => false
);
$resolver = new StyleResolver($context, new HtmlTransformerAnalysisCache());
$broken = '0: ;0: none;12: 1px; padding-inline: 4px 8px;-1: x;color: red;1px: 0';
$expectedVerbatim = array('padding-inline' => '4px 8px', 'color' => 'red');
$expectedPhysical = array('padding-left' => '4px', 'padding-right' => '8px', 'color' => 'red');
$assert($expectedVerbatim === $run('verbatimCssDeclarations', static fn (): array => $resolver->verbatimCssDeclarations($broken)), 'verbatim declarations skip number-like names');
$assert($expectedPhysical === $run('cssDeclarations', static fn (): array => $resolver->cssDeclarations($broken)), 'physical declarations skip number-like names');

// Every other declaration-map parser gives the same result as without the
// broken declarations.
$same = static function (string $label, callable $parse, string $withNumbers, string $without) use ($assert, $run, $stringKeys): void {
    $broken = $run($label . ' with number-like names', static fn (): mixed => $parse($withNumbers));
    $clean = $run($label . ' without them', static fn (): mixed => $parse($without));
    $assert($stringKeys($broken), $label . ' keeps string keys');
    $assert($clean === $broken, $label . ' ignores number-like names');
};
$same('ButtonStyleResolver', static fn (string $style): array => (new ButtonStyleResolver())->nativeAttributes($style), '0: none; padding: 4px 8px; 12: 0; background-color: #000; color: #fff', 'padding: 4px 8px; background-color: #000; color: #fff');
$same('CoverStyleResolver', static fn (string $style): array => (new CoverStyleResolver())->declarations($style), '0: none; min-height: 600px; 12: 0', 'min-height: 600px');
$same('RevealAnimationSettler', static fn (string $css): array => (new RevealAnimationSettler())->settleRules($css), '@keyframes up{from{opacity:0}to{opacity:1}}.r{0:none;opacity:0;animation:up 1s}', '@keyframes up{from{opacity:0}to{opacity:1}}.r{opacity:0;animation:up 1s}');
$same('ResponsiveBreakpoints', static fn (string $css): array => ResponsiveBreakpoints::fromAssets(array(array('kind' => 'css', 'path' => 'a.css', 'content' => $css))), '@media (max-width:900px){.a{0:none;display:none}.b{12:x;flex-direction:column}}', '@media (max-width:900px){.a{display:none}.b{flex-direction:column}}');
$same('ThemeJsonProjection', static fn (string $css): array => (new ThemeJsonProjection())->project(array(array('kind' => 'css', 'path' => 'website/a.css', 'content' => $css)))['theme'], 'body{0:none;color:#111}h1{12:x;font-size:40px}', 'body{color:#111}h1{font-size:40px}');
$same('RenderEquivalentMarkup', static fn (string $style): string => RenderEquivalentMarkup::canonicalStyle($style), '0: none; color: red; 12: x; margin: 0', 'color: red; margin: 0');

// Full transform: a stylesheet rule and an inline style with the broken
// declarations convert like the same input without them.
$inputs = array(
    'stylesheet rule' => array(
        '<style>.cta a{0: ;0: none;border-style:none;color:#c00;padding:4px 8px}</style><div class="cta"><p>Read <a href="/x">more</a></p></div>',
        '<style>.cta a{border-style:none;color:#c00;padding:4px 8px}</style><div class="cta"><p>Read <a href="/x">more</a></p></div>',
    ),
    'inline style' => array(
        '<div style="0: none; padding: 8px; 12: x"><p>Hello</p></div>',
        '<div style="padding: 8px"><p>Hello</p></div>',
    ),
);
foreach ($inputs as $label => [$withNumbers, $without]) {
    $broken = $run('transform of ' . $label, static fn (): array => (new HtmlTransformer())->transform($withNumbers)->toArray());
    $clean = $run('transform of clean ' . $label, static fn (): array => (new HtmlTransformer())->transform($without)->toArray());
    $assert(is_array($broken) && is_array($clean) && $clean['blocks'] === $broken['blocks'] && $clean['serialized_blocks'] === $broken['serialized_blocks'], 'transform of ' . $label . ' gives the same blocks as without the broken declarations');
}

if ($failures > 0) {
    exit(1);
}

fwrite(STDOUT, "numeric-css-property-names: passed\n");
