#!/usr/bin/env node

import { realpathSync } from 'node:fs';
import { readFile } from 'node:fs/promises';
import { resolve } from 'node:path';
import type { Readable } from 'node:stream';
import { fileURLToPath, pathToFileURL } from 'node:url';

import { convert } from './convert.js';
import { BlocksEngineError } from './errors.js';

interface WritableLike {
  write(chunk: string | Uint8Array): unknown;
}

type ConvertHtml = (html: string, ctx: { url: string }) => Promise<string>;

export interface RunCliOptions {
  stdin?: Readable;
  stdout?: WritableLike;
  stderr?: WritableLike;
  convertHtml?: ConvertHtml;
}

async function readStdin(stdin: Readable): Promise<string> {
  let html = '';

  for await (const chunk of stdin) {
    html += typeof chunk === 'string' ? chunk : Buffer.from(chunk).toString('utf8');
  }

  return html;
}

export async function runCli(argv: string[], options: RunCliOptions = {}): Promise<number> {
  const stdin = options.stdin ?? process.stdin;
  const stdout = options.stdout ?? process.stdout;
  const stderr = options.stderr ?? process.stderr;
  const convertHtml = options.convertHtml ?? convert;
  const fileArg = argv[0];

  try {
    const input =
      fileArg === undefined
        ? {
            html: await readStdin(stdin),
            url: pathToFileURL(resolve(process.cwd(), 'stdin')).href,
          }
        : {
            html: await readFile(resolve(fileArg), 'utf8'),
            url: pathToFileURL(resolve(fileArg)).href,
          };

    stdout.write(await convertHtml(input.html, { url: input.url }));
    return 0;
  } catch (error) {
    if (error instanceof BlocksEngineError) {
      stderr.write(`${error.message}\n${error.hint}\n`);
      return 1;
    }

    const message = error instanceof Error ? error.message : String(error);
    stderr.write(`${message}\n`);
    return 1;
  }
}

function realpathOrResolve(path: string): string {
  try {
    return realpathSync(path);
  } catch {
    return resolve(path);
  }
}

function isDirectInvocation(argv1: string | undefined): boolean {
  return argv1 !== undefined && realpathOrResolve(argv1) === realpathOrResolve(fileURLToPath(import.meta.url));
}

if (isDirectInvocation(process.argv[1])) {
  void runCli(process.argv.slice(2)).then((exitCode) => {
    process.exitCode = exitCode;
  });
}
