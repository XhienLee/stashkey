import { test } from 'node:test';
import assert from 'node:assert/strict';
import { sodium } from './helpers.mjs';
import { makeKeyWords } from '../js/key.js';
import { deriveStash, sealNotes, openNotes } from '../stash.js';

const FAST = { passes: 1, memBytes: 8192 };

test('stashkey: notes round-trip, the size is padded, and wrong words or changes are caught', async () => {
    const s = await sodium();
    const words = makeKeyWords().join(' ');
    const a = deriveStash(s, words, FAST);
    const b = deriveStash(s, makeKeyWords().join(' '), FAST);
    assert.deepEqual(a, deriveStash(s, words.toUpperCase().replace(/ /g, '-'), FAST));
    assert.notEqual(a.id, b.id);
    const notes = [{ id: 'n1', title: 'Wi-Fi', body: 'correct horse battery', updated: 1 }];
    const sealed = sealNotes(s, a.key, notes);
    assert.equal((sealed.length - 1 - 24 - 16) % 1024, 0);
    assert.deepEqual(openNotes(s, a.key, sealed), notes);
    assert.throws(() => openNotes(s, b.key, sealed), { code: 'wrong_words' });
    const flipped = sealed.slice();
    flipped[100] ^= 1;
    assert.throws(() => openNotes(s, a.key, flipped), { code: 'wrong_words' });
    assert.notDeepEqual(sealNotes(s, a.key, notes), sealNotes(s, a.key, notes));
    assert.equal(sealNotes(s, a.key, []).length, sealNotes(s, a.key, [{ id: 'x', title: 'a', body: 'b', updated: 1 }]).length);
    assert.throws(() => sealNotes(s, a.key, [{ body: 'x'.repeat(300000) }]), { code: 'stash_full' });
    assert.throws(() => deriveStash(s, 'one two three', FAST), { code: 'bad_key_format' });
});
