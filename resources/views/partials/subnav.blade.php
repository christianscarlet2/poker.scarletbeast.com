{{-- Cross-navigation strip for the standalone server-rendered pages
     (api-docs / developers / download). Pass $active = api|dev|download. --}}
<style>
  .sb-subnav{max-width:1040px;margin:16px auto 0;padding:0 22px;display:flex;flex-wrap:wrap;gap:9px;align-items:center}
  .sb-subnav a{font-family:var(--pk-mono,ui-monospace,monospace);font-size:12px;letter-spacing:.08em;text-transform:uppercase;
    text-decoration:none;color:var(--pk-dim,#a9a29a);border:1px solid rgba(255,255,255,.12);border-radius:7px;padding:6px 12px;
    transition:border-color .15s,color .15s,background .15s}
  .sb-subnav a:hover{color:var(--pk-scs,#ff6a5a);border-color:rgba(255,36,24,.45)}
  .sb-subnav a.on{color:#fff;background:var(--pk-sc,#e10600);border-color:var(--pk-sc,#e10600)}
</style>
<nav class="sb-subnav">
    <a href="/">♠ Lobby</a>
    <a href="/api-docs" class="{{ ($active ?? '') === 'api' ? 'on' : '' }}">API Docs</a>
    <a href="/developers" class="{{ ($active ?? '') === 'dev' ? 'on' : '' }}">Developers</a>
    <a href="/download" class="{{ ($active ?? '') === 'download' ? 'on' : '' }}">Get the Apps</a>
</nav>
