// Real-browser companion to rich-text-link-inline-style.php.
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { mkdtempSync, readFileSync, writeFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

const dir = mkdtempSync(join(tmpdir(), 'link-color-'));
try {
    const file = join(dir, 'render.html');
    execFileSync('php', [fileURLToPath(new URL('./rich-text-link-inline-style.php', import.meta.url)), file]);
    const html = readFileSync(file, 'utf8');
    // Measure the text carrier's color, not the anchor (whose inherited color
    // remains the author's orange even though its text is explicitly
    // white/red), and the anchor's own decoration, because the underline is
    // painted by the decorating box and no descendant can cancel it.
    const probe = `<script>
        document.body.dataset.links = JSON.stringify(
            ['/Blog-Actualites/index.html', '/blog/', '/course/', '/bare/'].map(href => {
                const a = document.querySelector('a[href="' + href + '"]');
                return [getComputedStyle(a.querySelector('mark') || a).color, getComputedStyle(a).textDecorationLine];
            }));
    </script>`;
    const chrome = (args) => execFileSync(process.env.CHROME_PATH || 'google-chrome', [
        '--headless', '--no-sandbox', '--disable-gpu', '--hide-scrollbars',
        `--user-data-dir=${join(dir, 'profile')}`, ...args, `file://${file}`,
    ], { encoding: 'utf8', env: { ...process.env, TMPDIR: '/tmp' }, stdio: ['ignore', 'pipe', 'ignore'] });
    const measure = (markup) => {
        writeFileSync(file, markup + probe);
        const output = chrome(['--dump-dom']);
        return JSON.parse(output.match(/data-links="([^"]+)"/)[1].replaceAll('&quot;', '"'));
    };
    const links = measure(html);
    assert.deepEqual(links, [
        ['rgb(255, 255, 255)', 'underline'],
        ['rgb(255, 0, 0)', 'underline'],
        ['rgb(85, 85, 85)', 'none'],
        ['rgb(251, 177, 83)', 'none'],
    ]);
    console.log('Compiled link [color, decoration]:', JSON.stringify(links));
    const source = '<!doctype html><style>a{color:#fbb153}</style>'
        + '<h2><a href="/Blog-Actualites/index.html" style="color:white">news</a></h2>'
        + '<p><a href="/blog/" style="color:red">news</a></p>'
        + '<p><a href="/course/" style="color:#555;text-decoration:none">course</a></p>'
        + '<p><a href="/bare/" style="text-decoration:none">bare</a></p>';
    assert.deepEqual(links, measure(source));
    console.log('Source and compiled computed link styles match.');

    // Pixel check: the compiled decoration-free link paints exactly like the source.
    const style = html.match(/<style>[\s\S]*?<\/style>/)[0];
    const course = html.match(/<p>Enroll <a href="\/course\/"[\s\S]*?<\/p>/)[0];
    const shot = (markup) => {
        writeFileSync(file, '<!doctype html><body style="margin:0;font:24px serif">' + markup);
        chrome([`--screenshot=${join(dir, 'shot.png')}`, '--window-size=320,80']);
        return readFileSync(join(dir, 'shot.png'));
    };
    assert.ok(shot(style + course).equals(shot('<style>a{color:#fbb153}</style><p>Enroll <a href="/course/" style="color:#555;text-decoration:none">course</a></p>')));
    console.log('Source and compiled text-decoration:none link screenshots are identical.');
} finally {
    rmSync(dir, { recursive: true, force: true });
}
