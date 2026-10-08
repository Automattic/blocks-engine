import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFileSync, writeFileSync, mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { createServer } from 'node:http';
import { createRequire } from 'node:module';

// Shared chrome is lifted out of route stylesheets into one global resource.
// Publish it through the emitted bootstrap in real WordPress and prove, in a
// browser, that the shared header keeps its source styling on the front page,
// on non-front routes and on a WordPress-native route no document owns.
const require = createRequire(import.meta.url);
const { chromium } = process.env.PLAYWRIGHT_MODULE ? await import(process.env.PLAYWRIGHT_MODULE) : require('playwright');
const root = new URL('../../..', import.meta.url).pathname;
assert.ok(process.env.WORDPRESS_PATH, 'Actual WordPress is required');
const wp = process.env.WORDPRESS_PATH;
const fixture = JSON.parse(execFileSync('php', ['-d', 'memory_limit=2G', '-r', `
require getenv('BLOCKS_ENGINE_AUTOLOAD') ?: $argv[1] . '/vendor/autoload.php';
$css = '[data-chrome=grid]{display:grid;grid-template-columns:200px 1fr;height:120px;background:#1d4ed8}.route-grid{display:grid;grid-template-columns:1fr 1fr}@media (max-width:700px){.route-grid{grid-template-columns:1fr}}';
$header = static fn(string $home, string $about): string => '<header id="site-chrome" class="site-header" data-chrome="grid"><nav><a href="' . $home . '">Home</a><a href="' . $about . '">About</a></nav></header>';
$document = static fn(string $header, string $main): string => '<!doctype html><html><head><style>' . $css . '</style><link rel="stylesheet" href="/route.css"></head><body><div id="site-root"><div id="masterPage">' . $header . '<div id="PAGES_CONTAINER"><div class="route-grid">' . $main . '</div></div></div></div></body></html>';
$documents = array(
    'index.html' => $document($header('index.html', 'guides/about.html'), '<main id="content"><h1>Home</h1></main>'),
    'guides/about.html' => $document($header('../index.html', 'about.html'), '<main id="content"><h1>About</h1></main>'),
    'guides/team.html' => $document($header('../index.html', 'about.html'), '<main id="content"><h1>Team</h1></main>'),
);
$plan = (new Automattic\\BlocksEngine\\PhpTransformer\\ArtifactCompiler\\ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => $documents + array(
    'route.css' => array('path' => 'route.css', 'kind' => 'css', 'content' => '.route-only{color:#123456}'),
)))->toArray()['source_reports']['wordpress_site_plan'];
echo json_encode(array('plan' => $plan, 'documents' => $documents, 'stylesheets' => array('route.css' => '.route-only{color:#123456}')), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
`, root], { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 }));

const { plan } = fixture;
const files = new Map(Object.entries({ ...fixture.documents, ...fixture.stylesheets }).map(([path, content]) => ['/' + path, content]));
const nativeCss = ['group', 'navigation'].map(name => readFileSync(wp + '/wp-includes/blocks/' + name + '/style.min.css', 'utf8')).join('\n');
const bootstrap = plan.writes.find(write => write.target_path === 'functions.php').payload.data;
const parts = plan.template_parts.filter(part => ['shared_shell', 'inline_shared_shell'].includes(part.placement?.kind)).map(part => part.slug);
assert.ok(parts.length > 0, 'The identical header is extracted as a shared template part');
const shared = plan.assets.filter(asset => /\/shared-chrome-[a-f0-9]{16}\.css$/.test(asset.target_path)).map(asset => asset.target_path);
assert.equal(shared.length, 1, 'One shared-chrome resource carries the header rules');

const server = createServer((request, response) => {
    const path = decodeURIComponent(new URL(request.url, 'http://local').pathname);
    response.statusCode = files.has(path) ? 200 : 404;
    response.setHeader('Content-Type', path.endsWith('.css') ? 'text/css' : 'text/html');
    response.end(files.get(path) ?? '');
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
const origin = `http://127.0.0.1:${server.address().port}`;
const scratch = mkdtempSync(join(tmpdir(), 'be-shared-chrome-publication-'));
const routes = [
    { source: 'index.html', page: plan.pages.find(page => page.source_path === 'index.html') },
    { source: 'guides/about.html', page: plan.pages.find(page => page.source_path === 'guides/about.html') },
    { source: 'guides/team.html', page: plan.pages.find(page => page.source_path === 'guides/team.html') },
    // WordPress search results: no source document owns this route, so the
    // shared header is compared against the front page's source header.
    { source: 'index.html', page: null, native: 'search.html' },
];
const evidence = [];
let browser;
try {
    for (const route of routes) {
        const input = join(scratch, 'input.json');
        writeFileSync(input, JSON.stringify({ plan, page: route.page, bootstrap, parts, markup: '', base: `${origin}/native` }));
        const runtime = JSON.parse(execFileSync(process.env.WP_CLI ?? 'wp', ['--path=' + wp, '--skip-plugins', '--skip-themes', 'eval-file', root + '/tools/visual-parity/tests/wordpress-stylesheet-publication-runtime.php', input], { encoding: 'utf8', maxBuffer: 16 * 1024 * 1024, env: process.env }));
        // Only the shared template part renders: the header is the subject.
        assert.deepEqual(runtime.unregistered, []);
        for (const write of runtime.resources) files.set(`/native/${write.target_path}`, write.payload.data);
        const nativePath = route.native ?? route.source;
        files.set(`/native/${nativePath}`, `<!doctype html><html><head><style>${nativeCss}</style>${runtime.head}</head><body>${runtime.html}</body></html>`);
        const sharedEnqueues = runtime.enqueued.filter(style => shared.some(target => style.src.endsWith('/' + target)));
        evidence.push({ route: nativePath, version: runtime.version, enqueued: runtime.enqueued.map(style => style.src.replace(`${origin}/native/`, '')), sharedEnqueues: sharedEnqueues.length });
    }
    browser = await chromium.launch({ headless: true });
    let comparisons = 0;
    for (const width of [1440, 390]) for (const route of routes) {
        const observations = [];
        for (const path of [route.source, `native/${route.native ?? route.source}`]) {
            const page = await browser.newPage({ viewport: { width, height: 600 } });
            await page.goto(`${origin}/${path}`, { waitUntil: 'load' });
            observations.push(await page.evaluate(() => {
                const header = document.querySelector('#site-chrome');
                if (!header) return null;
                const style = getComputedStyle(header);
                return { display: style.display, tracks: style.gridTemplateColumns, height: header.getBoundingClientRect().height, background: style.backgroundColor };
            }));
            await page.close();
        }
        assert.notEqual(observations[0], null, `${route.source}: source header exists`);
        assert.equal(observations[0].display, 'grid', `${route.source}: the source header is styled`);
        assert.deepEqual(observations[1], observations[0], `${route.native ?? route.source} / ${width}px: the published shared header matches the source header`);
        ++comparisons;
    }
    for (const item of evidence) assert.equal(item.sharedEnqueues, 1, `${item.route}: the emitted bootstrap enqueues the shared chrome stylesheet once (${JSON.stringify(item.enqueued)})`);
    console.log(JSON.stringify({ comparisons, evidence }, null, 2));
    console.log('Actual WordPress shared-chrome publication passed');
} finally { await browser?.close(); await new Promise(resolve => server.close(resolve)); rmSync(scratch, { recursive: true, force: true }); }
