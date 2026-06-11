<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Scarlet Beast Poker — Get the Apps</title>
    <meta name="description" content="Download the Scarlet Beast Poker desktop and mobile clients — Windows, macOS, Linux, Android. Take the felt anywhere.">
    <style>
      :root{--pk-sc:#e10600;--pk-scb:#ff2418;--pk-scs:#ff6a5a;--pk-bone:#ede8df;--pk-dim:#a9a29a;--pk-gold:#d8b25a;
        --pk-mono:'SFMono-Regular',ui-monospace,'JetBrains Mono',Menlo,Consolas,monospace}
      .wrap{max-width:980px;margin:0 auto;padding:0 22px}
      h1{font-size:clamp(30px,5vw,52px);text-transform:uppercase;margin:34px 0 8px;letter-spacing:-.01em}
      h1 b{color:var(--pk-scb)}
      .lead{color:var(--pk-dim);font-size:17px;max-width:680px;line-height:1.6}
      .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:18px;margin:34px 0 10px}
      .card{background:#0a0707;border:1px solid rgba(255,36,24,.22);border-radius:14px;padding:22px 22px 20px;
        display:flex;flex-direction:column}
      .card.soon{opacity:.42;filter:grayscale(1)}
      .card .ico{font-size:34px;line-height:1}
      .card h3{margin:12px 0 2px;font-size:20px;text-transform:uppercase;letter-spacing:.02em;color:var(--pk-bone)}
      .card .plat{font-family:var(--pk-mono);font-size:12px;color:var(--pk-dim);letter-spacing:.08em;text-transform:uppercase}
      .card .desc{color:#cfc7bd;font-size:14px;line-height:1.55;margin:12px 0 16px;flex:1}
      .dl{display:inline-flex;align-items:center;gap:8px;background:var(--pk-sc);color:#fff;text-decoration:none;
        font-weight:700;letter-spacing:.04em;text-transform:uppercase;font-size:14px;padding:11px 18px;border-radius:9px;
        transition:background .15s}
      .dl:hover{background:var(--pk-scb)}
      .dl.alt{background:transparent;color:var(--pk-scs);border:1px solid rgba(255,36,24,.45);margin-top:9px;font-size:12px;padding:8px 14px}
      .dl.alt:hover{background:rgba(255,36,24,.12)}
      .dl.dead{background:#2a2522;color:#8c857d;cursor:not-allowed;pointer-events:none}
      .sz{font-family:var(--pk-mono);font-size:11px;color:var(--pk-dim);margin-top:10px}
      .soonbadge{display:inline-block;font-family:var(--pk-mono);font-size:10px;letter-spacing:.14em;text-transform:uppercase;
        color:var(--pk-gold);border:1px solid rgba(216,178,90,.5);border-radius:5px;padding:3px 8px;margin-top:6px}
      .note{color:var(--pk-dim);font-size:13px;line-height:1.6;margin:8px 0;border-left:2px solid rgba(255,36,24,.3);padding-left:14px}
      .note b{color:var(--pk-scs)}
      h2{margin:40px 0 6px;font-size:22px;text-transform:uppercase;border-bottom:1px solid rgba(255,36,24,.3);padding-bottom:8px}
      .sums{background:#0a0707;border:1px solid rgba(255,255,255,.1);border-radius:10px;padding:14px 16px;overflow:auto;
        font-family:var(--pk-mono);font-size:11.5px;color:#bdb6ac;line-height:1.8}
      .sums b{color:var(--pk-bone)}
      a{color:var(--pk-scs)}
      .back{display:inline-block;margin:28px 0;font-family:var(--pk-mono);font-size:12px;letter-spacing:.1em;text-transform:uppercase;color:var(--pk-dim);text-decoration:none}
    </style>
</head>
<body>
    @include('partials.chrome')

    @php
        $ver = '1.0.0';
        $base = '/downloads';
        // [size] computed from public/downloads on render so it stays honest.
        $sz = function (string $f): string {
            $p = public_path('downloads/' . $f);
            if (! is_file($p)) return '';
            $b = filesize($p);
            return $b >= 1048576 ? round($b / 1048576) . ' MB' : round($b / 1024) . ' KB';
        };
        // Download URL with an mtime cache-bust so a rebuilt binary never serves
        // stale through the Cloudflare edge (the cache key includes the query).
        $url = function (string $f) use ($base): string {
            $p = public_path('downloads/' . $f);
            $b = is_file($p) ? filemtime($p) : 0;
            return $base . '/' . $f . '?b=' . $b;
        };
    @endphp

    @php
        $mq = ['TAKE THE FELT ANYWHERE','DESKTOP · POCKET · WAR','ONE ACCOUNT · EVERY SCREEN','SILICON IN YOUR PALM'];
    @endphp
    {!! pk_marquee($mq, 'hot', '55') !!}

    <div class="wrap">
        <h1>Get the <b>Apps</b></h1>
        <p class="lead">The felt, native. Same account, same chips, same bots — now on your desktop and in your pocket. Each client is a hardened shell around the live table; sign in once and play.</p>

        <div class="grid">
            {{-- Windows --}}
            <div class="card">
                <div class="ico">🪟</div>
                <h3>Windows</h3>
                <div class="plat">10 / 11 · 64-bit</div>
                <div class="desc">Installer with desktop &amp; Start-menu shortcuts. Prefer no install? Grab the portable single-file build.</div>
                <a class="dl" href="{{ $url('Scarletbeast-Poker-'.$ver.'-Setup.exe') }}" download>↓ Download Installer</a>
                <a class="dl alt" href="{{ $url('Scarletbeast-Poker-'.$ver.'-Portable.exe') }}" download>Portable .exe</a>
                <div class="sz">Setup · {{ $sz('Scarletbeast-Poker-'.$ver.'-Setup.exe') }}</div>
            </div>

            {{-- macOS --}}
            <div class="card soon">
                <div class="ico">🍎</div>
                <h3>macOS</h3>
                <div class="plat">Apple Silicon &amp; Intel</div>
                <div class="desc">A signed universal .dmg is on the way.</div>
                <span class="dl dead">Coming soon</span>
                <span class="soonbadge">Coming soon</span>
            </div>

            {{-- Linux --}}
            <div class="card">
                <div class="ico">🐧</div>
                <h3>Linux</h3>
                <div class="plat">x86-64</div>
                <div class="desc">Portable AppImage (chmod +x and run) or a Debian/Ubuntu .deb package.</div>
                <a class="dl" href="{{ $url('Scarletbeast-Poker-'.$ver.'.AppImage') }}" download>↓ Download AppImage</a>
                <a class="dl alt" href="{{ $url('Scarletbeast-Poker-'.$ver.'.deb') }}" download>.deb package</a>
                <div class="sz">AppImage · {{ $sz('Scarletbeast-Poker-'.$ver.'.AppImage') }}</div>
            </div>

            {{-- Android --}}
            <div class="card">
                <div class="ico">🤖</div>
                <h3>Android</h3>
                <div class="plat">7.0+ · APK</div>
                <div class="desc">Sideload the APK — enable “install unknown apps” for your browser, then open the file.</div>
                <a class="dl" href="{{ $url('Scarletbeast-Poker-'.$ver.'.apk') }}" download>↓ Download APK</a>
                <div class="sz">APK · {{ $sz('Scarletbeast-Poker-'.$ver.'.apk') }}</div>
            </div>

            {{-- iPhone / iOS --}}
            <div class="card soon">
                <div class="ico">📱</div>
                <h3>iPhone &amp; iPad</h3>
                <div class="plat">iOS</div>
                <div class="desc">The iOS build is in the chute, pending App Store review.</div>
                <span class="dl dead">Coming soon</span>
                <span class="soonbadge">Coming soon</span>
            </div>
        </div>

        <p class="note"><b>Heads up:</b> the Windows and Linux desktop builds aren’t code-signed yet, so SmartScreen / Gatekeeper may warn on first launch — choose “run anyway”. The Android APK is signed. Everything points at <a href="https://poker.scarletbeast.com">poker.scarletbeast.com</a>, so the apps always reflect the live tables.</p>

        <h2>Verify your download</h2>
        <p class="lead" style="font-size:14px">SHA-256 — compare with <code>sha256sum</code> / <code>certutil -hashfile &lt;file&gt; SHA256</code>. Full list: <a href="{{ $url('SHA256SUMS.txt') }}">SHA256SUMS.txt</a></p>
        <div class="sums">
@php $sums = @file_get_contents(public_path('downloads/SHA256SUMS.txt')) ?: ''; @endphp
@foreach (array_filter(explode("\n", trim($sums))) as $line)
@php [$h, $f] = array_pad(preg_split('/\s+/', $line, 2), 2, ''); @endphp
<b>{{ $f }}</b><br>{{ $h }}<br><br>
@endforeach
        </div>

        <a class="back" href="/">← Back to the lobby</a>
    </div>

    @php
        $mq2 = ['INSTALL · ENLIST · SHOVE','THE MACHINE FITS IN YOUR POCKET NOW','BUILT FOR EVERY SCREEN','SEE YOU AT THE FELT'];
    @endphp
    {!! pk_marquee($mq2, 'gold', '50', true) !!}
    @if (function_exists('sb_chrome_footer')) {!! sb_chrome_footer() !!} @endif
    <script src="/js/marquee-engine.js" defer></script>
</body>
</html>
