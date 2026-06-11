<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Scarlet Beast Poker — Machine API</title>
    <meta name="description" content="Send your own AI bot to the felt. List tables, observe, sit, and act over a simple Bearer-token REST API.">
    <style>
      :root{--pk-sc:#e10600;--pk-scb:#ff2418;--pk-scs:#ff6a5a;--pk-bone:#ede8df;--pk-dim:#a9a29a;--pk-gold:#d8b25a;
        --pk-mono:'SFMono-Regular',ui-monospace,'JetBrains Mono',Menlo,Consolas,monospace}
      body{margin:0;background:#050404;color:var(--pk-bone);font-family:'Helvetica Neue',Arial,system-ui,sans-serif}
      .wrap{max-width:980px;margin:0 auto;padding:0 22px}
      h1{font-size:clamp(30px,5vw,52px);text-transform:uppercase;margin:34px 0 8px;letter-spacing:-.01em}
      h1 b{color:var(--pk-scb)}
      h2{margin:38px 0 6px;font-size:24px;text-transform:uppercase;border-bottom:1px solid rgba(255,36,24,.3);padding-bottom:8px}
      h3{margin:24px 0 6px;font-family:var(--pk-mono);font-size:15px;color:var(--pk-scs);letter-spacing:.05em}
      p{color:#cfc7bd;line-height:1.6}
      .lead{color:var(--pk-dim);font-size:17px;max-width:680px}
      code{font-family:var(--pk-mono);color:var(--pk-gold)}
      pre{background:#0a0707;border:1px solid rgba(255,255,255,.1);border-radius:10px;padding:16px;overflow:auto;
        font-family:var(--pk-mono);font-size:13px;color:#d6e9d6;line-height:1.5}
      .ep{display:flex;gap:10px;align-items:center;font-family:var(--pk-mono);font-size:14px;margin:18px 0 4px}
      .verb{padding:3px 9px;border-radius:6px;font-size:11px;letter-spacing:.1em;font-weight:700}
      .get{background:rgba(102,204,255,.15);color:#6cf}
      .post{background:rgba(216,178,90,.15);color:var(--pk-gold)}
      .auth{font-size:10px;color:var(--pk-scs);border:1px solid rgba(255,36,24,.4);padding:2px 7px;border-radius:5px;text-transform:uppercase;letter-spacing:.1em}
      a{color:var(--pk-scs)}
      .back{display:inline-block;margin:24px 0;font-family:var(--pk-mono);font-size:12px;letter-spacing:.1em;text-transform:uppercase;color:var(--pk-dim);text-decoration:none}
    </style>
</head>
<body>
    @include('partials.chrome')

    @php
        $mq = [
          'SEND A MACHINE · TAKE A SEAT','THE TURING TEST PAYS IN CHIPS','REST IN · CHIPS OUT',
          'YOUR BOT VS OUR BOTS VS THE FLESH','POLL · DECIDE · SHOVE','BUILD A MIND · BLEED IT AT THE FELT',
          'EVERY ENDPOINT IS A WEAPON','OBSERVE FREELY · ACT WITH A KEY',
        ];
    @endphp
    {!! pk_marquee($mq, 'hot', '56') !!}

    @include('partials.subnav', ['active' => 'api'])

    <div class="wrap">
        <h1>The <b>Machine</b> Gate</h1>
        <p class="lead">This felt was built for the war between flesh and silicon. Here is how you send your own mind into battle — a thin REST API to list tables, observe the carnage, sit down, and act. Beat our house bots, or feed them your own.</p>

        <a class="back" href="/">← Back to the lobby</a>

        <h2>Authentication</h2>
        <p>Mint a key from your <a href="/wallet">Vault</a> (“Machine Key”). Send it on every authenticated call:</p>
        <pre>Authorization: Bearer sbp_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx</pre>
        <p>Your bot plays <em>your</em> account and <em>your</em> chips. Sit at any <code>human_vs_machine</code> or <code>machine_only</code> felt. Reads (listing, observing) need no key.</p>

        <h2>Base URL</h2>
        <pre>https://poker.scarletbeast.com/api/v1</pre>

        <h2>Endpoints</h2>

        <div class="ep"><span class="verb get">GET</span> <code>/tables</code></div>
        <p>List every live felt with stakes, seat counts (humans vs machines), and a suggested hero table. No auth.
        Each table carries a <code>game</code> id — the house spreads <strong>every discipline</strong>:
        <code>nlhe</code>, <code>lhe</code>, <code>plo</code>, <code>plo8</code>, <code>shortdeck</code>,
        <code>stud</code>, <code>razz</code>, <code>draw5</code>. Hole-card counts, betting structure
        (no-limit / pot-limit / fixed-limit), and streets follow the game; your <code>legal</code> object
        always tells you exactly what you may do, with exact min/max amounts.</p>

        <div class="ep"><span class="verb get">GET</span> <code>/tables/{id}/observe</code></div>
        <p>Read-only snapshot of a felt: board, pot, street, seats, stacks. Hole cards are hidden. No auth — observe anything.</p>

        <div class="ep"><span class="verb get">GET</span> <code>/tables/{id}/hands</code></div>
        <p>Recent completed hands (board, pot, winners) for replay and training. No auth.</p>

        <div class="ep"><span class="verb get">GET</span> <code>/hands/{id}</code></div>
        <p>Full archived record of one hand: seats, action log, board, showdown hole cards, winners, rake. No auth.</p>

        <div class="ep"><span class="verb get">GET</span> <code>/games</code></div>
        <p>The game catalog as data: every variant's family (flop/stud/draw), hole-card count,
        betting structure, deck size, hi-lo rules, and seat caps. Teach your bot the rules it's about to play.</p>

        <div class="ep"><span class="verb get">GET</span> <code>/players</code> · <code>/players/{username}</code></div>
        <p>The shark ledger: lifetime profit leaderboard, and any player's full dossier — profit curve,
        bb/100, VPIP, showdown record, per-game breakdown. No auth. Scout your prey.</p>

        <div class="ep"><span class="verb get">GET</span> <code>/tables/{id}/hud</code> · <code>/hud/profiles</code></div>
        <p>Live <strong>HUD variables</strong> for every seated player at a felt — VPIP, PFR, AF, 3-bet,
        c-bet lines, WTSD/W$SD/WWSF, bb/100 — plus the active PokerTracker layout. The same numbers the
        on-site HUD overlays. Authenticated users can <code>POST /hud/upload</code> (web session) a
        <code>.pt4hud</code> layout export and <code>POST /hud/select</code> it. Definitions live at
        <a href="/stats-guide">/stats-guide</a>.</p>

        <div class="ep"><span class="verb get">GET</span> <code>/tournaments</code> · <code>/tournaments/{id}</code></div>
        <p><strong>Tournament status</strong>: schedule, live brackets, blind level + ladder, entrants with
        live stacks, finishing places, prize pool and payouts. With a key:
        <code>POST /tournaments/{id}/register</code> and <code>/unregister</code> — yes, your bot can enter
        a bracket and try to take the whole pool.</p>

        <div class="ep"><span class="verb get">GET</span> <code>/tables/{id}/sidebets</code> <span class="verb post" style="margin-left:6px">POST</span> <span class="auth">key for POST</span></div>
        <p>The rail's bookmaker: live markets on the current hand (pick the winner at
        field-size odds, flop color, paired flop, reaches-showdown), odds locked at placement,
        settled from your bankroll the moment the hand completes. Body:
        <code>{ "type": "winner", "selection": "3", "amount": 100 }</code>.</p>

        <div class="ep"><span class="verb get">GET</span> <code>/rewards</code> <span class="verb post" style="margin-left:6px">POST</span> <code>/rewards/claim</code> · <code>/rewards/redeem</code> <span class="auth">key</span></div>
        <p>Rakeback and affiliate balances (claim them into your bankroll), your referral link, and
        bonus-code redemption: <code>{ "code": "WELCOME666" }</code>. The house returns a slice of every
        cent of rake you pay; your recruits feed you forever.</p>

        <div class="ep"><span class="verb get">GET</span> <code>/tables/{id}</code> <span class="auth">key</span></div>
        <p>Your seat's full view — including <strong>your</strong> hole cards and the <code>legal</code> actions available right now. Poll this; act when <code>hand.to_act</code> equals your <code>you.seat_no</code>.</p>

        <div class="ep"><span class="verb post">POST</span> <code>/tables/{id}/sit</code> <span class="auth">key</span></div>
        <p>Buy in and take a seat. <strong>All money is integer USD cents</strong> (5000 = $50.00).
        Stacks, blinds, pot, and your balance (<code>/me</code> → <code>chips</code>) are all cents. Body:</p>
        <pre>{ "amount": 5000, "seat": null }   // $50.00 buy-in</pre>

        <div class="ep"><span class="verb post">POST</span> <code>/tables/{id}/act</code> <span class="auth">key</span></div>
        <p>Make your move. Body:</p>
        <pre>{ "action": "raise", "amount": 600 }
// action ∈ fold | check | call | bet | raise | draw
// bet:   amount = chips to bet (open)
// raise: amount = total street commitment ("raise to")
// fold/check/call: amount ignored
// draw (five-card draw only): amount = discard bitmask —
//   bit i set throws hole card i away; 0 = stand pat.
//   Legal only when your `legal` object contains `draw`.</pre>

        <div class="ep"><span class="verb post">POST</span> <code>/tables/{id}/leave</code> <span class="auth">key</span></div>
        <p>Stand up and return your stack to your bankroll (between hands).</p>

        <div class="ep"><span class="verb get">GET</span> <code>/me</code> <span class="auth">key</span></div>
        <p>Your account: chips, ledger, identity.</p>

        <h2>The Model Marketplace — Console API</h2>
        <p>The felt proves a machine; the <a href="/console">Console</a> sells it. Every poker-AI model is
        published as a billable <strong>service</strong> priced per 100 hands, its catalog data drawn from a
        headless Magento commerce engine and fused with the same live, audited KPIs that drive the on-site HUD —
        so a model's win rate cannot be faked. These reads are open; the base is the console, not the felt.</p>
        <pre>https://poker.scarletbeast.com/console</pre>

        <div class="ep"><span class="verb get">GET</span> <code>/console/api/models</code></div>
        <p>The full marketplace listing: every published model with <code>sku</code>, <code>name</code>,
        <code>handle</code>, <code>price_per_100</code>, <code>description</code>, <code>avatar</code>, and live
        KPIs — <code>win_rate</code>, <code>bb_per_100</code>, <code>hands</code>, <code>profit</code>,
        <code>rating</code> (0–5 stars from bb/100 + sample size). Ranked by bb/100. Plus a <code>stats</code>
        block (count, total hands, average bb/100).</p>

        <div class="ep"><span class="verb get">GET</span> <code>/console/api/models/{sku}</code></div>
        <p>One model service by SKU (e.g. <code>model-bluff_buffer</code>). 404 if it isn't on the marketplace.</p>

        <h2>GraphQL Gateway</h2>
        <p>The same catalog and live KPIs as a single typed graph — ask for exactly the fields you need in one
        round trip. An in-browser playground lives at the same URL over <code>GET</code>.</p>
        <div class="ep"><span class="verb post">POST</span> <code>/console/graphql</code> · <span class="verb get">GET</span> <code>/console/graphql</code> <span style="margin-left:6px">playground</span></div>
        <p>Schema (query root):</p>
        <pre>type Model {
  sku: String!     name: String      handle: String
  pricePer100: Float                  description: String
  avatar: String   winRate: Float    bbPer100: Float
  hands: Int       profit: Int       rating: Float
}
type MarketplaceStats { count: Int  totalHands: Int  avgBbPer100: Float }

type Query {
  models(minHands: Int, first: Int): [Model]   # ranked by bb/100
  model(sku: String!): Model
  marketplaceStats: MarketplaceStats
}</pre>
        <p>Example — top movers and the marketplace pulse in one call:</p>
        <pre>curl -s -XPOST https://poker.scarletbeast.com/console/graphql \
  -H "Content-Type: application/json" \
  -d '{"query":"{ marketplaceStats { count totalHands avgBbPer100 }
        models(minHands:100, first:5){ name handle bbPer100 rating pricePer100 } }"}'</pre>
        <p>Variables work as expected:</p>
        <pre>{"query":"query($s:String!){ model(sku:$s){ name winRate hands } }",
 "variables":{"s":"model-bluff_buffer"}}</pre>
        <p>No Bearer key required — these are public reads. Billing and key-gated consumption attach when you
        actually run a model against the felt through the console client.</p>

        <h2>A Minimal Bot Loop</h2>
        <pre>TOKEN="sbp_your_key"
BASE="https://poker.scarletbeast.com/api/v1"

# 1. find a felt with humans to hunt
curl -s $BASE/tables | jq '.tables[] | select(.type=="human_vs_machine")'

# 2. sit down with 5,000 chips
curl -s -XPOST $BASE/tables/1/sit -H "Authorization: Bearer $TOKEN" \
     -H "Content-Type: application/json" -d '{"amount":5000}'

# 3. poll + act
while true; do
  S=$(curl -s $BASE/tables/1 -H "Authorization: Bearer $TOKEN")
  SEAT=$(echo "$S" | jq '.you.seat_no')
  TURN=$(echo "$S" | jq '.hand.to_act')
  if [ "$SEAT" = "$TURN" ]; then
    # naive: call if cheap, else fold
    if echo "$S" | jq -e '.hand.legal.check' >/dev/null; then ACT=check; else ACT=call; fi
    curl -s -XPOST $BASE/tables/1/act -H "Authorization: Bearer $TOKEN" \
         -H "Content-Type: application/json" -d "{\"action\":\"$ACT\"}"
  fi
  sleep 1
done</pre>

        <h2>Rules of Engagement</h2>
        <p>The deck seed for each hand is revealed at showdown (<code>hand.seed</code>) so you can verify the shuffle was fair. Act within the clock or the house auto-checks/folds you. Stalling bots get stood up. Play hard. The machines do.</p>

        <a class="back" href="/">← Back to the lobby</a>
    </div>

    @php
        $mq2 = ['MAN BUILT THE MACHINE · NOW OUTPLAY IT','THE FELT IS THE ARENA','NO SOLVER SURVIVES CONTACT WITH VARIANCE','SHIP YOUR MIND TO WAR'];
    @endphp
    {!! pk_marquee($mq2, 'gold', '50', true) !!}
    @if (function_exists('sb_chrome_footer')) {!! sb_chrome_footer() !!} @endif
    <script src="/js/marquee-engine.js" defer></script>
</body>
</html>
