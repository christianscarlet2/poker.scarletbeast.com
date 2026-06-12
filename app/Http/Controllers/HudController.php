<?php

namespace App\Http\Controllers;

use App\Models\HudProfile;
use App\Models\PokerTable;
use App\Models\TableState;
use App\Poker\Pt4Hud;
use App\Services\HudStats;
use Illuminate\Http\Request;

/**
 * The HUD desk. Players upload PokerTracker 4 layouts (.pt4hud), pick one,
 * and the felt overlays live PT-style stats on every seat — same numbers the
 * PT4 mirror would show, computed from the house's own hand archive.
 */
class HudController extends Controller
{
    public function __construct(private HudStats $stats)
    {
    }

    /** Profiles available to the caller: house defaults + their own uploads. */
    public function index(Request $request)
    {
        $user = $request->user();
        $profiles = HudProfile::whereNull('user_id')
            ->when($user, fn ($q) => $q->orWhere('user_id', $user->id))
            ->orderBy('id')->get(['id', 'user_id', 'name', 'source', 'rows']);
        return response()->json([
            'profiles' => $profiles,
            'selected' => $user?->hud_profile_id,
            'stat_keys' => HudStats::MAP, // PT4 stat name → computed key ('name', 'vpip', …)
        ]);
    }

    /** Upload a .pt4hud layout export. */
    public function upload(Request $request)
    {
        $request->validate(['file' => ['required', 'file', 'max:2048']]);
        $raw = file_get_contents($request->file('file')->getRealPath());
        try {
            $parsed = Pt4Hud::parse($raw);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
        $profile = HudProfile::create([
            'user_id' => $request->user()->id,
            'name' => $parsed['name'],
            'source' => $request->file('file')->getClientOriginalName(),
            'rows' => $parsed['rows'],
        ]);
        // Uploading implies wanting it on.
        $request->user()->update(['hud_profile_id' => $profile->id]);
        return response()->json(['ok' => true, 'profile' => $profile]);
    }

    /** Create a custom profile from scratch in the in-browser builder. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'rows' => ['required', 'array', 'min:1'],
        ]);
        $rows = $this->cleanRows($data['rows']);
        if (empty($rows)) {
            return response()->json(['error' => 'Add at least one stat to the layout.'], 422);
        }
        $profile = HudProfile::create([
            'user_id' => $request->user()->id,
            'name' => $data['name'],
            'source' => 'builder',
            'rows' => $rows,
        ]);
        $request->user()->update(['hud_profile_id' => $profile->id]); // saving arms it
        return response()->json(['ok' => true, 'profile' => $profile]);
    }

    /** Update one of your own custom profiles from the builder. */
    public function update(Request $request, HudProfile $profile)
    {
        if ($profile->user_id !== $request->user()->id) {
            return response()->json(['error' => 'Not yours to edit.'], 403);
        }
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'rows' => ['required', 'array', 'min:1'],
        ]);
        $rows = $this->cleanRows($data['rows']);
        if (empty($rows)) {
            return response()->json(['error' => 'Add at least one stat to the layout.'], 422);
        }
        $profile->update(['name' => $data['name'], 'rows' => $rows]);
        return response()->json(['ok' => true, 'profile' => $profile->fresh()]);
    }

    /**
     * Sanitise builder rows: keep only renderable stats (those the engine can
     * compute, i.e. present in the PT4 map), trim labels, drop empty rows, and
     * cap the grid so a layout can never bloat the felt.
     */
    private function cleanRows(array $rows): array
    {
        $known = array_keys(HudStats::MAP);
        $out = [];
        foreach (array_slice($rows, 0, 8) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $cells = [];
            foreach (array_slice($row, 0, 12) as $cell) {
                $stat = is_array($cell) ? ($cell['stat'] ?? null) : null;
                if (!is_string($stat) || !in_array($stat, $known, true)) {
                    continue;
                }
                $label = is_array($cell) ? ($cell['label'] ?? null) : null;
                $label = is_string($label) && trim($label) !== '' ? mb_substr(trim($label), 0, 6) : null;
                $cells[] = ['stat' => $stat, 'label' => $label];
            }
            if ($cells) {
                $out[] = $cells;
            }
        }
        return $out;
    }

    /** Choose the active profile (null = HUD off). */
    public function select(Request $request)
    {
        $data = $request->validate(['profile_id' => ['nullable', 'integer']]);
        $id = $data['profile_id'] ?? null;
        if ($id !== null) {
            $p = HudProfile::find($id);
            if (!$p || ($p->user_id !== null && $p->user_id !== $request->user()->id)) {
                return response()->json(['error' => 'No such HUD profile.'], 404);
            }
        }
        $request->user()->update(['hud_profile_id' => $id]);
        return response()->json(['ok' => true, 'selected' => $id]);
    }

    /** Delete one of your own uploads. */
    public function destroy(Request $request, HudProfile $profile)
    {
        if ($profile->user_id !== $request->user()->id) {
            return response()->json(['error' => 'Not yours to burn.'], 403);
        }
        if ($request->user()->hud_profile_id === $profile->id) {
            $request->user()->update(['hud_profile_id' => null]);
        }
        $profile->delete();
        return response()->json(['ok' => true]);
    }

    /** Token-friendly import: a .pt4hud sent as base64 JSON (used by Hiss). */
    public function import(Request $request)
    {
        $data = $request->validate([
            'content_b64' => ['required', 'string'],
            'name' => ['nullable', 'string', 'max:120'],
        ]);
        $raw = base64_decode($data['content_b64'], true);
        if ($raw === false) {
            return response()->json(['error' => 'Invalid base64 content.'], 422);
        }
        try {
            $parsed = Pt4Hud::parse($raw);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
        $profile = HudProfile::create([
            'user_id' => $request->user()->id,
            'name' => $data['name'] ?: $parsed['name'],
            'source' => ($data['name'] ?: $parsed['name']) . '.pt4hud',
            'rows' => $parsed['rows'],
        ]);
        $request->user()->update(['hud_profile_id' => $profile->id]);
        return response()->json(['ok' => true, 'profile' => ['id' => $profile->id, 'name' => $profile->name]]);
    }

    /**
     * Live HUD payload for a felt: the viewer's profile (or house default for
     * observers) + computed stats for every seated player.
     */
    public function table(Request $request, PokerTable $table)
    {
        $user = $request->user();
        $profile = null;
        if ($user && $user->hud_profile_id) {
            $profile = HudProfile::find($user->hud_profile_id);
        }
        $profile ??= HudProfile::whereNull('user_id')->orderBy('id')->first();

        $state = TableState::find($table->id);
        $userIds = [];
        foreach (($state?->state['players'] ?? []) as $sn => $p) {
            if (!empty($p['user_id'])) {
                $userIds[(int) $sn] = $p['user_id'];
            }
        }

        $stats = $this->stats->forTable($table, array_values($userIds));
        $bySeat = [];
        foreach ($userIds as $seat => $uid) {
            if (isset($stats[$uid])) {
                $bySeat[$seat] = $stats[$uid];
            }
        }

        return response()->json([
            'profile' => $profile ? [
                'id' => $profile->id,
                'name' => $profile->name,
                'rows' => $profile->rows,
            ] : null,
            'map' => HudStats::MAP,
            'seats' => $bySeat,
        ]);
    }
}
