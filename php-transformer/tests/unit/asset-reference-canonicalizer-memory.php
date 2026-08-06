<?php
declare(strict_types=1);

ini_set('memory_limit', '48M');

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\AssetReferenceCanonicalizer;

$content = str_repeat('x', 28 * 1024 * 1024);
$content .= '<!-- wp:html {"url":"https://example.test/inert"} /-->';
$canonical = (new AssetReferenceCanonicalizer(array()))->content($content, 'index.html');

if ($canonical !== $content) {
    throw new RuntimeException('Inert block comments must remain byte-identical under the bounded memory contract.');
}

fwrite(STDOUT, "Asset reference canonicalizer memory contract passed\n");
