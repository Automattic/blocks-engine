import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import path from 'node:path';
import { createHash } from 'node:crypto';

const [baselineDirectory, candidateDirectory, output] = process.argv.slice(2);
if (!baselineDirectory || !candidateDirectory || !output) throw new Error('Usage: seal-integrated-document-proof.mjs <existing baseline> <existing overlay> <proof.json>');
const load = async directory => JSON.parse(await fs.readFile(path.join(directory, 'paired.json'), 'utf8'));
const before = await load(baselineDirectory), after = await load(candidateDirectory);
const measurements = row => ({ inner: row.innerWidth, document: row.documentWidth, canvas: row.targets.find(node => /^masterPage(?:$|--)/.test(node.id))?.rect.width });
const profiles = [];
for (const pair of after) {
  const baseline = before.find(row => row.profile === pair.profile);
  assert.ok(baseline);
  assert.deepEqual(measurements(pair.wordpress), measurements(pair.source), `${pair.profile}: real served WordPress CSS replay retains source native viewport/canvas/document geometry`);
  assert.equal(pair.wordpress.selected, pair.source.selected);
  assert.equal(pair.wordpress.viewport.length, 1);
  assert.deepEqual(pair.wordpress.viewport, pair.source.viewport);
  assert.deepEqual(pair.wordpress.errors, []);
  assert.deepEqual(pair.source.errors, []);
  for (const sourceNode of pair.source.targets) {
    const destinationNode = pair.wordpress.targets.find(node => node.id === sourceNode.id || node.id.startsWith(sourceNode.id + '--'));
    assert.ok(destinationNode, `${pair.profile}: declared scoped target ${sourceNode.id}`);
    assert.equal(destinationNode.rect.width, sourceNode.rect.width);
    assert.equal(destinationNode.styles['min-width'], sourceNode.styles['min-width']);
  }
  const hash = async file => createHash('sha256').update(await fs.readFile(file)).digest('hex');
  profiles.push({ profile: pair.profile, source: measurements(pair.source), baseline: measurements(baseline.wordpress), candidate: measurements(pair.wordpress), baseline_html_sha256: await hash(path.join(baselineDirectory, `${pair.profile}-wordpress.html`)), candidate_html_sha256: await hash(path.join(candidateDirectory, `${pair.profile}-wordpress.html`)) });
}
assert.ok(profiles.filter(row => row.profile !== 'phone').every(row => JSON.stringify(row.baseline) !== JSON.stringify(row.source)), 'Retained destination baseline must reproduce desktop/tablet geometry losses');
const proof = { schema: 'blocks-engine/integrated-document-css-replay/v1', status: 'pass', baseline: baselineDirectory, candidate: candidateDirectory, profiles, provenance: 'Actual served WordPress HTML, Core/theme CSS, scripts and installed companion retained. Candidate ordered head/asset CSS substituted in isolated browser responses, with explicit generated source-marker seed alignment. No site mutation. Fresh SSI import without interception remains parent acceptance.' };
await fs.writeFile(output, JSON.stringify(proof, null, 2) + '\n');
console.log(JSON.stringify(proof, null, 2));
