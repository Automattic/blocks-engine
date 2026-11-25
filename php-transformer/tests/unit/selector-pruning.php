<?php
/**
 * Projected author selector pruning, one table row per behavior.
 *
 * Each case compiles a small artifact through the public ArtifactCompiler and
 * states which marker values must stay in (true) or be dropped from (false)
 * the published CSS. Add a regression as a new row here rather than as a new
 * script. A row may name:
 *   - `css`: `assets` (every CSS asset, default) or a path to read one asset;
 *   - `staged`: also compile through prepareShared/preparePages/compose;
 *   - `check`: extra assertions on each compiled result.
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$cases = array(
    'absent element selectors, including responsive and interaction variants' => array(
        'files' => array(
            'index.html' => '<!doctype html><html><head><link rel="stylesheet" href="site.css"></head><body><main><p>Hello</p></main></body></html>',
            'site.css' => 'fieldset{outline-color:salmon}@media(min-width:700px){fieldset:hover{outline-color:orchid}p:hover{color:teal}}p{color:navy}',
        ),
        'expect' => array('salmon' => false, 'orchid' => false, 'teal' => true, 'navy' => true),
    ),
    'absent attribute selectors; cross-route, script-set and negated attributes stay' => array(
        'files' => array(
            'index.html' => '<!doctype html><html><head><link rel="stylesheet" href="site.css"></head><body><main><p data-tone="quiet">Hello</p></main></body></html>',
            'other.html' => '<!doctype html><html><body><p data-other="on">Other route</p></body></html>',
            'runtime.js' => 'document.querySelector("p").setAttribute("data-runtime", "on"); document.querySelector("p").dataset.runtimeTone = "on";',
            'site.css' => '[data-absent]{outline-color:salmon}@media(min-width:700px){[data-absent]:hover{outline-color:orchid}[data-tone]:hover{color:teal}}[data-other]{color:navy}[data-runtime]{color:olive}[data-runtime-tone]{color:chocolate}p:not([data-absent]){color:maroon}:is([data-absent],[data-tone]){color:indigo}',
        ),
        'expect' => array('salmon' => false, 'orchid' => false, 'teal' => true, 'navy' => true, 'olive' => true, 'chocolate' => true, 'maroon' => true, 'indigo' => true),
    ),
    // Bootstrap ships RTLCSS annotations ahead of rules. Their `[ {` brackets
    // are comment text, not attribute syntax, and must not keep an absent
    // subject's rule (and its projections) in the published theme stylesheet.
    'bracketed comment text does not keep an absent rule' => array(
        'files' => array(
            'index.html' => '<!doctype html><html><head><link rel="stylesheet" href="site.css"></head><body><main class="present">Home</main></body></html>',
            'site.css' => ".present{color:teal}\n/* rtl:options: {\n  \"autoRename\": true,\n  \"stringMap\":[ {\n    \"name\": \"prev-next\"\n  } ]\n} */\n.absent-control-icon{background-color:salmon}",
        ),
        'expect' => array('teal' => true, 'salmon' => false),
    ),
    'escaped utility class selectors' => array(
        'files' => array(
            'index.html' => '<link rel="stylesheet" href="site.css"><main class="md:grid w-[50%]">Home</main>',
            'site.css' => '.md\\:grid{display:grid}.w-\\[50\\%\\]{width:50%}.md\\:absent{color:salmon}.w-\\[123px\\]{width:123px}',
        ),
        'expect' => array('display:grid' => true, 'width:50%' => true, 'salmon' => false, '123px' => false),
    ),
    'pseudo-element subjects; malformed lists stay untouched' => array(
        'files' => array(
            'index.html' => '<!doctype html><html><head><link rel="stylesheet" href="site.css"></head><body><main class="present">Home</main></body></html>',
            'site.css' => '.absent::before{content:"unused-pseudo"}.present::before{content:"live-pseudo"}.unused,.broken[foo=bar baz]{color:orchid}',
        ),
        'expect' => array('unused-pseudo' => false, 'live-pseudo' => true, 'orchid' => true),
    ),
    // Framework selectors unsupported by the matcher still have required
    // source classes. Neither generated native identities nor state pseudos
    // make those absent classes live. Pruning belongs to published
    // projections, not source inventory: orphan assets keep their bytes.
    'state pseudos on absent classes, single and staged compiles, inventory kept' => array(
        'files' => array(
            'index.html' => '<!doctype html><html><head><link rel="stylesheet" href="site.css"></head><body><main><p class="present">Home</p><button>Action</button></main></body></html>',
            'other.html' => '<main class="other-route">Other</main>',
            'site.css' => '.absent:focus-within{color:salmon}.present:focus-within{color:teal}.other-route{color:purple}.unused,.broken[foo=bar baz]{color:orchid}',
            'orphan.css' => '.inventory-only:focus-within{color:gold}',
        ),
        'expect' => array('salmon' => false, 'teal' => true, 'purple' => true, 'orchid' => true),
        'staged' => true,
        'check' => static function (array $result): ?string {
            $orphans = array_values(array_filter($result['assets'], static fn (array $asset): bool => 'orphan.css' === ($asset['path'] ?? null)));

            return '.inventory-only:focus-within{color:gold}' === ($orphans[0]['content'] ?? null) ? null : 'Selector pruning must preserve unreferenced source inventory';
        },
    ),
    // A theme stylesheet shared across sites styles widgets this site never
    // renders. Arms requiring a class or id no site file names are dropped;
    // script-built, state and engine names stay, as do rules with no such need.
    'unreachable class and id arms in a shared theme stylesheet' => array(
        'files' => array(
            'index.html' => '<link rel="stylesheet" href="site.css"><main><div class="card"><p>Card</p></div></main>'
                . '<script>document.querySelector("main").classList.add("slot-" + 3)</script>',
            'site.css' => '/*! Theme v1 */ .card .absent-widget,.card{color:red}'
                . '#absent-id .card{color:blue}'
                . '.card:not(.absent-modifier){color:green}'
                . ':is(.absent-one,.absent-two) .card{outline-color:salmon}'
                . ':where(.absent-one,.card){outline-color:lime}'
                . '.slot-3{color:teal}'
                . '.card.is-open{color:orange}'
                . '.card [class*=absent-]{color:purple}'
                . '@media (min-width:600px){.absent-grid{color:navy}}',
        ),
        'css' => 'site.css',
        'expect' => array('red' => true, 'blue' => false, 'green' => true, 'teal' => true, 'orange' => true, 'purple' => true, 'navy' => false, 'salmon' => false, 'lime' => true, 'absent-widget' => false, 'Theme v1' => true, '@media' => false),
    ),
    // Inline sheets remain conservative evidence for partial documents and
    // responsive variants. Cross-route markup and dynamic scripts stay live too.
    'inline sheets, cross-route markup and script-built names stay live' => array(
        'files' => array(
            'index.html' => '<link rel="stylesheet" href="site.css"><style>.unused-inline{color:salmon}.other-route{color:teal}.dynamic-item{color:purple}</style><main>Home</main><script>window.state="dynamic-" + "item";</script>',
            'other.html' => '<main class="other-route">Other</main>',
            'site.css' => '.unused-inline{background:salmon}.other-route{background:teal}',
        ),
        'expect' => array('salmon' => true, 'teal' => true, 'purple' => true),
    ),
    // Author selectors also define the specificity ceiling for projected inline
    // document styles. An unmatched selector cannot simply be erased before
    // that ceiling is computed, even though it does not match the document.
    'unmatched author ids still set the inline specificity ceiling' => array(
        'files' => array(
            'index.html' => '<html style="overflow:auto"><head><style>#absent #also-absent{color:salmon}</style></head><body><main>Home</main></body></html>',
        ),
        'expect' => array(),
        'check' => static function (array $result): ?string {
            $frontend = '';
            foreach ( $result['source_reports']['wordpress_site_plan']['assets'] as $asset ) {
                if ( 'frontend' === ($asset['stylesheet_target'] ?? 'both') ) {
                    $frontend .= $asset['content'] ?? '';
                }
            }

            return preg_match('/,(#[a-zA-Z0-9_-]+)\1\1\)/', $frontend) ? null : 'Inline root carrier must retain the author specificity ceiling, including unmatched author IDs';
        },
    ),
);

$failures = array();
foreach ( $cases as $name => $case ) {
    $artifact = array('entrypoint' => 'index.html', 'files' => $case['files']);
    $compiler = new ArtifactCompiler();
    $results = array('compile' => $compiler->compile($artifact)->toArray());
    if ( $case['staged'] ?? false ) {
        $shared = $compiler->prepareShared($artifact);
        $receipts = $compiler->compilePreparedPages($shared, $compiler->preparePages($artifact, $shared));
        $results['staged'] = $compiler->compose($shared, array_values($receipts))->toArray();
    }
    $source = $case['css'] ?? 'assets';
    foreach ( $results as $mode => $result ) {
        $css = implode("\n", array_map(
            static fn (array $asset): string => (string) ($asset['content'] ?? ''),
            array_filter(
                $result['assets'] ?? array(),
                static fn (array $asset): bool => 'assets' === $source ? 'css' === ($asset['kind'] ?? null) : $source === ($asset['path'] ?? null)
            )
        ));
        foreach ( $case['expect'] as $needle => $kept ) {
            if ( str_contains($css, (string) $needle) !== $kept ) {
                $failures[] = sprintf('%s [%s]: %s should %sremain', $name, $mode, $needle, $kept ? '' : 'not ');
            }
        }
        $message = isset($case['check']) ? $case['check']($result) : null;
        if ( null !== $message ) {
            $failures[] = sprintf('%s [%s]: %s', $name, $mode, $message);
        }
    }
}
if ( array() !== $failures ) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}
echo 'Selector pruning: ' . count($cases) . " cases passed\n";
