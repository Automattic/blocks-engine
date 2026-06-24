import { mkdtemp, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { Readable } from 'node:stream';

import { describe, expect, it } from 'vitest';

import { runCli } from '../cli';
import { BlocksEngineError } from '../errors';

function writableBuffer() {
  let output = '';

  return {
    stream: {
      write(chunk: string | Uint8Array) {
        output += typeof chunk === 'string' ? chunk : chunk.toString();
        return true;
      },
    },
    text: () => output,
  };
}

describe('CLI one-shot conversion', () => {
  it('reads a file arg and writes block markup to stdout', async () => {
    const dir = await mkdtemp(join(tmpdir(), 'blocks-engine-cli-'));

    try {
      const filePath = join(dir, 'sample.html');
      await writeFile(filePath, '<h2>Hello CLI</h2><p>Body copy.</p>');

      const stdout = writableBuffer();
      const stderr = writableBuffer();
      const exitCode = await runCli([filePath], {
        stdout: stdout.stream,
        stderr: stderr.stream,
      });

      expect(exitCode).toBe(0);
      expect(stdout.text()).toContain('<!-- wp:');
      expect(stderr.text()).toBe('');
    } finally {
      await rm(dir, { recursive: true, force: true });
    }
  });

  it('reads stdin when no file arg is provided', async () => {
    let seenUrl: string | undefined;
    const stdout = writableBuffer();
    const stderr = writableBuffer();

    const exitCode = await runCli([], {
      stdin: Readable.from(['<p>From stdin.</p>']),
      stdout: stdout.stream,
      stderr: stderr.stream,
      convertHtml: async (_html, ctx) => {
        seenUrl = ctx?.url;
        return '<!-- wp:paragraph --><p>From stdin.</p><!-- /wp:paragraph -->';
      },
    });

    expect(exitCode).toBe(0);
    expect(seenUrl).toMatch(/^file:\/\//);
    expect(seenUrl).toMatch(/stdin$/);
    expect(stdout.text()).toContain('<!-- wp:paragraph -->');
    expect(stderr.text()).toBe('');
  });

  it('prints BlocksEngineError message and hint to stderr', async () => {
    const stdout = writableBuffer();
    const stderr = writableBuffer();

    const exitCode = await runCli([], {
      stdin: Readable.from(['<p>Unsafe.</p>']),
      stdout: stdout.stream,
      stderr: stderr.stream,
      convertHtml: async () => {
        throw new BlocksEngineError('Cannot convert HTML.', {
          code: 'test-error',
          hint: 'Remove unsupported markup and try again.',
        });
      },
    });

    expect(exitCode).toBe(1);
    expect(stdout.text()).toBe('');
    expect(stderr.text()).toContain('Cannot convert HTML.');
    expect(stderr.text()).toContain('Remove unsupported markup and try again.');
  });
});
