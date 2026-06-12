# Scarlet Beast Estate SSO

One identity across the whole estate. **poker.scarletbeast.com is the identity
authority.** A session minted there is shared with every other property.

## How it works

- **Shared session.** All Laravel apps use the `database` session driver against
  `poker_scarletbeast.sessions`, the same `APP_KEY`, the cookie name
  `sb_estate_session`, and the cookie domain `.scarletbeast.com`. So a login on
  poker is *the same session* on the console and networkedin.
  - poker.scarletbeast.com → owns the session.
  - poker.scarletbeast.com/console → `SESSION_CONNECTION=poker`, `App\Models\User`
    is bound to the `poker` connection (`poker_scarletbeast.users`).
  - poker.scarletbeast.com/networkedin → same app as poker.
- **Broker.** `GET /api/sso/whoami` returns the current user as JSON with
  credentialed CORS for any `*.scarletbeast.com` origin (see `config/cors.php`).
  Cross-stack properties (the static WordPress site, the Flarum forum) read it.
- **Widget.** `https://poker.scarletbeast.com/sb-sso.js` is a drop-in that calls
  the broker and reflects login state into any page (`[data-sso-name]`,
  `[data-sso-in]`, `[data-sso-out]`, or an auto-injected corner chip).
  Already injected into the static site via
  `scarletbeast.com/wp-content/mu-plugins/sb-sso.php`.

## Upstream identity providers (Google + GitHub)

Custom OAuth2 (not Socialite — it conflicts on Laravel 13). Routes:
`/auth/{google|github}/redirect` and `/auth/{google|github}/callback`.

**You must create the OAuth apps and paste credentials into `.env`:**

```
GOOGLE_CLIENT_ID=…          # Google Cloud Console → Credentials → OAuth client (Web)
GOOGLE_CLIENT_SECRET=…
GOOGLE_REDIRECT=https://poker.scarletbeast.com/auth/google/callback

GITHUB_CLIENT_ID=…          # GitHub → Settings → Developer settings → OAuth Apps
GITHUB_CLIENT_SECRET=…
GITHUB_REDIRECT=https://poker.scarletbeast.com/auth/github/callback
```

Authorized redirect URIs to register with each provider:
- Google: `https://poker.scarletbeast.com/auth/google/callback`
- GitHub: `https://poker.scarletbeast.com/auth/github/callback`

Until those are filled, the "Continue with Google/GitHub" buttons bounce back to
`/login?sso=unconfigured`. Username/password login works regardless.

## Remaining integration (forum)

The broker + CORS are live. To make **forum.scarletbeast.com (Flarum)** share the
login, add a Flarum SSO extension that trusts `/api/sso/whoami` (e.g. a small
custom extension or `fof/passport`-style bridge), and link each forum username to
its `/networkedin/u/{slug}` profile. The networkedin profile already exposes the
matching `forum_url`.
