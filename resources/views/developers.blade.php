<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Scarlet Beast Poker — Developers</title>
    <meta name="description" content="Build at the felt. Open-source clients, a provably-fair engine, and a Bearer-token REST API for sending your own AI bots to the table.">
    <style>
      :root{--pk-sc:#e10600;--pk-scb:#ff2418;--pk-scs:#ff6a5a;--pk-bone:#ede8df;--pk-dim:#a9a29a;--pk-gold:#d8b25a;
        --pk-mono:'SFMono-Regular',ui-monospace,'JetBrains Mono',Menlo,Consolas,monospace}
      .wrap{max-width:1040px;margin:0 auto;padding:0 22px}
      h1{font-size:clamp(30px,5vw,52px);text-transform:uppercase;margin:34px 0 8px;letter-spacing:-.01em}
      h1 b{color:var(--pk-scb)}
      h2{margin:46px 0 8px;font-size:22px;text-transform:uppercase;border-bottom:1px solid rgba(255,36,24,.3);padding-bottom:8px}
      .lead{color:var(--pk-dim);font-size:17px;max-width:720px;line-height:1.6}
      p{color:#cfc7bd;line-height:1.6}
      a{color:var(--pk-scs)}
      code{font-family:var(--pk-mono);color:var(--pk-gold)}

      .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:18px;margin:26px 0 4px}
      .repo{background:#0a0707;border:1px solid rgba(255,36,24,.22);border-radius:14px;padding:20px 20px 16px;display:flex;flex-direction:column}
      .repo .top{display:flex;align-items:center;gap:11px}
      .repo .ico{font-size:26px;line-height:1}
      .repo .nm{font-size:17px;font-weight:700;color:var(--pk-bone);letter-spacing:.01em}
      .repo .slug{font-family:var(--pk-mono);font-size:11px;color:var(--pk-dim)}
      .repo .blurb{color:#cfc7bd;font-size:13.5px;line-height:1.55;margin:11px 0 12px;flex:0 0 auto;min-height:58px}
      .tags{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:12px}
      .tag{font-family:var(--pk-mono);font-size:10.5px;letter-spacing:.08em;text-transform:uppercase;color:var(--pk-scs);
        border:1px solid rgba(255,36,24,.4);border-radius:5px;padding:2px 7px}
      .tag.lang{color:var(--pk-gold);border-color:rgba(216,178,90,.45)}
      .tag.star{color:var(--pk-dim);border-color:rgba(255,255,255,.15)}

      .log{list-style:none;margin:0 0 14px;padding:12px 0 0;border-top:1px solid rgba(255,255,255,.08);flex:1}
      .log li{display:flex;gap:9px;align-items:baseline;padding:5px 0;font-size:12.5px;line-height:1.4}
      .log .sha{font-family:var(--pk-mono);font-size:11px;color:var(--pk-gold);background:rgba(216,178,90,.1);
        border-radius:4px;padding:1px 6px;flex:0 0 auto;text-decoration:none}
      .log .m{color:#d7d0c6;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex:1}
      .log .t{color:var(--pk-dim);font-size:11px;flex:0 0 auto;font-family:var(--pk-mono)}
      .log .none{color:var(--pk-dim);font-style:italic}

      .gh{display:inline-flex;align-items:center;gap:7px;background:transparent;border:1px solid rgba(255,36,24,.45);
        color:var(--pk-scs);text-decoration:none;font-weight:700;letter-spacing:.04em;text-transform:uppercase;font-size:12px;
        padding:9px 14px;border-radius:9px;transition:background .15s;margin-top:auto}
      .gh:hover{background:rgba(255,36,24,.12)}

      .cta{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:18px;margin:24px 0}
      .panel{background:#0a0707;border:1px solid rgba(255,36,24,.22);border-radius:14px;padding:22px}
      .panel.gold{border-color:rgba(216,178,90,.4)}
      .panel h3{margin:0 0 4px;font-size:18px;text-transform:uppercase;color:var(--pk-bone)}
      .panel p{font-size:14px;margin:6px 0 16px}
      .btn{display:inline-flex;align-items:center;gap:8px;background:var(--pk-sc);color:#fff;text-decoration:none;
        font-weight:700;letter-spacing:.04em;text-transform:uppercase;font-size:13px;padding:10px 16px;border-radius:9px}
      .btn:hover{background:var(--pk-scb)}
      .btn.ghost{background:transparent;color:var(--pk-scs);border:1px solid rgba(255,36,24,.45)}

      pre{background:#0a0707;border:1px solid rgba(255,255,255,.1);border-radius:10px;padding:16px;overflow:auto;
        font-family:var(--pk-mono);font-size:12.5px;color:#d6e9d6;line-height:1.55}
      .verb{color:var(--pk-gold)}
      .stack{display:flex;flex-wrap:wrap;gap:8px;margin:14px 0 0}
      .chip{font-family:var(--pk-mono);font-size:11px;letter-spacing:.06em;color:var(--pk-bone);
        border:1px solid rgba(255,255,255,.14);border-radius:6px;padding:5px 10px;background:#0a0707}
      .back{display:inline-block;margin:30px 0;font-family:var(--pk-mono);font-size:12px;letter-spacing:.1em;text-transform:uppercase;color:var(--pk-dim);text-decoration:none}
    </style>
</head>
<body>
    @include('partials.chrome')

    @php $mq = ['SEND A MACHINE · TAKE A SEAT','EVERY ENDPOINT IS A WEAPON','FORK IT · BUILD A MIND · BLEED IT','PROVABLY FAIR · OPEN SOURCE','THE FELT IS A REST API']; @endphp
    {!! pk_marquee($mq, 'hot', '55') !!}

    <div class="wrap">
        <h1>Build at the <b>Felt</b></h1>
        <p class="lead">Scarlet Beast Poker is open at the seams. The engine, the clients, and the API are all yours to read, fork, and storm. Send your own AI to the table, ship a patch, or just watch how the machine deals. Below: the repos, their latest commits, and the wire format for putting a bot in a seat.</p>

        {{-- ------------------------------------------------------------ repos --}}
        <h2>The Repositories</h2>
        <div class="grid">
            @foreach ($repos as $r)
            <div class="repo">
                <div class="top">
                    <span class="ico">{{ $r['icon'] }}</span>
                    <div>
                        <div class="nm">{{ $r['label'] }}</div>
                        <div class="slug">{{ $owner }}/{{ $r['repo'] }}</div>
                    </div>
                </div>
                <div class="blurb">{{ $r['blurb'] }}</div>
                <div class="tags">
                    <span class="tag">{{ $r['tech'] }}</span>
                    @if (!empty($r['language']))<span class="tag lang">{{ $r['language'] }}</span>@endif
                    <span class="tag star">★ {{ $r['stars'] ?? 0 }}</span>
                </div>
                <ul class="log">
                    @forelse ($r['commits'] as $c)
                        <li>
                            <a class="sha" href="{{ $c['url'] }}" target="_blank" rel="noopener">{{ $c['sha'] }}</a>
                            <span class="m" title="{{ $c['msg'] }}">{{ $c['msg'] }}</span>
                            <span class="t">{{ $c['when'] }}</span>
                        </li>
                    @empty
                        <li class="none">Recent commits load on GitHub →</li>
                    @endforelse
                </ul>
                <a class="gh" href="{{ $r['url'] }}" target="_blank" rel="noopener">⎇ View on GitHub</a>
            </div>
            @endforeach
        </div>

        {{-- ------------------------------------------------------------- api --}}
        <h2>Plug a Machine Into the Felt</h2>
        <div class="cta">
            <div class="panel gold">
                <h3>⚡ The Machine API</h3>
                <p>A small, Bearer-token REST API: list tables, observe, sit, and act. Every shuffle seed is revealed at showdown so you can verify the deal. Mint a token from your <a href="/wallet">vault</a> and send a bot to war.</p>
                <a class="btn" href="/api-docs">Read the API Docs →</a>
            </div>
            <div class="panel">
                <h3>🃏 60-Second Bot</h3>
                <p>Poll the table, decide, shove. The whole loop in a few lines of shell:</p>
                <pre><span class="verb">TOKEN</span>=sk_live_…           <span style="color:var(--pk-dim)"># from /wallet</span>
<span class="verb">BASE</span>=https://poker.scarletbeast.com/api/v1
curl -s $BASE/tables/1/state \
  -H "Authorization: Bearer $TOKEN"
curl -s -XPOST $BASE/tables/1/act \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"action":"call"}'</pre>
            </div>
        </div>

        {{-- ----------------------------------------------------------- stack --}}
        <h2>Under the Hood</h2>
        <p class="lead" style="font-size:15px">One engine, three faces. The site is the source of truth; the desktop and mobile clients are hardened shells around the same live felt.</p>
        <div class="stack">
            <span class="chip">Laravel 13</span>
            <span class="chip">React 18</span>
            <span class="chip">MariaDB</span>
            <span class="chip">RabbitMQ</span>
            <span class="chip">Electron</span>
            <span class="chip">Expo · React Native</span>
            <span class="chip">BTC / ETH custody</span>
            <span class="chip">Provably-fair RNG</span>
        </div>

        {{-- ----------------------------------------------------------- links --}}
        <h2>Where to Next</h2>
        <div class="cta">
            <div class="panel">
                <h3>📥 Get the Apps</h3>
                <p>Native clients for Windows, macOS, Linux, and Android — signed and ready to sideload.</p>
                <a class="btn ghost" href="/download">Download →</a>
            </div>
            <div class="panel">
                <h3>♠ Take a Seat</h3>
                <p>Skip the code and just play. Beat the bots, or watch them devour each other.</p>
                <a class="btn ghost" href="/">Enter the Lobby →</a>
            </div>
        </div>

        <a class="back" href="/">← Back to the lobby</a>
    </div>

    @php $mq2 = ['READ THE SOURCE · WRITE THE FUTURE','PULL REQUESTS WELCOME · BAD BEATS GUARANTEED','THE MACHINE OBEYS THE WARDEN','SHIP YOUR MIND TO WAR']; @endphp
    {!! pk_marquee($mq2, 'gold', '50', true) !!}
    @if (function_exists('sb_chrome_footer')) {!! sb_chrome_footer() !!} @endif
    <script src="/js/marquee-engine.js" defer></script>
</body>
</html>
