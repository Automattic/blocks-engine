// Browser geometry companion for the reduced centred line in #5199.
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { mkdtempSync, readFileSync, writeFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

const dir = mkdtempSync(join(tmpdir(), 'preserved-space-'));
try {
    const file = join(dir, 'render.html');
    execFileSync('php', [fileURLToPath(new URL('./rich-text-whitespace-inline.php', import.meta.url)), file]);
    writeFileSync(file, readFileSync(file, 'utf8') + `<style>
        #compiled p{width:200px;text-align:center;white-space:nowrap}
    </style><script>
        document.body.dataset.positions = JSON.stringify(['source','compiled'].map(id => {
            const p = document.querySelector('#' + id + ' p');
            const walker = document.createTreeWalker(p, NodeFilter.SHOW_TEXT);
            const text = walker.nextNode();
            const range = document.createRange();
            range.setStart(text, 0); range.setEnd(text, 1);
            return range.getBoundingClientRect().x;
        }));
    </script>`);
    const output = execFileSync(process.env.CHROME_PATH || 'google-chrome', [
        '--headless', '--no-sandbox', '--disable-gpu',
        `--user-data-dir=${join(dir, 'profile')}`, '--dump-dom', `file://${file}`,
    ], { encoding: 'utf8', env: { ...process.env, TMPDIR: '/tmp' }, stdio: ['ignore', 'pipe', 'ignore'] });
    const [source, compiled] = JSON.parse(output.match(/data-positions="([^"]+)"/)[1]);
    assert.ok(Math.abs(source - compiled) < 0.1, `first glyph x: source=${source}, compiled=${compiled}`);
    console.log('Preserved trailing space keeps the first glyph aligned.');
} finally {
    rmSync(dir, { recursive: true, force: true });
}
