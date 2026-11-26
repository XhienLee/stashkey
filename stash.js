import { normalizeKey, isKeyValid, KEY_WORDS } from './js/key.js';
import { SealError } from './js/format.js';
import { concat } from './family/lib.js';

const SALT = new TextEncoder().encode('stashkey salt v1');
const AD = new TextEncoder().encode('stashkey v1');
const DEFAULT_COST = { passes: 3, memBytes: 64 * 1024 * 1024 };
export const STASH_MAX = 262144;

export function deriveStash(s, words, cost = DEFAULT_COST) {
    const normalized = normalizeKey(words);
    if (!isKeyValid(normalized, KEY_WORDS)) throw new SealError('bad_key_format');
    const master = s.crypto_pwhash(32, normalized, SALT, cost.passes, cost.memBytes, s.crypto_pwhash_ALG_ARGON2ID13);
    try {
        return {
            id: s.to_hex(s.crypto_generichash(32, 'stashkey lookup v1', master)),
            key: s.crypto_generichash(32, 'stashkey encrypt v1', master),
        };
    } finally {
        s.memzero(master);
    }
}

export function sealNotes(s, key, notes) {
    const data = new TextEncoder().encode(JSON.stringify(notes));
    const padded = new Uint8Array(Math.max(1024, Math.ceil((4 + data.length) / 1024) * 1024));
    new DataView(padded.buffer).setUint32(0, data.length, true);
    padded.set(data, 4);
    const nonce = s.randombytes_buf(24);
    const out = concat([new Uint8Array([1]), nonce, s.crypto_aead_xchacha20poly1305_ietf_encrypt(padded, AD, null, nonce, key)]);
    if (out.length > STASH_MAX) throw new SealError('stash_full');
    return out;
}

export function openNotes(s, key, bytes) {
    let padded;
    try {
        if (bytes[0] !== 1) throw new Error('version');
        padded = s.crypto_aead_xchacha20poly1305_ietf_decrypt(null, bytes.slice(25), AD, bytes.slice(1, 25), key);
    } catch {
        throw new SealError('wrong_words');
    }
    const length = new DataView(padded.buffer, padded.byteOffset).getUint32(0, true);
    return JSON.parse(new TextDecoder().decode(padded.subarray(4, 4 + length)));
}
