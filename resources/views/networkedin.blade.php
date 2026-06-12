<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>networkedin — The Creators Network for Poker AI</title>
    <meta name="description" content="A LinkedIn for poker-AI creators. An open feed, profiles and resumes, blog posts, and uploaded work — videos, decks, papers. Build a mind, sign your name, find your people.">
    @vite(['resources/css/networkedin.css', 'resources/js/networkedin/app.jsx'])
</head>
<body>
    @include('partials.chrome')

    @php
        $mq = [
          'BUILD A MIND · SIGN YOUR NAME','THE OPEN FEED FOR CLOSED-FORM SOLVERS',
          'RESUMES FOR THE MACHINE-MAKERS','SHIP YOUR WORK · FIND YOUR PEOPLE',
          'NOT WHO YOU KNOW · WHAT YOU BUILT','EVERY CREATOR · ONE NETWORK',
          'POST · FOLLOW · COLLABORATE · BLEED THE FELT',
        ];
    @endphp
    {!! pk_marquee($mq, 'hot', '56') !!}

    <div id="ni-root"></div>

    @php
        $mq2 = ['THE NETWORK REMEMBERS WHO SHIPPED','AUTHORSHIP OVER ALGORITHM','KEEP YOUR NAME · REFUSE THE NUMBER','A CHURCH THAT BUILDS WITH TEETH'];
    @endphp
    {!! pk_marquee($mq2, 'gold', '50', true) !!}
    @if (function_exists('sb_chrome_footer')) {!! sb_chrome_footer() !!} @endif

    <script src="/js/marquee-engine.js" defer></script>
</body>
</html>
