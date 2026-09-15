<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPress\BlockValidityValidator;

$assertions = 0;
$failures = array();
$assert = static function (bool $condition, string $label) use (&$assertions, &$failures): void {
    ++$assertions;
    if ( ! $condition ) {
        $failures[] = $label;
    }
};
$transform = static fn (string $html): array => (new HtmlTransformer())->transform($html)->toArray();

$result = $transform((string) file_get_contents(dirname(__DIR__) . '/fixtures/static-content-containers.html'));
$markup = (string) ($result['serialized_blocks'] ?? '');
$assert(2 === substr_count($markup, '<!-- wp:heading') && str_contains($markup, 'Community services') && str_contains($markup, 'Contact the team'), 'Static content container headings survive as native heading blocks.');
$assert(2 === substr_count($markup, '<!-- wp:paragraph') && str_contains($markup, 'Practical help for local families.') && str_contains($markup, 'We welcome your questions.'), 'Static content container body text survives as editable paragraphs.');
$assert(str_contains($markup, '<!-- wp:list ') && 2 === substr_count($markup, '<!-- wp:list-item'), 'Nested list items retain their native list structure.');
$assert(str_contains($markup, '<content class="page-content" name="home"><div class="body-copy">'), 'Source tag, attributes, and child selector boundary survive in the existing layout shell.');
$css = implode("\n", array_map(static fn (array $asset): string => 'css' === ($asset['kind'] ?? '') ? (string) ($asset['content'] ?? '') : '', $result['assets'] ?? array()));
$assert(str_contains($css, 'content.page-content[name="home"] > .body-copy { color:#123456; }'), 'Source tag and attribute selectors remain attached to the preserved wrapper boundary.');
$assert(array() === ($result['fallbacks'] ?? array()) && ! str_contains($markup, '<!-- wp:html'), 'Static source containers require no unsupported or raw HTML fallback.');
$assert('pass' === ((new BlockValidityValidator())->validateBlocks($result['blocks'] ?? array())['status'] ?? ''), 'Retained content serializes as valid editable blocks.');

foreach (array(
    'host runtime directive' => '<content data-wp-interactive="sample"><div><h1>Runtime heading</h1></div></content>',
    'descendant runtime directive' => '<content><div><p data-wp-text="state.title">Runtime text</p></div></content>',
    'host event handler' => '<content onclick="activate()"><div><p>Runtime text</p></div></content>',
    'descendant event handler' => '<content><div><p onclick="activate()">Runtime text</p></div></content>',
    'legacy shadow distribution' => '<content select=".selected"><div><p>Fallback content</p></div></content>',
    'nonstructural child' => '<content><span>Inline label</span></content>',
    'unsupported descendant' => '<content><div><h1>Heading</h1><progress value="1" max="2">Progress</progress></div></content>',
    'script descendant' => '<content><div><script>alert(1)</script><h1>Heading</h1></div></content>',
    'embed descendant' => '<content><div><embed src="https://example.test/plugin"><h1>Heading</h1></div></content>',
    'unrecognized element' => '<pagebody><div><h1>Unknown component</h1></div></pagebody>',
) as $label => $source) {
    $guarded = $transform($source);
    $unsupported = array_filter($guarded['fallbacks'] ?? array(), static fn (array $fallback): bool => 'html_unsupported_element' === ($fallback['diagnostic_code'] ?? ''));
    $assert(1 === count($unsupported) && array() === ($guarded['blocks'] ?? array()), $label . ' retains the existing explicit unsupported boundary.');
}

$collection = $transform('<content><div>One</div><div>Two</div></content>');
$assert(str_starts_with((string) ($collection['blocks'][0]['blockName'] ?? ''), 'custom/collection-'), 'Repeated children retain the existing custom collection conversion.');

foreach (array('progress', 'meter', 'dialog', 'slot', 'shadow', 'applet') as $tag) {
    $guarded = $transform('<' . $tag . '><div><h1>Semantic fallback</h1></div></' . $tag . '>');
    $assert(! str_contains((string) ($guarded['serialized_blocks'] ?? ''), '<!-- wp:heading'), $tag . ' is not reinterpreted as a transparent source container.');
}

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
echo 'Static content containers: ' . $assertions . " passed\n";
