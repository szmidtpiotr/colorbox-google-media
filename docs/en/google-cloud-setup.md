# Google Cloud setup (once, about 15 minutes)

*[Polska wersja → ../pl/konfiguracja-google-cloud.md](../pl/konfiguracja-google-cloud.md)*

The plugin talks to Google with **your own** OAuth client, so nobody else's app ever touches your Drive or Photos.
Google Cloud is free for this use. Menu names below are from the Google Cloud console in late 2026; Google renames
things now and then, so look for the closest match.

## 1. Project and APIs

1. Open [console.cloud.google.com](https://console.cloud.google.com/) and create a project (any name, e.g.
   “my-site-media”).
2. **APIs & Services → Library**: search for and **Enable** both
   - **Google Drive API** (video upload and the video list),
   - **Photos Picker API** (Google Photos import).

## 2. Consent screen (Google Auth Platform)

Open **Google Auth Platform** (APIs & Services → OAuth consent screen leads there).

1. **Branding**: app name (e.g. your site's name), user support e-mail, developer contact e-mail. Add your site under
   *Authorized domains* and a link to your privacy policy.
   **Do not upload a logo** — a logo triggers Google's brand verification, which takes days.
2. **Audience**: user type **External**. Then **Publish app** (status *In production*).
   - Why: in *Testing* mode Google lets in only listed test users **and expires the connection after 7 days**.
   - If *Publish app* is greyed out, Branding is incomplete.
   - Want to try it before publishing? Add your Google address under *Test users*; you will have to reconnect weekly.
3. **Data access** (recommended): *Add or remove scopes* → add
   `.../auth/drive.file` and `.../auth/photospicker.mediaitems.readonly`.

## 3. OAuth client

1. **Clients → Create client**, application type **Web application**, any name.
2. *Authorized JavaScript origins*: leave empty.
3. *Authorized redirect URIs* → **Add URI** → paste the address shown in WordPress under
   **Settings → Google Media** (it looks like `https://your-site/wp-json/colorbox-google-media/v1/oauth-callback`).
   It must match exactly — no trailing slash.
4. Leave “Use this client for an AI agent” unticked. **Create**.
5. Copy the **Client ID** and **Client secret** now (or download the JSON) — the console shows the full secret only
   at creation time.

## 4. Connect in WordPress

1. **Settings → Google Media**: paste the client ID and secret, **Save**.
2. **Connect with Google**, choose your account.
3. “Google hasn't verified this app”: click **Advanced → Go to … (unsafe)**. It is your own app; the developer
   e-mail on that screen is yours.
4. On the consent screen **tick both checkboxes** (Drive files and Google Photos) — Google sometimes leaves them
   unticked — and **Continue**.
5. Back in WordPress you should see *Connected*, *Google Drive: access granted*, *Google Photos: access granted*.

## Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| `Error 403: access_denied`, “app is being tested” | Consent screen still in *Testing* and you are not a test user | Publish the app (step 2.2) or add yourself as a test user. |
| `Error 500` on Google's page right after setup | Google is still propagating fresh settings (“5 minutes to a few hours”) | Wait ~10 minutes, then click **Connect with Google** again in WordPress (do not reload Google's error page). |
| `redirect_uri_mismatch` | The redirect URI differs from the one in WordPress, or the change has not propagated yet | Copy the address from Settings → Google Media again; wait a few minutes. |
| “Returned from Google without a valid session” | More than 15 minutes passed, or the page was reloaded | Click **Connect with Google** again. |
| Worked for a week, then “Google did not issue a token (invalid_grant)” | App left in *Testing* mode | Publish the app, then reconnect. |
| *Google Photos: no access* | Photos Picker API not enabled, or the Photos checkbox was left unticked | Enable the API, then **Reconnect** and tick both boxes. |
| “Google returned no refresh token” | An old grant exists | Remove the app at [myaccount.google.com/permissions](https://myaccount.google.com/permissions), connect again. |
