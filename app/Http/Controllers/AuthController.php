<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class AuthController extends Controller
{
    public function showLogin()
    {
        return view('poker', ['route' => 'login']);
    }

    public function register(Request $request)
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'min:3', 'max:24', 'regex:/^[A-Za-z0-9_]+$/', Rule::unique('users', 'username')],
            'email' => ['nullable', 'email', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8'],
        ]);

        $user = User::create([
            'username' => $data['username'],
            'name' => $data['username'],
            'email' => $data['email'] ?? null,
            'password' => Hash::make($data['password']),
            'chips' => 0,
            'avatar' => $this->randomGlyph(),
        ]);

        Auth::login($user, true);
        $request->session()->regenerate();
        return response()->json(['ok' => true, 'user' => $this->me($user)]);
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('username', $data['username'])->where('is_bot', false)->first();
        if (!$user || !Hash::check($data['password'], $user->password)) {
            return response()->json(['error' => 'The beast does not know you. Wrong name or key.'], 422);
        }

        // Admins must pass a second factor (Google Authenticator). Players don't.
        if ($user->is_admin) {
            require_once '/var/www/sb-shared/totp.php';
            $request->session()->regenerate();
            $request->session()->put('2fa_user_id', $user->id);
            $rec = \SbTotp::load('poker', $user->username);
            if (!$rec || empty($rec['confirmed'])) {
                $secret = $rec['secret'] ?? \SbTotp::secret();
                \SbTotp::save('poker', $user->username, $secret, false);
                return response()->json(['ok' => true, 'twofa' => [
                    'step' => 'enroll', 'secret' => $secret, 'uri' => \SbTotp::uri($secret, $user->username, 'Scarlet Beast Poker'),
                ]]);
            }
            return response()->json(['ok' => true, 'twofa' => ['step' => 'verify']]);
        }

        Auth::login($user, true);
        $request->session()->regenerate();
        return response()->json(['ok' => true, 'user' => $this->me($user)]);
    }

    public function twofa(Request $request)
    {
        $data = $request->validate(['code' => ['required', 'string']]);
        $user = User::find($request->session()->get('2fa_user_id'));
        if (!$user || !$user->is_admin) {
            return response()->json(['error' => 'Start at the gate.'], 419);
        }
        require_once '/var/www/sb-shared/totp.php';
        $rec = \SbTotp::load('poker', $user->username);
        if (!$rec || !\SbTotp::verify($rec['secret'], $data['code'])) {
            return response()->json(['error' => 'Wrong or expired code.'], 422);
        }
        if (empty($rec['confirmed'])) \SbTotp::save('poker', $user->username, $rec['secret'], true);
        Auth::login($user, true);
        $request->session()->regenerate();
        $request->session()->forget('2fa_user_id');
        return response()->json(['ok' => true, 'user' => $this->me($user)]);
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return response()->json(['ok' => true]);
    }

    private function me(User $user): array
    {
        return [
            'id' => $user->id,
            'username' => $user->username,
            'chips' => $user->chips,
            'is_admin' => $user->is_admin,
            'avatar' => $user->avatar,
        ];
    }

    private function randomGlyph(): string
    {
        $g = ['☠️', '🩸', '🔥', '⛧', '👁️', '🐍', '🃏', '♠️', '♣️', '⚰️', '🦇', '🕷️'];
        return $g[array_rand($g)];
    }
}
