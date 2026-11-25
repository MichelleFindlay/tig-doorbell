# TIG Door 🚪

A tiny PHP "door knocker" for the TIG event.

- **Visitors** open the door page (e.g. from a QR code on the door), enter their name, tick
  "I confirm I'm here for the TIG event" and tap **Knock**. Their screen then shows
  *"Alex is on the way!"* as soon as an organiser responds. If nobody responds within a minute, they can knock again.
- **Organisers** log in on their phones and enable notifications. Every knock sends a push
  alert with the visitor's name, note and time to every organiser's device. Tapping **I'm on my way**
  (in the notification or on the dashboard) tells the visitor someone is coming. Other organisers'
  alerts change to "Alex is getting the door".

No Composer and no external services: plain PHP + SQLite + standard Web Push (VAPID).

## Requirements

- Any PHP web hosting (cPanel, Plesk, etc.) with PHP **8.0+** and the `pdo_sqlite`, `openssl`, `curl`, `mbstring`
  extensions. These are on by default at most hosts.
- **HTTPS** on the subdomain (browsers only allow push notifications over HTTPS). Free Let's Encrypt SSL is fine.
- No SSH, Composer or database server needed.

## Install on a subdomain (single folder)

1. In your hosting panel, create the subdomain (e.g. `door.example.com`). Note the folder it points to,
   e.g. `public_html/door` or `door.example.com/`.
2. Switch on SSL for the subdomain.
3. Upload **the contents** of this project into that folder, so `index.php` sits directly inside it.
   Include the hidden `.htaccess` files; some FTP clients hide dotfiles by default.
4. Make sure the `data` folder is writable by PHP (permissions `755`, or `775` if 755 doesn't work).
5. Make a copy of `config.example.php` named **`config.php`** and edit it. It's ignored by git, so your
   settings stay private. Any option you leave out falls back to the example file's value. Set:
   - **`admin_access_word`**: set your own secret word (8+ characters). Setup won't run while it is still `change-me`.
   - Optionally the event name, timezone, and `vapid_subject` (a contact email for the push services).
6. Visit `https://door.example.com/organiser/`. On first run it checks the server and asks for the admin access
   word, then lets you create the first organiser account.
7. You're taken to **Team**, where you can add the other organisers.

### Admin access word

The `admin_access_word` in `config.php` must be entered to:
- create the first account on the setup page, and
- add or remove organisers on the **Team** page. Once entered, it stays unlocked for that login session
  until you tap *Lock team management*.

Organisers don't need it to log in, receive alerts, answer the door or change their own password.
Changing the word in `config.php` immediately re-locks every Team page that was unlocked.

The folder ends up looking like this:

```
door.example.com/
├── .htaccess            blocks config/data/src/bin from the web
├── index.php            visitor knock form          ← https://door.example.com/
├── status.php           visitor "someone's coming" page
├── sw.js                service worker (must stay in this top folder)
├── manifest.php         home-screen app info (name/colours from config)
├── config.php           your settings: copy of config.example.php (not web-accessible, not in git)
├── config.example.php   template with every option documented
├── organiser/           login, setup, dashboard, events, team ← https://door.example.com/organiser/
├── api/                 JSON endpoints
├── assets/              CSS, JS, icons
├── src/                 PHP code incl. theme.php colour schemes (not web-accessible)
├── data/                database + push keys (not web-accessible, must be writable)
└── bin/                 optional command-line tool
```

### Events (one-off and repeating)

Organisers manage events on **Organiser → Events**:

- **Event details:** name, location/room, and notes for visitors (shown on the door page).
- **When:** date, start and end time.
- **Repeats:** never, daily, weekdays, weekly, every 2 weeks, monthly on the same weekday (e.g. 2nd Tuesday),
  monthly on the last weekday (e.g. last Friday), or monthly on the same date.
- **Repeat until:** an optional end date. **Skip dates** cover holidays; you can also tap × on any upcoming
  session to cancel just that one.
- **Pause / Resume** hides an event without deleting it.

On the door page, visitors see the event that is on right now, with its time, location and notes, and confirm
they're here for it. If two events overlap, they pick one. Each knock records the event, and the organisers'
alert says "Here for <event>".

The door shows an event from `door_opens_before` minutes before it starts (default 60) until
`door_closes_after` minutes after it ends (default 30). When nothing is on, `allow_knock_without_event`
decides whether visitors can still knock for `event_name` (`true`) or see "no event right now" with the
next session date (`false`).

### Logo and colours

Everything is set in `config.php`:

- **`branding`**: `app_name`, a `logo` (plus an optional `logo_dark` version for dark mode), `logo_height`,
  `logo_link`, `favicon`, and `show_icons` to hide the emoji icons above page headings. Upload your logo into
  `assets/` and give its path, e.g. `'logo' => 'assets/logo.png'`, or use a full `https://` URL.
- **`theme`**:
  - `scheme`: a ready-made palette, one of `warm`, `ocean`, `forest`, `berry` or `slate`.
  - `mode`: the starting look. `auto` follows each person's phone setting; `light` or `dark` sets the default.
  - `allow_toggle`: shows a 🌓 button on every page that cycles Auto → Light → Dark. Each person's choice is
    remembered on their device.
  - `corner_radius`: how rounded cards and buttons are.
  - `light` / `dark`: override individual colours, e.g. `'light' => ['accent' => '#0057b8']` to use your
    brand colour on buttons.

Only plain colour values (hex, `rgb()`, `hsl()` or CSS colour names) are accepted. Anything else is ignored.

### How the private files are protected

- **Apache / LiteSpeed** (most shared hosts): the `.htaccess` files deny access to `config.php`, `src/`, `data/`
  and `bin/`, and turn off directory listings.
- **Hosts that ignore `.htaccess`** (nginx-only): `src/` files do nothing when loaded directly, and `config.php`
  outputs nothing. The database is created as `data/tig-door-<32 random hex chars>.sqlite`, so its URL can't be
  guessed. If your host lets you put files above the web folder, setting `db_path` in `config.php` to a path
  there is safer still.

### Keep your data

`data/` holds the database, including the push keys. **Don't delete or overwrite it when you upload
updates.** If it's lost, organisers must log in and enable notifications again.

### Optional: command line

If you do have SSH, you can add an organiser or reset a password with:

```bash
php bin/add-organiser.php alex "Alex"
```

Local testing: `php -S localhost:8000` from the project folder, then open http://localhost:8000/organiser/.
Note that the PHP built-in server ignores `.htaccess`.

## Using it

| Who        | URL                         |
|------------|-----------------------------|
| Visitors   | `https://your-host/`        |
| Organisers | `https://your-host/organiser/` |

Print a QR code of the visitor URL and stick it on the door.

**Organiser phones**
- *Android / desktop (Chrome, Edge, Firefox):* log in, tap **Enable notifications**, then **Send test alert**.
- *iPhone / iPad (iOS 16.4+):* open the site in Safari, then **Share → Add to Home Screen**. Open the app
  from the home screen, log in, and enable notifications. iOS only allows web push for home-screen apps.

Alerts keep arriving after the browser is closed. Logging out stops them on that device.

## How it works

```
Visitor form ──POST──▶ index.php ──▶ knocks table
                                 └─▶ Web Push (empty, VAPID-signed) ──▶ each organiser device
Organiser service worker wakes ──▶ api/latest.php (device token) ──▶ shows "🚪 Sam is at the door"
"I'm on my way" ──▶ api/ack.php ──▶ visitor's status page updates; other organisers' alerts update
```

The pushes carry no payload, so no message encryption library is needed. The service worker fetches the
details over HTTPS using a per-device token that is created at login.

## Troubleshooting

**500 error / blank page**
1. Set `'debug' => true` in `config.php` and reload. The page will show the actual error. Set it back to
   `false` afterwards.
2. Check the PHP version for the subdomain in your hosting panel. It must be 8.0 or newer.
3. Make sure `config.php` exists. Copy it from `config.example.php` if it doesn't.
4. Make sure `data/` is writable (permissions 755 or 775).
5. If even `debug` shows nothing, look at the error log in your hosting panel. A rejected `.htaccess` line
   shows up there as "… not allowed here". Delete that line from `.htaccess`.

## Privacy

Knocks store the visitor's name, optional note and IP address (used for rate limiting). They are
deleted automatically after `retention_days` (default 30).
