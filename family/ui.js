import { sizeText } from './lib.js';

export const $ = (id) => document.getElementById(id);

export function fileButton(buttonId, inputId, labelId, empty = 'No file chosen') {
    const input = $(inputId);
    const label = $(labelId);
    label.textContent = empty;
    $(buttonId).addEventListener('click', () => input.click());
    input.addEventListener('change', () => {
        const file = input.files[0];
        label.textContent = file ? `${file.name} · ${sizeText(file.size)}` : empty;
    });
}

export function notice(id, text, kind = '') {
    const el = $(id);
    el.textContent = text;
    el.className = kind ? `alert alert-${kind}` : 'alert';
    el.hidden = !text;
}

export function renderWords(list, words) {
    list.replaceChildren();
    words.forEach((word, i) => {
        const item = document.createElement('li');
        const n = document.createElement('span');
        n.className = 'key-n';
        n.textContent = String(i + 1);
        const w = document.createElement('span');
        w.className = 'key-word';
        w.textContent = word;
        item.append(n, w);
        list.append(item);
    });
}

export async function busy(button, text, task) {
    const before = button.textContent;
    button.disabled = true;
    button.textContent = text;
    try {
        return await task();
    } finally {
        button.disabled = false;
        button.textContent = before;
    }
}
