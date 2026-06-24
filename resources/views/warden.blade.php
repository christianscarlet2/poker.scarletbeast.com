<!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>Warden · Train Randomizer</title>
<style>
  body{margin:0;background:#0b0e0c;color:#cfe9d6;font:15px/1.5 -apple-system,Segoe UI,Roboto,sans-serif}
  .wrap{max-width:680px;margin:40px auto;padding:0 18px}
  h1{font-weight:800;letter-spacing:.04em;color:#7ef0a6;margin:0 0 4px}
  .sub{color:#6f8c79;margin:0 0 26px;font-size:13px}
  .card{background:#11160f;border:1px solid #20301d;border-radius:14px;padding:22px 22px 18px;margin-bottom:18px}
  .pill{display:inline-block;padding:8px 18px;border-radius:999px;font-weight:800;letter-spacing:.06em;font-size:18px}
  .on{background:#0f3d1f;color:#67ff9b;box-shadow:0 0 22px #1f8f4a55}
  .off{background:#2a2320;color:#c98b6b}
  .row{display:flex;justify-content:space-between;padding:7px 0;border-top:1px solid #182411;font-size:14px}
  .k{color:#6f8c79}.v{color:#dff3e4;font-weight:600}
  .branches span{display:inline-block;min-width:20px;text-align:center;margin:2px;padding:2px 6px;border-radius:6px;background:#16241040;border:1px solid #20301d;color:#9fcfaf;font-size:12px}
  button{cursor:pointer;border:none;border-radius:10px;padding:11px 20px;font-weight:700;font-size:15px}
  .btn-on{background:#1f8f4a;color:#021}.btn-off{background:#3a2a22;color:#f0c2a6}
  .stale{color:#c98b6b}
</style></head>
<body><div class="wrap">
  <h1>⚖ WARDEN</h1>
  <p class="sub">The machine obeys the warden. Train-randomizer = per-hand random synapse dials / observer branch / game style on the bot fleet, for diverse PPO/CFR training data.</p>
  <div class="card">
    <div style="display:flex;align-items:center;justify-content:space-between">
      <span id="pill" class="pill off">…</span>
      <button id="toggle" class="btn-on" onclick="toggle()">…</button>
    </div>
    <div class="row"><span class="k">since</span><span class="v" id="since">—</span></div>
    <div class="row"><span class="k">fleet rows / 2 min</span><span class="v" id="rows">—</span></div>
    <div class="row"><span class="k">observer branches active (last 60)</span><span class="v branches" id="branches">—</span></div>
    <div class="row"><span class="k">last poll</span><span class="v" id="poll">—</span></div>
  </div>
</div>
<script>
var CSRF=document.querySelector('meta[name=csrf-token]').content, cur=false;
function fmt(ts){ if(!ts) return "—"; var d=new Date(ts*1000); return d.toLocaleString(); }
function render(s){
  cur = !!s.running;
  var pill=document.getElementById("pill"), btn=document.getElementById("toggle");
  pill.className="pill "+(cur?"on":"off"); pill.textContent="TRAIN RANDOMIZER: "+(cur?"RUNNING":"OFF");
  btn.className=cur?"btn-off":"btn-on"; btn.textContent=cur?"Turn OFF":"Turn ON";
  document.getElementById("since").textContent=fmt(s.since);
  var rows=document.getElementById("rows");
  rows.textContent=(s.rows_2min!==undefined?s.rows_2min:(s.db||"—"));
  rows.className="v"+((s.rows_2min!==undefined && s.rows_2min===0)?" stale":"");
  var b=document.getElementById("branches");
  b.innerHTML=(s.branches&&s.branches.length)?s.branches.map(function(x){return "<span>"+x+"</span>";}).join(""):"—";
  document.getElementById("poll").textContent=new Date().toLocaleTimeString();
}
function refresh(){ fetch("/api/admin/trainrand",{cache:"no-store",credentials:"same-origin"}).then(function(r){return r.json();}).then(render).catch(function(){}); }
function toggle(){ fetch("/api/admin/trainrand",{method:"POST",credentials:"same-origin",
  headers:{"Content-Type":"application/json","X-CSRF-TOKEN":CSRF},body:JSON.stringify({on:!cur})})
  .then(function(r){return r.json();}).then(render).catch(function(){}); }
refresh(); setInterval(refresh, 3000);
</script>
</body></html>
