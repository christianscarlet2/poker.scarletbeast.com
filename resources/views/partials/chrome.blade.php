{{-- Shared Scarlet Beast chrome (header/footer) + poker theme + marquee CSS. --}}
@php
    // Single source of truth for header/footer across the whole beast.
    if (is_readable('/var/www/sb-shared/chrome.php')) {
        require_once '/var/www/sb-shared/chrome.php';
    }
    /** Render a marquee track (content duplicated 2x for the seamless loop). */
    if (! function_exists('pk_marquee')) {
        function pk_marquee(array $items, string $cls = '', string $speed = '60', bool $rev = false): string {
            $span = '';
            foreach ($items as $it) {
                $span .= '<span class="pk-it">' . htmlspecialchars($it, ENT_QUOTES) . '</span><span class="pk-sep">⛧</span>';
            }
            $dup = $span . $span; // engine loops at scrollWidth/2
            $r = $rev ? ' data-rev="1"' : '';
            return '<div class="pk-mq ' . $cls . '"><div class="pk-tk" data-mq data-speed="' . $speed . '"' . $r . '>' . $dup . '</div></div>';
        }
    }
@endphp

@if (function_exists('sb_chrome_css')) {!! sb_chrome_css() !!} @endif

<style id="pk-theme">
:root{
  --pk-sc:#e10600;--pk-scb:#ff2418;--pk-scs:#ff6a5a;--pk-blk:#050404;--pk-ink:#0c0a0b;
  --pk-bone:#ede8df;--pk-dim:#a9a29a;--pk-felt:#0a1410;--pk-felt2:#0d2018;--pk-gold:#d8b25a;
  --pk-mono:'SFMono-Regular',ui-monospace,'JetBrains Mono',Menlo,Consolas,monospace;
  --pk-sans:'Helvetica Neue',Arial,'Segoe UI',system-ui,sans-serif;
}
*{box-sizing:border-box}
html,body{margin:0;background:var(--pk-blk);color:var(--pk-bone);font-family:var(--pk-sans);
  -webkit-font-smoothing:antialiased}
a{color:var(--pk-scs)}
/* ---- marquee ---- */
.pk-mq{overflow:hidden;white-space:nowrap;border-top:1px solid rgba(255,36,24,.32);
  border-bottom:1px solid rgba(255,36,24,.32);background:#0a0707;padding:9px 0}
.pk-mq.flush{border:none;background:transparent}
.pk-tk{display:inline-block;white-space:nowrap;will-change:transform}
.pk-it{font-family:var(--pk-mono);font-size:12.5px;letter-spacing:.14em;text-transform:uppercase;
  color:var(--pk-dim);padding:0 16px}
.pk-mq.hot .pk-it{color:var(--pk-scs)}
.pk-mq.bone .pk-it{color:var(--pk-bone)}
.pk-mq.gold .pk-it{color:var(--pk-gold)}
.pk-sep{color:var(--pk-sc);opacity:.55;font-size:10px;vertical-align:middle}
</style>

@if (function_exists('sb_chrome_header') && empty($bare)) {!! sb_chrome_header('Tables') !!} @endif
