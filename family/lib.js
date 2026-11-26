import sodiumModule from '../vendor/libsodium/libsodium-wrappers.mjs';
import { parseHeader, KEY_HEADER_BYTES, KIND_FILE, cleanName } from '../js/format.js';
import { createSealer, sealStream, createOpener, openMeta, unsealStream, META_CIPHER_BYTES } from '../js/core.js';

export const MAX_BYTES = 25 * 1024 * 1024;
export const HEAD_BYTES = 700;

export async function sodium() {
    await sodiumModule.ready;
    return sodiumModule;
}

export function concat(parts) {
    const out = new Uint8Array(parts.reduce((sum, part) => sum + part.length, 0));
    let offset = 0;
    for (const part of parts) {
        out.set(part, offset);
        offset += part.length;
    }
    return out;
}

export function sizeText(bytes) {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1048576) return `${Math.round(bytes / 1024)} KB`;
    return `${(bytes / 1048576).toFixed(1)} MB`;
}

export async function sealFile(s, file, { keyText = null, recipient = null }, params) {
    if (file.size > MAX_BYTES) throw new Error(`That file is ${sizeText(file.size)}. The limit is ${sizeText(MAX_BYTES)}.`);
    const bytes = new Uint8Array(await file.arrayBuffer());
    const meta = { kind: KIND_FILE, count: 1, name: cleanName(file.name), size: bytes.length };
    const sealer = createSealer(s, recipient ? { recipient, meta, ...(params && { params }) } : { keyText, meta, ...(params && { params }) });
    const parts = [];
    async function* source() { yield bytes; }
    for await (const { data } of sealStream(sealer, source(), bytes.length)) parts.push(data);
    return concat(parts);
}

export function peek(s, bytes, { keyText = null, secretKey = null }) {
    const header = parseHeader(bytes.subarray(0, KEY_HEADER_BYTES));
    const state = createOpener(s, { keyText, secretKey, header });
    const meta = openMeta(s, state, header, bytes.slice(header.size, header.size + META_CIPHER_BYTES));
    return { header, state, meta };
}

export async function openFile(s, bytes, secrets) {
    const { header, state, meta } = peek(s, bytes, secrets);
    const parts = [];
    for await (const { data } of unsealStream(s, state, async (offset, length) => bytes.slice(offset, offset + length), bytes.length, header, meta)) parts.push(data);
    return { meta, data: concat(parts) };
}

export function saveFile(name, data) {
    const link = document.createElement('a');
    link.href = URL.createObjectURL(new Blob([data], { type: 'application/octet-stream' }));
    link.download = name;
    link.hidden = true;
    document.body.appendChild(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(link.href), 60000);
}

export async function call(url, options = {}) {
    let res;
    try {
        res = await fetch(url, options);
    } catch {
        throw new Error('Could not reach the server. Check your connection and try again.');
    }
    if (!res.ok) {
        let message = 'Something went wrong. Please try again.';
        try { message = (await res.json()).message || message; } catch {}
        const error = new Error(message);
        error.status = res.status;
        throw error;
    }
    return res;
}

export async function copyText(text, button, done = 'Copied') {
    const before = button.textContent;
    try {
        await navigator.clipboard.writeText(text);
        button.textContent = done;
    } catch {
        button.textContent = 'Select and copy it yourself';
    }
    setTimeout(() => { button.textContent = before; }, 2500);
}

export function messageFor(error) {
    const text = {
        wrong_key: "That key doesn't open this file. Check the words and try again.",
        needs_secret_words: 'This file needs the 12 secret words.',
        bad_secret_words: "Check your words. They should be 12 words from Sealkey's list.",
        bad_public_key: "That isn't a valid public key.",
        unsafe_header: "This file can't be opened safely.",
        damaged: 'The file is damaged.',
        incomplete: 'The file is incomplete.',
        extra_data: 'The file was changed.',
        wrong_words: "Those words don't open this stash.",
        out_of_memory: 'Your device ran out of memory. Try a computer.',
    };
    return text[error && error.code] || (error && error.message) || 'Something went wrong. Please try again.';
}
