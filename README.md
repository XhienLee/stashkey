# Stashkey

Private notes for passwords, recovery codes and short secrets, under 6 words. Your browser locks everything before it is saved, so the server can't read your notes.

Part of the key family, small private tools with one key each and no accounts, next to [Sealkey](https://github.com/XhienLee/sealkey) and [Stowkey](https://github.com/XhienLee/stowkey).

## How it works

1. **Your key:** 6 random words from Sealkey's word list (about 77 bits). Stashkey shows them once when you make a stash.
2. **Two keys from one:** the words go through Argon2id (3 passes, 64 MiB) into a master key. From it come a **lookup id** that names your stash on the server and a separate **encryption key**. Knowing the lookup id does not give the encryption key.
3. **Saving:** all your notes become one blob: JSON, padded to 1 KiB steps so its size says little, then locked with XChaCha20-Poly1305. The server keeps the blob and a version number.
4. **Two tabs:** a save must name the version it started from. If another tab or device saved first, the second save is refused and that copy turns read-only, so notes are never silently overwritten.
5. **Logging out** wipes the key and the notes from the page. "Delete my whole stash" erases the blob from the server and frees your words.

## Run it

It needs PHP 8.1+ with `pdo_sqlite`. Try it on your computer:

```bash
FAMILY_DATA_DIR=/tmp/stashkey-data php -S 127.0.0.1:8800 router.php
```

Then open <http://127.0.0.1:8800>. `router.php` only adds the Content-Security-Policy that `_headers` gives on Netlify (Netlify can't run PHP). For real hosting use Apache or nginx with PHP-FPM and HTTPS, and keep `FAMILY_DATA_DIR` **outside the web root** (the API refuses to run otherwise).

| Setting (environment variable) | Default | Meaning |
|---|---|---|
| `FAMILY_DATA_DIR` | `family_data` next to the repo folder | Database |
| `STASHKEY_NEW_PER_IP_HOUR` | 5 | New stashes one address can make per hour |
| `STASHKEY_NEW_GLOBAL_HOUR` | 100 | New stashes the whole site can make per hour |
| `STASHKEY_MAX_STASHES` | 10000 | Stashes on one server |
| `STASHKEY_SAVES_PER_IP_HOUR`, `STASHKEY_SAVES_GLOBAL_HOUR` | 600, 20000 | Saves per hour |
| `STASHKEY_OPEN_PER_IP_HOUR`, `STASHKEY_OPEN_GLOBAL_HOUR` | 120, 5000 | Opens per hour |

A stash can hold up to 256 KiB.

## What it cannot protect

- Lost words can't be recovered. Nobody can read or reset a stash without them.
- The server sees when a stash changes and its size to the nearest KiB.
- Anyone who steals the database can try to guess 6-word keys offline. Each guess costs 64 MiB and three Argon2id passes, and there are about 2^77 of them.
- It can't protect you from a hacked device, a changed copy of this website, or shoulder surfing while a note is on screen.

## Tests

```bash
node --test tests/stashkey.test.mjs
php tests/api.php
pip install -r tests/requirements.txt && python3 -m playwright install chromium
python3 tests/e2e.py
```

They check the crypto (padding, tampering, wrong words), every server rule (versions, size limits, rate limits, deleting), and the whole flow in Chromium, including that note text never goes over the network readable.

## Credits

The word list and key handling in `js/` come from [Sealkey](https://github.com/XhienLee/sealkey), and the encryption library is [libsodium](https://github.com/jedisct1/libsodium.js) in `vendor/`. MIT licensed.
