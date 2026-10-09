<?php
declare(strict_types=1);

/**
 * A code sample whose text mentions markup stays text.
 *
 * Code that documents HTML or components is written escaped in the source:
 * `<code>&lt;span&gt;Label&lt;/span&gt;</code>`. Its text is `<span>Label</span>`,
 * not a span element. core/code `content` used to carry two shapes (plain text
 * for text-only code, sanitized HTML for syntax-highlighted code), and the
 * serializer guessed which one it had by looking for `<span`, `<b`, `<i`, `<em`,
 * `<strong` or `<mark` in the value. Plain text that mentioned one of those
 * tags was saved unescaped, so the sample's own tags became real elements:
 * its links and images became browser references (an unresolved route aborted
 * the whole site plan), and escaped `<script>` text became a script element.
 *
 * `content` is now always HTML, like every other RichText value, and the
 * serializer writes it as is.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message, string $detail = ''): void {
    if ( $condition ) {
        return;
    }

    fwrite(STDERR, 'FAIL: ' . $message . ('' !== $detail ? ' - ' . $detail : '') . PHP_EOL);
    exit(1);
};

$runtime = new Runtime();
$escape = static fn (string $text): string => htmlspecialchars($text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
$savedCode = static function (string $markup): ?string {
    return preg_match('~<pre class="wp-block-code[^"]*"[^>]*><code>(.*?)</code></pre>~s', $markup, $match) ? $match[1] : null;
};

// Every tag name the serializer used to treat as "already HTML", alone and
// next to tags it never looked for.
$samples = array(
    'component' => "<Button variant=\"primary\" href=\"/contact/\">\n  <span>Book a call</span>\n</Button>",
    'bold' => '<b>Bold</b> text',
    'italic' => '<i>Italic</i> text',
    'emphasis' => 'Use <em>em</em> for stress',
    'strong' => '<strong>Important</strong>',
    'mark' => '<mark>Highlighted</mark>',
    'image' => '<img src="/logo.png" alt="Logo"> <em>logo</em>',
    'script' => '<b>x</b><script>alert(1)</script>',
    'entity' => 'Write &lt; as <span>&amp;lt;</span>',
);

foreach ( $samples as $name => $text ) {
    $result = ( new HtmlTransformer() )->transform('<pre><code>' . $escape($text) . '</code></pre>')->toArray();
    $block = $result['blocks'][0] ?? array();
    $markup = (string) ($result['serialized_blocks'] ?? '');
    $saved = $savedCode((string) ($block['innerHTML'] ?? ''));

    $assert('core/code' === ($block['blockName'] ?? ''), "{$name}: the sample lowers to core/code", $markup);
    $assert($escape($text) === $saved, "{$name}: the saved code is the sample text escaped exactly once", (string) $saved);
    $assert($text === html_entity_decode((string) $saved, ENT_QUOTES | ENT_HTML5, 'UTF-8'), "{$name}: the saved code reads back as the authored text", (string) $saved);
    $assert($escape($text) === ($block['attrs']['content'] ?? null), "{$name}: the content attribute is the escaped RichText value", (string) json_encode($block['attrs'] ?? array()));

    $validity = $runtime->validateBlockSerialization($markup);
    $assert(
        'pass' === ($validity['status'] ?? '') && array() === ($validity['findings'] ?? array()),
        "{$name}: the saved code block passes the canonical block validity checks",
        (string) json_encode($validity['findings'] ?? array())
    );
}

// Syntax-highlighted code keeps its sanitized token markup and escapes its
// text nodes once, as before.
$rich = ( new HtmlTransformer() )->transform('<pre><code><span class="tok">&lt;?php</span> echo &quot;&lt;b&gt;&quot;;</code></pre>')->toArray();
$assert(
    '<span class="tok">&lt;?php</span> echo "&lt;b&gt;";' === $savedCode((string) ($rich['blocks'][0]['innerHTML'] ?? '')),
    'syntax-highlighted code keeps its token spans and escaped text',
    (string) ($rich['blocks'][0]['innerHTML'] ?? '')
);

// Plain code without markup in its text keeps the byte-identical saved shape.
$plain = ( new HtmlTransformer() )->transform('<pre><code>if (a &lt; b &amp;&amp; c &gt; d) { run("x"); }</code></pre>')->toArray();
$assert(
    '<pre class="wp-block-code"><code>if (a &lt; b &amp;&amp; c &gt; d) { run("x"); }</code></pre>' === (string) ($plain['blocks'][0]['innerHTML'] ?? ''),
    'plain code keeps its exact saved markup',
    (string) ($plain['blocks'][0]['innerHTML'] ?? '')
);

// Whole-site compile: a style guide page whose code samples mention a page
// route, a missing image and a script still produces a valid site plan, and
// the samples stay text in the page markup.
$guide = '<main><h1>Components</h1>'
    . '<pre><code>' . $escape($samples['component']) . '</code></pre>'
    . '<pre><code>' . $escape($samples['image']) . '</code></pre>'
    . '<pre><code>' . $escape($samples['script']) . '</code></pre>'
    . '</main>';
$site = ( new ArtifactCompiler() )->compile(array(
    'entrypoint' => 'index.html',
    'files' => array(
        'index.html' => '<main><h1>Home</h1><p><a href="/contact/">Contact</a> <a href="/guide/">Guide</a></p></main>',
        'contact/index.html' => '<main><h1>Contact</h1></main>',
        'guide/index.html' => $guide,
    ),
))->toArray();
$plan = $site['source_reports']['wordpress_site_plan'] ?? null;
$errors = array_values(array_filter($site['diagnostics'] ?? array(), static fn (array $diagnostic): bool => 'error' === ($diagnostic['severity'] ?? null)));
$assert(is_array($plan) && 'failed' !== ($site['status'] ?? null), 'a page whose code samples mention routes and media still produces a site plan', (string) json_encode($errors));
WordPressSitePlan::assertValid($plan);

$guideMarkup = '';
foreach ( $plan['pages'] as $page ) {
    if ( 'guide/index.html' === ($page['source_path'] ?? '') || str_ends_with((string) ($page['source_path'] ?? ''), '/guide/index.html') ) {
        $guideMarkup = (string) ($page['canonical_block_markup'] ?? '');
    }
}
$assert('' !== $guideMarkup, 'the guide page is in the plan', (string) json_encode(array_column($plan['pages'], 'source_path')));
foreach ( array('image', 'script') as $name ) {
    $assert(str_contains($guideMarkup, '<code>' . $escape($samples[$name]) . '</code>'), "the {$name} sample stays escaped text in the page markup", $guideMarkup);
}
// Route canonicalization may normalize the quoted path, so check the rest.
$assert(
    str_contains($guideMarkup, '<code>&lt;Button variant="primary" href="/contact') && str_contains($guideMarkup, "&gt;\n  &lt;span&gt;Book a call&lt;/span&gt;\n&lt;/Button&gt;</code>"),
    'the component sample stays escaped text in the page markup',
    $guideMarkup
);
$assert(! preg_match('~<(?:button|img|script|span|b|em)\b~i', $guideMarkup), 'no sample tag becomes an element in the page markup', $guideMarkup);

echo "core/code literal markup text contract passed\n";
