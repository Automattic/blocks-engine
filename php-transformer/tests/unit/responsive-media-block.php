<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\ResponsiveMediaBlockGenerator;

if ( ! function_exists('wp_kses') ) {
    function wp_kses(string $content, array $allowed): string
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<div>' . $content . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $root = $document->documentElement;
        foreach (iterator_to_array($root->getElementsByTagName('*')) as $element) {
            $tag = strtolower($element->tagName);
            if (!isset($allowed[$tag])) {
                $element->parentNode?->removeChild($element);
                continue;
            }
            foreach (iterator_to_array($element->attributes) as $attribute) {
                $name = strtolower($attribute->name);
                $permitted = isset($allowed[$tag][$name]) || (str_starts_with($name, 'aria-') && isset($allowed[$tag]['aria-*'])) || (str_starts_with($name, 'data-') && isset($allowed[$tag]['data-*']));
                $value = strtolower(rawurldecode(rawurldecode(preg_replace('/\s+/', '', html_entity_decode($attribute->value, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '')));
                if (!$permitted || (in_array($name, array('href', 'src', 'longdesc'), true) && preg_match('/^(?:javascript|vbscript|file|blob):/', $value))) $element->removeAttribute($attribute->name);
            }
        }
        $output = '';
        foreach (iterator_to_array($root->childNodes) as $child) $output .= $document->saveHTML($child);
        return $output;
    }
}

if ( ! function_exists('esc_attr') ) {
    function esc_attr(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}

$assert = static function (bool $condition, string $message): void {
    if ( ! $condition ) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
};

$generator = new ResponsiveMediaBlockGenerator();
$definition = $generator->definition('ssi-example');
$assert('ssi-example/responsive-media' === ($definition['block_json']['name'] ?? null), 'one namespaced responsive-media block type is defined');
$assert(false === ($definition['block_json']['supports']['html'] ?? null), 'the companion disables raw HTML editing');
$assert('file:./index.js' === ($definition['block_json']['editorScript'] ?? null), 'the companion declares its editor script');
$assert(array('wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element') === ($definition['script_dependencies']['index.js'] ?? null), 'the companion declares editor dependencies');
$editor = (string) ($definition['assets']['index.js'] ?? '');
$assert(str_contains($editor, "registerBlockType( 'ssi-example/responsive-media'") && str_contains($editor, 'TextareaControl') && str_contains($editor, 'save: function() { return null; }') && !str_contains($editor, 'RawHTML'), 'the editor registers an editable dynamic block with no unsafe markup preview');

$source = '<a class="social" href="/profile" target="_blank" rel="noopener" aria-label="Profile"><picture class="hero"><source media="(min-width: 800px)" type="image/webp" srcset="hero,wide.webp 1200w, hero.webp 600w" sizes="100vw"><img class="avatar" src="hero.jpg" srcset="hero.jpg 1x, hero-2x.jpg 2x" sizes="100vw" width="44" height="44" alt="Profile"></picture></a>';
$result = ( new HtmlTransformer() )->transform($source)->toArray();
$assert('custom/responsive-media' === ($result['blocks'][0]['blockName'] ?? null), 'linked responsive media uses the companion');
$repeated = ( new HtmlTransformer() )->transform($source . $source)->toArray();
$assert(2 === count($repeated['blocks'] ?? array()) && 1 === count($repeated['source_reports']['generated_blocks'] ?? array()), 'multiple instances need one generated definition');
$content = (string) ($result['blocks'][0]['attrs']['content'] ?? '');
foreach (array('media="(min-width: 800px)"', 'type="image/webp"', 'hero,wide.webp 1200w, hero.webp 600w', 'sizes="100vw"', 'href="/profile"', 'target="_blank"', 'rel="noopener"', 'aria-label="Profile"', 'width="44"', 'class="avatar"') as $fragment) {
    $assert(str_contains($content, $fragment), 'responsive companion preserves ' . $fragment);
}

$render = $generator->render();
$attributes = array( 'content' => '<a class="social" data-track="profile" tabindex="-1" aria-current="page" aria-hidden="false" hidden role="link" download href="/profile" target="_blank" rel="noopener"><picture data-picture="hero"><source media="(min-width:800px)" type="image/webp" srcset="safe.webp 1x, javascript:alert(1) 2x, hero,wide.webp 3x" sizes="100vw"><img data-image="avatar" src="data:image/png;base64,aGVsbG8=" srcset="safe.png 1x, %6a%61vascript:alert(1) 2x, data:image/svg+xml;base64,PHN2Zz4= 3x" usemap="#map" longdesc="/description" alt="Profile"></picture></a>' );
ob_start();
eval('?>' . $render);
$safeOutput = ob_get_clean();
foreach (array('data-track="profile"', 'tabindex="-1"', 'aria-current="page"', 'aria-hidden="false"', 'hidden', 'role="link"', 'download', 'target="_blank"', 'rel="noopener"', 'data-picture="hero"', 'data-image="avatar"', 'usemap="#map"', 'longdesc="/description"', 'safe.webp 1x', 'hero,wide.webp 3x', 'safe.png 1x', 'data:image/png;base64,aGVsbG8=') as $fragment) $assert(str_contains($safeOutput, $fragment), 'runtime renderer preserves allowed ' . $fragment);
foreach (array('javascript:', '%6a%61vascript:', 'data:image/svg+xml') as $fragment) $assert(!str_contains($safeOutput, $fragment), 'runtime renderer removes unsafe srcset candidate ' . $fragment);

foreach (array('<script>alert(1)</script>', '<img src=x onerror=alert(1)>', '<a href="java&#x0A;script:alert(1)">x</a>', '<img src="%6a%61vascript:alert(1)">') as $payload) {
    $attributes = array( 'content' => $payload );
    ob_start();
    eval('?>' . $render);
    $output = ob_get_clean();
    $assert(!str_contains(strtolower($output), 'javascript:') && !str_contains(strtolower($output), 'onerror') && !str_contains(strtolower($output), '<script'), 'runtime renderer rejects executable payloads');
}

fwrite(STDOUT, "Responsive media companion tests passed\n");
