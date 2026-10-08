import { execFile } from 'node:child_process';
import fs from 'node:fs/promises';
import http from 'node:http';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { promisify } from 'node:util';

const run = promisify(execFile);
const resolver = path.join(path.dirname(fileURLToPath(import.meta.url)), 'resolve-html-includes.php');
const types = { '.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css', '.svg': 'image/svg+xml', '.avif': 'image/avif', '.png': 'image/png', '.jpg': 'image/jpeg', '.jpeg': 'image/jpeg', '.webp': 'image/webp', '.woff': 'font/woff', '.woff2': 'font/woff2' };

/** Source HTML exactly as compilation sees it: canonical artifact includes resolved by the PHP transformer. */
export async function resolveSourceDocument(root, relative) {
  const { stdout } = await run('php', [resolver, root, relative], { maxBuffer: 256 * 1024 * 1024, encoding: 'buffer' });
  return stdout;
}

/**
 * Serves a read-only captured source root. HTML documents resolve their
 * artifact includes; a document that cannot resolve is a 500 and is recorded
 * in `failures`, so a probe never measures an unresolved source document.
 */
export async function serveSourceRoot(sourceRoot) {
  const root = path.resolve(sourceRoot);
  const failures = [];
  const server = http.createServer(async (request, response) => {
    const url = new URL(request.url, 'http://localhost');
    const file = path.resolve(root, '.' + decodeURIComponent(url.pathname.endsWith('/') ? url.pathname + 'index.html' : url.pathname));
    if (!file.startsWith(root + path.sep)) { response.statusCode = 404; response.end(); return; }
    const extension = path.extname(file).toLowerCase();
    response.setHeader('Content-Type', types[extension] ?? 'application/octet-stream');
    if (extension === '.html' || extension === '.htm') {
      try { await fs.access(file); } catch { response.statusCode = 404; response.end(); return; }
      try { response.end(await resolveSourceDocument(root, path.relative(root, file))); } catch (error) {
        failures.push({ path: path.relative(root, file), error: String(error.stderr ?? error.message).trim() });
        response.statusCode = 500; response.end();
      }
      return;
    }
    try { response.end(await fs.readFile(file)); } catch { response.statusCode = 404; response.end(); }
  });
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  return { origin: `http://127.0.0.1:${server.address().port}`, failures, close: () => new Promise(resolve => server.close(resolve)) };
}
