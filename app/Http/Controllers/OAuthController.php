<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Single sign-on for the whole estate. poker.scarletbeast.com is the identity
 * authority; a session minted here is shared across every *.scarletbeast.com
 * property via a .scarletbeast.com cookie and a common database session store.
 * Google and GitHub are the upstream identity providers (standard OAuth2/OIDC,
 * implemented directly — no Socialite, which conflicts on Laravel 13).
 */
class OAuthController extends Controller
{
    private const PROVIDERS = [
        'google' => [
            'auth' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token' => 'https://oauth2.googleapis.com/token',
            'user' => 'https://openidconnect.googleapis.com/v1/userinfo',
            'scope' => 'openid email profile',
        ],
        'github' => [
            'auth' => 'https://github.com/login/oauth/authorize',
            'token' => 'https://github.com/login/oauth/access_token',
            'user' => 'https://api.github.com/user',
            'emails' => 'https://api.github.com/user/emails',
            'scope' => 'read:user user:email',
        ],
    ];

    public function redirect(Request $request, string $provider)
    {
        abort_unless(isset(self::PROVIDERS[$provider]), 404);
        $cfg = config("services.$provider");
        if (empty($cfg['client_id'])) {
            return redirect('/login?sso=unconfigured&p=' . $provider);
        }

        $state = Str::random(40);
        $request->session()->put('oauth_state', $state);
        $request->session()->put('oauth_return', $request->query('return', '/'));

        $params = http_build_query([
            'client_id' => $cfg['client_id'],
            'redirect_uri' => $cfg['redirect'],
            'response_type' => 'code',
            'scope' => self::PROVIDERS[$provider]['scope'],
            'state' => $state,
            'allow_signup' => 'true',
        ]);

        return redirect(self::PROVIDERS[$provider]['auth'] . '?' . $params);
    }

    public function callback(Request $request, string $provider)
    {
        abort_unless(isset(self::PROVIDERS[$provider]), 404);
        $p = self::PROVIDERS[$provider];
        $cfg = config("services.$provider");

        if ($request->query('state') !== $request->session()->pull('oauth_state')) {
            return redirect('/login?sso=badstate');
        }
        if (!$request->filled('code')) {
            return redirect('/login?sso=denied');
        }

        // 1. exchange code → access token
        $tokenRes = Http::asForm()->acceptJson()->post($p['token'], [
            'client_id' => $cfg['client_id'],
            'client_secret' => $cfg['client_secret'],
            'code' => $request->query('code'),
            'redirect_uri' => $cfg['redirect'],
            'grant_type' => 'authorization_code',
        ]);
        $token = $tokenRes->json('access_token');
        if (!$token) {
            return redirect('/login?sso=notoken');
        }

        // 2. fetch the identity
        $ures = Http::withToken($token)->acceptJson()
            ->withHeaders(['User-Agent' => 'ScarletBeast-SSO'])->get($p['user']);
        $u = $ures->json();

        $email = $u['email'] ?? null;
        if (!$email && $provider === 'github') {
            $emails = Http::withToken($token)->acceptJson()
                ->withHeaders(['User-Agent' => 'ScarletBeast-SSO'])->get($p['emails'])->json();
            foreach ((array) $emails as $e) {
                if (!empty($e['primary']) && !empty($e['verified'])) { $email = $e['email']; break; }
            }
        }
        if (!$email) {
            return redirect('/login?sso=noemail');
        }

        $name = $u['name'] ?? ($u['login'] ?? Str::before($email, '@'));
        $avatar = $u['picture'] ?? ($u['avatar_url'] ?? null);

        // 3. find-or-create the estate identity, then log in
        $user = $this->resolveUser($provider, (string) ($u['sub'] ?? $u['id'] ?? ''), $email, $name, $avatar);
        Auth::login($user, true);
        $request->session()->regenerate();

        $return = $request->session()->pull('oauth_return', '/');
        return redirect()->away($this->safeReturn($return));
    }

    private function resolveUser(string $provider, string $providerId, string $email, string $name, ?string $avatar): User
    {
        $user = User::where('email', $email)->first();
        if (!$user) {
            $base = Str::slug(Str::before($email, '@'), '_') ?: 'creator';
            $username = $base;
            $n = 1;
            while (User::where('username', $username)->exists()) {
                $username = $base . (++$n);
            }
            $user = User::create([
                'username' => $username,
                'name' => $name,
                'email' => $email,
                'password' => bcrypt(Str::random(40)),
                'avatar' => $this->emojiFor($username),
                'email_verified_at' => now(),
            ]);
        }

        // record the linked provider id (json column oauth on users, added by migration)
        $links = $user->oauth ?? [];
        $links[$provider] = ['id' => $providerId, 'avatar' => $avatar];
        $user->oauth = $links;
        if (!$user->email_verified_at) {
            $user->email_verified_at = now();
        }
        $user->save();

        return $user;
    }

    private function emojiFor(string $seed): string
    {
        $set = ['🤖', '👁', '🐍', '⛧', '🜏', '🦾', '🧠', '♠', '♣', '♥', '♦', '🎰'];
        return $set[crc32($seed) % count($set)];
    }

    private function safeReturn(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if ($host === null) {
            return url($url);                       // relative path on this host
        }
        if ($host && str_ends_with($host, 'scarletbeast.com')) {
            return $url;                            // any estate property
        }
        return url('/');
    }

    /**
     * SSO broker: who is the bearer of the shared .scarletbeast.com session?
     * The static site and the forum call this cross-origin to resolve identity.
     */
    public function whoami(Request $request)
    {
        // CORS (credentialed, estate-origin reflection) is handled by the
        // framework HandleCors middleware via config/cors.php on api/sso/*.
        $u = Auth::user();

        return response()->json([
            'authenticated' => (bool) $u,
            'user' => $u ? [
                'username' => $u->username,
                'name' => $u->name,
                'email' => $u->email,
                'avatar' => $u->avatar,
                'is_admin' => (bool) $u->is_admin,
            ] : null,
        ]);
    }
}
