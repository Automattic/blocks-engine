/**
 * Client for the long-lived PHP transformer worker (transform-worker.php).
 *
 * Requests are newline-delimited JSON and answered in order. If the worker
 * dies mid-request (a PHP fatal error), that request rejects with
 * `crashed: true` and the next request starts a fresh worker.
 */
import { spawn } from 'node:child_process';
import { createInterface } from 'node:readline';
import { fileURLToPath } from 'node:url';

const WORKER = fileURLToPath(new URL('./transform-worker.php', import.meta.url));
export const TRANSFORMER_ROOT = fileURLToPath(new URL('../../../', import.meta.url));

export class TransformerWorker {
  constructor({ php = process.env.PHP_BINARY || 'php', root = TRANSFORMER_ROOT, timeoutMs = 120000 } = {}) {
    this.php = php;
    this.root = root;
    this.timeoutMs = timeoutMs;
    this.process = null;
    this.pending = [];
    this.ready = null;
    this.stderr = '';
  }

  start() {
    if (this.ready) return this.ready;
    this.stderr = '';
    const child = spawn(this.php, ['-d', 'memory_limit=1G', WORKER, this.root], { stdio: ['pipe', 'pipe', 'pipe'] });
    this.process = child;
    child.stderr.setEncoding('utf8');
    child.stderr.on('data', (chunk) => {
      this.stderr = (this.stderr + chunk).slice(-4000);
    });
    const lines = createInterface({ input: child.stdout });
    this.ready = new Promise((resolve, reject) => {
      let started = false;
      lines.on('line', (line) => {
        if (!started) {
          started = true;
          try {
            JSON.parse(line).ready ? resolve() : reject(new Error(`Unexpected worker greeting: ${line}`));
          } catch {
            reject(new Error(`Unexpected worker greeting: ${line}`));
          }
          return;
        }
        const waiter = this.pending.shift();
        if (!waiter) return;
        clearTimeout(waiter.timer);
        try {
          waiter.resolve(JSON.parse(line));
        } catch (error) {
          waiter.reject(new Error(`Unparseable worker response: ${error.message}`));
        }
      });
      child.on('error', (error) => {
        if (!started) reject(new Error(`Could not start PHP worker (${this.php}): ${error.message}`));
      });
      child.on('exit', (code, signal) => {
        if (!started) reject(new Error(`PHP worker exited before it was ready (code ${code}): ${this.stderr.trim()}`));
        const error = Object.assign(new Error(`PHP worker exited (code ${code}, signal ${signal}): ${this.stderr.trim().slice(-800)}`), { crashed: true });
        for (const waiter of this.pending.splice(0)) {
          clearTimeout(waiter.timer);
          waiter.reject(error);
        }
        this.ready = null;
        this.process = null;
      });
    });
    return this.ready;
  }

  /** Transform one source document; resolves with the worker response. */
  async transform(id, html) {
    await this.start();
    const child = this.process;
    return new Promise((resolve, reject) => {
      const timer = setTimeout(() => {
        reject(Object.assign(new Error(`Transform of ${id} exceeded ${this.timeoutMs}ms`), { crashed: true }));
        child.kill('SIGKILL');
      }, this.timeoutMs);
      this.pending.push({ resolve, reject, timer });
      child.stdin.write(`${JSON.stringify({ id, html })}\n`);
    });
  }

  async close() {
    if (!this.process) return;
    const child = this.process;
    const exited = new Promise((resolve) => child.once('exit', resolve));
    child.stdin.end();
    await exited;
  }
}

/**
 * The converted page as a front end would render it: the serialized blocks
 * plus every non-editor stylesheet asset, in placement order.
 */
export function convertedDocument(response, title = 'converted case') {
  const placementOrder = { 'before-author': 0, author: 1, 'after-author': 2 };
  const styles = response.assets
    .filter((asset) => asset.stylesheet_target !== 'editor')
    .map((asset, position) => ({ asset, position }))
    .sort((left, right) => (placementOrder[left.asset.stylesheet_placement] ?? 1) - (placementOrder[right.asset.stylesheet_placement] ?? 1) || left.position - right.position)
    .map(({ asset }) => (asset.media ? `@media ${asset.media} {\n${asset.content}\n}` : asset.content));
  return [
    '<!doctype html>',
    '<html lang="en">',
    '<head>',
    '<meta charset="utf-8">',
    `<title>${title}</title>`,
    ...styles.map((css) => `<style>\n${css}\n</style>`),
    '</head>',
    '<body>',
    response.serialized_blocks,
    '</body>',
    '</html>',
    '',
  ].join('\n');
}
