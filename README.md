# Colorbox Google Media — Drive videos & Google Photos for WordPress

*[Polska wersja → README.pl.md](README.pl.md)*

A WordPress plugin that keeps videos off your server and photos one click away:

- **Upload a video from the block editor straight to your Google Drive** and embed it with a cover frame you pick.
- **Pick a video uploaded before** and embed it again — its cover is remembered.
- **Import photos from Google Photos** into the media library and insert them as a gallery.
- **Paste any Google Drive video link** on its own line and it becomes a player.

**Why:** video files are large for a small WordPress host and YouTube is not always the right home for them. Drive
already holds your videos, but its player shows a black first frame and connects every visitor to Google. This plugin
uploads from the browser directly to Drive (no PHP upload limit), shows your own cover image, and loads the Drive
player only when someone clicks play.

## What it does

| Feature | How |
|---|---|
| **Drive video** block | Pick a file → scrub to a frame → *Upload — this frame is the cover*. The browser sends the video straight to Google (resumable upload with a progress bar); the frame goes to the media library as a JPEG. The block becomes a normal Embed block. |
| Videos uploaded before | *Pick a video from Drive* lists the plugin's Drive folder. *Insert* reuses the remembered cover; *Other frame* downloads the video into the browser so you can pick again. |
| Click-to-play player | Cover + play button first; the Drive iframe (and any connection to Google) only after a click. Vertical phone videos get a 9:16 frame automatically. |
| **Google Photos** block | Opens Google's own photo picker in a new tab. Picked photos are copied into the media library (max 2560 px, JPEG — iPhone HEIC included), location and camera data removed, then inserted as a gallery. |
| Pasted Drive links | `drive.google.com/file/d/ID/view` (also `/preview`, `/edit`, `?usp=sharing`, `open?id=ID`) on its own line becomes the same player. Options after `#`: `vertical`, `cover=<attachment ID>`, e.g. `…/view#vertical&cover=123`. |
| Languages | English and Polish, following the WordPress language. |

## Requirements

- WordPress 6.5+, PHP 8.1+, pretty permalinks (any setting other than “Plain”), HTTPS.
- A Google account and a free Google Cloud project with your own OAuth client — see the
  [step-by-step Google Cloud setup](docs/en/google-cloud-setup.md) (about 15 minutes, once).

## Quick start

1. Download the ZIP from **Releases** (or build it: `bin/build-zip.sh`), then **Plugins → Add New → Upload Plugin →
   Activate**.
2. Follow the [Google Cloud setup](docs/en/google-cloud-setup.md): enable *Google Drive API* and *Photos Picker API*,
   publish the consent screen, create a *Web application* OAuth client with the redirect URI shown under
   **Settings → Google Media**.
3. **Settings → Google Media**: paste the client ID and secret, **Save**, **Connect with Google**.
4. In the editor add the **Drive video** or **Google Photos** block.

## Privacy and security

- **Scopes:** `drive.file` — the plugin sees only files it created itself, never the rest of your Drive;
  `photospicker.mediaitems.readonly` — only photos you pick in Google's window, for 60 minutes.
- **Only administrators** can upload or import (filter `colorbox_gm_capability`). A short-lived access token reaches
  the administrator's browser during uploads; editors — including AI-agent accounts — never get it.
- The OAuth client secret and refresh token are stored in the WordPress database (option `colorbox_gm_settings`),
  never in files. **Disconnect** revokes access at Google.
- Visitors do not connect to Google until they click play. Mention embedded Drive videos in your privacy policy.
- Imported photos have EXIF/XMP/IPTC removed (GPS, camera, dates); only the colour profile stays. If that fails, the
  photo is rejected.

## Limitations

- **Drive is not a streaming service.** A file watched by many people in a short time gets a temporary “too many
  users” block. Fine for a personal blog; for viral videos use YouTube or a video CDN.
- Right after upload Drive needs a few minutes to process a video; until then the player says so.
- The video list shows only videos this plugin uploaded (that is what `drive.file` allows). Other Drive videos still
  work as pasted links if shared as “Anyone with the link”.
- Videos picked in Google Photos are skipped.
- Unverified Google apps show an “unverified app” warning on connect; for your own account you can continue via
  *Advanced*. Publishing the app for other people's accounts would need Google's verification.

## Hooks

| Hook | Use |
|---|---|
| `colorbox_gm_capability` (filter) | Capability required to upload/import. Default `manage_options`. |
| `colorbox_gm_folder_name` (filter) | Name of the Drive folder created on first upload. |
| `colorbox_gm_embed_html` (filter) | Final HTML of the player. |
| `colorbox_mcp_instructions` (filter) | If [Colorbox MCP](https://github.com/szmidtpiotr/colorbox-mcp) is active, its AI agent learns how to embed Drive videos. |

## Repository layout

```
colorbox-google-media.php   plugin entry point
includes/                   google.php (OAuth, API), settings.php, drive.php, embed.php, photos.php
assets/js/                  the two editor blocks (plain JS, no build step)
languages/                  Polish translation (.po/.mo/.json/.l10n.php) and the .pot template
docs/                       Google Cloud setup (en/, pl/)
bin/build-zip.sh            builds the installable ZIP
```

## Status

Version 0.1.0, grown out of a must-use plugin on a live site (WordPress 7.1, PHP 8.3), where Drive uploads and
embeds are in daily use. Not there yet: screenshots in the docs, cleanup of settings on uninstall, choosing an existing
Drive folder (would need Google's folder picker).

## License

[GPL-2.0-or-later](LICENSE), like WordPress itself.
