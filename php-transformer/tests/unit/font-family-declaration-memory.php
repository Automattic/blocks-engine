<?php
declare(strict_types=1);

ini_set('memory_limit', '96M');

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\StaticSite\FontMaterialization\CssFontAnalysisCache;
use Automattic\BlocksEngine\PhpTransformer\StaticSite\FontMaterialization\FontMaterializationPlanBuilder;

$css = '@keyframes inert{' . str_repeat('x', 32 * 1024 * 1024) . '}'
    . ':root{--heading:"Playfair Display",serif;--body:"Lora",serif}'
    . 'body{font-family:var(--body)}h1{font-family:var(--heading)}';
$roles = ( new FontMaterializationPlanBuilder() )->fontRolesFromCss($css);

if ( array( 'heading' => 'Playfair Display', 'body' => 'Lora' ) !== $roles ) {
    fwrite(STDERR, 'Font-family declaration memory contract failed: ' . json_encode($roles) . PHP_EOL);
    exit(1);
}

// Projected site CSS carries rules whose selector lists run to thousands of
// selectors. Each selector's declaration cites the same rule, so 4,000
// selectors over a ~200 KB prelude must stay near the prelude's size rather
// than holding a copy per selector (~800 MB).
$selectors = array();
for ( $index = 0; $index < 4000; ++$index ) {
    $selectors[] = sprintf('.wp-site-blocks .page-%04d .entry-content h2.heading', $index);
}
$listCss = implode(',', $selectors) . '{font-family:"Playfair Display",serif}body{font-family:Lora,serif}';
$before = memory_get_usage();
$declarations = ( new FontMaterializationPlanBuilder() )->fontFamilyDeclarationsFromCssSources(array($listCss));
$retained = memory_get_usage() - $before;
if ( 4001 !== count($declarations) || $declarations[0]['source_snippet'] !== $declarations[3999]['source_snippet'] || $retained > 16 * 1024 * 1024 ) {
    fwrite(STDERR, sprintf('Selector-list font declarations were not bounded: %d declarations, %d bytes retained.', count($declarations), $retained) . PHP_EOL);
    exit(1);
}
unset($declarations, $listCss, $selectors);

// One shared stylesheet is scanned by several typography consumers on every
// page. A shared cache builds that immutable analysis once and reuses it.
$sharedCss = ':root{--heading:"Playfair Display",serif}h1{font-family:var(--heading)}body{font-family:Lora,serif}';
$sharedCache = new CssFontAnalysisCache();
$cachedRoles = array();
for ( $page = 0; $page < 4; ++$page ) {
    $builder = new FontMaterializationPlanBuilder($sharedCache);
    $cachedRoles[] = $builder->fontRolesFromCss($sharedCss);
    $builder->fontFamilyDeclarationsFromCssSources(array($sharedCss));
}
$isolatedRoles = ( new FontMaterializationPlanBuilder() )->fontRolesFromCss($sharedCss);

if ( array_unique(array_map(static fn (array $role): string => json_encode($role), $cachedRoles)) !== array(json_encode($isolatedRoles)) ) {
    fwrite(STDERR, 'Cached font analysis changed canonical role resolution.' . PHP_EOL);
    exit(1);
}
if ( 1 !== $sharedCache->declarationBuilds || 7 !== $sharedCache->declarationHits ) {
    fwrite(STDERR, 'Font-family declarations were not built once per shared stylesheet: ' . $sharedCache->declarationBuilds . '/' . $sharedCache->declarationHits . PHP_EOL);
    exit(1);
}
if ( 1 !== $sharedCache->variableBuilds || 0 !== $sharedCache->variableHits ) {
    fwrite(STDERR, 'CSS custom properties were not built once per shared stylesheet: ' . $sharedCache->variableBuilds . '/' . $sharedCache->variableHits . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "Font-family declaration memory contract passed\n");
