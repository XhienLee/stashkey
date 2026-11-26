import { sodium, call, messageFor } from './family/lib.js';
import { makeKeyWords } from './js/key.js';
import { $, notice, renderWords, busy } from './family/ui.js';
import { deriveStash, sealNotes, openNotes } from './stash.js';

let stash = null;
let newWords = [];
let current = null;
let timer = 0;
let locked = false;

const api = (path, options) => call(`api.php?${path}`, options);

$('make').addEventListener('click', () => {
    newWords = makeKeyWords();
    renderWords($('made-words'), newWords);
    $('saved').checked = false;
    $('create').disabled = true;
    $('made').hidden = false;
});
$('saved').addEventListener('change', () => { $('create').disabled = !$('saved').checked; });

$('create').addEventListener('click', () => busy($('create'), 'Creating…', async () => {
    notice('new-alert', '');
    try {
        const s = await sodium();
        const keys = deriveStash(s, newWords.join(' '));
        await api(`action=put&id=${keys.id}&rev=0`, { method: 'POST', body: sealNotes(s, keys.key, []) });
        newWords = [];
        $('made-words').replaceChildren();
        $('made').hidden = true;
        await load(keys);
    } catch (error) {
        notice('new-alert', error.status === 409 ? 'Those words are already taken. Make a new stash again.' : messageFor(error));
    }
}));

$('open').addEventListener('click', () => busy($('open'), 'Opening…', async () => {
    notice('login-alert', '');
    try {
        const s = await sodium();
        await load(deriveStash(s, $('words').value));
    } catch (error) {
        notice('login-alert', error.status === 404 ? "No stash found for those words. Check them and try again." : error.code === 'bad_key_format' ? 'Your key is 6 words.' : messageFor(error));
    }
}));

async function load(keys) {
    const s = await sodium();
    const res = await api(`action=get&id=${keys.id}`);
    const notes = openNotes(s, keys.key, new Uint8Array(await res.arrayBuffer()));
    stash = { ...keys, rev: Number(res.headers.get('X-Rev')), notes };
    current = null;
    locked = false;
    $('words').value = '';
    $('view-login').hidden = true;
    $('view-stash').hidden = false;
    $('logout').hidden = false;
    notice('stash-alert', '');
    render();
}

function visible() {
    const q = $('search').value.trim().toLowerCase();
    return stash.notes
        .filter((n) => !q || n.title.toLowerCase().includes(q) || n.body.toLowerCase().includes(q))
        .sort((a, b) => b.updated - a.updated);
}

function render() {
    const list = $('notes');
    list.replaceChildren();
    for (const note of visible()) {
        const item = document.createElement('li');
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'note-btn';
        button.setAttribute('aria-current', String(note.id === current));
        const title = document.createElement('span');
        title.className = 'note-title';
        title.textContent = note.title || 'Untitled';
        const snip = document.createElement('span');
        snip.className = 'note-snip';
        snip.textContent = note.body.split('\n')[0] || 'Empty note';
        button.append(title, snip);
        button.addEventListener('click', () => select(note.id));
        item.append(button);
        list.append(item);
    }
    const note = stash.notes.find((n) => n.id === current);
    $('editor').hidden = !note;
    $('empty').hidden = stash.notes.length > 0 || Boolean(note);
    if (note && document.activeElement !== $('title') && document.activeElement !== $('body')) {
        $('title').value = note.title;
        $('body').value = note.body;
    }
}

function select(id) {
    current = id;
    const note = stash.notes.find((n) => n.id === id);
    $('title').value = note.title;
    $('body').value = note.body;
    $('saved-status').textContent = '';
    render();
}

$('new').addEventListener('click', () => {
    if (locked) return;
    const note = { id: crypto.randomUUID(), title: '', body: '', updated: Date.now() };
    stash.notes.push(note);
    select(note.id);
    $('title').focus();
    queueSave();
});

for (const id of ['title', 'body']) {
    $(id).addEventListener('input', () => {
        const note = stash.notes.find((n) => n.id === current);
        if (!note || locked) return;
        note[id] = $(id).value;
        note.updated = Date.now();
        render();
        queueSave();
    });
}
$('search').addEventListener('input', () => stash && render());

$('delete').addEventListener('click', () => {
    if (locked || !current) return;
    stash.notes = stash.notes.filter((n) => n.id !== current);
    current = null;
    render();
    queueSave();
});

function queueSave() {
    $('saved-status').textContent = 'Saving…';
    clearTimeout(timer);
    timer = setTimeout(save, 900);
}

async function save() {
    if (!stash || locked) return;
    try {
        const s = await sodium();
        const result = await (await api(`action=put&id=${stash.id}&rev=${stash.rev}`, { method: 'POST', body: sealNotes(s, stash.key, stash.notes) })).json();
        stash.rev = result.rev;
        $('saved-status').textContent = 'Saved and locked';
    } catch (error) {
        $('saved-status').textContent = '';
        if (error.status === 409) {
            locked = true;
            notice('stash-alert', 'This stash changed on another device or tab, so this copy is read-only. Log out and open it again to see the latest.');
        } else {
            notice('stash-alert', error.code === 'stash_full' ? 'Your stash is full. Delete some notes.' : messageFor(error));
        }
    }
}

function leave() {
    clearTimeout(timer);
    if (stash) stash.key.fill(0);
    stash = null;
    current = null;
    $('title').value = '';
    $('body').value = '';
    $('search').value = '';
    $('notes').replaceChildren();
    $('view-stash').hidden = true;
    $('view-login').hidden = false;
    $('logout').hidden = true;
}

$('logout').addEventListener('click', async () => {
    clearTimeout(timer);
    await save();
    leave();
});

let armed = 0;
$('wipe').addEventListener('click', async () => {
    if (!armed) {
        $('wipe').textContent = 'Click again to delete everything';
        armed = setTimeout(() => { armed = 0; $('wipe').textContent = 'Delete everything'; }, 5000);
        return;
    }
    clearTimeout(armed);
    armed = 0;
    $('wipe').textContent = 'Delete everything';
    try {
        await api(`action=del&id=${stash.id}`, { method: 'POST' });
        leave();
    } catch (error) {
        notice('stash-alert', messageFor(error));
    }
});

