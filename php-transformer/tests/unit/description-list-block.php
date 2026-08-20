<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\AuthorLayoutBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\AuthoredInputBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\AuthoredSelectBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\DescriptionListBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\CompanionPluginPayload;

$failures = 0;
$passes = 0;
$assert = static function (bool $condition, string $message) use (&$failures, &$passes): void {
    if ( $condition ) {
        ++$passes;
        return;
    }
    ++$failures;
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
};

$generator = new DescriptionListBlockGenerator();
$definition = $generator->definition();
$assert(DescriptionListBlockGenerator::NAME === ($definition['block_json']['name'] ?? null), 'block metadata uses the stable companion name');
$assert(3 === ($definition['block_json']['apiVersion'] ?? null), 'block metadata uses apiVersion 3');
$assert(false === ($definition['block_json']['supports']['html'] ?? null), 'block metadata disables raw HTML editing');
$assert('file:./index.js' === ($definition['block_json']['editorScript'] ?? null), 'block metadata uses a single editor asset reference');
$assert(str_contains((string) ($definition['assets']['index.js'] ?? ''), 'RawHTML'), 'editor asset serializes semantic static markup');
$assert(str_contains((string) ($definition['assets']['index.js'] ?? ''), 'escapeAttribute'), 'editor asset escapes presentation attributes');
$assert(str_contains((string) ($definition['assets']['index.js'] ?? ''), 'attributes: attributes'), 'client registration declares the block attribute schema');
$assert(str_contains((string) ($definition['assets']['index.js'] ?? ''), 'safeCssText'), 'editor rendering sanitizes captured inline styles through the browser CSSOM');
$assert(str_contains((string) ($definition['assets']['index.js'] ?? ''), 'useEffect'), 'editor rendering scopes exact inline CSS without React style-object loss');
$assets = $definition['assets'] ?? array();
$isSafeCompanionAsset = static function (mixed $path, mixed $content): bool {
    if ( ! is_string($path) || ! is_scalar($content) || '' === $path || str_starts_with($path, '/') || str_contains($path, '\\') || str_contains($path, '../') || str_contains($path, './') ) {
        return false;
    }

    foreach ( explode('/', $path) as $segment ) {
        if ( '' === $segment || '.' === $segment || '..' === $segment || 1 !== preg_match('/^[A-Za-z0-9._-]+$/', $segment) ) {
            return false;
        }
    }

    $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
    return in_array($extension, array( 'js', 'mjs', 'css', 'json', 'svg', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'ico', 'woff', 'woff2', 'ttf', 'otf', 'eot' ), true)
        && ! preg_match('/<\\?(?:php|=|[[:space:]])/i', (string) $content);
};
$assert(array( 'index.js' ) === array_keys($assets), 'description-list emits only its static editor asset');
$assert(array_reduce(array_keys($assets), static fn (bool $safe, string $path): bool => $safe && $isSafeCompanionAsset($path, $assets[$path]), true), 'description-list emitted assets satisfy SSI companion safe-path and static-content validation');
$editorAsset = $definition['block_json']['editorScript'] ?? '';
$editorPath = is_string($editorAsset) && str_starts_with($editorAsset, 'file:./') ? substr($editorAsset, 7) : '';
$assert('index.js' === $editorPath && array_key_exists($editorPath, $assets), 'description-list editor metadata resolves to a materializable package-relative asset');
$authorLayout = ( new AuthorLayoutBlockGenerator() )->definition();
$authorAssets = $authorLayout['assets'] ?? array();
$assert(array( 'index.js' ) === array_keys($authorAssets), 'author-layout emits only its static editor asset');
$assert(array_reduce(array_keys($authorAssets), static fn (bool $safe, string $path): bool => $safe && $isSafeCompanionAsset($path, $authorAssets[$path]), true), 'author-layout emitted assets satisfy SSI companion safe-path and static-content validation');
$assert('file:./index.js' === ($authorLayout['block_json']['editorScript'] ?? null), 'author-layout metadata uses a single editor asset reference');

$companionGenerators = array(
    new AuthoredInputBlockGenerator(),
    new AuthoredSelectBlockGenerator(),
    new DescriptionListBlockGenerator(),
    new AuthorLayoutBlockGenerator(),
);
$nodeRegistrationRunner = <<<'JS'
const vm = require( 'node:vm' );
const registered = [];
const context = {
    window: { wp: {
        blocks: { registerBlockType: ( name ) => registered.push( name ) },
        blockEditor: {},
        element: {}
    } }
};
vm.runInNewContext( Buffer.from( process.argv[ 1 ], 'base64' ).toString(), context );
process.stdout.write( JSON.stringify( registered ) );
JS;
foreach ( $companionGenerators as $companionGenerator ) {
    $companionDefinition = $companionGenerator->definition();
    $companionAssets = $companionDefinition['assets'];
    $expectedBlockName = $companionDefinition['block_json']['name'];
    $registered = shell_exec('node -e ' . escapeshellarg($nodeRegistrationRunner) . ' ' . escapeshellarg(base64_encode($companionAssets['index.js'])));
    $payload = ( new CompanionPluginPayload() )->fromBlockTypes(array(), array(), array(), array( $companionDefinition ));

    $assert('file:./index.js' === ($companionDefinition['block_json']['editorScript'] ?? null), $expectedBlockName . ' editorScript is a single WordPress file reference');
    $assert(array( 'index.js' => array( 'wp-blocks', 'wp-block-editor', 'wp-element' ) ) === ($companionDefinition['script_dependencies'] ?? null), $expectedBlockName . ' declares its editor dependencies for SSI without emitting server code');
    $assert(array_reduce(array_keys($companionAssets), static fn (bool $safe, string $path): bool => $safe && $isSafeCompanionAsset($path, $companionAssets[$path]), true), $expectedBlockName . ' emits only static companion assets');
    $assert(array( $expectedBlockName ) === json_decode((string) $registered, true), $expectedBlockName . ' editor script registers after WordPress dependencies are loaded');
    $assert($companionDefinition['script_dependencies'] === ($payload['blocks'][0]['script_dependencies'] ?? null), $expectedBlockName . ' dependency metadata survives companion payload normalization');
}
$dependencyPayload = ( new CompanionPluginPayload() )->fromBlockTypes(
    array(),
    array(),
    array(),
    array(
        array(
            'name' => 'dependency-test',
            'block_json' => array( 'name' => 'blocks-engine/dependency-test' ),
            'assets' => array( 'index.js' => 'window.test = true;' ),
            'script_dependencies' => array(
                'index.js' => array( 'wp-blocks', 'wp-blocks', 'invalid handle' ),
                '../outside.js' => array( 'wp-element' ),
                'missing.js' => array( 'wp-element' ),
            ),
        ),
    )
);
$assert(array( 'index.js' => array( 'wp-blocks' ) ) === ($dependencyPayload['blocks'][0]['script_dependencies'] ?? null), 'companion payload retains only deduplicated dependencies for emitted safe script assets');

$html = '<dl class="facts &amp; figures" style="display:grid"><dt class="term"><strong>Office</strong> <em>location</em></dt><dt>Alias</dt><dd class="definition">North <a href="/hall">Hall</a></dd><dd>Weekdays</dd><dt>Hours</dt><dd>09:00 &amp; 17:00</dd></dl>';
$result = ( new HtmlTransformer() )->transform($html)->toArray();
$block = $result['blocks'][0] ?? array();
$groups = $block['attrs']['groups'] ?? array();
$serialized = (string) ($result['serialized_blocks'] ?? '');
$assert(DescriptionListBlockGenerator::NAME === ($block['blockName'] ?? null), 'direct valid list maps to the companion block');
$assert(2 === count($groups) && 2 === count($groups[0]['terms'] ?? array()) && 2 === count($groups[0]['descriptions'] ?? array()), 'term and description ordering is grouped deterministically');
$assert(! isset($groups[0]['wrapper'], $groups[0]['items']), 'direct definition lists retain the persisted terms/descriptions group schema');
$assert('<strong>Office</strong> <em>location</em>' === ($groups[0]['terms'][0]['content'] ?? null) && 'North <a href="/hall">Hall</a>' === ($groups[0]['descriptions'][0]['content'] ?? null), 'nested inline markup is preserved in the payload');
$assert(str_contains($serialized, '<dl class="facts &amp; figures" style="display:grid"><dt class="term"><strong>Office</strong> <em>location</em></dt><dt>Alias</dt><dd class="definition">North <a href="/hall">Hall</a></dd><dd>Weekdays</dd><dt>Hours</dt><dd>09:00 &amp; 17:00</dd></dl>'), 'static markup retains semantics and escapes attributes exactly once');
$assert('pass' === ($result['source_reports']['wp_block_validity']['status'] ?? null), 'static companion serialization is editor-valid');
$assert(1 === count($result['source_reports']['generated_blocks'] ?? array()), 'one definition is generated for multiple lists in one document');
$assert('semantic-description-list' === ($result['source_reports']['gutenberg_gaps'][0]['id'] ?? null), 'source report records the Gutenberg description-list gap');
$assert(str_contains((string) ($result['diagnostics'][count($result['diagnostics']) - 1]['references'][0] ?? ''), 'gutenberg/issues/4880'), 'diagnostic links the missing core capability to Gutenberg issue #4880');

foreach ( array(
    '<dl><dd>Description before term</dd><dt>Term</dt><dd>Description</dd></dl>',
    '<dl><dt>Term</dt></dl>',
    '<dl><dt>Term</dt><dd>Description</dd><span>Unexpected wrapper</span></dl>',
    '<dl><dt>Term</dt><dd><p>Block-level description</p></dd></dl>',
    '<dl><dt><span class="unsupported-richtext-attribute">Term</span></dt><dd>Description</dd></dl>',
) as $malformed ) {
    $converted = ( new HtmlTransformer() )->transform($malformed)->toArray();
    $assert(DescriptionListBlockGenerator::NAME !== ($converted['blocks'][0]['blockName'] ?? null), 'malformed or wrapped lists retain conservative fallback conversion');
    $assert(array() === ($converted['source_reports']['generated_blocks'] ?? null), 'malformed or wrapped lists do not generate a companion definition');
}

$grouped = ( new HtmlTransformer() )->transform('<dl class="facts"><div class="fact-row" style="display:grid;grid-template-columns:8rem 1fr" data-layout="grid" aria-label="Office details"><dt>Office</dt><dd>North Hall</dd><dt>Hours</dt><dd>Weekdays</dd></div></dl>')->toArray();
$groupedBlock = $grouped['blocks'][0] ?? array();
$groupedItems = $groupedBlock['attrs']['groups'][0]['items'] ?? array();
$assert(DescriptionListBlockGenerator::NAME === ($groupedBlock['blockName'] ?? null), 'valid div-grouped lists map to the companion block');
$assert(array('dt', 'dd', 'dt', 'dd') === array_column($groupedItems, 'tagName'), 'grouped list payload preserves dt/dd source order');
$assert('fact-row' === ($groupedBlock['attrs']['groups'][0]['wrapper']['className'] ?? null) && 'display:grid;grid-template-columns:8rem 1fr' === ($groupedBlock['attrs']['groups'][0]['wrapper']['style'] ?? null) && 'grid' === ($groupedBlock['attrs']['groups'][0]['wrapper']['attributes']['data-layout'] ?? null) && 'Office details' === ($groupedBlock['attrs']['groups'][0]['wrapper']['attributes']['aria-label'] ?? null), 'grouped list payload preserves wrapper classes, grid layout, and attributes');
$assert(str_contains((string) ($grouped['serialized_blocks'] ?? ''), '<dl class="facts"><div class="fact-row" style="display:grid;grid-template-columns:8rem 1fr" data-layout="grid" aria-label="Office details"><dt>Office</dt><dd>North Hall</dd><dt>Hours</dt><dd>Weekdays</dd></div></dl>'), 'grouped list serialization preserves wrapper topology and source order');
$assert('pass' === ($grouped['source_reports']['wp_block_validity']['status'] ?? null), 'grouped list serialization remains editor-valid');

$findDescriptionList = static function (array $blocks, string $className) use (&$findDescriptionList): ?array {
    foreach ( $blocks as $candidate ) {
        if ( DescriptionListBlockGenerator::NAME === ($candidate['blockName'] ?? null) && $className === ($candidate['attrs']['className'] ?? null) ) {
            return $candidate;
        }
        $match = $findDescriptionList($candidate['innerBlocks'] ?? array(), $className);
        if ( null !== $match ) {
            return $match;
        }
    }
    return null;
};
$sportsFixture = (string) file_get_contents(dirname(__DIR__, 3) . '/fixtures/websites/33-sports-team-league/team-roxbury-roar.html');
$sports = ( new HtmlTransformer() )->transform($sportsFixture)->toArray();
$sportsList = $findDescriptionList($sports['blocks'] ?? array(), 'record-strip');
$assert(null !== $sportsList, 'observed sports fixture grouped record strip maps to the companion block');
$assert(4 === count($sportsList['attrs']['groups'] ?? array()) && array('dt', 'dd') === array_column($sportsList['attrs']['groups'][0]['items'] ?? array(), 'tagName'), 'observed sports fixture retains each grouped record row and dt/dd source order');
$assert(str_contains((string) ($sports['serialized_blocks'] ?? ''), '<dl class="record-strip"><div><dt>2025-26 record</dt><dd>31-8-3</dd></div><div><dt>Points</dt><dd>65</dd></div>'), 'observed sports fixture serialization retains its grouped description-list topology');

$scheduleFixture = (string) file_get_contents(dirname(__DIR__) . '/fixtures/description-list-grouped-schedule.html');
$schedule = ( new HtmlTransformer() )->transform($scheduleFixture)->toArray();
$scheduleBlock = $schedule['blocks'][0] ?? array();
$scheduleWrapper = $scheduleBlock['attrs']['groups'][0]['wrapper'] ?? array();
$scheduleMarkup = (string) ($schedule['serialized_blocks'] ?? '');
$assert(DescriptionListBlockGenerator::NAME === ($scheduleBlock['blockName'] ?? null), 'independent grouped schedule fixture maps to the companion block');
$assert(array('dt', 'dt', 'dd', 'dd') === array_column($scheduleBlock['attrs']['groups'][0]['items'] ?? array(), 'tagName'), 'independent grouped schedule fixture preserves multiple-term source order');
$assert('arrival-row' === ($scheduleWrapper['className'] ?? null) && 'arrival' === ($scheduleWrapper['attributes']['id'] ?? null) && 'group' === ($scheduleWrapper['attributes']['role'] ?? null) && 'Arrival details' === ($scheduleWrapper['attributes']['aria-label'] ?? null) && 'morning' === ($scheduleWrapper['attributes']['data-slot'] ?? null), 'wrapper safe-attribute policy retains id, role, aria, and ordinary data attributes');
$assert(! isset($scheduleWrapper['attributes']['data-wp-interactive'], $scheduleWrapper['attributes']['data-wp-bind--hidden'], $scheduleWrapper['attributes']['onclick'], $scheduleWrapper['attributes']['title']) && ! str_contains($scheduleMarkup, 'data-wp-') && ! str_contains($scheduleMarkup, 'onclick=') && ! str_contains($scheduleMarkup, 'title="Behavioral title"'), 'wrapper safe-attribute policy excludes WordPress directives and behavior-bearing attributes');
$assert(str_contains($scheduleMarkup, '<div class="arrival-row" id="arrival" role="group" aria-label="Arrival details" data-slot="morning"><dt>Doors</dt><dt>Registration</dt><dd>09:00</dd><dd>Foyer</dd></div>'), 'independent grouped schedule fixture retains wrapper topology and ordered records');

if ( 0 < $failures ) {
    fwrite(STDERR, "Description-list block unit tests: {$passes} passed, {$failures} FAILED" . PHP_EOL);
    exit(1);
}
fwrite(STDOUT, "Description-list block unit tests: {$passes} passed" . PHP_EOL);
