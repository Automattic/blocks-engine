/**
 * Seeded randomness for the differential corpus.
 *
 * Every case draws from its own stream, derived from the run seed and the
 * case index, so `--count 50` produces the same first 50 cases as
 * `--count 200` and a single case can be regenerated in isolation.
 */

/** 32-bit FNV-1a over a string, used to derive per-case seeds. */
export function hashString(value) {
  let hash = 0x811c9dc5;
  for (let index = 0; index < value.length; index++) {
    hash ^= value.charCodeAt(index);
    hash = Math.imul(hash, 0x01000193) >>> 0;
  }
  return hash >>> 0;
}

/** mulberry32: small, fast, and good enough for grammar choices. */
export function createRandom(seed) {
  let state = seed >>> 0;
  const next = () => {
    state = (state + 0x6d2b79f5) >>> 0;
    let t = state;
    t = Math.imul(t ^ (t >>> 15), t | 1);
    t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };

  return {
    next,
    /** Integer in [min, max], inclusive. */
    int(min, max) {
      return min + Math.floor(next() * (max - min + 1));
    },
    /** True with the given probability. */
    chance(probability) {
      return next() < probability;
    },
    pick(list) {
      if (!list.length) throw new Error('Cannot pick from an empty list');
      return list[Math.floor(next() * list.length)];
    },
    /** Pick from `[[weight, value], ...]`. */
    weighted(entries) {
      const total = entries.reduce((sum, [weight]) => sum + weight, 0);
      let roll = next() * total;
      for (const [weight, value] of entries) {
        roll -= weight;
        if (roll < 0) return value;
      }
      return entries[entries.length - 1][1];
    },
    /** `count` distinct items, in a random order. */
    sample(list, count) {
      const pool = [...list];
      const chosen = [];
      while (chosen.length < count && pool.length) {
        chosen.push(pool.splice(Math.floor(next() * pool.length), 1)[0]);
      }
      return chosen;
    },
    shuffle(list) {
      return this.sample(list, list.length);
    },
  };
}

/** The random stream for one case of one run. */
export function caseRandom(seed, index, salt = '') {
  return createRandom(hashString(`${seed}:${index}:${salt}`));
}
