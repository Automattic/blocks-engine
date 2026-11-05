<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$source = <<<'HTML'
<style>
body{font-family:Verdana,sans-serif}
.unstyled{font-family:Georgia,serif}
.brand{font-family:Verdana,sans-serif;color:#3c47aa}
</style>
<h2 class="unstyled brand">The Power of Community</h2>
<p class="brand">Body copy in the brand font.</p>
HTML;
$compile = static fn(string $html): array => (new ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html', 'files' => array('index.html' => $html),
))->toArray()['source_reports']['wordpress_site_plan'];
foreach (array($source, str_replace('.brand{', '.brand   {', $source)) as $html) {
    $plan = $compile($html);
    $css = implode("\n", array_column($plan['assets'], 'content'));
    $assert(1 === preg_match('/\.brand\s*\{[^}]*font-family:var\(--wp--preset--font-family--body,Verdana,sans-serif\)/', $css), 'Brand font keeps its class specificity on headings despite the competing Georgia rule: ' . $css);
    $assert(!str_contains($css, '.brand:not(:where(h'), 'Competing font prevents heading exclusion');
    $assert(str_contains($plan['pages'][0]['canonical_block_markup'], 'The Power of Community'), 'Heading survives compilation');
}

// Without the competing rule, headings can safely follow Global Styles.
$plan = $compile(str_replace('.unstyled{font-family:Georgia,serif}', '', str_replace('.brand{', '.brand   {', $source)));
$css = implode("\n", array_column($plan['assets'], 'content'));
$assert(str_contains($css, '.brand:not(:where(h1,h2,h3,h4,h5,h6)){font-family:'), 'Safe exclusion attaches to the trimmed class, never a descendant');
echo "Heading competing font passed\n";
