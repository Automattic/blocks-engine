<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$item = '<article><button aria-expanded="false"><span>Neutral question?</span><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" class="resting" data-dla-disclosure-open-class="expanded"><path d="M2 3L21 7L6 22Z"/></svg></button><div role="region" hidden><p>Editable answer.</p></div></article>';
$html = '<style>.list{--offset:12px}.expanded{transform:translateX(var(--offset)) rotate(45deg);transform-origin:25% 75%;scale:1.6 .7}button{display:flex;padding:20px}.label{flex:1}</style><main><section class="list">' . $item . $item . '</section></main>';
$result = (new HtmlTransformer())->transform($html)->toArray();
$scripts = array_values(array_filter($result['assets'], static fn (array $asset): bool => 'engine-presentation' === ($asset['source'] ?? '')));
if (1 !== count($scripts)) throw new RuntimeException('Sanitized vector artwork must emit one content-addressed presentation script per document.');
if (!str_contains($scripts[0]['content'], '\\u003Csvg') || str_contains($scripts[0]['content'], 'getComputedStyle') || str_contains($scripts[0]['content'], 'backgroundImage')) throw new RuntimeException('Artwork must come from compile-time sanitized SVG, without runtime CSS-image parsing.');
if (str_contains($result['serialized_blocks'], '<svg') || str_contains($result['serialized_blocks'], 'data-blocks-engine-vector-icon')) throw new RuntimeException('Vector rendering may not change core stored save markup.');
$css = implode("\n", array_column(array_filter($result['assets'], static fn (array $asset): bool => 'css' === $asset['kind']), 'content'));
if (!str_contains($css, 'translateX(12px) rotate(45deg)') || !str_contains($css, 'transform-origin:25% 75%') || !str_contains($css, 'scale:1.6 .7')) throw new RuntimeException('Inline vector states must retain source geometry and scoped custom properties.');
if (!str_contains($css, 'overflow:visible')) throw new RuntimeException('The native trigger must retain default source-visible overflow.');
$clipped = (new HtmlTransformer())->transform(str_replace('button{display:flex;padding:20px}', 'button{display:flex;padding:20px;overflow:hidden}', $html))->toArray();
$clippedCss = implode("\n", array_column(array_filter($clipped['assets'], static fn (array $asset): bool => 'css' === $asset['kind']), 'content'));
if (!preg_match('/__toggle\{[^}]*overflow:hidden/', $clippedCss)) throw new RuntimeException('Explicit source trigger clipping must be preserved.');
$conditional = (new HtmlTransformer())->transform(str_replace('</style>', '@media(min-width:401px){button{overflow:hidden}}</style>', $html))->toArray();
$conditionalCss = implode("\n", array_column(array_filter($conditional['assets'], static fn (array $asset): bool => 'css' === $asset['kind']), 'content'));
if (!preg_match('/@media\s*\(min-width:401px\)\{[^}]*__toggle\{;?overflow:hidden/', $conditionalCss)) throw new RuntimeException('Source clipping conditions must use the unfiltered presentation stream.');
$compiled = (new ArtifactCompiler())->compile(array('site_slug' => 'neutral-vector', 'files' => array('index.html' => $html)))->toArray();
$payload = $compiled['source_reports']['companion_plugin_payload'] ?? array();
$views = array_values(array_filter($payload['preserved_js'] ?? array(), static fn (array $script): bool => str_starts_with($script['handle'] ?? '', 'vector-icon-')));
if (1 !== count($views) || $views[0]['content'] !== $scripts[0]['content']) throw new RuntimeException('The existing companion frontend-script registry must receive the generated vector artwork.');
if (!empty($payload['editor_scripts'])) throw new RuntimeException('Passive frontend artwork must not modify native editor DOM.');
$plain = (new HtmlTransformer())->transform('<main><p>Plain content.</p></main>')->toArray();
if (array_filter($plain['assets'], static fn (array $asset): bool => 'engine-presentation' === ($asset['source'] ?? ''))) throw new RuntimeException('Unrelated documents must not gain a presentation runtime.');
fwrite(STDOUT, "Accordion vector presentation compile-to-companion contract passed\n");
