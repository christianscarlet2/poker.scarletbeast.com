<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Signed In — Scarlet Beast Poker</title>
    <meta name="robots" content="noindex">
    @php
        $providers = ['google' => 'Google', 'github' => 'GitHub'];
        $label = $providers[$via] ?? null;
        $glyph = ['google' => 'G', 'github' => '⎇'][$via] ?? '✓';
    @endphp
    <style>
      :root{--pk-sc:#e10600;--pk-scb:#ff2418;--pk-scs:#ff6a5a;--pk-bone:#ede8df;--pk-dim:#a9a29a;--pk-gold:#d8b25a;
        --pk-mono:'SFMono-Regular',ui-monospace,'JetBrains Mono',Menlo,Consolas,monospace}
      .stage{min-height:62vh;display:flex;align-items:center;justify-content:center;padding:48px 22px}
      .card{width:100%;max-width:480px;background:#0a0707;border:1px solid rgba(255,36,24,.25);border-radius:18px;
        padding:38px 34px 30px;text-align:center;box-shadow:0 18px 60px rgba(0,0,0,.5)}
      .seal{width:72px;height:72px;margin:0 auto 18px;border-radius:50%;display:flex;align-items:center;justify-content:center;
        font-size:36px;background:radial-gradient(circle at 50% 35%,rgba(225,6,0,.35),rgba(225,6,0,.08));
        border:1px solid rgba(255,36,24,.5);animation:pop .5s cubic-bezier(.2,1.4,.4,1) both}
      @keyframes pop{from{transform:scale(.5);opacity:0}to{transform:scale(1);opacity:1}}
      h1{font-size:30px;text-transform:uppercase;letter-spacing:-.01em;margin:0 0 4px;color:var(--pk-bone)}
      h1 b{color:var(--pk-scb)}
      .who{display:flex;align-items:center;gap:12px;justify-content:center;margin:20px 0 6px}
      .ava{width:46px;height:46px;border-radius:50%;background:#140e0e;border:1px solid rgba(255,255,255,.12);
        display:flex;align-items:center;justify-content:center;font-size:24px}
      .who .nm{text-align:left}
      .who .nm strong{display:block;color:var(--pk-bone);font-size:17px}
      .who .nm span{color:var(--pk-dim);font-size:12.5px;font-family:var(--pk-mono)}
      .via{display:inline-flex;align-items:center;gap:7px;margin:14px 0 4px;font-family:var(--pk-mono);font-size:12px;
        letter-spacing:.06em;color:var(--pk-dim)}
      .via .g{width:20px;height:20px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;
        font-weight:700;font-size:12px;background:#fff;color:#111}
      .via.github .g{background:#1c1c1c;color:#fff}
      .sub{color:var(--pk-dim);font-size:14px;line-height:1.55;margin:6px 0 24px}
      .enter{display:inline-flex;align-items:center;gap:9px;background:var(--pk-sc);color:#fff;text-decoration:none;
        font-weight:700;letter-spacing:.05em;text-transform:uppercase;font-size:15px;padding:13px 30px;border-radius:11px;
        transition:background .15s}
      .enter:hover{background:var(--pk-scb)}
      .meta{margin-top:18px;font-size:12px;color:var(--pk-dim)}
      .meta a{color:var(--pk-scs)}
      .count{font-family:var(--pk-mono);color:var(--pk-scs)}
      .estate{margin-top:22px;padding-top:16px;border-top:1px solid rgba(255,255,255,.08);font-size:11.5px;color:var(--pk-dim);
        font-family:var(--pk-mono);letter-spacing:.05em}
    </style>
</head>
<body>
    @include('partials.chrome')
    {!! pk_marquee(['ONE KEY · EVERY FELT','THE GATE OPENS','WELCOME TO THE BEAST','YOUR SEAT IS WAITING'], 'hot', '55') !!}

    <div class="stage">
        <div class="card">
            <div class="seal">✓</div>
            <h1>You're <b>In</b></h1>
            <div class="who">
                <div class="ava">{{ $user->avatar ?: '♠' }}</div>
                <div class="nm">
                    <strong>{{ $user->name ?: $user->username }}</strong>
                    <span>{{ '@' . $user->username }}{{ $user->email ? ' · ' . $user->email : '' }}</span>
                </div>
            </div>

            @if ($label)
            <div class="via {{ $via }}"><span class="g">{{ $glyph }}</span> Signed in with {{ $label }}</div>
            @endif

            <p class="sub">One key now opens every felt across the estate. The session follows you to every Scarlet Beast property.</p>

            <a class="enter" href="{{ $to }}" id="enter">Enter the Felt →</a>

            <div class="meta">
                Taking you in automatically in <span class="count" id="count">5</span>s
            </div>

            <div class="estate">⛧ B·E·A·S·T — ONE IDENTITY · MANY MASKS</div>
        </div>
    </div>

    @if (function_exists('sb_chrome_footer')) {!! sb_chrome_footer() !!} @endif
    <script src="/js/marquee-engine.js" defer></script>
    <script>
      // Gentle auto-continue; cancels the moment the user interacts.
      (function () {
        var to = @json($to), n = 5, el = document.getElementById('count'), go = true;
        ['click','keydown','touchstart','wheel'].forEach(function (e) {
          window.addEventListener(e, function (ev) { if (ev.target.id !== 'enter') go = false; }, { once: true, passive: true });
        });
        var t = setInterval(function () {
          n -= 1;
          if (el) el.textContent = n;
          if (n <= 0) { clearInterval(t); if (go) window.location.assign(to); }
        }, 1000);
      })();
    </script>
</body>
</html>
