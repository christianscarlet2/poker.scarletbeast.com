<?php

namespace App\Http\Controllers;

use App\Models\PokerTable;
use App\Models\Seat;
use App\Models\TableChat;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The table's voice box: talk, taunt, and launch theatrical ordnance. Poll
 * with ?after={last_id} — the same cadence the felt already polls at.
 */
class ChatController extends Controller
{
    /** Emote keys the felt knows how to perform. Targeted ones need a victim. */
    public const EMOTES = [
        // burst-at-seat expressions
        'happy', 'sad', 'angry', 'devil', 'embarrassed', 'love', 'hate',
        'laugh', 'cry', 'clown', 'fish', 'snake', 'fire', 'salt', 'sleep',
        'skull', 'gg', 'cheers', 'monkey', 'brain', 'rage',
        // projectiles — fly from sender to target seat
        'rocket', 'tomato', 'egg', 'kiss', 'punch',
    ];
    public const TARGETED = ['rocket', 'tomato', 'egg', 'kiss', 'punch'];

    public function index(Request $request, PokerTable $table)
    {
        $after = (int) $request->query('after', 0);
        $rows = TableChat::where('table_id', $table->id)
            ->when($after > 0, fn ($q) => $q->where('id', '>', $after))
            ->orderByDesc('id')->limit(60)->get()->reverse()->values();
        return response()->json([
            'messages' => $rows->map(fn ($m) => [
                'id' => $m->id,
                'kind' => $m->kind,
                'username' => $m->username,
                'body' => $m->body,
                'from_seat' => $m->from_seat,
                'target_seat' => $m->target_seat,
                'at' => $m->created_at?->toIso8601String(),
            ]),
            'emotes' => self::EMOTES,
            'targeted' => self::TARGETED,
        ]);
    }

    public function send(Request $request, PokerTable $table)
    {
        $user = $request->user();
        $key = "chat:{$user->id}";
        if (RateLimiter::tooManyAttempts($key, 8)) {
            return response()->json(['error' => 'Easy. The felt hears you — 8 messages per 10s.'], 429);
        }
        RateLimiter::hit($key, 10);

        $data = $request->validate([
            'text' => ['required_without:emote', 'nullable', 'string', 'max:140'],
            'emote' => ['required_without:text', 'nullable', 'in:' . implode(',', self::EMOTES)],
            'target_seat' => ['nullable', 'integer', 'min:1', 'max:9'],
        ]);

        $seat = Seat::where('table_id', $table->id)->where('user_id', $user->id)
            ->where('status', '!=', 'empty')->value('seat_no');

        if (!empty($data['emote'])) {
            $emote = $data['emote'];
            $target = $data['target_seat'] ?? null;
            if (in_array($emote, self::TARGETED, true)) {
                $occupied = $target && Seat::where('table_id', $table->id)
                    ->where('seat_no', $target)->where('status', '!=', 'empty')->exists();
                if (!$occupied) {
                    return response()->json(['error' => 'Pick a victim — that seat is empty.'], 422);
                }
            } else {
                $target = null;
            }
            $msg = TableChat::create([
                'table_id' => $table->id, 'user_id' => $user->id, 'username' => $user->username,
                'kind' => 'emote', 'body' => $emote, 'from_seat' => $seat, 'target_seat' => $target,
            ]);
        } else {
            $text = trim(preg_replace('/\s+/', ' ', $data['text']));
            if ($text === '') {
                return response()->json(['error' => 'Say something.'], 422);
            }
            $msg = TableChat::create([
                'table_id' => $table->id, 'user_id' => $user->id, 'username' => $user->username,
                'kind' => 'chat', 'body' => $text, 'from_seat' => $seat,
            ]);
        }

        // Opportunistic prune: keep each felt's transcript shallow.
        if ($msg->id % 100 === 0) {
            TableChat::where('table_id', $table->id)
                ->where('id', '<', $msg->id - 500)->delete();
        }
        return response()->json(['ok' => true, 'id' => $msg->id]);
    }
}
