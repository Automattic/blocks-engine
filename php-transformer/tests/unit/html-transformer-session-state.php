<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;

$assert = static function (bool $condition, string $message): void {
    if ( ! $condition ) {
        throw new RuntimeException($message);
    }
};

$withoutDurations = static function (array $value) use (&$withoutDurations): array {
    foreach ( $value as $key => &$item ) {
        if ( 'transform_duration_ms' === $key ) {
            unset($value[$key]);
        } elseif ( is_array($item) ) {
            $item = $withoutDurations($item);
        }
    }
    unset($item);

    return $value;
};

$tiles = '<my-pricing><div class="tier"><h3>Basic</h3><p>$9</p></div><div class="tier"><h3>Pro</h3><p>$19</p></div><div class="tier"><h3>Max</h3><p>$49</p></div></my-pricing>';
$svg = '<main><svg viewBox="0 0 10 10" role="img" aria-label="Map"><path d="M0 0h10v10z"/></svg></main>';
$script = '<main><script src="widget.js"></script><canvas id="map">Map</canvas></main>';
$styled = '<style>.card{display:grid;gap:1rem;color:#123}.card .title{font-weight:700}</style><main class="card" style="display:flex;gap:2rem"><h2 class="title">Styled</h2></main>';
$borderBox = '<style>*,*::before,*::after{box-sizing:border-box}.card{width:640px;padding:48px;border:2px solid}</style><main class="card">Border box</main>';
$contentBox = '<style>.card{width:640px;padding:48px;border:2px solid}</style><main class="card">Content box</main>';
$accordion = static fn (string $label, string $answer): string => '<section class="faq"><div class="faq-item"><button aria-controls="a">' . $label . ' A?</button><div id="a"><p>' . $answer . ' A.</p></div></div><div class="faq-item"><button aria-controls="b">' . $label . ' B?</button><div id="b"><p>' . $answer . ' B.</p></div></div></section>';

$families = array(
    'generated blocks and provenance' => array(
        'seed' => array($tiles, array('generated_block_namespace' => 'seed-blocks')),
        'target' => array($tiles, array('generated_block_namespace' => 'target-blocks')),
        'assertion' => static fn (array $result): bool => 'target-blocks/' === substr((string) ($result['blocks'][0]['blockName'] ?? ''), 0, 14)
            && 1 === count($result['source_reports']['generated_blocks'] ?? array()),
    ),
    'materialized assets' => array(
        'seed' => array($svg, array('generated_asset_root' => 'seed')),
        'target' => array($svg, array('generated_asset_root' => 'target')),
        'assertion' => static fn (array $result): bool => 1 === count(array_filter(
            $result['assets'] ?? array(),
            static fn (array $asset): bool => 'inline-svg' === ($asset['source'] ?? '') && str_starts_with((string) ($asset['path'] ?? ''), 'target/')
        )),
    ),
    'fallback and runtime findings' => array(
        'seed' => array($script, array('runtime_canvas_selectors' => array('#map'))),
        'target' => array('<main><script src="target.js"></script><canvas id="target-map">Map</canvas></main>', array('runtime_canvas_selectors' => array('#target-map'))),
        'assertion' => static fn (array $result): bool => 2 === count($result['source_reports']['runtime_islands'] ?? array())
            && str_contains(json_encode($result['fallbacks'] ?? array()) ?: '', 'target.js'),
    ),
    'author style and selector state' => array(
        'seed' => array($styled, array('static_css' => '.card{color:#f00}')),
        'target' => array(str_replace('Styled', 'Target', $styled), array('static_css' => '.card{color:#0a0}')),
        'assertion' => static fn (array $result): bool => str_contains(
            implode('', array_column(array_filter($result['assets'] ?? array(), static fn (array $asset): bool => 'author-css' === ($asset['source'] ?? '')), 'content')),
            '#0a0'
        ),
    ),
    'author border-box reset' => array(
        'seed' => array($borderBox, array()),
        'target' => array($contentBox, array()),
        'assertion' => static fn (array $result): bool => str_contains(
            implode('', array_column(array_filter($result['assets'] ?? array(), static fn (array $asset): bool => 'author-css' === ($asset['source'] ?? '')), 'content')),
            'box-sizing:content-box'
        ),
    ),
    'reused pattern execution context' => array(
        'seed' => array($accordion('Seed', 'Old'), array()),
        'target' => array($accordion('Target', 'Current'), array()),
        'assertion' => static fn (array $result): bool => 'core/accordion' === ($result['blocks'][0]['blockName'] ?? null)
            && 'Target A?' === ($result['blocks'][0]['innerBlocks'][0]['innerBlocks'][0]['attrs']['title'] ?? null)
            && 'Current B.' === ($result['blocks'][0]['innerBlocks'][1]['innerBlocks'][1]['innerBlocks'][0]['attrs']['content'] ?? null),
    ),
);

foreach ( $families as $name => $family ) {
    $reused = new HtmlTransformer();
    $reused->transform(...$family['seed']);
    $reusedResult = $reused->transform(...$family['target'])->toArray();
    $freshResult = (new HtmlTransformer())->transform(...$family['target'])->toArray();

    $assert(($family['assertion'])($freshResult), $name . ' fixture must exercise its stateful output family.');
    $assert(
        $withoutDurations($freshResult) === $withoutDurations($reusedResult),
        $name . ' must produce identical fresh and reused-instance output.'
    );
}

$sessionReflection = new ReflectionClass(HtmlTransformerSession::class);
$transformerReflection = new ReflectionClass(HtmlTransformer::class);
$assert(array() === $sessionReflection->getProperties(ReflectionProperty::IS_PUBLIC), 'Transform session state must remain encapsulated behind typed lifecycle APIs.');
foreach ( array('__get', '__set', '__isset') as $magicAccessor ) {
    $assert(! $transformerReflection->hasMethod($magicAccessor), 'HtmlTransformer must not delegate state through ' . $magicAccessor . '.');
}

fwrite(STDOUT, "HTML transformer session state passed\n");
