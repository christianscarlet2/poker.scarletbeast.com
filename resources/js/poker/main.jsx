import React, { useState, useEffect, useCallback, createContext, useContext } from 'react';
import { createRoot } from 'react-dom/client';
import { api } from './api.js';
import Felt, { ActionBar } from './Felt.jsx';
import Marquee, { PHRASES } from './Marquee.jsx';
import { Card, Board } from './cards.jsx';
import { UnitProvider, useUnit, Money, usd, dollars } from './money.jsx';
import { SkinProvider, useSkin, SkinSwitcher, DesktopTitlebar, MobileNav } from './skin.jsx';
import { STAT_INFO } from './statsInfo.js';
import { toggleSound, soundMuted } from './sounds.js';
import { CasinoLobby, CasinoGamePage } from './casino.jsx';
import { useTableChat, ChatPanel } from './chat.jsx';

/* ----------------------------------------------------- app / window context */
// "Bare" = a standalone table window: just the felt, no shared chrome.
const BARE = typeof window !== 'undefined'
  && new URLSearchParams(window.location.search).get('w') === 'table';
// Desktop (Electron) injects this in preload; mobile (RN WebView) injects scarletbeastApp.
const DESKTOP = typeof window !== 'undefined' && !!(window.scarletbeastDesktop && window.scarletbeastDesktop.isDesktop);
const INAPP = DESKTOP || (typeof window !== 'undefined' && !!window.scarletbeastApp);

function tablePath(id, observe = false) { return `${observe ? '/observe' : '/tables'}/${id}`; }

// How a table opens depends on where we are: desktop pops a new OS window, mobile
// swaps to a full-screen bare view, a plain browser keeps in-page SPA nav.
function openTable(go, path) {
  if (DESKTOP) { window.open(path + '?w=table', '_blank', 'noopener'); return; }
  if (INAPP) { window.location.assign(path + '?w=table'); return; }
  go(path);
}
// Leaving a bare table window: close it on desktop, return to the lobby elsewhere.
function exitBare() {
  if (DESKTOP) { window.close(); return; }
  window.location.assign('/');
}

/* ------------------------------------------------------------------ router */
const Nav = createContext({ path: '/', go: () => {} });
function useNav() { return useContext(Nav); }

function useRouter() {
  const [path, setPath] = useState(window.location.pathname);
  useEffect(() => {
    const onPop = () => setPath(window.location.pathname);
    window.addEventListener('popstate', onPop);
    return () => window.removeEventListener('popstate', onPop);
  }, []);
  const go = useCallback((to) => {
    if (to === window.location.pathname) return;
    window.history.pushState({}, '', to);
    setPath(to);
    window.scrollTo(0, 0);
  }, []);
  return { path, go };
}

function A({ href, children, ...rest }) {
  const { go } = useNav();
  return <a href={href} onClick={(e) => { e.preventDefault(); go(href); }} {...rest}>{children}</a>;
}

// A link that opens a table — in a new bare window inside the apps, in-page on the web.
function TableLink({ id, observe = false, children, ...rest }) {
  const { go } = useNav();
  const path = tablePath(id, observe);
  return <a href={path} onClick={(e) => { e.preventDefault(); openTable(go, path); }} {...rest}>{children}</a>;
}

/* -------------------------------------------------------------- session ctx */
const Me = createContext(null);
function useMe() { return useContext(Me); }

/* ----------------------------------------------------------------- app bar */
function AppBar() {
  const { me, refresh } = useMe();
  const { go } = useNav();
  const { unit, toggle } = useUnit();
  const logout = async () => { await api.post('/auth/logout'); await refresh(); go('/'); };
  return (
    <div className="wrap toprow">
      <div style={{ display: 'flex', gap: 14, alignItems: 'center' }}>
        <A href="/" className="badge hvm">♠ LOBBY</A>
        <A href="/tournaments" className="badge mo">BRACKETS</A>
        <A href="/casino" className="badge hvm">PIT</A>
        <A href="/players" className="badge gold">SHARKS</A>
        <a href="/api-docs" className="badge">API</a>
        <a href="/developers" className="badge">DEV</a>
        <a href="/download" className="badge">GET APP</a>
        {me && <A href="/wallet" className="badge ho">VAULT</A>}
        {me && <A href="/rewards" className="badge gold">TRIBUTE</A>}
        {me && me.is_admin && <A href="/admin" className="badge mo">ALTAR</A>}
      </div>
      <div style={{ display: 'flex', gap: 12, alignItems: 'center' }}>
        <SoundToggle />
        <SkinSwitcher />
        <button className="badge" title="Toggle cash / big-blind display" onClick={toggle}>{unit === 'bb' ? 'BB' : '$'}</button>
        {me ? (
          <>
            <span className="chips-pill">{me.avatar} {me.username} · {usd(me.chips)}</span>
            <button className="btn ghost" onClick={logout}>Leave</button>
          </>
        ) : (
          <>
            <A href="/login" className="btn ghost">Enter</A>
            <A href="/register" className="btn">Enlist</A>
          </>
        )}
      </div>
    </div>
  );
}

/* ------------------------------------------------------------------- home */
function Home() {
  const [lobby, setLobby] = useState(null);
  const [tab, setTab] = useState('human_vs_machine');
  useEffect(() => {
    const load = () => api.get('/api/lobby').then(setLobby).catch(() => {});
    load();
    const id = setInterval(load, 4000);
    return () => clearInterval(id);
  }, []);

  const tabs = [
    ['human_vs_machine', 'Human vs Machine'],
    ['machine_only', 'Machine Only'],
    ['human_only', 'Human Only'],
  ];
  const phraseSet = tab === 'machine_only' ? PHRASES.machine : tab === 'human_only' ? PHRASES.flesh : PHRASES.arena;
  const tables = (lobby?.tables || []).filter(t => t.type === tab);

  return (
    <>
      <HeroLive heroId={lobby?.hero_table_id} />

      <div className="wrap">
        <Marquee items={PHRASES.arena} cls="hot flush" speed={54} />

        <div className="callouts">
          <div className="callout">
            <div className="ic">🩸</div>
            <h3>Bleed In, Cash Out</h3>
            <p>Fund your seat with BTC or ETH. The maw scans the chain, credits your chips, and sweeps to cold. Withdraw to any address at the live rate.</p>
          </div>
          <div className="callout">
            <div className="ic">⚙️</div>
            <h3>The Machines Have a Buy-In</h3>
            <p>Every felt can be stormed by AI bots through our public API. Beat them, or unleash your own. The Turing test pays real chips.</p>
          </div>
          <div className="callout">
            <div className="ic">🃏</div>
            <h3>Provably Cruel</h3>
            <p>Every shuffle is seeded and revealed at showdown. No rigged decks — only bad beats you earned. The felt remembers everything.</p>
          </div>
        </div>

        <Marquee items={phraseSet} cls="bone flush" speed={50} rev />

        <div className="tabs">
          {tabs.map(([k, label]) => (
            <button key={k} className={`tab${tab === k ? ' on' : ''}`} onClick={() => setTab(k)}>{label}</button>
          ))}
        </div>

        {!lobby ? <div className="center-msg">Summoning the felts…</div> : (
          <div className="tbl-list">
            {tables.length === 0 && <div className="center-msg">No felts of this kind are open. The auto-dealer will raise one shortly.</div>}
            {tables.map(t => <TableCard key={t.id} t={t} />)}
          </div>
        )}
      </div>
    </>
  );
}

function HeroLive({ heroId }) {
  const [state, setState] = useState(null);
  useEffect(() => {
    if (!heroId) return;
    const load = () => api.get(`/api/tables/${heroId}/observe`).then(d => setState({ table: d.table, hand: d.hand, you: null })).catch(() => {});
    load();
    const id = setInterval(load, 1800);
    return () => clearInterval(id);
  }, [heroId]);

  return (
    <div className="hero">
      <div className="wrap hero-grid">
        <div>
          <div className="kick">{state?.table?.game_name || "No-Limit Texas Hold'em"} · Man vs Machine</div>
          <h1>The felt where <b>flesh</b> bleeds <b>silicon</b>.</h1>
          <p className="sub">Real chips. Real crypto. Real bots. Sit down against opponents that never tilt and never sleep — or watch the machines devour each other. This is the last honest war.</p>
          <div style={{ display: 'flex', gap: 12, marginTop: 22, flexWrap: 'wrap' }}>
            <A href="/register" className="btn big">Claim a Seat</A>
            {heroId && <TableLink id={heroId} observe className="btn ghost big">Observe the Carnage</TableLink>}
            <a href="/download" className="btn ghost big">↓ Get the Apps</a>
          </div>
        </div>
        <div>
          {heroId
            ? <Felt state={state} observer quiet />
            : <div className="center-msg">No live felt yet — the dealer is shuffling.</div>}
        </div>
      </div>
    </div>
  );
}

function TableCard({ t }) {
  const typeBadge = { human_vs_machine: 'hvm', machine_only: 'mo', human_only: 'ho' }[t.type];
  const dots = [];
  for (let i = 0; i < t.max_seats; i++) {
    const cls = i < t.humans ? 'h' : i < t.humans + t.bots ? 'b' : '';
    dots.push(<div key={i} className={`dot ${cls}`} />);
  }
  return (
    <div className="tcard">
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
        <div className="nm">{t.name}</div>
        <div style={{ display: 'flex', gap: 6 }}>
          {t.game_short && <span className="badge gold" title={t.game_name}>{t.game_short}</span>}
          <span className={`badge ${typeBadge}`}>{t.type.replace(/_/g, ' ')}</span>
        </div>
      </div>
      <div className="meta"><span>Blinds {usd(t.sb)}/{usd(t.bb)}</span><span>{t.players}/{t.max_seats} seated</span></div>
      <div className="seatbar">{dots}</div>
      <div className="meta"><span>Buy-in {usd(t.min_buy_in)}–{usd(t.max_buy_in)}</span><span>Ⓗ{t.humans} ⚙{t.bots}</span></div>
      <div style={{ display: 'flex', gap: 8 }}>
        <TableLink id={t.id} className="btn" style={{ flex: 1, textAlign: 'center' }}>Sit Down</TableLink>
        <TableLink id={t.id} observe className="btn ghost">Watch</TableLink>
      </div>
    </div>
  );
}

/* ----------------------------------------------------------------- casino */
function CasinoRoute({ game }) {
  const { me, refresh } = useMe();
  if (!game) return <CasinoLobby A={A} />;
  return <CasinoGamePage game={game} me={me} refresh={refresh} A={A} />;
}

/* ------------------------------------------------------------------ sound */
function SoundToggle() {
  const [on, setOn] = useState(!soundMuted());
  return (
    <button className="badge" title="Table sounds" onClick={() => setOn(toggleSound())}>
      {on ? '🔊' : '🔇'}
    </button>
  );
}

/* -------------------------------------------------------------------- hud */
// Live PT4-style HUD: poll the per-table stats, honor the on/off preference.
function useHud(tableId) {
  const [hud, setHud] = useState(null);
  const [on, setOn] = useState(() => { try { return localStorage.getItem('sbp_hud') !== 'off'; } catch (e) { return true; } });
  useEffect(() => {
    if (!on || !tableId) { setHud(null); return; }
    const load = () => api.get(`/api/tables/${tableId}/hud`).then(setHud).catch(() => {});
    load();
    const iv = setInterval(load, 20000);
    return () => clearInterval(iv);
  }, [tableId, on]);
  const toggle = () => setOn(o => { const n = !o; try { localStorage.setItem('sbp_hud', n ? 'on' : 'off'); } catch (e) {} return n; });
  return { hud: on ? hud : null, hudOn: on, hudToggle: toggle };
}

// HUD toggle + (for the logged-in) profile picker and .pt4hud upload.
function HudControls({ hudOn, hudToggle, onChange }) {
  const { me } = useMe();
  const [open, setOpen] = useState(false);
  const [profiles, setProfiles] = useState(null);
  const [selected, setSelected] = useState(null);
  const [msg, setMsg] = useState('');

  const load = () => api.get('/api/hud/profiles').then(d => { setProfiles(d.profiles); setSelected(d.selected); }).catch(() => {});
  useEffect(() => { if (open) load(); }, [open]);

  const pick = async (id) => {
    try { await api.post('/api/hud/select', { profile_id: id }); setSelected(id); setMsg('HUD armed.'); onChange?.(); }
    catch (e) { setMsg(e.message); }
  };
  const up = async (e) => {
    const f = e.target.files?.[0];
    if (!f) return;
    const fd = new FormData();
    fd.append('file', f);
    try { await api.post('/api/hud/upload', fd); setMsg('Layout imported.'); load(); onChange?.(); }
    catch (err) { setMsg(err.message); }
    e.target.value = '';
  };

  return (
    <span style={{ position: 'relative', display: 'inline-flex', gap: 6 }}>
      <button className={`badge${hudOn ? ' gold' : ''}`} title="Toggle the stat overlay" onClick={hudToggle}>HUD {hudOn ? 'ON' : 'OFF'}</button>
      {me && <button className="badge" title="HUD profiles" onClick={() => setOpen(o => !o)}>⚙</button>}
      {open && me && (
        <div className="hud-pop">
          <div className="hint" style={{ marginBottom: 8 }}>PokerTracker 4 layouts (.pt4hud)</div>
          {!profiles ? <div className="hint">loading…</div> : profiles.map(p => (
            <div key={p.id} className="hud-pick" onClick={() => pick(p.id)}>
              <span>{selected === p.id ? '◉' : '○'} {p.name}</span>
              {p.user_id !== null && (
                <button className="badge" onClick={async (e) => { e.stopPropagation(); await api.del(`/api/hud/profiles/${p.id}`); load(); }}>✕</button>
              )}
            </div>
          ))}
          <label className="btn ghost" style={{ display: 'block', textAlign: 'center', marginTop: 10, cursor: 'pointer' }}>
            Upload .pt4hud
            <input type="file" accept=".pt4hud" style={{ display: 'none' }} onChange={up} />
          </label>
          {msg && <div className="hint" style={{ marginTop: 6 }}>{msg}</div>}
        </div>
      )}
    </span>
  );
}

/* ------------------------------------------------------------- table page */
function TablePage({ id }) {
  const { me, refresh } = useMe();
  const { go } = useNav();
  const [state, setState] = useState(null);
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState('');
  const [buyAmt, setBuyAmt] = useState(0);
  const { hud, hudOn, hudToggle } = useHud(id);
  const { messages, effects, send } = useTableChat(id);

  const load = useCallback(() => {
    api.get(`/api/tables/${id}/state`).then(setState).catch(e => setErr(e.message));
  }, [id]);

  useEffect(() => {
    load();
    const iv = setInterval(load, 1500);
    return () => clearInterval(iv);
  }, [load]);

  useEffect(() => {
    if (state && !buyAmt) setBuyAmt(state.table.max_buy_in);
  }, [state]);

  const seated = state?.you?.seat_no != null;

  const act = async (action, amount = 0) => {
    setBusy(true); setErr('');
    try { const r = await api.post(`/api/tables/${id}/act`, { action, amount }); setState(r.state ? { ...state, hand: r.state } : state); load(); }
    catch (e) { setErr(e.message); }
    finally { setBusy(false); }
  };
  const sit = async () => {
    setBusy(true); setErr('');
    try { await api.post(`/api/tables/${id}/sit`, { amount: buyAmt }); await refresh(); load(); }
    catch (e) { setErr(e.message); }
    finally { setBusy(false); }
  };
  const leave = async () => {
    setBusy(true); setErr('');
    try { await api.post(`/api/tables/${id}/leave`); await refresh(); load(); }
    catch (e) { setErr(e.message); }
    finally { setBusy(false); }
  };

  if (!state) return <div className="wrap"><div className="center-msg">{err || 'Approaching the felt…'}</div></div>;
  const t = state.table;

  return (
    <div className="wrap felt-wrap">
      <div className="toprow">
        <div>{BARE ? <button className="badge" onClick={exitBare}>{DESKTOP ? '✕ CLOSE' : '← LOBBY'}</button> : <A href="/" className="badge">← LOBBY</A>} <strong style={{ marginLeft: 10 }}>{t.name}</strong> <span className="mono" style={{ color: 'var(--pk-dim)' }}> · {t.game_name ? `${t.game_name} · ` : ''}{usd(t.sb)}/{usd(t.bb)} · {t.type.replace(/_/g, ' ')}</span></div>
        <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
          <SoundToggle />
          <HudControls hudOn={hudOn} hudToggle={hudToggle} />
          {me ? <span className="chips-pill">{usd(me.chips)}</span> : <A href="/login" className="btn ghost">Enter to play</A>}
        </div>
      </div>

      <Felt state={state} mySeat={state.you?.seat_no} hud={hud} effects={effects} />

      {me && (
        <ActionBar state={{ ...state, table: { ...t, action_timeout: 25 } }} onAct={act} busy={busy} />
      )}

      <div style={{ display: 'flex', gap: 10, justifyContent: 'center', marginTop: 14, alignItems: 'center', flexWrap: 'wrap' }}>
        {me && !seated && t.type !== 'machine_only' && (
          <>
            <span className="mono" style={{ color: 'var(--pk-gold)' }}>$</span>
            <input style={{ width: 130 }} type="number" step="0.01" value={dollars(buyAmt)}
              min={(t.min_buy_in / 100).toFixed(2)} max={(t.max_buy_in / 100).toFixed(2)}
              onChange={e => setBuyAmt(Math.round(parseFloat(e.target.value || '0') * 100))} />
            <button className="btn big" disabled={busy} onClick={sit}>Buy in</button>
            <span className="mono" style={{ color: 'var(--pk-dim)', fontSize: 12 }}>({usd(t.min_buy_in)}–{usd(t.max_buy_in)})</span>
          </>
        )}
        {me && seated && <button className="btn ghost" disabled={busy} onClick={leave}>Stand Up</button>}
        {!me && <A href="/login" className="btn big">Enter to take a seat</A>}
        {t.type === 'machine_only' && <span className="mono" style={{ color: 'var(--pk-dim)' }}>Machine-only felt — observe the silicon, or send a bot via the API.</span>}
      </div>
      {err && <div className="err" style={{ textAlign: 'center' }}>{err}</div>}

      <div className="row">
        <ChatPanel tableId={id} me={me} seats={state.seats} send={send} messages={messages} />
        <SideBetsPanel tableId={id} />
      </div>
    </div>
  );
}

/* ---------------------------------------------------------------- observe */
function Observe({ id }) {
  const [state, setState] = useState(null);
  const [hands, setHands] = useState([]);
  const { hud, hudOn, hudToggle } = useHud(id);
  const { messages, effects, send } = useTableChat(id);
  const { me } = useMe();
  useEffect(() => {
    const load = () => {
      api.get(`/api/tables/${id}/observe`).then(d => setState({ table: d.table, hand: d.hand, you: null })).catch(() => {});
      api.get(`/api/tables/${id}/hands`).then(d => setHands(d.hands || [])).catch(() => {});
    };
    load();
    const iv = setInterval(load, 1800);
    return () => clearInterval(iv);
  }, [id]);

  if (!state) return <div className="wrap"><div className="center-msg">Pulling up a chair to watch…</div></div>;
  const t = state.table;
  const seatName = (s) => state.hand?.players?.[s]?.name || `Seat ${s}`;
  const feed = (state.hand?.actions || []).slice(-12).reverse();

  return (
    <div className="wrap felt-wrap">
      <div className="toprow">
        <div>
          {BARE ? <button className="badge" onClick={exitBare}>{DESKTOP ? '✕ CLOSE' : '← LOBBY'}</button> : <A href="/" className="badge">← LOBBY</A>}{' '}
          <strong style={{ marginLeft: 10 }}>OBSERVING · {t.name}</strong>
          <span className="mono" style={{ color: 'var(--pk-dim)', marginLeft: 8 }}>
            {t.game_name || ''} · {usd(t.sb)}/{usd(t.bb)}
          </span>
        </div>
        <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
          <SoundToggle />
          <HudControls hudOn={hudOn} hudToggle={hudToggle} />
          <TableLink id={id} className="btn ghost">Sit Down</TableLink>
        </div>
      </div>
      <Felt state={state} observer hud={hud} effects={effects} />
      <Marquee items={PHRASES.machine} cls="hot flush" speed={48} />

      <div className="row">
        <ChatPanel tableId={id} me={me} seats={(Object.values(state.hand?.players || {})).map(p => ({ seat_no: p.seat, name: p.name, status: 'sitting' }))} send={send} messages={messages} />
        <SideBetsPanel tableId={id} />
      </div>

      <div className="row">
        <div className="panel">
          <h2>Live Action</h2>
          {feed.length === 0 ? <div className="hint">The dealer is shuffling…</div> : (
            <table className="tbl">
              <tbody>
                {feed.map((a, i) => (
                  <tr key={i}>
                    <td className="mono" style={{ color: 'var(--pk-dim)' }}>{a.street}</td>
                    <td>{seatName(a.seat)}</td>
                    <td>
                      {a.action === 'draw'
                        ? (a.amount === 0 ? 'stands pat' : `draws ${a.amount}`)
                        : <>{a.action.replace(/_/g, ' ')}{a.amount > 0 ? ` ${usd(a.amount)}` : ''}</>}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>

        <div className="panel">
          <h2>Recent Hands</h2>
          <table className="tbl">
            <thead><tr><th>#</th><th>Board</th><th>Pot</th><th>Winner</th><th></th></tr></thead>
            <tbody>
              {hands.map(h => (
                <tr key={h.id}>
                  <td>{h.hand_no}</td>
                  <td>{(h.board || []).join(' ') || '—'}</td>
                  <td>{usd(h.pot)}</td>
                  <td>{(h.winners || []).map(w => `#${w.seat} +${usd(w.amount)}${w.pot_kind ? ` (${w.pot_kind})` : ''}`).join(', ')}</td>
                  <td><A href={`/replay/${h.id}`} className="badge">▶ replay</A></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}

/* ----------------------------------------------------------------- replay */
function Replay({ id }) {
  const [hand, setHand] = useState(null);
  const [step, setStep] = useState(0);     // actions revealed so far
  const [playing, setPlaying] = useState(false);
  const [err, setErr] = useState('');

  useEffect(() => {
    api.get(`/api/hands/${id}`).then(d => { setHand(d.hand); setStep(0); }).catch(e => setErr(e.message));
  }, [id]);

  useEffect(() => {
    if (!playing || !hand) return;
    const iv = setInterval(() => {
      setStep(s => {
        if (s >= (hand.actions || []).length) { setPlaying(false); return s; }
        return s + 1;
      });
    }, 700);
    return () => clearInterval(iv);
  }, [playing, hand]);

  if (err) return <div className="wrap"><div className="center-msg">{err}</div></div>;
  if (!hand) return <div className="wrap"><div className="center-msg">Exhuming the hand…</div></div>;

  const actions = hand.actions || [];
  const done = step >= actions.length;
  const visible = actions.slice(0, step);

  // Board reveal follows the furthest street the visible actions have reached.
  const streetRank = { preflop: 0, flop: 1, turn: 2, river: 3 };
  const boardCount = { 0: 0, 1: 3, 2: 4, 3: 5 };
  const reached = visible.reduce((m, a) => Math.max(m, streetRank[a.street] ?? 0), 0);
  const noBoard = ['stud', 'razz', 'draw5'].includes(hand.game_type);
  const board = noBoard ? [] : (hand.board || []).slice(0, done ? 5 : (boardCount[reached] ?? 0));

  // Pot reconstructed from the visible actions (draw logs cards, not chips).
  const pot = visible.reduce((sum, a) => sum + (a.action === 'draw' ? 0 : (a.amount || 0)), 0);
  const folded = new Set(visible.filter(a => a.action === 'fold').map(a => a.seat));
  const seats = Object.values(hand.seats || {});
  const seatName = (s) => (hand.seats?.[s]?.name) || `Seat ${s}`;

  return (
    <div className="wrap felt-wrap">
      <div className="toprow">
        <div>
          <A href={`/observe/${hand.table_id}`} className="badge">← {hand.table_name || 'TABLE'}</A>{' '}
          <strong style={{ marginLeft: 10 }}>REPLAY · HAND #{hand.hand_no}</strong>
          <span className="mono" style={{ color: 'var(--pk-dim)', marginLeft: 8 }}>{hand.game_name}</span>
        </div>
        <div className="mono" style={{ color: 'var(--pk-gold)' }}>POT {usd(done ? hand.pot : pot)}</div>
      </div>

      <div className="felt" style={{ minHeight: 180 }}>
        <div className="center">
          {!noBoard && <Board cards={board} />}
          <div className="pot">POT · {usd(done ? hand.pot : pot)}</div>
          <div className="street">{done ? 'COMPLETE' : (visible[visible.length - 1]?.street || 'DEAL').toUpperCase()}</div>
        </div>
      </div>

      <div style={{ display: 'flex', gap: 10, justifyContent: 'center', margin: '14px 0', alignItems: 'center' }}>
        <button className="btn ghost" onClick={() => { setPlaying(false); setStep(0); }}>⏮</button>
        <button className="btn ghost" onClick={() => { setPlaying(false); setStep(s => Math.max(0, s - 1)); }}>‹ back</button>
        <button className="btn gold" onClick={() => setPlaying(p => !p)}>{playing ? '⏸ pause' : '▶ play'}</button>
        <button className="btn ghost" onClick={() => { setPlaying(false); setStep(s => Math.min(actions.length, s + 1)); }}>next ›</button>
        <button className="btn ghost" onClick={() => { setPlaying(false); setStep(actions.length); }}>⏭</button>
        <span className="mono" style={{ color: 'var(--pk-dim)' }}>{step}/{actions.length}</span>
      </div>

      <div className="row">
        <div className="panel">
          <h2>Action</h2>
          <table className="tbl">
            <tbody>
              {visible.length === 0 && <tr><td className="hint">Press play — the cards are about to fly.</td></tr>}
              {visible.slice().reverse().map((a, i) => (
                <tr key={visible.length - i} style={i === 0 ? { color: 'var(--pk-gold)' } : {}}>
                  <td className="mono" style={{ color: 'var(--pk-dim)' }}>{a.street}</td>
                  <td>{seatName(a.seat)}</td>
                  <td>{a.action === 'draw' ? (a.amount === 0 ? 'stands pat' : `draws ${a.amount}`) : `${a.action.replace(/_/g, ' ')}${a.amount > 0 ? ' ' + usd(a.amount) : ''}`}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        <div className="panel">
          <h2>Seats {done ? '· Showdown' : ''}</h2>
          <table className="tbl">
            <tbody>
              {seats.map(s => {
                const cards = done ? (hand.hole_cards?.[s.seat] || []) : [];
                const win = done ? (hand.winners || []).filter(w => w.seat === s.seat) : [];
                return (
                  <tr key={s.seat} style={{ opacity: folded.has(s.seat) ? 0.45 : 1 }}>
                    <td>#{s.seat}</td>
                    <td><A href={`/player/${encodeURIComponent(s.name)}`}>{s.name}</A>{s.is_bot ? ' ⚙' : ''}</td>
                    <td style={{ display: 'flex', gap: 3 }}>
                      {cards.length
                        ? cards.map((c, i) => <Card key={i} code={c} sm />)
                        : <span className="mono" style={{ color: 'var(--pk-dim)' }}>{folded.has(s.seat) ? 'folded' : '— —'}</span>}
                    </td>
                    <td style={{ color: 'var(--pk-gold)' }}>
                      {win.map((w, i) => <div key={i}>+{usd(w.amount)}{w.pot_kind ? ` ${w.pot_kind.toUpperCase()}` : ''} {w.hand || ''}</div>)}
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
          {done && hand.rake > 0 && <div className="hint">House rake: {usd(hand.rake)}</div>}
        </div>
      </div>
    </div>
  );
}

/* ----------------------------------------------------- player statistics */
// Sharkscope-style cumulative profit curve, drawn as a bare SVG line.
function ProfitGraph({ points }) {
  if (!points || points.length < 2) {
    return <div className="hint" style={{ padding: 30, textAlign: 'center' }}>Not enough hands for a curve yet.</div>;
  }
  const W = 760, H = 220, PAD = 8;
  const min = Math.min(0, ...points);
  const max = Math.max(0, ...points);
  const span = Math.max(1, max - min);
  const x = (i) => PAD + (i / (points.length - 1)) * (W - PAD * 2);
  const y = (v) => PAD + (1 - (v - min) / span) * (H - PAD * 2);
  const path = points.map((v, i) => `${i ? 'L' : 'M'}${x(i).toFixed(1)},${y(v).toFixed(1)}`).join(' ');
  const last = points[points.length - 1];
  const up = last >= 0;
  return (
    <svg viewBox={`0 0 ${W} ${H}`} style={{ width: '100%', height: 'auto', display: 'block' }}>
      <line x1={PAD} x2={W - PAD} y1={y(0)} y2={y(0)} stroke="rgba(255,255,255,.18)" strokeDasharray="4 4" />
      <path d={path} fill="none" stroke={up ? 'var(--pk-gold, #d8b25a)' : '#ff2418'} strokeWidth="2" />
      <circle cx={x(points.length - 1)} cy={y(last)} r="3.5" fill={up ? '#d8b25a' : '#ff2418'} />
    </svg>
  );
}

function StatCard({ label, value, accent }) {
  return (
    <div className="callout" style={{ textAlign: 'center' }}>
      <h3 style={accent ? { color: accent } : {}}>{value}</h3>
      <p style={{ margin: 0 }}>{label}</p>
    </div>
  );
}

function PlayerPage({ username }) {
  const [s, setS] = useState(null);
  const [err, setErr] = useState('');
  useEffect(() => {
    setS(null);
    api.get(`/api/players/${encodeURIComponent(username)}`).then(d => setS(d.stats)).catch(e => setErr(e.message));
  }, [username]);

  if (err) return <div className="wrap"><div className="center-msg">{err}</div></div>;
  if (!s) return <div className="wrap"><div className="center-msg">Auditing the ledger…</div></div>;

  const sign = (n) => `${n < 0 ? '-' : '+'}${usd(Math.abs(n))}`;
  const profitColor = s.total_profit >= 0 ? 'var(--pk-gold)' : 'var(--pk-scs)';

  return (
    <div className="wrap">
      <div className="toprow">
        <div>
          <A href="/players" className="badge">← SHARKS</A>
          <strong style={{ marginLeft: 12, fontSize: 20 }}>{s.avatar} {s.username}</strong>
          {s.is_bot && <span className="badge mo" style={{ marginLeft: 8 }}>MACHINE{s.bot_engine ? ` · ${s.bot_engine}` : ''}</span>}
        </div>
        <span className="mono" style={{ color: 'var(--pk-dim)' }}>in the ledger since {s.member_since}</span>
      </div>

      <div className="callouts">
        <StatCard label="hands played" value={s.hands_played.toLocaleString()} />
        <StatCard label="total profit" value={sign(s.total_profit)} accent={profitColor} />
        <StatCard label="win rate" value={`${s.bb_per_100} bb/100`} accent={s.bb_per_100 >= 0 ? 'var(--pk-gold)' : 'var(--pk-scs)'} />
      </div>
      <div className="callouts">
        <StatCard label="avg profit / hand" value={sign(s.avg_profit)} />
        <StatCard label="VPIP (looseness)" value={`${s.vpip}%`} />
        <StatCard label={`showdown wins (${s.showdowns} seen)`} value={`${s.showdown_win_pct}%`} />
        <StatCard label="biggest pot dragged" value={usd(s.biggest_pot)} accent="var(--pk-gold)" />
      </div>

      <div className="panel">
        <h2>Profit Curve</h2>
        <div className="hint">Cumulative profit across all {s.hands_played.toLocaleString()} archived hands — the Sharkscope line, drawn in house blood.</div>
        <ProfitGraph points={s.graph} />
      </div>

      <div className="row">
        <div className="panel">
          <h2>By Game</h2>
          <table className="tbl">
            <thead><tr><th>Game</th><th>Hands</th><th>Profit</th><th>bb/100</th></tr></thead>
            <tbody>
              {s.per_game.map(g => (
                <tr key={g.game}>
                  <td>{g.name}</td>
                  <td>{g.hands.toLocaleString()}</td>
                  <td style={{ color: g.profit >= 0 ? 'var(--pk-gold)' : 'var(--pk-scs)' }}>{sign(g.profit)}</td>
                  <td>{g.bb_per_100}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        <div className="panel">
          <h2>Recent Hands</h2>
          <table className="tbl">
            <thead><tr><th>#</th><th>Game</th><th>Pot</th><th>Net</th><th></th></tr></thead>
            <tbody>
              {s.recent.map(r => (
                <tr key={r.hand_id}>
                  <td>{r.hand_no}</td>
                  <td className="mono">{r.game}</td>
                  <td>{usd(r.pot)}</td>
                  <td style={{ color: r.profit >= 0 ? 'var(--pk-gold)' : 'var(--pk-scs)' }}>{sign(r.profit)}</td>
                  <td><A href={`/replay/${r.hand_id}`} className="badge">▶</A></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}

function PlayersPage() {
  const [rows, setRows] = useState(null);
  useEffect(() => { api.get('/api/players').then(d => setRows(d.players)).catch(() => setRows([])); }, []);
  if (!rows) return <div className="wrap"><div className="center-msg">Ranking the predators…</div></div>;
  return (
    <div className="wrap">
      <div className="toprow"><h2 style={{ margin: 0 }}>The Sharks</h2><span className="mono" style={{ color: 'var(--pk-dim)' }}>ranked by lifetime profit</span></div>
      <div className="panel">
        <table className="tbl">
          <thead><tr><th>#</th><th>Player</th><th>Hands</th><th>Profit</th><th>bb/100</th><th>Biggest Pot</th></tr></thead>
          <tbody>
            {rows.map((r, i) => (
              <tr key={r.username}>
                <td className="mono">{i + 1}</td>
                <td><A href={`/player/${encodeURIComponent(r.username)}`}>{r.avatar} {r.username}{r.is_bot ? ' ⚙' : ''}</A></td>
                <td>{r.hands_played.toLocaleString()}</td>
                <td style={{ color: r.total_profit >= 0 ? 'var(--pk-gold)' : 'var(--pk-scs)' }}>{r.total_profit < 0 ? '-' : '+'}{usd(Math.abs(r.total_profit))}</td>
                <td>{r.bb_per_100}</td>
                <td>{usd(r.biggest_pot)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}

/* -------------------------------------------------------------- side bets */
// The rail's bookmaker window: live markets on the current hand.
function SideBetsPanel({ tableId }) {
  const { me, refresh } = useMe();
  const [sheet, setSheet] = useState(null);
  const [amt, setAmt] = useState(100);
  const [msg, setMsg] = useState('');
  const [err, setErr] = useState('');

  useEffect(() => {
    const load = () => api.get(`/api/tables/${tableId}/sidebets`).then(setSheet).catch(() => {});
    load();
    const iv = setInterval(load, 3000);
    return () => clearInterval(iv);
  }, [tableId]);

  const place = async (type, selection) => {
    setMsg(''); setErr('');
    try {
      const r = await api.post(`/api/tables/${tableId}/sidebets`, { type, selection, amount: amt });
      setMsg(`Locked: ${type} ${selection} @ ${(r.bet.odds_x100 / 100).toFixed(2)}x`);
      await refresh();
    } catch (e) { setErr(e.message); }
  };

  if (!sheet) return null;
  const open = (sheet.mine || []).filter(b => b.status === 'open');
  const settled = (sheet.mine || []).filter(b => b.status !== 'open').slice(0, 6);

  return (
    <div className="panel">
      <h2>🎲 Side Bets <span className="mono" style={{ fontSize: 11, color: 'var(--pk-dim)' }}>· odds lock when you fire · paid from your bankroll</span></h2>
      {(!sheet.offers || sheet.offers.length === 0) ? (
        <div className="hint">Markets open when the next hand is dealt…</div>
      ) : (
        <>
          {me && (
            <div style={{ display: 'flex', gap: 8, alignItems: 'center', marginBottom: 10 }}>
              <span className="mono" style={{ color: 'var(--pk-gold)' }}>stake $</span>
              <input style={{ width: 90 }} type="number" step="0.10" min="0.10" value={(amt / 100).toFixed(2)}
                onChange={e => setAmt(Math.max(10, Math.round(+e.target.value * 100)))} />
            </div>
          )}
          {sheet.offers.map(o => (
            <div key={o.type} style={{ margin: '8px 0' }}>
              <div className="mono" style={{ fontSize: 11, color: 'var(--pk-dim)', textTransform: 'uppercase', letterSpacing: '.1em', marginBottom: 5 }}>
                {o.label} · pays {(o.odds_x100 / 100).toFixed(2)}x
              </div>
              <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
                {o.selections.map(s => (
                  <button key={s.key} className="btn ghost" disabled={!me}
                    title={me ? `Bet ${usd(amt)} on ${s.label}` : 'Sign in to bet'}
                    onClick={() => place(o.type, s.key)}>
                    {s.label}
                  </button>
                ))}
              </div>
            </div>
          ))}
          {!me && <div className="hint"><A href="/login">Enter</A> to bet the rail.</div>}
        </>
      )}
      {open.length > 0 && (
        <div style={{ marginTop: 10 }}>
          {open.map(b => <div key={b.id} className="kv"><span>⏳ {b.bet_type} {b.selection} · hand #{b.hand_no}</span><span>{usd(b.stake)} @ {(b.odds_x100 / 100).toFixed(2)}x</span></div>)}
        </div>
      )}
      {settled.length > 0 && (
        <div style={{ marginTop: 6 }}>
          {settled.map(b => (
            <div key={b.id} className="kv" style={{ opacity: .75 }}>
              <span>{b.status === 'won' ? '🏆' : b.status === 'void' ? '↩' : '💀'} {b.bet_type} {b.selection} · #{b.hand_no}</span>
              <span style={{ color: b.status === 'won' ? 'var(--pk-gold)' : 'var(--pk-dim)' }}>
                {b.status === 'won' ? `+${usd(b.payout)}` : b.status === 'void' ? 'push' : `-${usd(b.stake)}`}
              </span>
            </div>
          ))}
        </div>
      )}
      {msg && <div className="ok">{msg}</div>}
      {err && <div className="err">{err}</div>}
    </div>
  );
}

/* ---------------------------------------------------------------- rewards */
function RewardsPage() {
  const { me, refresh } = useMe();
  const [d, setD] = useState(null);
  const [code, setCode] = useState('');
  const [msg, setMsg] = useState('');
  const [err, setErr] = useState('');
  const load = () => api.get('/api/rewards').then(setD).catch(e => setErr(e.message));
  useEffect(() => { if (me) load(); }, [me]);

  if (!me) return <div className="wrap"><div className="center-msg">Enter first. <A href="/login">Sign in.</A></div></div>;
  if (!d) return <div className="wrap"><div className="center-msg">{err || 'Tallying your tribute…'}</div></div>;

  const claim = async (kind) => {
    setMsg(''); setErr('');
    try { const r = await api.post('/api/rewards/claim', { kind }); setMsg(`Claimed ${usd(r.claimed)} — it's in your bankroll.`); await refresh(); load(); }
    catch (e) { setErr(e.message); }
  };
  const redeem = async (e) => {
    e.preventDefault(); setMsg(''); setErr('');
    try { const r = await api.post('/api/rewards/redeem', { code }); setMsg(`Code drank: +${usd(r.credited)}.`); setCode(''); await refresh(); load(); }
    catch (e2) { setErr(e2.message); }
  };
  const copy = () => { navigator.clipboard?.writeText(d.affiliate.link); setMsg('Link copied — go recruit.'); };

  return (
    <div className="wrap">
      <div className="toprow"><h2 style={{ margin: 0 }}>The Tribute</h2><span className="mono" style={{ color: 'var(--pk-dim)' }}>rakeback · recruits · bonus blood</span></div>

      <div className="row">
        <div className="panel">
          <h2>🩸 Rakeback</h2>
          <div className="hint">The house returns {d.rakeback.pct}% of every cent of rake you pay. It pools here — claim it to your bankroll whenever.</div>
          <div className="kv"><span>Accrued, unclaimed</span><span>{usd(d.rakeback.accrued)}</span></div>
          <div className="kv"><span>Lifetime earned</span><span>{usd(d.rakeback.lifetime)}</span></div>
          <button className="btn gold big" style={{ marginTop: 14, width: '100%' }} disabled={!d.rakeback.accrued} onClick={() => claim('rakeback')}>
            Claim {usd(d.rakeback.accrued)}
          </button>
        </div>

        <div className="panel">
          <h2>🕸 The Affiliate Web</h2>
          <div className="hint">Recruit souls with your link. You earn {d.affiliate.pct}% of all rake they ever pay — forever.</div>
          <div className="addr" style={{ cursor: 'pointer' }} onClick={copy} title="Click to copy">{d.affiliate.link}</div>
          <div className="kv"><span>Recruits</span><span>{d.affiliate.recruits.length}</span></div>
          <div className="kv"><span>Accrued, unclaimed</span><span>{usd(d.affiliate.accrued)}</span></div>
          <div className="kv"><span>Lifetime earned</span><span>{usd(d.affiliate.lifetime)}</span></div>
          <button className="btn gold big" style={{ marginTop: 14, width: '100%' }} disabled={!d.affiliate.accrued} onClick={() => claim('affiliate')}>
            Claim {usd(d.affiliate.accrued)}
          </button>
          {d.affiliate.recruits.length > 0 && (
            <table className="tbl" style={{ marginTop: 12 }}>
              <thead><tr><th>Recruit</th><th>Since</th></tr></thead>
              <tbody>{d.affiliate.recruits.map(r => <tr key={r.username}><td>{r.avatar} {r.username}</td><td className="mono">{r.since}</td></tr>)}</tbody>
            </table>
          )}
        </div>
      </div>

      <div className="panel">
        <h2>🎁 Bonus Codes</h2>
        <div className="hint">Got a code? Speak it. Each one pays once.</div>
        <form onSubmit={redeem} style={{ display: 'flex', gap: 10, maxWidth: 420 }}>
          <input value={code} onChange={e => setCode(e.target.value.toUpperCase())} placeholder="BONUS-CODE" />
          <button className="btn" disabled={!code}>Redeem</button>
        </form>
        {d.bonuses.length > 0 && (
          <table className="tbl" style={{ marginTop: 12 }}>
            <thead><tr><th>Code</th><th>Promo</th><th>Paid</th><th>When</th></tr></thead>
            <tbody>{d.bonuses.map((b, i) => <tr key={i}><td className="mono">{b.code}</td><td>{b.name}</td><td style={{ color: 'var(--pk-gold)' }}>+{usd(b.amount)}</td><td className="mono">{b.when}</td></tr>)}</tbody>
          </table>
        )}
      </div>
      {msg && <div className="ok">{msg}</div>}
      {err && <div className="err">{err}</div>}
    </div>
  );
}

// Warden promo mint, mounted on the altar.
function BonusAdminPanel() {
  const [d, setD] = useState(null);
  const [f, setF] = useState({ code: '', name: '', amount: 2500, max_claims: 100, expires_at: '' });
  const [msg, setMsg] = useState('');
  const load = () => api.get('/api/admin/bonuses').then(setD).catch(() => {});
  useEffect(() => { load(); }, []);
  const save = async () => {
    setMsg('');
    try { await api.post('/api/admin/bonuses', { ...f, expires_at: f.expires_at || null }); setMsg('Promo minted.'); load(); }
    catch (e) { setMsg(e.message); }
  };
  if (!d) return null;
  return (
    <div className="panel">
      <h2>Promo Mint</h2>
      <div className="hint">Bonus codes redeemable at signup or in The Tribute. Rakeback {d.rakeback_bps / 100}% · affiliate {d.affiliate_bps / 100}% (tune in house rules above).</div>
      <table className="tbl">
        <thead><tr><th>Code</th><th>Promo</th><th>Pays</th><th>Claims</th><th>On</th></tr></thead>
        <tbody>
          {d.codes.map(c => (
            <tr key={c.id}>
              <td className="mono">{c.code}</td><td>{c.name}</td><td>{usd(c.amount)}</td>
              <td>{c.claims}{c.max_claims ? `/${c.max_claims}` : ''}</td>
              <td><button className="badge" onClick={async () => { await api.post(`/api/admin/bonuses/${c.id}/toggle`); load(); }}>{c.enabled ? 'on' : 'off'}</button></td>
            </tr>
          ))}
        </tbody>
      </table>
      <div className="row" style={{ marginTop: 8 }}>
        <div><label>Code</label><input value={f.code} onChange={e => setF({ ...f, code: e.target.value.toUpperCase() })} placeholder="BLOODMOON" /></div>
        <div><label>Name</label><input value={f.name} onChange={e => setF({ ...f, name: e.target.value })} placeholder="Blood Moon Promo" /></div>
        <div><label>Pays ($)</label><input type="number" step="0.01" value={(f.amount / 100).toFixed(2)} onChange={e => setF({ ...f, amount: Math.round(+e.target.value * 100) })} /></div>
        <div><label>Max claims (0=∞)</label><input type="number" value={f.max_claims} onChange={e => setF({ ...f, max_claims: +e.target.value })} /></div>
        <div><label>Expires (optional)</label><input type="datetime-local" value={f.expires_at} onChange={e => setF({ ...f, expires_at: e.target.value })} /></div>
      </div>
      <button className="btn" style={{ marginTop: 10 }} onClick={save}>Mint code</button>
      {msg && <div className="ok">{msg}</div>}
    </div>
  );
}

/* ------------------------------------------------------------ stats guide */
function StatsGuidePage() {
  useEffect(() => {
    // honor #anchor deep links from HUD tooltips
    const id = window.location.hash.slice(1);
    if (id) setTimeout(() => document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'center' }), 150);
  }, []);
  const entries = Object.entries(STAT_INFO).filter(([k]) => k !== 'name');
  return (
    <div className="wrap">
      <div className="toprow">
        <h2 style={{ margin: 0 }}>The Tell Codex</h2>
        <span className="mono" style={{ color: 'var(--pk-dim)' }}>every HUD number, decoded</span>
      </div>
      <div className="panel">
        <div className="hint">These are the statistics the HUD overlays on every seat — the same numbers PokerTracker grinds from millions of hands, computed here from the house's own archive. Hover any stat at the felt for the short version; this page is the long one.</div>
        {entries.map(([key, info]) => (
          <div key={key} id={key} style={{ padding: '14px 0', borderBottom: '1px solid rgba(255,255,255,.07)' }}>
            <h3 style={{ margin: '0 0 6px', color: 'var(--pk-gold)', fontFamily: 'var(--pk-mono)', fontSize: 14, letterSpacing: '.05em', textTransform: 'uppercase' }}>{info.title}</h3>
            <p style={{ margin: 0, color: '#cfc7bd', lineHeight: 1.6, fontSize: 14 }}>{info.long}</p>
          </div>
        ))}
      </div>
    </div>
  );
}

/* ------------------------------------------------------------ tournaments */
const T_STATUS = { running: 'LIVE', registering: 'OPEN', scheduled: 'SOON', finished: 'DONE', cancelled: 'DEAD' };

function TournamentsPage() {
  const [rows, setRows] = useState(null);
  useEffect(() => {
    const load = () => api.get('/api/tournaments').then(d => setRows(d.tournaments)).catch(() => setRows([]));
    load();
    const iv = setInterval(load, 5000);
    return () => clearInterval(iv);
  }, []);
  if (!rows) return <div className="wrap"><div className="center-msg">Raising the brackets…</div></div>;
  return (
    <div className="wrap">
      <div className="toprow"><h2 style={{ margin: 0 }}>The Brackets</h2><span className="mono" style={{ color: 'var(--pk-dim)' }}>last soul standing takes the pool</span></div>
      <div className="panel">
        {rows.length === 0 ? <div className="hint">No tournaments yet — the warden is sharpening the knives.</div> : (
          <table className="tbl">
            <thead><tr><th></th><th>Name</th><th>Game</th><th>Buy-in</th><th>Prize Pool</th><th>Field</th><th>Blinds</th><th></th></tr></thead>
            <tbody>
              {rows.map(t => (
                <tr key={t.id}>
                  <td><span className={`badge ${t.status === 'running' ? 'hvm' : t.status === 'registering' ? 'gold' : ''}`}>{T_STATUS[t.status] || t.status}</span></td>
                  <td><A href={`/tournaments/${t.id}`}>{t.name}</A></td>
                  <td className="mono">{t.game_name}</td>
                  <td>{usd(t.buy_in)}{t.fee > 0 ? `+${usd(t.fee)}` : ''}</td>
                  <td style={{ color: 'var(--pk-gold)' }}>{usd(t.prize_pool)}</td>
                  <td>{t.players}/{t.max_players}</td>
                  <td className="mono">{usd(t.blinds.sb)}/{usd(t.blinds.bb)}{t.status === 'running' ? ` · L${t.level + 1}` : ''}</td>
                  <td><A href={`/tournaments/${t.id}`} className="badge">view</A></td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
    </div>
  );
}

function TournamentPage({ id }) {
  const { me, refresh } = useMe();
  const [t, setT] = useState(null);
  const [err, setErr] = useState('');
  const [busy, setBusy] = useState(false);

  const load = useCallback(() => {
    api.get(`/api/tournaments/${id}`).then(d => setT(d.tournament)).catch(e => setErr(e.message));
  }, [id]);
  useEffect(() => {
    load();
    const iv = setInterval(load, 4000);
    return () => clearInterval(iv);
  }, [load]);

  const doIt = async (action) => {
    setBusy(true); setErr('');
    try { await api.post(`/api/tournaments/${id}/${action}`); await refresh(); load(); }
    catch (e) { setErr(e.message); }
    finally { setBusy(false); }
  };

  if (!t) return <div className="wrap"><div className="center-msg">{err || 'Reading the bracket…'}</div></div>;
  const live = t.entries.filter(e => e.status === 'playing');
  const done = t.entries.filter(e => e.status === 'busted').sort((a, b) => (a.place || 999) - (b.place || 999));

  return (
    <div className="wrap">
      <div className="toprow">
        <div>
          <A href="/tournaments" className="badge">← BRACKETS</A>
          <strong style={{ marginLeft: 12, fontSize: 20 }}>{t.name}</strong>
          <span className={`badge ${t.status === 'running' ? 'hvm' : 'gold'}`} style={{ marginLeft: 8 }}>{T_STATUS[t.status] || t.status}</span>
        </div>
        <span className="chips-pill">PRIZE POOL · {usd(t.prize_pool)}</span>
      </div>

      <div className="callouts">
        <div className="callout"><div className="ic">🎟</div><h3>{usd(t.buy_in)}{t.fee > 0 ? ` + ${usd(t.fee)}` : ''}</h3><p>buy-in · {t.game_name}</p></div>
        <div className="callout"><div className="ic">⏱</div><h3>{usd(t.blinds.sb)}/{usd(t.blinds.bb)}</h3><p>{t.status === 'running' ? `level ${t.level + 1} · ${t.blinds.minutes}m levels` : `starting blinds · ${(t.starting_stack / t.blinds.bb).toFixed(0)}bb deep`}</p></div>
        <div className="callout"><div className="ic">⚔️</div><h3>{t.status === 'running' ? `${live.length} alive` : `${t.players} entered`}</h3><p>of {t.field} field · {t.seats_per_table}-max tables</p></div>
      </div>

      {me && (t.status === 'registering' || t.status === 'scheduled') && (
        <div style={{ display: 'flex', gap: 10, justifyContent: 'center', margin: '6px 0 14px' }}>
          {t.you === 'registered'
            ? <button className="btn ghost" disabled={busy} onClick={() => doIt('unregister')}>Unregister (refund)</button>
            : <button className="btn big gold" disabled={busy} onClick={() => doIt('register')}>Register · {usd(t.buy_in + t.fee)}</button>}
        </div>
      )}
      {err && <div className="err" style={{ textAlign: 'center' }}>{err}</div>}

      <div className="row">
        <div className="panel">
          <h2>{t.status === 'finished' ? 'Final Standings' : 'The Field'}</h2>
          <table className="tbl">
            <thead><tr><th>#</th><th>Player</th><th>{t.status === 'running' ? 'Stack' : 'Prize'}</th></tr></thead>
            <tbody>
              {live.sort((a, b) => (b.stack || 0) - (a.stack || 0)).map((e, i) => (
                <tr key={e.username}>
                  <td className="mono">{i + 1}</td>
                  <td><A href={`/player/${encodeURIComponent(e.username)}`}>{e.avatar} {e.username}</A>{e.is_bot ? ' ⚙' : ''}</td>
                  <td style={{ color: 'var(--pk-gold)' }}>{e.stack != null ? e.stack.toLocaleString() : '—'}</td>
                </tr>
              ))}
              {done.map(e => (
                <tr key={e.username} style={{ opacity: e.place <= (t.payout_pct || []).length ? 1 : 0.5 }}>
                  <td className="mono">{e.place === 1 ? '👑' : `#${e.place}`}</td>
                  <td><A href={`/player/${encodeURIComponent(e.username)}`}>{e.avatar} {e.username}</A>{e.is_bot ? ' ⚙' : ''}</td>
                  <td style={{ color: e.prize > 0 ? 'var(--pk-gold)' : 'var(--pk-dim)' }}>{e.prize > 0 ? `+${usd(e.prize)}` : '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        <div>
          {t.status === 'running' && (
            <div className="panel">
              <h2>Live Tables</h2>
              {t.tables.map(tb => (
                <div key={tb.id} className="kv">
                  <span>{tb.name} · {tb.players} seated</span>
                  <span><A href={`/observe/${tb.id}`} className="badge">👁 watch</A></span>
                </div>
              ))}
            </div>
          )}
          <div className="panel">
            <h2>Blind Ladder</h2>
            <table className="tbl">
              <thead><tr><th>Lvl</th><th>Blinds</th><th>Mins</th></tr></thead>
              <tbody>
                {(t.blind_levels || []).map((l, i) => (
                  <tr key={i} style={t.status === 'running' && i === t.level ? { color: 'var(--pk-gold)' } : {}}>
                    <td>{i + 1}{t.status === 'running' && i === t.level ? ' ◀' : ''}</td>
                    <td className="mono">{usd(l.sb)}/{usd(l.bb)}</td>
                    <td>{l.minutes}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <div className="panel">
            <h2>Payouts</h2>
            {(t.payout_pct || []).map((p, i) => (
              <div key={i} className="kv"><span>{i + 1}. place</span><span>{p}% · {usd(Math.floor(t.prize_pool * p / 100))}</span></div>
            ))}
          </div>
        </div>
      </div>
    </div>
  );
}

// Warden panel: forge and drive tournaments from the altar.
function TournamentAdminPanel() {
  const [rows, setRows] = useState([]);
  const [msg, setMsg] = useState('');
  const [f, setF] = useState({ name: '', game_type: 'nlhe', buy_in: 1000, fee: 100, starting_stack: 10000, seats_per_table: 6, max_players: 32, starts_at: '' });
  const load = () => api.get('/api/tournaments').then(d => setRows(d.tournaments)).catch(() => {});
  useEffect(() => { load(); const iv = setInterval(load, 6000); return () => clearInterval(iv); }, []);

  const create = async () => {
    setMsg('');
    try {
      await api.post('/api/admin/tournaments', { ...f, starts_at: f.starts_at || null });
      setMsg('Bracket raised.'); load();
    } catch (e) { setMsg(e.message); }
  };
  const act = async (id, what, body) => {
    setMsg('');
    try { const r = await api.post(`/api/admin/tournaments/${id}/${what}`, body); setMsg(r.added != null ? `${r.added} machines conscripted.` : 'Done.'); load(); }
    catch (e) { setMsg(e.message); }
  };

  return (
    <div className="panel">
      <h2>Tournament Forge</h2>
      <div className="hint">Create brackets, conscript machines, start or burn them. The clock, bust-outs, balancing, and payouts run themselves.</div>
      <table className="tbl">
        <thead><tr><th>St</th><th>Name</th><th>Field</th><th>Pool</th><th></th></tr></thead>
        <tbody>
          {rows.map(t => (
            <tr key={t.id}>
              <td><span className="badge">{T_STATUS[t.status] || t.status}</span></td>
              <td><A href={`/tournaments/${t.id}`}>{t.name}</A></td>
              <td>{t.players}/{t.max_players}</td>
              <td>{usd(t.prize_pool)}</td>
              <td style={{ display: 'flex', gap: 5 }}>
                {(t.status === 'registering' || t.status === 'scheduled') && <>
                  <button className="badge" onClick={() => act(t.id, 'fill-bots', { count: 8 })}>+bots</button>
                  <button className="badge gold" onClick={() => act(t.id, 'start')}>start</button>
                </>}
                {t.status !== 'finished' && t.status !== 'cancelled' && <button className="badge" onClick={() => act(t.id, 'cancel')}>burn</button>}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
      <div className="hint" style={{ marginTop: 12 }}>New bracket — money in dollars-cents handled below as cents.</div>
      <div className="row" style={{ marginTop: 6 }}>
        <div><label>Name</label><input value={f.name} onChange={e => setF({ ...f, name: e.target.value })} placeholder="Midnight Massacre" /></div>
        <div><label>Game</label>
          <select value={f.game_type} onChange={e => setF({ ...f, game_type: e.target.value })}>
            {['nlhe', 'lhe', 'plo', 'plo8', 'shortdeck', 'stud', 'razz', 'draw5'].map(g => <option key={g} value={g}>{g}</option>)}
          </select>
        </div>
        <div><label>Buy-in ($)</label><input type="number" step="0.01" value={(f.buy_in / 100).toFixed(2)} onChange={e => setF({ ...f, buy_in: Math.round(+e.target.value * 100) })} /></div>
        <div><label>Fee ($)</label><input type="number" step="0.01" value={(f.fee / 100).toFixed(2)} onChange={e => setF({ ...f, fee: Math.round(+e.target.value * 100) })} /></div>
        <div><label>Starting stack</label><input type="number" value={f.starting_stack} onChange={e => setF({ ...f, starting_stack: +e.target.value })} /></div>
        <div><label>Seats/table</label><input type="number" value={f.seats_per_table} onChange={e => setF({ ...f, seats_per_table: +e.target.value })} /></div>
        <div><label>Max players</label><input type="number" value={f.max_players} onChange={e => setF({ ...f, max_players: +e.target.value })} /></div>
        <div><label>Auto-start (optional)</label><input type="datetime-local" value={f.starts_at} onChange={e => setF({ ...f, starts_at: e.target.value })} /></div>
      </div>
      <button className="btn" style={{ marginTop: 12 }} onClick={create}>Raise the bracket</button>
      {msg && <div className="ok">{msg}</div>}
    </div>
  );
}

/* ------------------------------------------------------------------ auth */
function AuthPage({ mode }) {
  const { refresh } = useMe();
  const { go } = useNav();
  const [f, setF] = useState({
    username: '', email: '', password: '',
    code: (typeof window !== 'undefined' && new URLSearchParams(window.location.search).get('ref')) || '',
  });
  const [err, setErr] = useState('');
  const [busy, setBusy] = useState(false);
  const [twofa, setTwofa] = useState(null); const [code, setCode] = useState(''); const [qr, setQr] = useState('');
  useEffect(() => { if (twofa?.uri) import('qrcode').then(Q => Q.toDataURL(twofa.uri, { margin: 1, width: 210, color: { dark: '#ff2418', light: '#070505' } }).then(setQr).catch(() => {})); }, [twofa]);
  const submit = async (e) => {
    e.preventDefault(); setBusy(true); setErr('');
    try {
      const r = await api.post(mode === 'register' ? '/auth/register' : '/auth/login', f);
      if (r && r.twofa) { setTwofa(r.twofa); setCode(''); }
      else { await refresh(); go('/'); }
    } catch (e) { setErr(e.data?.errors ? Object.values(e.data.errors).flat().join(' ') : e.message); }
    finally { setBusy(false); }
  };
  const verify = async (e) => {
    e.preventDefault(); setBusy(true); setErr('');
    try { await api.post('/auth/2fa', { code }); await refresh(); go('/'); }
    catch (e) { setErr(e.message); } finally { setBusy(false); }
  };
  if (twofa) return (
    <div className="wrap" style={{ maxWidth: 460 }}>
      <div className="panel" style={{ marginTop: 40, textAlign: 'center' }}>
        <h2>{twofa.step === 'enroll' ? 'Seal the Altar' : 'Prove it’s you'}</h2>
        {twofa.step === 'enroll' ? (
          <>
            <div className="hint">Admin 2FA is required. Scan with <b>Google Authenticator</b>, then enter the code.</div>
            {qr && <img src={qr} alt="2FA QR" style={{ width: 200, height: 200, margin: '14px auto', display: 'block', borderRadius: 12 }} />}
            <div className="hint" style={{ wordBreak: 'break-all' }}>key: {twofa.secret}</div>
          </>
        ) : <div className="hint">Enter the 6-digit code from <b>Google Authenticator</b>.</div>}
        <form onSubmit={verify}>
          <input inputMode="numeric" autoComplete="one-time-code" maxLength={6} autoFocus value={code}
            onChange={e => setCode(e.target.value.replace(/\D/g, '').slice(0, 6))} placeholder="000000"
            style={{ textAlign: 'center', letterSpacing: '.4em', fontSize: 22, fontWeight: 800, marginTop: 12 }} />
          <button className="btn big" style={{ marginTop: 16, width: '100%' }} disabled={busy || code.length !== 6}>{busy ? 'Verifying…' : (twofa.step === 'enroll' ? 'Confirm & enter' : 'Cross the threshold')}</button>
        </form>
        {err && <div className="err">{err}</div>}
        <div className="hint" style={{ marginTop: 12 }}><A href="/login" onClick={() => setTwofa(null)}>‹ back</A></div>
      </div>
    </div>
  );
  return (
    <div className="wrap" style={{ maxWidth: 460 }}>
      <div className="panel" style={{ marginTop: 40 }}>
        <h2>{mode === 'register' ? 'Enlist' : 'Enter'}</h2>
        <div className="hint">{mode === 'register' ? 'Claim a name. The beast keeps a ledger of every soul.' : 'Speak your name and key.'}</div>
        <form onSubmit={submit}>
          <label>Username</label>
          <input value={f.username} onChange={e => setF({ ...f, username: e.target.value })} autoFocus />
          {mode === 'register' && <>
            <label>Email (optional)</label>
            <input value={f.email} onChange={e => setF({ ...f, email: e.target.value })} />
            <label>Bonus / referral code (optional)</label>
            <input value={f.code} onChange={e => setF({ ...f, code: e.target.value.toUpperCase() })} placeholder="WELCOME666" />
          </>}
          <label>Password</label>
          <input type="password" value={f.password} onChange={e => setF({ ...f, password: e.target.value })} />
          <button className="btn big" style={{ marginTop: 18, width: '100%' }} disabled={busy}>
            {mode === 'register' ? 'Sign in blood' : 'Cross the threshold'}
          </button>
        </form>
        {err && <div className="err">{err}</div>}
        <div className="hint" style={{ marginTop: 14 }}>
          {mode === 'register'
            ? <>Already sworn? <A href="/login">Enter</A></>
            : <>No name yet? <A href="/register">Enlist</A></>}
        </div>
      </div>
    </div>
  );
}

/* ----------------------------------------------------------------- wallet */
function Wallet() {
  const { me, refresh } = useMe();
  const [w, setW] = useState(null);
  const [dep, setDep] = useState(null);
  const [wd, setWd] = useState({ currency: 'btc', address: '', chips: 0 });
  const [tok, setTok] = useState(null);
  const [msg, setMsg] = useState('');
  const [err, setErr] = useState('');

  const load = () => api.get('/api/wallet').then(setW).catch(e => setErr(e.message));
  useEffect(() => { load(); }, []);

  const genAddr = async (currency) => {
    setErr(''); setDep(null);
    try { setDep(await api.post('/api/wallet/address', { currency })); }
    catch (e) { setErr(e.message); }
  };
  const withdraw = async (e) => {
    e.preventDefault(); setErr(''); setMsg('');
    try { const r = await api.post('/api/wallet/withdraw', wd); setMsg(r.note); await refresh(); load(); }
    catch (e) { setErr(e.message); }
  };
  const genToken = async () => {
    try { const r = await api.post('/api/me/token'); setTok(r.token); }
    catch (e) { setErr(e.message); }
  };

  if (!me) return <div className="wrap"><div className="center-msg">Enter first. <A href="/login">Sign in.</A></div></div>;
  if (!w) return <div className="wrap"><div className="center-msg">Opening the vault…</div></div>;

  return (
    <div className="wrap">
      <Marquee items={PHRASES.vault} cls="gold flush" speed={50} />
      <div className="toprow"><h2 style={{ margin: 0 }}>The Vault</h2><span className="chips-pill">Balance · {usd(w.chips)}</span></div>

      <div className="row">
        <div className="panel">
          <h2>Deposit</h2>
          <div className="hint">Send BTC or ETH ({w.network} network). The scanner watches the chain and credits your balance in <strong>real cash</strong> — at the coin's USD value the moment it lands. Deposit $20 worth, the maw sees $18.88 on arrival, you get $18.88.</div>
          <div style={{ display: 'flex', gap: 10 }}>
            <button className="btn" onClick={() => genAddr('btc')} disabled={!w.configured.btc}>BTC address</button>
            <button className="btn" onClick={() => genAddr('eth')} disabled={!w.configured.eth}>ETH address</button>
          </div>
          {(!w.configured.btc && !w.configured.eth) && <div className="hint" style={{ marginTop: 10 }}>⚠ Crypto is not yet armed by the warden (no house xpub set).</div>}
          {dep && (
            <div style={{ marginTop: 16, textAlign: 'center' }}>
              <img className="qr" src={dep.qr} alt="deposit QR" />
              <div className="addr" style={{ marginTop: 10 }}>{dep.address}</div>
              <div className="hint">{dep.note}</div>
            </div>
          )}
        </div>

        <div className="panel">
          <h2>Withdraw</h2>
          <div className="hint">Cash out to any address. We take your USD balance and pay you that much in BTC/ETH at the live rate. The warden reviews each rite.</div>
          <form onSubmit={withdraw}>
            <label>Currency</label>
            <select value={wd.currency} onChange={e => setWd({ ...wd, currency: e.target.value })}>
              <option value="btc">BTC · ${w.rates.btc.toLocaleString()}</option>
              <option value="eth">ETH · ${w.rates.eth.toLocaleString()}</option>
            </select>
            <label>Destination address</label>
            <input value={wd.address} onChange={e => setWd({ ...wd, address: e.target.value })} placeholder="bc1… / 0x…" />
            <label>Cash to withdraw (USD)</label>
            <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
              <span className="mono" style={{ color: 'var(--pk-gold)' }}>$</span>
              <input type="number" step="0.01" value={(wd.chips / 100).toFixed(2)} max={(w.chips / 100).toFixed(2)}
                onChange={e => setWd({ ...wd, chips: Math.round(parseFloat(e.target.value || '0') * 100) })} />
              <button type="button" className="btn ghost" onClick={() => setWd({ ...wd, chips: w.chips })}>Max</button>
            </div>
            <div className="hint" style={{ marginTop: 6 }}>
              ≈ {((wd.chips / 100) / (w.rates[wd.currency] || 1)).toFixed(8)} {wd.currency.toUpperCase()} at ${w.rates[wd.currency]?.toLocaleString()}
            </div>
            <button className="btn big" style={{ marginTop: 16, width: '100%' }}>Demand withdrawal</button>
          </form>
          {msg && <div className="ok">{msg}</div>}
        </div>
      </div>

      <div className="panel">
        <h2>Machine Key</h2>
        <div className="hint">Mint an API token to play this account through a bot. Sent as <code className="mono">Authorization: Bearer …</code>. See the <a href="/api-docs">API docs</a>.</div>
        <button className="btn" onClick={genToken}>{w ? 'Generate / rotate token' : ''}</button>
        {tok && <div className="addr" style={{ marginTop: 12 }}>{tok}<div className="hint">Shown once. Store it now.</div></div>}
      </div>

      <div className="panel">
        <h2>Ledger</h2>
        <table className="tbl">
          <thead><tr><th>Type</th><th>Δ cash</th><th>Balance</th><th>Memo</th></tr></thead>
          <tbody>
            {(w.deposits || []).length === 0 && (me.ledger || []).length === 0 && <tr><td colSpan="4">No movements yet.</td></tr>}
            {(me.ledger || []).map(l => (
              <tr key={l.id}><td>{l.type}</td><td>{l.delta > 0 ? '+' : '-'}{usd(Math.abs(l.delta))}</td><td>{usd(l.balance_after)}</td><td>{l.memo}</td></tr>
            ))}
          </tbody>
        </table>
      </div>
      {err && <div className="err">{err}</div>}
    </div>
  );
}

/* ------------------------------------------------------------------ admin */
function Admin() {
  const { me } = useMe();
  const [d, setD] = useState(null);
  const [err, setErr] = useState('');
  const [msg, setMsg] = useState('');
  const load = () => api.get('/api/admin').then(setD).catch(e => setErr(e.message));
  useEffect(() => { load(); }, []);

  if (!me || !me.is_admin) return <div className="wrap"><div className="center-msg">The altar is sealed.</div></div>;
  if (!d) return <div className="wrap"><div className="center-msg">{err || 'Opening the altar…'}</div></div>;

  const saveSettings = async (s) => {
    setMsg(''); setErr('');
    try { await api.post('/api/admin/settings', s); setMsg('Settings burned in.'); load(); }
    catch (e) { setErr(e.message); }
  };

  return (
    <div className="wrap">
      <div className="toprow"><h2 style={{ margin: 0 }}>The Altar</h2></div>
      <div className="callouts">
        <div className="callout"><div className="ic">👤</div><h3>{d.stats.players}</h3><p>souls · {d.stats.bots} machines</p></div>
        <div className="callout"><div className="ic">🎰</div><h3>{d.stats.tables}</h3><p>live felts · {d.stats.seated} seated</p></div>
        <div className="callout"><div className="ic">💰</div><h3>{usd(d.stats.cash_in_play)}</h3><p>cash in play · {usd(d.stats.cash_banked)} banked</p></div>
      </div>

      <div className="panel" style={{ borderColor: 'rgba(216,178,90,.4)' }}>
        <h2>🩸 Rake Collected</h2>
        <div className="hint">{d.settings.rake_bps / 100}% of every flopped pot, capped at {d.settings.rake_cap_bb} BB (no flop, no drop).</div>
        <div className="callouts" style={{ margin: '6px 0 0' }}>
          <div className="callout gold"><div className="ic">⛧</div><h3 style={{ color: 'var(--pk-gold)' }}>{usd(d.stats.rake_total)}</h3><p>all-time rake</p></div>
          <div className="callout"><div className="ic">🌅</div><h3>{usd(d.stats.rake_today)}</h3><p>raked today</p></div>
          <div className="callout"><div className="ic">🃏</div><h3>{d.stats.rake_hands.toLocaleString()}</h3><p>hands raked</p></div>
        </div>
      </div>

      <SettingsPanel settings={d.settings} onSave={saveSettings} />
      <TournamentAdminPanel />
      <BonusAdminPanel />
      <StakesPanel stakes={d.stakes} gameTypes={d.game_types} onChange={load} />
      <WithdrawalsPanel rows={d.pending_withdrawals} onChange={load} />
      {msg && <div className="ok">{msg}</div>}
      {err && <div className="err">{err}</div>}
    </div>
  );
}

function SettingsPanel({ settings, onSave }) {
  const [s, setS] = useState(settings);
  const f = (k) => ({ value: s[k] ?? '', onChange: (e) => setS({ ...s, [k]: e.target.value }) });
  return (
    <div className="panel">
      <h2>Machine Topology & House Rules</h2>
      <div className="hint">Workers = cpu_count × workers_per_cpu RabbitMQ consumers. The supervisor scales live.</div>
      <div className="row">
        <div><label>CPU count</label><input type="number" {...f('cpu_count')} /></div>
        <div><label>Workers per CPU</label><input type="number" {...f('workers_per_cpu')} /></div>
        <div><label>Action timeout (s)</label><input type="number" {...f('action_timeout')} /></div>
        <div><label>Rake (bps)</label><input type="number" {...f('rake_bps')} /></div>
        <div><label>Rakeback (bps)</label><input type="number" {...f('rakeback_bps')} /></div>
        <div><label>Affiliate (bps)</label><input type="number" {...f('affiliate_bps')} /></div>
        <div><label>Min bots / table</label><input type="number" {...f('min_bots_per_table')} /></div>
        <div><label>Bot think min (ms)</label><input type="number" {...f('bot_think_min')} /></div>
        <div><label>Bot think max (ms)</label><input type="number" {...f('bot_think_max')} /></div>
        <div><label>Crypto network</label>
          <select value={s.crypto_network} onChange={e => setS({ ...s, crypto_network: e.target.value })}>
            <option value="test">test</option><option value="main">main</option>
          </select>
        </div>
        <div><label>BTC main (cold) wallet</label><input {...f('btc_main_wallet')} /></div>
        <div><label>ETH main (cold) wallet</label><input {...f('eth_main_wallet')} /></div>
      </div>
      <button className="btn big" style={{ marginTop: 16 }} onClick={() => onSave(s)}>Burn in settings</button>
    </div>
  );
}

function StakesPanel({ stakes, gameTypes, onChange }) {
  const [n, setN] = useState({ name: '', game_type: 'nlhe', small_blind: 25, big_blind: 50, min_buy_in: 2000, max_buy_in: 5000, max_seats: 6, enabled: true });
  const save = async (st) => { await api.post('/api/admin/stakes', st); onChange(); };
  const games = gameTypes || { nlhe: "No-Limit Hold'em" };
  return (
    <div className="panel">
      <h2>Blind Ladder</h2>
      <div className="hint">Tables auto-spawn per enabled stake × type. Every poker variant the house spreads lives here — hold'em, Omaha, stud, razz, short deck, draw.</div>
      <table className="tbl">
        <thead><tr><th>Name</th><th>Game</th><th>SB</th><th>BB</th><th>Min</th><th>Max</th><th>Seats</th><th>On</th></tr></thead>
        <tbody>
          {stakes.map(st => (
            <tr key={st.id}>
              <td>{st.name}</td><td className="mono">{games[st.game_type || 'nlhe'] || st.game_type}</td>
              <td>{usd(st.small_blind)}</td><td>{usd(st.big_blind)}</td>
              <td>{usd(st.min_buy_in)}</td><td>{usd(st.max_buy_in)}</td><td>{st.max_seats}</td>
              <td><button className="btn ghost" onClick={() => save({ ...st, enabled: !st.enabled })}>{st.enabled ? 'on' : 'off'}</button></td>
            </tr>
          ))}
        </tbody>
      </table>
      <div className="hint" style={{ marginTop: 10 }}>New stake — all money values in dollars.</div>
      <div className="row" style={{ marginTop: 6 }}>
        <div><label>Name</label><input value={n.name} onChange={e => setN({ ...n, name: e.target.value })} placeholder="$10/$20" /></div>
        <div><label>Game</label>
          <select value={n.game_type} onChange={e => setN({ ...n, game_type: e.target.value })}>
            {Object.entries(games).map(([id, name]) => <option key={id} value={id}>{name}</option>)}
          </select>
        </div>
        <div><label>Small blind ($)</label><input type="number" step="0.01" value={(n.small_blind / 100).toFixed(2)} onChange={e => setN({ ...n, small_blind: Math.round(+e.target.value * 100) })} /></div>
        <div><label>Big blind ($)</label><input type="number" step="0.01" value={(n.big_blind / 100).toFixed(2)} onChange={e => setN({ ...n, big_blind: Math.round(+e.target.value * 100) })} /></div>
        <div><label>Min buy-in ($)</label><input type="number" step="0.01" value={(n.min_buy_in / 100).toFixed(2)} onChange={e => setN({ ...n, min_buy_in: Math.round(+e.target.value * 100) })} /></div>
        <div><label>Max buy-in ($)</label><input type="number" step="0.01" value={(n.max_buy_in / 100).toFixed(2)} onChange={e => setN({ ...n, max_buy_in: Math.round(+e.target.value * 100) })} /></div>
        <div><label>Max seats</label><input type="number" value={n.max_seats} onChange={e => setN({ ...n, max_seats: +e.target.value })} /></div>
      </div>
      <button className="btn" style={{ marginTop: 12 }} onClick={() => save(n)}>Add stake</button>
    </div>
  );
}

function WithdrawalsPanel({ rows, onChange }) {
  const act = async (id, how) => { await api.post(`/api/admin/withdrawals/${id}/${how}`); onChange(); };
  return (
    <div className="panel">
      <h2>Pending Withdrawals</h2>
      {rows.length === 0 ? <div className="hint">No outbound rites awaiting the warden.</div> : (
        <table className="tbl">
          <thead><tr><th>User</th><th>Cur</th><th>Address</th><th>Cash</th><th>Crypto</th><th></th></tr></thead>
          <tbody>
            {rows.map(r => (
              <tr key={r.id}>
                <td>{r.user?.username}</td><td>{r.currency}</td><td style={{ maxWidth: 180, overflow: 'hidden', textOverflow: 'ellipsis' }}>{r.to_address}</td>
                <td>{usd(r.amount_chips)}</td><td>{r.amount_crypto}</td>
                <td style={{ display: 'flex', gap: 6 }}>
                  <button className="btn" onClick={() => act(r.id, 'approve')}>Approve</button>
                  <button className="btn ghost" onClick={() => act(r.id, 'reject')}>Reject</button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </div>
  );
}

/* ------------------------------------------------------------------- root */
function Router() {
  const { path } = useNav();
  if (path === '/' ) return <Home />;
  if (path === '/login') return <AuthPage mode="login" />;
  if (path === '/register') return <AuthPage mode="register" />;
  if (path === '/wallet') return <Wallet />;
  if (path === '/admin') return <Admin />;
  let m;
  if (path === '/players') return <PlayersPage />;
  if (path === '/rewards') return <RewardsPage />;
  if (path === '/stats-guide') return <StatsGuidePage />;
  if (path === '/casino') return <CasinoRoute />;
  if ((m = path.match(/^\/casino\/([a-z_]+)/))) return <CasinoRoute game={m[1]} />;
  if (path === '/tournaments') return <TournamentsPage />;
  if ((m = path.match(/^\/tournaments\/(\d+)/))) return <TournamentPage id={m[1]} />;
  if ((m = path.match(/^\/tables\/(\d+)/))) return <TablePage id={m[1]} />;
  if ((m = path.match(/^\/observe\/(\d+)/))) return <Observe id={m[1]} />;
  if ((m = path.match(/^\/replay\/(\d+)/))) return <Replay id={m[1]} />;
  if ((m = path.match(/^\/player\/([^/]+)/))) return <PlayerPage username={decodeURIComponent(m[1])} />;
  return <div className="wrap"><div className="center-msg">Lost in the void. <A href="/">Return to the lobby.</A></div></div>;
}

function Chrome() {
  const router = useNav();
  const { me } = useMe();
  const { skin } = useSkin();
  // The action bar owns the bottom edge at a live felt — the dock yields.
  const atTable = /^\/tables\/\d+/.test(router.path);
  return (
    <>
      {!BARE && skin === 'desktop' && <DesktopTitlebar />}
      {!BARE && <AppBar />}
      <Router />
      {!BARE && skin === 'mobile' && !atTable && <MobileNav path={router.path} go={router.go} me={me} />}
    </>
  );
}

function App() {
  const router = useRouter();
  const [me, setMe] = useState(null);
  const refresh = useCallback(async () => {
    try { setMe(await api.get('/api/me')); } catch (e) { setMe(null); }
  }, []);
  useEffect(() => { refresh(); }, [refresh]);

  return (
    <Nav.Provider value={router}>
      <Me.Provider value={{ me, refresh }}>
        <UnitProvider>
          <SkinProvider>
            <Chrome />
          </SkinProvider>
        </UnitProvider>
      </Me.Provider>
    </Nav.Provider>
  );
}

const root = document.getElementById('poker-root');
if (root) createRoot(root).render(<App />);
