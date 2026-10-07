import { ESLint } from 'eslint';
import { fileURLToPath } from 'node:url';

const root = fileURLToPath(new URL('../../', import.meta.url));
const eslint = new ESLint({ cwd: root, overrideConfigFile: `${root}/eslint.config.mjs` });
const results = await eslint.lintFiles(['tools', 'tests']);
const formatter = await eslint.loadFormatter('stylish');
process.stdout.write(formatter.format(results));
if (results.some(result => result.errorCount || result.warningCount)) process.exitCode = 1;
