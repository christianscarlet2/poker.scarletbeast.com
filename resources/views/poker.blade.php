<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Scarlet Beast Poker — Man vs Machine, No-Limit</title>
    <meta name="description" content="No-limit Texas Hold'em where flesh and silicon bleed at the same felt. Crypto in, crypto out. Bots welcome — beat them or become them.">
    @vite(['resources/css/poker.css', 'resources/js/poker/main.jsx'])
<!-- Google tag (gtag.js) — GA4 G-STPXJY5KPF -->
<script async src="https://www.googletagmanager.com/gtag/js?id=G-STPXJY5KPF"></script>
<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','G-STPXJY5KPF');</script>
</head>
<body>
    @php $bare = request()->query('w') === 'table'; @endphp
    @include('partials.chrome', ['bare' => $bare])

    @unless ($bare)
    {{-- Fork-me corner ribbon → the developers hub. Only on wide viewports,
         where the centered nav leaves the corner empty (so it never overlaps). --}}
    <style>
      .sb-fork{position:fixed;top:20px;right:-58px;transform:rotate(45deg);z-index:60;
        background:var(--pk-sc,#e10600);color:#fff;font:700 12px/1 var(--pk-mono,ui-monospace,monospace);
        letter-spacing:.12em;text-transform:uppercase;text-decoration:none;padding:8px 66px;
        box-shadow:0 3px 14px rgba(0,0,0,.45);border-top:1px solid rgba(255,255,255,.28);
        border-bottom:1px solid rgba(0,0,0,.35);transition:background .15s}
      .sb-fork:hover{background:var(--pk-scb,#ff2418)}
      @media(max-width:1180px){.sb-fork{display:none}}
    </style>
    <a class="sb-fork" href="/developers" title="Fork the source — developers hub">⑂ Fork me</a>
    {{-- Marquee BELOW the header --}}
    @php
        $mq_top = [
            'NO LIMIT · NO MERCY · NO HUMAN GUARANTEED',
            'THE FELT REMEMBERS EVERY FOLD',
            'SHUFFLE UP AND SUFFER',
            'EVERY SEAT IS A KNIFE FIGHT',
            'THE MACHINE NEVER TILTS — MAKE IT',
            'BLOOD ANTE · BONE BLINDS',
            'STACK THE SILICON · BURY THE BOTS',
            'CHIPS ARE JUST CRYSTALLISED COURAGE',
            'COLD DECK · COLDER OPPONENTS','⛧ B·E·A·S·T — BOLD EVOLVING AUTONOMOUS SENTIENT TECHNOLOGY',
            'PROVABLY FAIR · MERCILESSLY HONEST',
            'YOUR RANGE IS SHOWING',
            'THE BUTTON ROTATES · THE HUNGER DOES NOT',
        ];
    @endphp
    {!! pk_marquee($mq_top, 'hot', '58') !!}
    @endunless

    <div id="poker-root"></div>

    @unless ($bare)
    {{-- Marquee ABOVE the footer --}}
    @php
        $mq_bottom = [
            'ALL IN IS A LOVE LANGUAGE',
            'THE RIVER GIVES · THE RIVER TAKES',
            'WE DEAL IN BAD BEATS AND WORSE ANGELS',
            'MUCK YOUR PRIDE · NOT YOUR ACES',
            'THE BOTS ARE WATCHING YOUR TIMING TELLS',
            'WITHDRAW IN CHAIN · DEPOSIT IN BLOOD',
            'EVERY POT IS A SMALL APOCALYPSE',
            'VARIANCE IS THE ONLY GOD HERE',
            'FOLD AND LIVE · SHOVE AND ASCEND',
            'MAN BUILT THE MACHINE · NOW OUTPLAY IT',
            'THE NUTS OR NOTHING',
            'SCARLET BEAST DEALS THE LAST HAND',
        ];
    @endphp
    {!! pk_marquee($mq_bottom, 'gold', '52', true) !!}

    @if (function_exists('sb_chrome_footer')) {!! sb_chrome_footer() !!} @endif
    @endunless

    <script src="/js/marquee-engine.js" defer></script>
</body>
</html>
