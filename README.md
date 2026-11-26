# Stashkey

**Private notes you open with 6 words. No account, no password.**

Type your notes, and they are locked in your browser and saved on the server. To get them back, type your 6 words on any phone or computer.

> **No ready-made website yet.** This is the code for the tool. To use it you (or someone you trust) run it on a web host that supports PHP. Once it is running, anyone can use it from a browser with nothing to install. If you are not technical, ask a friend or a hosting company to set it up, or follow "Run it" below. Running it on your own computer takes one command.

## What is it good for?

- A small private notepad you can reach from any device
- Keeping a recovery code, a list or a draft away from apps that read your notes
- Notes you want without making yet another account

## How to use it

1. Open the site and press **Make a new stash**.
2. Write down your **6 words**. They are your key and your login in one. They cannot be reset.
3. Tick "I wrote down my 6 words" and press **Create my stash**.
4. Press **New note**, write a title and text. You can search your notes.
5. Next time, type your 6 words and press **Open my stash**.

**Delete note** removes one note. **Delete everything** removes the whole stash.

## Is it safe? What can it not do?

- **The server cannot read your notes.** They are locked in your browser first. The server only holds scrambled data and a lookup number made from your words.
- **Lose the 6 words, lose the notes.** There is no reset and no one to ask.
- Anyone who learns your 6 words can read and change your notes. Treat them like a password.
- Notes are limited to 256 KiB (a lot of text, but not a document library).
- The server can see roughly how often you save. It cannot see what you wrote.
- It cannot protect you from a hacked device or a fake copy of the website. Only use a site you trust.

## Questions

**Can the person running the site read my notes?** No.
**What if I edit on two devices at once?** Stashkey notices and asks you before it overwrites anything.
**Can I choose my own 6 words?** No. They are generated randomly so they are strong. Make a new stash for new words.

---

## For developers

### How it works

- 6 random words from Sealkey's 7,772-word list (about 77 bits) go through Argon2id with a fixed salt into a master key. One part is the **lookup id** (BLAKE2b), the other the **encryption key**.
- Notes are JSON padded to 1 KiB steps and sealed with XChaCha20-Poly1305 (AEAD).
- Saves are versioned (`rev`). A stale write gets a `409` so two devices never silently overwrite each other.
- Limit 256 KiB per stash. A `del` action removes a stash.

## Run it

Needs PHP 8.1+ with `pdo_sqlite` and `sodium`. On your own computer:

```bash
FAMILY_DATA_DIR=/tmp/stashkey-data php -d post_max_size=40M -S 127.0.0.1:8800 router.php
```

Open <http://127.0.0.1:8800>. `router.php` only adds the same security headers that `_headers` gives on Netlify (Netlify can't run PHP).

For real hosting use Apache or nginx with PHP-FPM and **HTTPS**. Keep `FAMILY_DATA_DIR` **outside the web root** (the API refuses to run otherwise), and allow request bodies of about 26 MB (`post_max_size`, `client_max_body_size`).

| Setting (environment variable) | Default | Meaning |
|---|---|---|
| `FAMILY_DATA_DIR` | `family_data` next to the repo folder | Database |
| `STASHKEY_NEW_PER_IP_HOUR` | 5 | New stashes one address can make per hour |
| `STASHKEY_NEW_GLOBAL_HOUR` | 100 | New stashes the whole site can make per hour |

## Tests

```bash
node --test tests/stashkey.test.mjs
php tests/api.php
pip install -r tests/requirements.txt && python3 -m playwright install chromium
python3 tests/e2e.py
```

They check the crypto, every server rule (conflicts, size limits, deletion, rate limits) and the whole flow in Chromium.

## The key family

Small private tools. One key each, no accounts, nothing to sign up for.

| Tool | What it does | Needs a server? |
|---|---|---|
| [Sealkey](https://github.com/XhienLee/sealkey) | Lock a file with a few words, open it later | No |
| [Stowkey](https://github.com/XhienLee/stowkey) | Private online file storage, one key | Yes (PHP) |
| [Sendkey](https://github.com/XhienLee/sendkey) | Send a file through a link that works once | Yes (PHP + SQLite) |
| [Dropkey](https://github.com/XhienLee/dropkey) | A link anyone can use to send *you* a file privately | Yes (PHP + SQLite) |
| [Stashkey](https://github.com/XhienLee/stashkey) | Private notes you open with 6 words | Yes (PHP + SQLite) |
| [Stampkey](https://github.com/XhienLee/stampkey) | Prove a file is yours and unchanged | No |

## Credits

The word list, file format and crypto in `js/` come from [Sealkey](https://github.com/XhienLee/sealkey). The encryption library is [libsodium](https://github.com/jedisct1/libsodium.js) in `vendor/`. MIT licensed, free to use and change.
