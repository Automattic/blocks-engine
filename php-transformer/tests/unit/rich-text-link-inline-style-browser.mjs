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
    // Measure the text carrier, not the anchor (whose inherited color remains
    // the author's orange even though its text is explicitly white/red).
    const probe = `<script>
        document.body.dataset.colors = JSON.stringify(
            [...document.querySelectorAll('h2 a, p a')].slice(0, 2).map(a =>
                getComputedStyle(a.querySelector('mark') || a).color));
    </script>`;
    const measure = (markup) => {
        writeFileSync(file, markup + probe);
        const output = execFileSync(process.env.CHROME_PATH || 'google-chrome', [
            '--headless', '--no-sandbox', '--disable-gpu', '--dump-dom',
            `--user-data-dir=${join(dir, 'profile')}`, `file://${file}`,
        ], { encoding: 'utf8', env: { ...process.env, TMPDIR: '/tmp' }, stdio: ['ignore', 'pipe', 'ignore'] });
        return JSON.parse(output.match(/data-colors="([^"]+)"/)[1].replaceAll('&quot;', '"'));
    };
    const colors = measure(html);
    assert.deepEqual(colors, ['rgb(255, 255, 255)', 'rgb(255, 0, 0)']);
    console.log('Compiled heading / paragraph text:', colors.join(' / '));
    const sourceColors = measure('<!doctype html><style>a{color:#fbb153}</style><h2><a href="/blog/" style="color:white">news</a></h2><p><a href="/blog/" style="color:red">news</a></p>');
    assert.deepEqual(colors, sourceColors);
    console.log('Source and compiled computed colors match.');
} finally {
    rmSync(dir, { recursive: true, force: true });
}
