<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$transform = static fn(string $html): array => (new HtmlTransformer())->transform($html, array())->toArray();

$coalesced = $transform('<div class="image-carrier" style="padding-top:0;margin-left:0;text-align:left"><img src="hero.jpg" alt="Hero" width="640" height="360"></div>');
$image = $coalesced['blocks'][0] ?? array();
$markup = (string) ($coalesced['serialized_blocks'] ?? '');
$assert('core/image' === ($image['blockName'] ?? null) && array() === ($image['innerBlocks'] ?? null), 'A render-neutral carrier around a synthetic image coalesces into the native image block.');
$assert(str_contains((string) ($image['attrs']['className'] ?? ''), 'image-carrier') && str_contains((string) ($image['attrs']['className'] ?? ''), 'blocks-engine-synthetic-image-figure'), 'Coalescing moves the carrier selector and synthetic figure class onto the image block.');
$assert('640px' === ($image['attrs']['width'] ?? null) && '360px' === ($image['attrs']['height'] ?? null) && str_contains($markup, 'src="hero.jpg"'), 'Image geometry and source survive coalescing.');

$selectorOwned = $transform('<style>.image-carrier img{border:1px solid red}</style><div class="image-carrier"><img src="hero.jpg" alt="Hero"></div>');
$assert('core/group' === ($selectorOwned['blocks'][0]['blockName'] ?? null), 'A selector whose descendant relationship would change retains its carrier.');

$slider = $transform('<div class="slider image-carrier"><img src="hero.jpg" alt="Hero"></div>');
$assert('core/group' === ($slider['blocks'][0]['blockName'] ?? null), 'Runtime-shaped slider topology is never flattened around media.');

fwrite(STDOUT, "Geometry chain coalescing contract passed\n");
