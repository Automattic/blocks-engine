<?php
declare(strict_types=1);

/**
 * A control can stack an alternate state over its resting state: a second,
 * absolutely positioned label that a hover script fades in. The frozen-state
 * strip keeps that layer's `opacity:0` because hiding it is intentional, so
 * the inline carrier must carry it too. Otherwise both labels render.
 *
 * Content hidden only by a frozen entrance (in flow, not positioned) is still
 * revealed, and the carrier does not start carrying authored partial opacity.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$failures = 0;
$passes = 0;

$assert = static function (bool $condition, string $message, string $detail = '') use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }

    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . ('' !== $detail ? ' - ' . $detail : '') . PHP_EOL);
};

$css = '.control{position:relative;display:flex;overflow:hidden;padding:4px}.label{position:relative}.label-next{position:absolute;bottom:-32px;left:50%}';

/** The declarations of the carrier rule on the element whose class starts with `$class`. */
$carrier = static function (string $html, string $class) use ($css): string {
    $result = ( new HtmlTransformer() )->transform($html, array( 'static_css' => $css ))->toArray();
    $styles = '';
    foreach ( $result['assets'] ?? array() as $asset ) {
        $styles .= (string) ($asset['content'] ?? '') . "\n";
    }
    $serialized = (string) ($result['serialized_blocks'] ?? '');
    if ( ! preg_match('/class="wp-block-group ' . preg_quote($class, '/') . '\b[^"]*\b(be-inline-geometry-[a-f0-9-]+)/', $serialized, $match) ) {
        return '';
    }

    return preg_match('/\.' . preg_quote($match[1], '/') . '\{([^}]*)\}/', $styles, $rule) ? $rule[1] : '';
};

$alternate = $carrier('<a class="control" href="/reply"><div class="label"><p>Reply</p></div><div class="label-next" style="opacity:0;transform:translateX(-50%)"><p>Reply</p></div></a>', 'label-next');
$assert(1 === preg_match('/(?:^|;)opacity:0\b/', $alternate), 'a positioned alternate-state layer stays hidden', $alternate);

$inline = $carrier('<div class="control"><div class="label"><p>Reply</p></div><div style="position:absolute;left:0;opacity:0"><p>Reply</p></div></div>', 'control');
$assert('' === $inline || ! str_contains($inline, 'opacity:1'), 'the resting state is not forced visible over its layer', $inline);

$entrance = ( new HtmlTransformer() )->transform('<section><div class="label" style="opacity:0"><p>Revealed on entrance</p></div></section>', array( 'static_css' => $css ))->toArray();
$entranceCss = '';
foreach ( $entrance['assets'] ?? array() as $asset ) {
    $entranceCss .= (string) ($asset['content'] ?? '');
}
$assert(! preg_match('/opacity:0\b/', (string) $entrance['serialized_blocks'] . $entranceCss), 'in-flow content hidden by a frozen entrance is still revealed', $entranceCss);

$partial = $carrier('<div class="control"><div class="label"><p>Reply</p></div><div class="label-next" style="opacity:0.5"><p>Reply</p></div></div>', 'label-next');
$assert(! str_contains($partial, 'opacity'), 'authored partial opacity is not newly carried', $partial);

fwrite(STDOUT, sprintf("hidden positioned layer opacity: %d passed, %d failed\n", $passes, $failures));
exit($failures > 0 ? 1 : 0);
