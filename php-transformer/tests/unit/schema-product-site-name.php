<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\SchemaProductEvidence;

$failures = 0;
$assert = static function (bool $ok, string $message) use (&$failures): void {
    if ($ok) return;
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
};
$html = file_get_contents(dirname(__DIR__) . '/fixtures/schema-product-site-name.html');
$rows = static fn (string $content): array => SchemaProductEvidence::rows(
    array(array('path' => 'index.html', 'content' => $content)),
    static fn (string $value): string => $value,
    static fn (mixed $value): string => (string) $value
);
foreach (array('—', '–', '-', '|', '·', ':') as $separator) {
    $entities = $rows(str_replace('—', $separator, $html));
    $assert('Studio Mug' === ($entities['mug']['name'] ?? null), $separator . ' site-name suffix is removed.');
    $assert('Cedar & Field Studio Mug' === ($entities['branded']['name'] ?? null), 'A leading site name stays.');
    $assert('Mug ' . $separator . ' Cedar & Field Edition' === ($entities['edition']['name'] ?? null), 'A nonterminal site name stays.');
    $assert('Mug ' . $separator . ' Other Studio' === ($entities['other']['name'] ?? null), 'An unrelated suffix stays.');
}
$assert('Studio Mug — Cedar & Field' === ($rows(str_replace('"@type":"WebSite"', '"@type":"Brand"', $html))['mug']['name'] ?? null), 'Brand alone is not site-name evidence.');
$assert('Cedar & Field' === ($rows(str_replace('Studio Mug — Cedar &amp; Field', 'Cedar &amp; Field', $html))['mug']['name'] ?? null), 'The site name alone is not erased.');

$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
$names = array();
foreach ($result['source_reports']['wordpress_site_plan']['runtime_declarations'] ?? array() as $declaration) {
    if ('products' === ($declaration['type'] ?? null)) $names = array_column($declaration['payload']['entities'] ?? array(), 'name', 'slug');
}
$assert('Studio Mug' === ($names['mug'] ?? null), 'The seeded product uses the normalized name.');
if ($failures > 0) exit(1);
echo "schema-product-site-name: ok\n";
