# MlSignup – privacy-friendly MailerLite signup for Joomla

A Joomla system plugin that adds a newsletter signup form for [MailerLite](https://www.mailerlite.com/) **without loading any third-party scripts, fonts or captchas** on your pages.

The form posts to your own site. Your server then calls the MailerLite API with your API token. A visitor's browser only talks to your domain; MailerLite receives data only when someone actually submits the form.

- **Plugin:** `plg_system_mlsignup` (element `mlsignup`, group `system`)
- **Current version:** 1.0.5
- **License:** GNU General Public License version 2 or later

---

## Features

- Shortcode `{mlsignup}` renders the form in articles and in Custom modules.
- No external requests from the page: no MailerLite universal script, no Google reCAPTCHA, no web fonts.
- The API token never leaves the server.
- Spam and abuse protection that needs no external service:
  - honeypot field
  - signed, single-use proof-of-work challenge (SHA-256, solved in the browser)
  - rate limit per IP, a looser limit on all attempts per IP, and a limit per e-mail address
- Works with Joomla's page cache (no CSRF token is embedded in the cached HTML).
- Consent checkbox with a link to your privacy policy; double opt-in handled by MailerLite.
- By default the same neutral success message is shown for new and existing subscribers (no account enumeration). Optionally the form can tell people that their address is already subscribed.
- Translations: German (de-DE) and English (en-GB).
- Layout can be overridden in your template.

## Requirements

- Joomla 5 or 6 (developed for and running on Joomla 6.2; Joomla 5 is expected to work but has not been tested)
- PHP 8.1 or newer
- A MailerLite account (new MailerLite, with API access)
- Outgoing HTTPS from your server to `connect.mailerlite.com` (cURL or the PHP stream transport)
- JavaScript enabled in the visitor's browser, in a secure context (HTTPS), because the proof-of-work uses `crypto.subtle`

---

## Installation

1. In the Joomla administrator go to **System → Install → Extensions** and upload `plg_system_mlsignup.zip`.
2. Go to **System → Plugins**, find **System - MailerLite Newsletter Signup** and enable it.
3. Open the plugin and fill in the settings (see below). Save.
4. Put `{mlsignup}` where the form should appear.

Updating: install the new ZIP over the existing one. Your settings are kept.

If you previously used the MailerLite universal script or an embedded form, remove it. This plugin does not need it.

---

## MailerLite setup

### 1. API token

Create a token in MailerLite under **Integrations → MailerLite API → Generate new token**.

- The token is long (several hundred characters or more). Paste it completely.
- Tokens from the old "Classic" MailerLite do not work.
- The token has full access to the account. Keep your Joomla backend secure and never share the token. If it was exposed, revoke it in MailerLite and create a new one.

### 2. Double opt-in

In your MailerLite account settings, enable **double opt-in for API and integrations** (the exact label can differ; look in the subscribe settings). Without it, subscribers added through the API are active immediately and no confirmation e-mail is sent.

After setup, make a test signup and check that the confirmation e-mail arrives.

### 3. Group ID (optional)

To add new subscribers to a group, you need the group's ID. Query it through the API:

```bash
curl -s https://connect.mailerlite.com/api/groups \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json"
```

Each group is listed with its `id` and `name`. Enter the ID in the plugin settings. Leave the field empty to create subscribers without a group.

---

## Plugin settings

| Setting | Description |
|---|---|
| **MailerLite API token** | Token from MailerLite (see above). Stored in the plugin parameters in the database. Accepts up to 4000 characters. |
| **Group ID (optional)** | New subscribers are added to this group. |
| **Privacy policy URL** | Linked in the consent checkbox. Joomla's URL filter removes unsafe schemes such as `javascript:` when saving. |
| **Show name field** | Show or hide the optional name field. |
| **Message for addresses that are already subscribed** | Off (default): always the same neutral success message. On: the plugin asks MailerLite whether the address already exists and shows a dedicated message (see below). Note that this lets anyone check from outside whether an address is on your list. |
| **Signups per IP and hour** | Strict limit before the MailerLite call. Default: 5. |

---

## Usage

### In an article

Write `{mlsignup}` into the article text on its own line.

### In a module

Create a **Custom** module, put `{mlsignup}` into its content and enable **Prepare Content** in the module's options. Without that option, content plugins do not run and the placeholder is shown as text.

You can use the shortcode more than once per page. Each form gets its own field IDs.

---

## How it works

1. The visitor submits the form. JavaScript (`media/plg_system_mlsignup/js/mlsignup.js`) first requests a challenge: `POST index.php` with `mlsignup=challenge`.
2. The server answers with a random salt, a hash and an HMAC signature. The secret number is hidden; the browser has to find it by trying numbers up to 30,000.
3. The browser solves the challenge with `crypto.subtle.digest('SHA-256', …)` and sends the form plus the solved challenge: `POST index.php` with `mlsignup=subscribe`.
4. The plugin handles both requests in `onAfterInitialise`, before routing, and answers with JSON. Because the request ends there, Joomla's page cache never stores it.
5. On success the plugin calls `POST https://connect.mailerlite.com/api/subscribers` with the e-mail address, the optional name and the optional group.
6. MailerLite sends the double opt-in e-mail.

### Order of checks on the server

1. Honeypot filled? Pretend success, do nothing.
2. E-mail address valid (and at most 254 characters)? Consent checkbox ticked?
3. Attempt limit per IP (see table below).
4. Challenge valid? (Format, size, expiry, signature, hash, single use.)
5. Signup limit per IP.
6. Optional (setting on): look the address up at MailerLite. Already subscribed? Show the "already subscribed" message and stop (this does not count towards the per-address limit).
7. Limit per e-mail address. If exceeded, the normal success message is shown but nothing is sent.
8. MailerLite API call.

### Message for addresses that are already subscribed

With the setting **Message for addresses that are already subscribed** switched on, the plugin calls `GET https://connect.mailerlite.com/api/subscribers/{email}` before adding the address:

| MailerLite says | What the visitor sees |
|---|---|
| Subscriber is active and (no group configured, or already in the configured group) | "This email address is already subscribed to the newsletter." Nothing is sent to MailerLite. |
| Subscriber is active but not yet in the configured group | The address is added to the group and the visitor sees "You are now subscribed to the newsletter." No confirmation e-mail follows, because the address is already confirmed. |
| Not found, unconfirmed, unsubscribed, bounced or junk | Normal flow with the usual "check your inbox" message. |
| Lookup fails (network error, unexpected answer) | Normal flow; the error is logged. |

Both messages can be changed with language overrides (`PLG_SYSTEM_MLSIGNUP_ALREADY`, `PLG_SYSTEM_MLSIGNUP_ADDED`).

Privacy trade-off: with this setting on, anyone who passes the spam protection can find out whether a specific address is subscribed. The IP limits slow this down but do not prevent it. Keep the setting off if that matters to you.

---

## Spam and abuse protection

| Protection | Value |
|---|---|
| Honeypot field | Field `website`, hidden off-screen |
| Challenge validity | 10 minutes |
| Challenge difficulty | secret number between 0 and 30,000 (SHA-256) |
| Challenge reuse | Not possible (atomic marker file) |
| Max. payload size | 2048 characters before decoding |
| Attempts per IP | 4 × the signup limit per hour (default 20) |
| Signups per IP | Setting "Signups per IP and hour" (default 5) |
| Signups per e-mail address | 3 per 24 hours |
| Storage failure | Fail closed: the request is refused |

Notes:

- The proof-of-work is a speed bump for simple bots, not a real captcha. A determined attacker can solve it. The real protection is the combination with double opt-in and the rate limits.
- Rate limits use `REMOTE_ADDR`. Behind a reverse proxy or CDN that hides client IPs, all visitors share one IP and therefore one limit.
- The per-address limit stops flooding one mailbox with confirmation e-mails from many IPs.

---

## Data and privacy

**On your site's visitors:** the page makes no requests to MailerLite or any other third party. The signup form itself only contacts your own domain.

**On submit:** the e-mail address and the optional name are sent from your server to MailerLite. With the optional "already subscribed" message the address is additionally sent in a lookup request before that. Sign a data processing agreement (DPA) with MailerLite and mention the service in your privacy policy.

**Stored locally** in `cache/plg_system_mlsignup/` (only hashes, no readable personal data):

| File prefix | Content | Kept for |
|---|---|---|
| `u_…` | Empty marker for a used challenge | about 11 minutes |
| `a_…`, `r_…` | Counter for an IP (HMAC with your Joomla secret) | up to about 1 hour |
| `e_…` | Counter for an e-mail address (HMAC with your Joomla secret) | up to about 24 hours |

The folder gets an `index.html` and a `.htaccess` that denies web access. Expired files are removed automatically (about once per 20 signup requests).

**Consent text:** the checkbox text is in the language files and is based on a typical double opt-in wording. This is not legal advice. Have the text and your privacy policy checked for your situation.

---

## Customization

### Layout

Copy `plugins/system/mlsignup/tmpl/default.php` to

```
templates/YOUR_TEMPLATE/html/plg_system_mlsignup/default.php
```

and edit it there. Keep the form class `mlsignup__form`, the `.mlsignup__status` element, the `data-msg-*` attributes and the field names (`email`, `name`, `website`, `consent`), because the JavaScript and the server use them.

### Texts

Change texts with **System → Language Overrides**. The keys start with `PLG_SYSTEM_MLSIGNUP_`, for example `PLG_SYSTEM_MLSIGNUP_CONSENT` (contains `%s` for the privacy policy link) or `PLG_SYSTEM_MLSIGNUP_SUCCESS`.

### Styling

The stylesheet `media/plg_system_mlsignup/css/mlsignup.css` is intentionally minimal and uses the BEM-style classes `mlsignup`, `mlsignup__field`, `mlsignup__consent`, `mlsignup__hint`, `mlsignup__button` and `mlsignup__status`. Override them in your template CSS. The submit button also carries the Bootstrap classes `btn btn-primary`.

---

## Troubleshooting

Errors from the MailerLite API are written to the Joomla log folder (see **System → Global Configuration → Server → Path to Log Folder**) in the file `mlsignup.php`.

| Symptom | Cause and fix |
|---|---|
| "Signing up is currently not possible" and log shows `HTTP 401` | Token is wrong, revoked or truncated. Paste it again completely (the field accepts up to 4000 characters), or create a new one. |
| Same message, no log entry | The API token field is empty, or the request fails before reaching the plugin. Check the browser's network tab (F12): both requests to `index.php` should return JSON. |
| "Already subscribed" message never appears | The setting is off, or the lookup fails. Look for `MailerLite lookup` entries in the log. Note that only active subscribers are reported; unconfirmed or unsubscribed addresses go through the normal flow. |
| Same message, log shows `MailerLite request failed` | The server cannot reach `connect.mailerlite.com`. Check cURL/streams and firewall rules. |
| "Please enter a valid email address" for a valid address | MailerLite answered with HTTP 422. Often a wrong group ID. Check the log. |
| "Too many attempts" | One of the rate limits was reached. Wait, or raise the limit in the plugin settings. Behind a proxy all visitors share one IP. |
| "The security check failed" | Challenge expired (page open for more than 10 minutes), reused, or the browser blocked `crypto.subtle` (not HTTPS). Reload the page. |
| Page error `UnknownAssetException … plg_system_mlsignup.form` | Fixed in 1.0.1 (plugin assets are registered by the plugin). Update the plugin. |
| Placeholder `{mlsignup}` shown as text | The content is not processed by content plugins. In a Custom module enable **Prepare Content**. |
| No confirmation e-mail | Double opt-in for the API is not enabled in MailerLite, or the mail landed in spam. |
| Requests go to the wrong host | The form posts to `Uri::base() . 'index.php'`. Make sure your site URL does not redirect between `http`/`https` or `www`/non-`www`, as redirects can drop the POST body. |

---

## File structure

```
plg_system_mlsignup/
├── mlsignup.xml                 Manifest and plugin parameters
├── services/provider.php        Joomla service provider
├── src/Extension/MlSignup.php   Plugin class (form, endpoint, challenge, limits, API call)
├── tmpl/default.php             Form layout
├── language/
│   ├── de-DE/                   German strings
│   └── en-GB/                   English strings
└── media/
    ├── joomla.asset.json        Web asset registry
    ├── js/mlsignup.js           Form handling and proof-of-work solver
    └── css/mlsignup.css         Minimal styles
```

Namespace: `MlSignup\Plugin\System\MlSignup`

---

## Security notes

- All user input is validated on the server; output is escaped; the API URL is fixed (HTTPS, no user-controlled target).
- The challenge is signed with an HMAC key derived from your Joomla `secret`. Changing the secret invalidates open challenges only.
- Log messages from the API are stripped of control characters and line breaks.
- The plugin has been tested with a stub harness (challenge, replay, expiry, parallel rate-limit counting, cleanup, log sanitising) and by hand on a live Joomla 6.2 site. It has not been through an independent security audit. Use at your own risk.

---

## Changelog

**1.0.5**
- Optional message for addresses that are already subscribed (setting is off by default), plus a separate message when an active subscriber is only added to the group

**1.0.4**
- Consent checkbox is now hidden together with the rest of the form after a successful signup (CSS `display: flex` overrode the `hidden` attribute)
- README included in the ZIP

**1.0.3**
- Attempt limit per IP checked before the challenge is verified, so junk requests create no marker files
- Rate-limit counters are atomic under parallel requests and fail closed
- New limit per e-mail address (3 per 24 hours)
- Challenge markers are removed after about 11 minutes; cleanup rewritten
- `.htaccess` and `index.html` in the storage folder
- Payload length limit (2048); log messages sanitised

**1.0.2**
- API token field accepts up to 4000 characters (Joomla's password field cut it at 99)

**1.0.1**
- Plugin registers its own web asset file (fixes `UnknownAssetException`)

**1.0.0**
- First release

---

## License

GNU General Public License version 2 or later.

MailerLite is a trademark of its owner. This plugin is not affiliated with or endorsed by MailerLite.
