# Run with: python3 tests/e2e.py   (needs php and Playwright's Chromium, see tests/requirements.txt)
import json
import os
import pathlib
import random
import shutil
import subprocess
import sys
import tempfile
import time

os.environ['NO_PROXY'] = '*'
from playwright.sync_api import sync_playwright

ROOT = pathlib.Path(__file__).resolve().parent.parent
WORK = pathlib.Path(tempfile.mkdtemp())
PORT = 18900 + random.randint(0, 90)
BASE = f'http://127.0.0.1:{PORT}/'
results = [0, 0]


def check(name, ok, info=''):
    results[0 if ok else 1] += 1
    print(('PASS ' if ok else 'FAIL ') + name + ('' if ok else f'  {info}'), flush=True)


def write(name, data):
    path = WORK / name
    path.write_bytes(data if isinstance(data, bytes) else data.encode())
    return path


env = dict(os.environ, FAMILY_DATA_DIR=str(WORK / 'data'), FAMILY_MIN_FREE_BYTES='0', STASHKEY_NEW_PER_IP_HOUR='100')
server = subprocess.Popen(['php', '-d', 'post_max_size=40M', '-S', f'127.0.0.1:{PORT}', '-t', str(ROOT), str(ROOT / 'router.php')], env=env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
time.sleep(1.2)


class Tab:
    def __init__(self, context, path):
        self.page = context.new_page()
        self.errors = []
        self.requests = []
        self.page.on('pageerror', lambda e: self.errors.append('PAGEERROR ' + str(e)))
        self.page.on('console', lambda m: self.errors.append(m.text) if m.type == 'error' and 'status of 4' not in m.text else None)
        self.page.on('request', lambda r: self.requests.append(r))
        self.page.goto(BASE + path)


def words_of(page, selector):
    return page.locator(f'{selector} .key-word').all_inner_texts()


def body_has(tab, needle):
    return any(r.method == 'POST' and r.post_data_buffer and needle in r.post_data_buffer for r in tab.requests)


try:
    with sync_playwright() as pw:
        browser = pw.chromium.launch(args=['--no-proxy-server'])
        context = browser.new_context(accept_downloads=True, viewport={'width': 1100, 'height': 900})

        stash = Tab(context, '')
        p = stash.page
        p.click('#make')
        s_words = '-'.join(words_of(p, '#made-words'))
        check('stashkey: 6 words are made and creating waits for the tick', len(s_words.split('-')) == 6 and p.is_disabled('#create'))
        p.check('#saved')
        p.click('#create')
        p.wait_for_selector('#view-stash', state='visible', timeout=90000)
        p.click('#new')
        p.fill('#title', 'Wi-Fi')
        p.fill('#body', 'correct horse battery staple')
        p.wait_for_function('document.getElementById("saved-status").textContent.includes("Saved")', timeout=30000)
        check('stashkey: a note saves, locked', body_has(stash, b'PUT') or any(r.method == 'POST' and 'action=put' in r.url for r in stash.requests))
        check('stashkey: the note text never leaves the browser readable', not body_has(stash, b'correct horse') and not body_has(stash, b'Wi-Fi'))
        p.click('#logout')
        p.wait_for_selector('#view-login', state='visible')
        check('stashkey: logging out clears the notes from the page', p.locator('#notes li').count() == 0 and p.input_value('#body') == '')
        p.fill('#words', s_words)
        p.click('#open')
        p.wait_for_selector('#view-stash', state='visible', timeout=90000)
        check('stashkey: the 6 words bring the note back', p.locator('.note-title').first.inner_text() == 'Wi-Fi')
        p.click('.note-btn')
        check('stashkey: opening a note shows its text', p.input_value('#body') == 'correct horse battery staple')
        p.fill('#search', 'horse')
        check('stashkey: search finds the note by its text', p.locator('.note-btn').count() == 1)
        p.fill('#search', 'zzz')
        check('stashkey: search with no match shows none', p.locator('.note-btn').count() == 0)
        p.fill('#search', '')
        second = Tab(context, '')
        q = second.page
        q.fill('#words', s_words)
        q.click('#open')
        q.wait_for_selector('#view-stash', state='visible', timeout=90000)
        q.click('.note-btn')
        q.fill('#body', 'changed on the second tab')
        q.wait_for_function('document.getElementById("saved-status").textContent.includes("Saved")', timeout=30000)
        p.fill('#body', 'changed on the first tab')
        p.wait_for_selector('#stash-alert:not([hidden])', timeout=30000)
        check('stashkey: a clash between two tabs is caught', 'changed on another' in p.inner_text('#stash-alert'), p.inner_text('#stash-alert'))
        p.click('#logout')
        stranger = Tab(context, '')
        stranger.page.click('#make')
        stranger.page.fill('#words', '-'.join(words_of(stranger.page, '#made-words')))
        stranger.page.click('#open')
        stranger.page.wait_for_selector('#login-alert:not([hidden])', timeout=60000)
        check('stashkey: words that make no stash find nothing', 'No stash found' in stranger.page.inner_text('#login-alert'))
        q.click('summary')
        q.click('#wipe')
        q.click('#wipe')
        q.wait_for_selector('#view-login', state='visible', timeout=30000)
        q.fill('#words', s_words)
        q.click('#open')
        q.wait_for_selector('#login-alert:not([hidden])', timeout=60000)
        check('stashkey: deleting the stash really removes it', 'No stash found' in q.inner_text('#login-alert'))
        check('stashkey: no console errors', not stash.errors and not second.errors, (stash.errors + second.errors)[:3])
        browser.close()
finally:
    server.terminate()
    server.wait()
    shutil.rmtree(WORK, ignore_errors=True)

print(f'{results[0]} passed, {results[1]} failed')
sys.exit(1 if results[1] else 0)
