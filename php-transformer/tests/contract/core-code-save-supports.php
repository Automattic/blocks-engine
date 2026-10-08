<?php
declare(strict_types=1);

/**
 * core/code save() must serialize its wrapper through the canonical native
 * support serializer.
 *
 * WordPress registers core/code with typography, color, spacing (padding and
 * top/bottom margin), border, and className support, and its save() is
 * `<pre {...useBlockProps.save()}><code>{escaped content}</code></pre>`.
 * `useBlockProps.save()` merges the generated `wp-block-code` class, the
 * support-derived `has-*` classes and inline style, and the block's
 * `className` attribute onto the `<pre>` wrapper. When BlockFactory
 * hard-coded `<pre class="wp-block-code">`, every styled code block declared
 * those supports in its block comment while the saved markup omitted them, so
 * the editor's validateBlock flagged the block invalid on first open. The
 * missing projected className token also stopped authored rules such as
 * `white-space:pre-wrap` from matching, leaving the source `white-space:pre`.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\CanonicalSaveShapeValidator;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;

$assert = static function (bool $condition, string $message, string $detail = ''): void {
    if ( $condition ) {
        return;
    }

    fwrite(STDERR, 'FAIL: ' . $message . ('' !== $detail ? ' - ' . $detail : '') . PHP_EOL);
    exit(1);
};

$runtime = new Runtime();

// A neutral page section: one styled code block whose wrapper class an authored
// wrapping rule targets, with a deliberately long line that can only wrap once
// that rule matches again.
$longLine = str_repeat('emit "step-$i,";', 16);
$source = '<style>'
    . '.snippet-card{max-width:640px}'
    . '.snippet-card pre.prompt-code{white-space:pre-wrap;overflow-wrap:anywhere}'
    . '</style>'
    . '<main class="snippet-card">'
    . '<pre class="prompt-code" style="padding:16px 20px;border:1px solid #1e3a8a;border-radius:12px;color:#0f172a;background-color:#e2e8f0;font-size:14px;line-height:1.6">'
    . '<code>' . htmlspecialchars("<?php\n// A long line that must wrap once the projected wrapper marker matches again.\n" . $longLine . "\nprint \$total;\n", ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code>'
    . '</pre>'
    . '</main>';

$result = ( new HtmlTransformer() )->transform($source)->toArray();
$markup = (string) ($result['serialized_blocks'] ?? '');
$css = implode("\n", array_map(static fn (array $asset): string => (string) ($asset['content'] ?? ''), $result['assets'] ?? array()));
$code = $result['blocks'][0]['innerBlocks'][0] ?? array();
$wrapper = (string) ($code['innerHTML'] ?? '');

$assert('core/code' === ($code['blockName'] ?? ''), 'the styled pre/code lowers to core/code', $markup);
$assert(
    'prompt-code' === ($code['attrs']['className'] ?? '')
        && isset($code['attrs']['style']['typography']['fontSize'], $code['attrs']['style']['color']['text'], $code['attrs']['style']['spacing']['padding'], $code['attrs']['style']['border']['width']),
    'the block comment still declares the projected className and the typography/color/spacing/border supports',
    (string) json_encode($code['attrs'] ?? array())
);
$assert(
    str_starts_with($wrapper, '<pre class="wp-block-code has-text-color has-background has-border-color prompt-code" style="color:#0f172a;background-color:#e2e8f0;border-color:#1e3a8a;border-style:solid;border-width:1px;border-radius:12px;padding-top:16px;padding-right:20px;padding-bottom:16px;padding-left:20px;font-size:14px;line-height:1.6"><code>')
        && str_ends_with($wrapper, '</code></pre>'),
    'the saved wrapper serializes the generated class, support classes, projected class, and inline support style the canonical native serializer emits everywhere else',
    $wrapper
);
$assert(
    str_contains($wrapper, htmlspecialchars("<?php\n// A long line that must wrap once the projected wrapper marker matches again.\n" . $longLine . "\nprint \$total;\n", ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8')),
    'code text keeps its newlines, entities, and the unbroken long line exactly as authored',
    $wrapper
);
$assert(
    substr_count($wrapper, '&amp;') === 0 && ! str_contains($wrapper, '&amp;lt;'),
    'code text is escaped exactly once',
    $wrapper
);
$assert(
    str_contains($css, 'white-space:pre-wrap') && str_contains($css, 'pre.prompt-code'),
    'the authored wrapping rule stays in the carried stylesheet',
    $css
);

$validity = $runtime->validateBlockSerialization($markup);
$assert(
    'pass' === ($validity['status'] ?? '') && array() === ($validity['findings'] ?? array()),
    'the serialized page passes the canonical block validity checks',
    (string) json_encode($validity['findings'] ?? array())
);
$assert(
    array() === ( new CanonicalSaveShapeValidator() )->findings($result['blocks'] ?? array()),
    'the serialized page carries no duplicate class tokens or unexpected wrapper classes',
    (string) json_encode(( new CanonicalSaveShapeValidator() )->findings($result['blocks'] ?? array()))
);

$parsed = $runtime->parseBlocks($markup);
$parsedCode = $parsed[0]['innerBlocks'][0] ?? array();
$assert(
    'core/code' === ($parsedCode['blockName'] ?? '')
        && 'prompt-code' === ($parsedCode['attrs']['className'] ?? '')
        && str_contains((string) ($parsedCode['innerHTML'] ?? ''), 'has-text-color has-background has-border-color prompt-code'),
    'parsing the saved markup restores the block comment supports and the serialized wrapper verbatim',
    (string) json_encode(array($parsedCode['attrs'] ?? array(), $parsedCode['innerHTML'] ?? ''))
);

// An unstyled code block keeps the byte-identical canonical shape core saves
// with no supports, so the repair changes nothing for plain markup.
$plain = ( new HtmlTransformer() )->transform('<pre><code>plain code</code></pre>')->toArray();
$assert(
    '<pre class="wp-block-code"><code>plain code</code></pre>' === (string) (($plain['blocks'][0]['innerHTML'] ?? '')),
    'an unstyled code block keeps the exact canonical wrapper core save() emits without supports',
    (string) ($plain['blocks'][0]['innerHTML'] ?? '')
);

// The rich content path (sanitized syntax tokens) stays unescaped by the
// serializer while its own text nodes remain escaped once.
$rich = ( new HtmlTransformer() )->transform(
    '<style>.prompt-code{white-space:pre-wrap}</style><pre class="prompt-code"><code><span class="tok">&lt;?php</span>' . "\n" . 'print $total;</code></pre>'
)->toArray();
$richWrapper = (string) ($rich['blocks'][0]['innerHTML'] ?? '');
$assert(
    str_contains($richWrapper, '<span class="tok">&lt;?php</span>') && str_contains($richWrapper, "\nprint \$total;"),
    'rich code content keeps sanitized token markup and escaped text nodes',
    $richWrapper
);
$richValidity = $runtime->validateBlockSerialization((string) ($rich['serialized_blocks'] ?? ''));
$assert(
    'pass' === ($richValidity['status'] ?? '') && array() === ($richValidity['findings'] ?? array()),
    'the rich-content variant passes the canonical block validity checks',
    (string) json_encode($richValidity['findings'] ?? array())
);

echo "core/code save support serialization contract passed\n";
