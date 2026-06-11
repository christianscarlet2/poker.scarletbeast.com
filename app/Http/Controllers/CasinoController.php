<?php

namespace App\Http\Controllers;

use App\Casino\Blackjack;
use App\Casino\Craps;
use App\Casino\Rng;
use App\Casino\Roulette;
use App\Casino\Slots;
use App\Casino\VideoPoker;
use App\Models\CasinoRound;
use App\Services\Bankroll;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The pit beyond the felt: roulette (US/EU), blackjack, craps, video poker,
 * slots. Every round is seeded provably fair (seed revealed at settle),
 * debits the bankroll up front, and pays through the ledger.
 */
class CasinoController extends Controller
{
    private const MIN_BET = 10;        // $0.10
    private const MAX_BET = 50000;     // $500

    /** One-shot games: roulette spins and slot pulls. */
    public function play(Request $request)
    {
        $data = $request->validate([
            'game' => ['required', 'in:roulette_us,roulette_eu,slots'],
            'bets' => ['required_if:game,roulette_us,roulette_eu', 'array', 'max:24'],
            'bets.*.type' => ['required_with:bets', 'string', 'max:12'],
            'bets.*.selection' => ['nullable', 'string', 'max:4'],
            'bets.*.amount' => ['required_with:bets', 'integer', 'min:' . self::MIN_BET, 'max:' . self::MAX_BET],
            'amount' => ['required_if:game,slots', 'integer', 'min:' . self::MIN_BET, 'max:' . self::MAX_BET],
        ]);
        $user = $request->user();
        $game = $data['game'];
        $seed = Rng::freshSeed();

        return DB::transaction(function () use ($user, $game, $seed, $data) {
            if ($game === 'slots') {
                $bet = (int) $data['amount'];
                Bankroll::adjust($user->id, -$bet, 'casino_bet', 'Beast Reels pull');
                $res = Slots::spin(new Rng($seed), $bet);
                if ($res['paid'] > 0) {
                    Bankroll::adjust($user->id, $res['paid'], 'casino_win', "Beast Reels: {$res['label']}");
                }
                $round = CasinoRound::create([
                    'user_id' => $user->id, 'game' => $game, 'seed' => $seed,
                    'outcome' => $res, 'wagered' => $bet, 'paid' => $res['paid'], 'status' => 'settled',
                ]);
                return response()->json(['ok' => true, 'round_id' => $round->id, 'seed' => $seed, 'result' => $res, 'chips' => $user->fresh()->chips]);
            }

            // Roulette: validate the book, debit the total, spin once.
            $bets = $data['bets'];
            foreach ($bets as $b) {
                if (!Roulette::validBet($game, $b['type'], (string) ($b['selection'] ?? ''))) {
                    throw new \RuntimeException("Bad bet: {$b['type']}");
                }
            }
            $total = array_sum(array_column($bets, 'amount'));
            Bankroll::adjust($user->id, -$total, 'casino_bet', 'Roulette spin');
            $res = Roulette::spin($game, $bets, new Rng($seed));
            if ($res['total_paid'] > 0) {
                Bankroll::adjust($user->id, $res['total_paid'], 'casino_win', "Roulette: {$res['pocket']}");
            }
            $round = CasinoRound::create([
                'user_id' => $user->id, 'game' => $game, 'seed' => $seed,
                'outcome' => $res, 'wagered' => $total, 'paid' => $res['total_paid'], 'status' => 'settled',
            ]);
            return response()->json(['ok' => true, 'round_id' => $round->id, 'seed' => $seed, 'result' => $res, 'chips' => $user->fresh()->chips]);
        });
    }

    /** Multi-step games: open a round (blackjack deal / craps come-out / VP deal). */
    public function start(Request $request)
    {
        $data = $request->validate([
            'game' => ['required', 'in:blackjack,craps,videopoker'],
            'amount' => ['required', 'integer', 'min:' . self::MIN_BET, 'max:' . self::MAX_BET],
        ]);
        $user = $request->user();
        if (CasinoRound::where('user_id', $user->id)->where('game', $data['game'])->where('status', 'open')->exists()) {
            return response()->json(['error' => 'Finish your open round first.'], 422);
        }
        $seed = Rng::freshSeed();
        $bet = (int) $data['amount'];

        return DB::transaction(function () use ($user, $data, $seed, $bet) {
            Bankroll::adjust($user->id, -$bet, 'casino_bet', ucfirst($data['game']) . ' buy-in');
            $state = match ($data['game']) {
                'blackjack' => Blackjack::deal(new Rng($seed), $bet),
                'craps' => Craps::start($bet),
                'videopoker' => VideoPoker::deal(new Rng($seed), $bet),
            };
            $round = CasinoRound::create([
                'user_id' => $user->id, 'game' => $data['game'], 'seed' => $seed,
                'state' => $state, 'wagered' => $bet, 'status' => 'open',
            ]);
            // Blackjack can end on the deal (naturals).
            if ($data['game'] === 'blackjack' && $state['phase'] === 'done') {
                $this->settleBlackjack($round);
            }
            return response()->json(['ok' => true] + $this->viewRound($round->fresh()));
        });
    }

    /** Act on an open round: hit/stand/double, roll (+props), hold+draw. */
    public function act(Request $request)
    {
        $data = $request->validate([
            'round_id' => ['required', 'integer'],
            'action' => ['required', 'string', 'max:12'],
            'mask' => ['nullable', 'integer', 'min:0', 'max:31'],
            'props' => ['nullable', 'array', 'max:4'],
            'props.*.type' => ['required_with:props', 'in:field,any7,yo,anycraps'],
            'props.*.amount' => ['required_with:props', 'integer', 'min:' . self::MIN_BET, 'max:' . self::MAX_BET],
        ]);
        $user = $request->user();
        $round = CasinoRound::where('id', $data['round_id'])->where('user_id', $user->id)
            ->where('status', 'open')->first();
        if (!$round) {
            return response()->json(['error' => 'No such open round.'], 404);
        }

        return DB::transaction(function () use ($round, $data, $user) {
            $state = $round->state;
            switch ($round->game) {
                case 'blackjack':
                    if ($data['action'] === 'double') {
                        Bankroll::adjust($user->id, -$state['bet'], 'casino_bet', 'Blackjack double');
                        $round->wagered += $state['bet'];
                    }
                    $state = Blackjack::act($state, $data['action']);
                    $round->state = $state;
                    $round->save();
                    if ($state['phase'] === 'done') {
                        $this->settleBlackjack($round);
                    }
                    break;

                case 'craps':
                    if ($data['action'] !== 'roll') {
                        throw new \RuntimeException('Only the dice speak here — roll.');
                    }
                    $props = $data['props'] ?? [];
                    $propTotal = array_sum(array_column($props, 'amount'));
                    if ($propTotal > 0) {
                        Bankroll::adjust($user->id, -$propTotal, 'casino_bet', 'Craps props');
                        $round->wagered += $propTotal;
                    }
                    // The RNG continues the round's seed stream by roll count.
                    $rollRng = new Rng($round->seed . ':roll:' . count($state['rolls']));
                    [$state, $propResults, $propPaid] = Craps::roll($state, $props, $rollRng);
                    if ($propPaid > 0) {
                        Bankroll::adjust($user->id, $propPaid, 'casino_win', 'Craps props');
                        $round->paid += $propPaid;
                    }
                    $round->state = $state;
                    $round->outcome = ['last_roll' => end($state['rolls']), 'props' => $propResults];
                    if ($state['phase'] === 'done') {
                        $pass = Craps::passPayout($state);
                        if ($pass > 0) {
                            Bankroll::adjust($user->id, $pass, 'casino_win', 'Craps pass line');
                            $round->paid += $pass;
                        }
                        $round->status = 'settled';
                    }
                    $round->save();
                    break;

                case 'videopoker':
                    if ($data['action'] !== 'draw') {
                        throw new \RuntimeException('Hold your cards and draw.');
                    }
                    $state = VideoPoker::draw($state, (int) ($data['mask'] ?? 0));
                    $round->state = $state;
                    $pay = VideoPoker::payout($state);
                    if ($pay > 0) {
                        Bankroll::adjust($user->id, $pay, 'casino_win', 'Video poker: ' . $state['result']);
                    }
                    $round->paid += $pay;
                    $round->outcome = ['hand' => $state['hand'], 'result' => $state['result'], 'paid' => $pay];
                    $round->status = 'settled';
                    $round->save();
                    break;
            }
            return response()->json(['ok' => true] + $this->viewRound($round->fresh()));
        });
    }

    /**
     * The wheel's memory: the last pockets across ALL players at this wheel —
     * the HOT NUMBERS board every roulette pit posts. Public (pockets only).
     */
    public function hot(Request $request, string $game)
    {
        if (!in_array($game, ['roulette_us', 'roulette_eu'], true)) {
            return response()->json(['error' => 'Only the wheels keep a public memory.'], 404);
        }
        $pockets = CasinoRound::where('game', $game)->where('status', 'settled')
            ->latest('id')->limit(18)->pluck('outcome')
            ->map(fn ($o) => $o['pocket'] ?? null)->filter()->values();
        return response()->json(['pockets' => $pockets]);
    }

    /** Open round (resume after refresh) + recent history for a game. */
    public function state(Request $request, string $game)
    {
        $user = $request->user();
        $open = CasinoRound::where('user_id', $user->id)->where('game', $game)->where('status', 'open')->first();
        $recent = CasinoRound::where('user_id', $user->id)->where('game', $game)
            ->where('status', 'settled')->latest('id')->limit(10)
            ->get(['id', 'seed', 'outcome', 'wagered', 'paid', 'created_at']);
        return response()->json([
            'open' => $open ? $this->viewRound($open) : null,
            'recent' => $recent,
            'chips' => $user->chips,
        ]);
    }

    private function settleBlackjack(CasinoRound $round): void
    {
        $state = $round->state;
        $pay = Blackjack::payout($state);
        if ($pay > 0) {
            Bankroll::adjust($round->user_id, $pay, 'casino_win', 'Blackjack: ' . $state['outcome']);
        }
        $round->paid += $pay;
        $round->outcome = ['outcome' => $state['outcome'], 'paid' => $pay];
        $round->status = 'settled';
        $round->save();
    }

    private function viewRound(CasinoRound $round): array
    {
        $view = match ($round->game) {
            'blackjack' => Blackjack::view($round->state),
            'craps' => [
                'phase' => $round->state['phase'], 'point' => $round->state['point'],
                'rolls' => $round->state['rolls'], 'outcome' => $round->state['outcome'],
                'pass' => $round->state['pass'], 'last_props' => $round->outcome['props'] ?? [],
            ],
            'videopoker' => [
                'hand' => $round->state['hand'], 'phase' => $round->state['phase'],
                'result' => $round->state['result'], 'bet' => $round->state['bet'],
            ],
            default => $round->outcome,
        };
        return [
            'round_id' => $round->id,
            'game' => $round->game,
            'status' => $round->status,
            'wagered' => $round->wagered,
            'paid' => $round->paid,
            'seed' => $round->status === 'settled' ? $round->seed : null, // provably-fair reveal
            'view' => $view,
        ];
    }
}
