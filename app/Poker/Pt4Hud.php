<?php

namespace App\Poker;

/**
 * Reader for PokerTracker 4 HUD layout exports (.pt4hud).
 *
 * The file is PT4's typed binary serialization: tokens tagged 's' (UTF-16BE
 * string with a 32-bit char count), 'i' (int32), 'b' (bool) and friends. We
 * don't need the whole object graph — only the ordered stat rows of the first
 * group ("Player Stats" in stock layouts), which is the panel that overlays
 * each seat. Strings are walked in order; fonts, glue chars and popup wiring
 * are dropped; "New Line" / "Horizontal Line" markers become row breaks and
 * `Abbr:` strings attach as the label of the preceding stat.
 */
final class Pt4Hud
{
    /** Magic prefix every export starts with (as UTF-16BE string token). */
    private const MAGIC = 'PT4.Hud.Layout.Export';

    private const FONTS = ['Tahoma', 'Segoe UI', 'Lucida Sans', 'Arial', 'Verdana', 'Courier New'];
    private const BREAKS = ['New Line', 'Horizontal Line'];

    /**
     * @return array{name:string, rows:array<int,array<int,array{stat:string,label:?string}>>}
     * @throws \RuntimeException on files that don't look like a PT4 export
     */
    public static function parse(string $binary): array
    {
        // The magic lives in the raw header (its own framing, not a string
        // token) — match the UTF-16BE bytes directly.
        $magic = mb_convert_encoding(self::MAGIC, 'UTF-16BE', 'UTF-8');
        if (!str_contains(substr($binary, 0, 120), $magic)) {
            throw new \RuntimeException('Not a PT4 HUD layout export (.pt4hud)');
        }

        $strings = self::strings($binary);
        $name = $strings[0] ?? 'Imported HUD';

        // The seat panel is the stretch after the first group title (usually
        // "Player Stats") and before the next group ("Table Stats") or the
        // popup section.
        $start = 2;
        foreach ($strings as $i => $s) {
            if ($i >= 1 && in_array($s, ['Player Stats', 'Player'], true)) {
                $start = $i + 1;
                break;
            }
        }
        $end = count($strings);
        foreach ($strings as $i => $s) {
            if ($i > $start && in_array($s, ['Table Stats', 'Preflop', 'Flop', 'Tools'], true)) {
                $end = $i;
                break;
            }
        }

        $rows = [];
        $row = [];
        for ($i = $start; $i < $end; $i++) {
            $s = trim($strings[$i]);
            if ($s === '' || mb_strlen($s) <= 1) {
                continue; // glue: '(', ')', '/', separators
            }
            if (in_array($s, self::FONTS, true) || str_contains($s, "\t")) {
                continue; // fonts and popup wiring
            }
            if (in_array($s, self::BREAKS, true)) {
                if ($row) {
                    $rows[] = $row;
                    $row = [];
                }
                continue;
            }
            // Color-condition keywords, not stats.
            if (in_array(strtolower($s), ['win', 'lose', 'tie'], true)) {
                continue;
            }
            // 'F3B:'-style abbreviations label the stat that precedes them.
            if (preg_match('/^[A-Za-z0-9$\/+ ]{1,8}:$/', $s) && $row) {
                $row[count($row) - 1]['label'] = rtrim($s, ':');
                continue;
            }
            // Colon-less abbreviation (e.g. 'FRFB' after 'Fold to R Float
            // Bet'): attach only when it matches the stat's initials.
            if ($row && preg_match('/^[A-Z0-9$\/+]{2,8}$/', $s)) {
                $prev = $row[count($row) - 1];
                if ($prev['label'] === null) {
                    // PT abbreviations skip filler words ("Fold to R Float
                    // Bet" → FRFB), so drop stopwords before taking initials.
                    $words = array_filter(
                        preg_split('/[\s\/]+/', $prev['stat'], -1, PREG_SPLIT_NO_EMPTY),
                        fn ($w) => !in_array(strtolower($w), ['to', 'the', 'of', 'a', 'in', 'after', 'vs', 'by'], true)
                    );
                    $initials = implode('', array_map(fn ($w) => strtoupper($w[0]), $words));
                    if ($initials === $s) {
                        $row[count($row) - 1]['label'] = $s;
                        continue;
                    }
                }
            }
            if (mb_strlen($s) > 40) {
                continue; // tooltips / descriptions
            }
            $row[] = ['stat' => $s, 'label' => null];
        }
        if ($row) {
            $rows[] = $row;
        }

        if (empty($rows)) {
            throw new \RuntimeException('No stat rows found in HUD layout');
        }
        return ['name' => $name, 'rows' => $rows];
    }

    /** All string tokens, in file order. */
    private static function strings(string $b): array
    {
        $out = [];
        $n = strlen($b);
        $i = 0;
        while ($i < $n - 5) {
            if ($b[$i] === 's') {
                $len = unpack('N', substr($b, $i + 1, 4))[1];
                $bytes = $len * 2;
                if ($len > 0 && $len <= 400 && $i + 5 + $bytes <= $n) {
                    $raw = substr($b, $i + 5, $bytes);
                    $s = @mb_convert_encoding($raw, 'UTF-8', 'UTF-16BE');
                    if ($s !== false && $s !== '' && !preg_match('/[^\P{C}\t\n]/u', $s)) {
                        $out[] = $s;
                        $i += 5 + $bytes;
                        continue;
                    }
                }
            }
            $i++;
        }
        return $out;
    }
}
