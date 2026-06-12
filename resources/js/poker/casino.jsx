import React, { useState, useEffect, useRef } from 'react';
import { api } from './api.js';
import { Card } from './cards.jsx';
import { usd } from './money.jsx';
import { play as sfx } from './sounds.js';

/**
 * The Pit — real furniture, not forms. A wheel that spins to the true pocket,
 * a half-moon blackjack felt, a craps layout with tumbling bone dice, and a
 * video poker cabinet with a humming CRT. Every round provably fair.
 */

// ── Win / Lose theater ────────────────────────────────────────────────────
// One shared hook every game uses: feed it the round's NET result in cents
// (positive = win, negative = loss) and it flashes the big neon banner, plays
// the cue, and keeps a running session tally for games played back-to-back.
function useOutcome() {
  const [banner, setBanner] = useState(null); // { won, amount, n }
  const [session, setSession] = useState(0);   // cumulative net cents this sitting
  const n = useRef(0);
  const timer = useRef(null);
  const flash = (netCents) => {
    netCents = Math.round(netCents || 0);
    setSession((s) => s + netCents);
    if (netCents === 0) return;                 // a push: no fanfare
    sfx(netCents > 0 ? 'jackpot' : 'lose');
    n.current += 1;
    setBanner({ won: netCents > 0, amount: Math.abs(netCents), n: n.current });
    clearTimeout(timer.current);
    timer.current = setTimeout(() => setBanner(null), 3000);
  };
  useEffect(() => () => clearTimeout(timer.current), []);
  return { banner, session, flash };
}

function OutcomeBanner({ banner, session }) {
  return (
    <div className="cz-fx" aria-live="polite">
      {banner && (
        <div key={banner.n} className={`cz-out ${banner.won ? 'win' : 'lose'}`}>
          {banner.won ? 'YOU WIN' : 'YOU LOSE'}: {usd(banner.amount)}
        </div>
      )}
      {session !== 0 && (
        <div className={`cz-sess ${session > 0 ? 'win' : 'lose'}`}>
          SESSION {session > 0 ? '+' : '−'}{usd(Math.abs(session))}
        </div>
      )}
    </div>
  );
}

const GAMES = [
  { id: 'roulette_eu', icon: '🎡', name: 'European Roulette', blurb: 'One zero. 2.7% for the house — the merciful wheel.' },
  { id: 'roulette_us', icon: '🛞', name: 'American Roulette', blurb: 'Zero and double-zero. The house bites twice.' },
  { id: 'blackjack', icon: '🃏', name: 'Blackjack', blurb: 'Six decks, dealer stands all 17s, naturals pay 3:2.' },
  { id: 'craps', icon: '🎲', name: 'Craps', blurb: 'Pass line and the loud one-roll props. The bones decide.' },
  { id: 'videopoker', icon: '🕹️', name: 'Video Poker', blurb: 'Jacks or Better, full-pay 9/6. Hold and pray.' },
  { id: 'slots', icon: '🎰', name: 'Beast Reels', blurb: 'Three reels, one line, the ⛧ pays 150:1.' },
];

export function CasinoLobby({ A }) {
  return (
    <div className="wrap">
      <div className="toprow"><h2 style={{ margin: 0 }}>The Pit</h2><span className="mono" style={{ color: 'var(--pk-dim)' }}>provably fair · seeds revealed on settle · house edge printed on the door</span></div>
      <div className="tbl-list">
        {GAMES.map(g => (
          <div key={g.id} className="tcard">
            <div className="nm">{g.icon} {g.name}</div>
            <div className="meta"><span>{g.blurb}</span></div>
            <A href={`/casino/${g.id}`} className="btn" style={{ textAlign: 'center' }}>Play</A>
          </div>
        ))}
      </div>
    </div>
  );
}

function BetInput({ amt, setAmt }) {
  return (
    <span style={{ display: 'inline-flex', gap: 6, alignItems: 'center' }}>
      <span className="mono" style={{ color: 'var(--pk-gold)' }}>bet $</span>
      <input style={{ width: 90 }} type="number" step="0.10" min="0.10" value={(amt / 100).toFixed(2)}
        onChange={e => setAmt(Math.max(10, Math.round(+e.target.value * 100)))} />
    </span>
  );
}
function SeedLine({ seed }) {
  return seed ? <div className="hint" style={{ marginTop: 8, wordBreak: 'break-all' }}>fair seed · {seed}</div> : null;
}

/* ════════════════════════════════════════════════════════════ ROULETTE ═══ */
// True physical pocket order, clockwise around each wheel.
const WHEEL_EU = ['0', '32', '15', '19', '4', '21', '2', '25', '17', '34', '6', '27', '13', '36', '11', '30', '8', '23', '10', '5', '24', '16', '33', '1', '20', '14', '31', '9', '22', '18', '29', '7', '28', '12', '35', '3', '26'];
const WHEEL_US = ['0', '28', '9', '26', '30', '11', '7', '20', '32', '17', '5', '22', '34', '15', '3', '24', '36', '13', '1', '00', '27', '10', '25', '29', '12', '8', '19', '31', '18', '6', '21', '33', '16', '4', '23', '35', '14', '2'];
const RED_NUMS = [1, 3, 5, 7, 9, 12, 14, 16, 18, 19, 21, 23, 25, 27, 30, 32, 34, 36];
const pocketColor = (p) => (p === '0' || p === '00') ? '#1f7a4d' : RED_NUMS.includes(+p) ? '#a31111' : '#141114';

function Wheel({ variant, rotation, spinning }) {
  const order = variant === 'roulette_us' ? WHEEL_US : WHEEL_EU;
  const slice = 360 / order.length;
  const stops = order.map((p, i) => `${pocketColor(p)} ${(i * slice).toFixed(3)}deg ${((i + 1) * slice).toFixed(3)}deg`).join(',');
  return (
    <div className="rw-stage">
      <div className="rw-pointer" />
      <div className={`rw-wheel${spinning ? ' spinning' : ''}`}
        style={{ transform: `rotate(${rotation}deg)`, background: `conic-gradient(from ${-slice / 2}deg, ${stops})` }}>
        {order.map((p, i) => (
          <span key={p} className="rw-num" style={{ transform: `rotate(${i * slice}deg)` }}>{p}</span>
        ))}
        <div className="rw-hub">⛧</div>
      </div>
    </div>
  );
}

const ROULETTE_OUTSIDE = [
  ['red', '♥ RED', 1], ['black', '♠ BLACK', 1], ['odd', 'ODD', 1], ['even', 'EVEN', 1],
  ['low', '1–18', 1], ['high', '19–36', 1],
  ['dozen1', '1st 12', 2], ['dozen2', '2nd 12', 2], ['dozen3', '3rd 12', 2],
  ['col1', 'COL 1', 2], ['col2', 'COL 2', 2], ['col3', 'COL 3', 2],
];

// The pit's memory board: the last pockets this wheel spat out. Numbers that
// repeat in the window run hot and glow.
function HotNumbers({ pockets }) {
  if (!pockets || !pockets.length) return null;
  const counts = pockets.reduce((m, p) => (m[p] = (m[p] || 0) + 1, m), {});
  return (
    <div className="rw-hot">
      <span className="rw-hot-label">HOT NUMBERS</span>
      <span className="rw-hot-strip">
        {pockets.map((p, i) => (
          <span key={i}
            className={`rw-hot-chip${counts[p] >= 2 ? ' hot' : ''}${i === 0 ? ' last' : ''}`}
            style={{ background: pocketColor(p) }}>{p}</span>
        ))}
      </span>
    </div>
  );
}

export function RouletteGame({ variant, me, refresh }) {
  const order = variant === 'roulette_us' ? WHEEL_US : WHEEL_EU;
  const slice = 360 / order.length;
  const [amt, setAmt] = useState(100);
  const [book, setBook] = useState([]);
  const [spin, setSpin] = useState(null);
  const [seed, setSeed] = useState(null);
  const [err, setErr] = useState('');
  const [spinning, setSpinning] = useState(false);
  const [rotation, setRotation] = useState(0);
  const [hot, setHot] = useState([]);
  const rotRef = useRef(0);
  const { banner, session, flash } = useOutcome();

  const loadHot = () => api.get(`/api/casino/${variant}/hot`).then(d => setHot(d.pockets)).catch(() => {});
  useEffect(() => { loadHot(); }, [variant]);

  const total = book.reduce((s, b) => s + b.amount, 0);
  const bankroll = me?.chips ?? 0;

  // Pre-bets stack up on the felt before the spin — never let the book outrun the
  // bankroll, so the spin can always be paid for.
  const add = (type, selection = '') => {
    setErr('');
    if (total + amt > bankroll) {
      setErr(`Not enough chips — that bet needs ${usd(total + amt)} but you have ${usd(bankroll)}.`);
      return;
    }
    setBook(b => [...b, { type, selection, amount: amt }]);
  };

  const fire = async () => {
    setErr(''); setSpin(null); setSeed(null);
    const stake = total;
    if (stake > bankroll) { setErr('Not enough chips to cover these bets.'); return; }
    try {
      const r = await api.post('/api/casino/play', { game: variant, bets: book });
      // Land the true pocket under the pointer: 5 extra revolutions, always forward.
      const idx = order.indexOf(r.result.pocket);
      const targetMod = ((-idx * slice) % 360 + 360) % 360;
      const current = ((rotRef.current % 360) + 360) % 360;
      rotRef.current += 5 * 360 + ((targetMod - current + 360) % 360);
      setSpinning(true);
      setRotation(rotRef.current);
      sfx('bet');
      setTimeout(() => {
        setSpinning(false); setSpin(r.result); setSeed(r.seed); setBook([]);
        flash((r.result.total_paid || 0) - stake);
        refresh(); loadHot();
      }, 4100);
    } catch (e) { setErr(e.message); }
  };

  const numbers = ['0', ...(variant === 'roulette_us' ? ['00'] : []), ...Array.from({ length: 36 }, (_, i) => String(i + 1))];

  return (
    <div className="panel">
      <OutcomeBanner banner={banner} session={session} />
      <div className="row">
        <div style={{ textAlign: 'center' }}>
          <Wheel variant={variant} rotation={rotation} spinning={spinning} />
          <HotNumbers pockets={hot} />
          {spin && (
            <div className="rw-result" style={{ color: pocketColor(spin.pocket) === '#141114' ? 'var(--pk-bone)' : pocketColor(spin.pocket) }}>
              {spin.pocket}
            </div>
          )}
          {spin && spin.results.map((r, i) => (
            <div key={i} className="kv"><span>{r.type}{r.selection ? ` ${r.selection}` : ''}</span>
              <span style={{ color: r.won ? 'var(--pk-gold)' : 'var(--pk-dim)' }}>{r.won ? `+${usd(r.paid)}` : `-${usd(r.amount)}`}</span></div>
          ))}
          <SeedLine seed={seed} />
        </div>

        <div>
          <BetInput amt={amt} setAmt={setAmt} />
          <div className="hint" style={{ margin: '10px 0 4px' }}>Straight numbers pay 35:1</div>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(40px, 1fr))', gap: 4 }}>
            {numbers.map(n => (
              <button key={n} className="btn ghost" style={{
                padding: '7px 0', textAlign: 'center',
                color: n === '0' || n === '00' ? '#3a6' : RED_NUMS.includes(+n) ? 'var(--pk-scs)' : 'var(--pk-bone)',
              }} onClick={() => add('straight', n)}>{n}</button>
            ))}
          </div>
          <div className="hint" style={{ margin: '10px 0 4px' }}>Outside bets</div>
          <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
            {ROULETTE_OUTSIDE.map(([t, label, m]) => (
              <button key={t} className="btn ghost" onClick={() => add(t)}>{label} <span className="mono" style={{ color: 'var(--pk-dim)' }}>{m}:1</span></button>
            ))}
          </div>
          {book.length > 0 && (
            <div style={{ marginTop: 12 }}>
              {book.map((b, i) => (
                <div key={i} className="kv">
                  <span>{b.type}{b.selection ? ` ${b.selection}` : ''} · {usd(b.amount)}</span>
                  <button className="badge" onClick={() => setBook(book.filter((_, j) => j !== i))}>✕</button>
                </div>
              ))}
              <button className="btn gold big" style={{ marginTop: 10, width: '100%' }} disabled={spinning || !me || total > bankroll} onClick={fire}>
                {spinning ? 'No more bets…' : total > bankroll ? 'Not enough chips' : `SPIN · ${usd(total)}`}
              </button>
            </div>
          )}
          {err && <div className="err">{err}</div>}
          {!me && <div className="hint">Sign in to play.</div>}
        </div>
      </div>
    </div>
  );
}

/* ═══════════════════════════════════════════════════════════ BLACKJACK ═══ */
function FlipCard({ code }) {
  const isDown = code === '??';
  return (
    <span className={`bj-flip${isDown ? '' : ' shown'}`}>
      <span className="face back"><Card code="??" /></span>
      <span className="face front"><Card code={isDown ? 'As' : code} /></span>
    </span>
  );
}

export function BlackjackGame({ me, refresh }) {
  const [amt, setAmt] = useState(500);
  const [round, setRound] = useState(null);
  const [err, setErr] = useState('');
  const { banner, session, flash } = useOutcome();
  const bankroll = me?.chips ?? 0;

  useEffect(() => { api.get('/api/casino/blackjack').then(d => d.open && setRound(d.open)).catch(() => {}); }, []);

  const deal = async () => {
    setErr('');
    if (amt > bankroll) { setErr(`Not enough chips — the buy-in is ${usd(amt)} but you have ${usd(bankroll)}.`); return; }
    try {
      const r = await api.post('/api/casino/start', { game: 'blackjack', amount: amt });
      setRound(r); sfx('deal');
      if (r.status === 'settled') flash((r.paid || 0) - (r.wagered || 0)); // a natural settles on the deal
      refresh();
    } catch (e) { setErr(e.message); }
  };
  const act = async (action) => {
    setErr('');
    try {
      const r = await api.post('/api/casino/act', { round_id: round.round_id, action });
      setRound(r); sfx(action === 'hit' ? 'deal' : 'check');
      if (r.status === 'settled') { flash((r.paid || 0) - (r.wagered || 0)); refresh(); }
    } catch (e) { setErr(e.message); }
  };

  const v = round?.view;
  return (
    <div className="panel" style={{ padding: 0, overflow: 'hidden', background: 'transparent', border: 'none' }}>
      <OutcomeBanner banner={banner} session={session} />
      <div className="bj-table">
        <div className="bj-arc">BLACKJACK PAYS 3 TO 2 · DEALER STANDS ON ALL 17s · ⛧</div>
        <div className="bj-zone">
          <div className="bj-label">DEALER {v?.dealer_total != null ? `· ${v.dealer_total}` : ''}</div>
          <div className="bj-cards">
            {(v?.dealer || []).map((c, i) => (
              <span key={`${round?.round_id}-d-${i}`} className="bj-deal" style={{ animationDelay: `${i * 0.14}s` }}>
                {i === 1 ? <FlipCard code={c} /> : <Card code={c} />}
              </span>
            ))}
          </div>
        </div>
        <div className="bj-zone" style={{ marginTop: 26 }}>
          <div className="bj-cards">
            {(v?.player || []).map((c, i) => (
              <span key={`${round?.round_id}-p-${i}`} className="bj-deal" style={{ animationDelay: `${0.07 + i * 0.14}s` }}><Card code={c} /></span>
            ))}
          </div>
          <div className="bj-label">YOU {v ? `· ${v.player_total}` : ''}</div>
        </div>

        <div className="bj-rail">
          {(!round || round.status === 'settled') && (
            <>
              <BetInput amt={amt} setAmt={setAmt} />
              <button className="btn gold" disabled={!me || amt > bankroll} onClick={deal}>{amt > bankroll ? 'Not enough chips' : 'DEAL'}</button>
            </>
          )}
          {round?.status === 'open' && v?.phase === 'player' && (
            <>
              <button className="btn" onClick={() => act('hit')}>HIT</button>
              <button className="btn gold" onClick={() => act('stand')}>STAND</button>
              {v.can_double && <button className="btn ghost" onClick={() => act('double')}>DOUBLE</button>}
            </>
          )}
          {round?.status === 'settled' && (
            <span className={round.paid > 0 ? 'ok' : 'err'} style={{ fontSize: 16, fontFamily: 'var(--pk-mono)' }}>
              {String(v.outcome).toUpperCase()} {round.paid > 0 ? `+${usd(round.paid)}` : `-${usd(round.wagered)}`}
            </span>
          )}
        </div>
        {round?.status === 'settled' && <div style={{ textAlign: 'center' }}><SeedLine seed={round.seed} /></div>}
        {err && <div className="err" style={{ textAlign: 'center' }}>{err}</div>}
        {!me && <div className="hint" style={{ textAlign: 'center' }}>Sign in to play.</div>}
      </div>
    </div>
  );
}

/* ═══════════════════════════════════════════════════════════════ CRAPS ═══ */
const PIPS = { 1: [4], 2: [0, 8], 3: [0, 4, 8], 4: [0, 2, 6, 8], 5: [0, 2, 4, 6, 8], 6: [0, 2, 3, 5, 6, 8] };
function Die({ value, tumbling }) {
  return (
    <span className={`die3d${tumbling ? ' tumble' : ''}`}>
      {Array.from({ length: 9 }, (_, i) => (
        <i key={i} className={PIPS[value]?.includes(i) ? 'pip' : 'pip off'} />
      ))}
    </span>
  );
}

const CRAPS_POINTS = [4, 5, 6, 8, 9, 10];
const CRAPS_PROPS = [
  { type: 'any7', label: 'ANY 7', sub: 'any seven', pay: '4:1' },
  { type: 'yo', label: 'YO · 11', sub: 'eleven', pay: '15:1' },
  { type: 'anycraps', label: 'ANY CRAPS', sub: '2 · 3 · 12', pay: '7:1' },
];

// A little stack of casino chips standing on a bet zone.
function ChipStack({ amount }) {
  return (
    <span className="cr-chip" title={usd(amount)}>
      <i /><i /><i /><b>{usd(amount)}</b>
    </span>
  );
}

export function CrapsGame({ me, refresh }) {
  const [amt, setAmt] = useState(200);
  const [round, setRound] = useState(null);
  const [props, setProps] = useState([]);
  const [tumbling, setTumbling] = useState(false);
  const [shown, setShown] = useState(null);   // dice faces currently displayed
  const [err, setErr] = useState('');
  const { banner, session, flash } = useOutcome();
  const bankroll = me?.chips ?? 0;

  useEffect(() => {
    api.get('/api/casino/craps').then(d => {
      if (d.open) { setRound(d.open); const last = d.open.view.rolls?.slice(-1)[0]; if (last) setShown(last); }
    }).catch(() => {});
  }, []);

  const v = round?.view;
  const open = round && round.status === 'open';
  const point = v?.point || null;
  const pendingFor = (t) => props.filter(p => p.type === t).reduce((s, p) => s + p.amount, 0);
  const propsTotal = props.reduce((s, p) => s + p.amount, 0);

  const comeOut = async () => {
    setErr('');
    if (amt > bankroll) { setErr(`Not enough chips — the pass-line bet is ${usd(amt)} but you have ${usd(bankroll)}.`); return; }
    try { const r = await api.post('/api/casino/start', { game: 'craps', amount: amt }); setRound(r); setShown(null); setProps([]); sfx('bet'); refresh(); }
    catch (e) { setErr(e.message); }
  };
  // Drop a one-roll prop on a zone for the NEXT throw — never past the bankroll.
  const addProp = (t) => {
    setErr('');
    if (!open || tumbling) return;
    if (amt + propsTotal > bankroll) { setErr(`Not enough chips to add that ${t.toUpperCase().replace('_', ' ')} bet.`); return; }
    setProps(p => [...p, { type: t, amount: amt }]); sfx('bet');
  };
  const roll = async () => {
    setErr(''); setTumbling(true);
    sfx('check');
    try {
      const r = await api.post('/api/casino/act', { round_id: round.round_id, action: 'roll', props });
      setTimeout(() => {
        setTumbling(false);
        setRound(r); setProps([]);
        setShown(r.view.rolls[r.view.rolls.length - 1]);
        if (r.status === 'settled') { flash((r.paid || 0) - (r.wagered || 0)); refresh(); }
        else refresh();
      }, 750);
    } catch (e) { setTumbling(false); setErr(e.message); }
  };

  const d = shown || [1, 1];

  return (
    <div className="panel" style={{ padding: 0, background: 'transparent', border: 'none' }}>
      <OutcomeBanner banner={banner} session={session} />
      <div className="cr-table cr-felt">
        {/* Point boxes — the puck rides the live point. */}
        <div className="cr-points">
          {CRAPS_POINTS.map(n => (
            <div key={n} className={`cr-pt${point === n ? ' on' : ''}`}>
              <span className="cr-pt-n">{n === 6 ? 'SIX' : n === 9 ? 'NINE' : n}</span>
              {point === n && <span className="cr-onpuck">ON</span>}
            </div>
          ))}
        </div>

        {/* The dice pit. */}
        <div className="cr-pit">
          {!point && <div className="cr-offpuck">OFF</div>}
          <div className="cr-dice">
            <Die value={d[0]} tumbling={tumbling} />
            <Die value={d[1]} tumbling={tumbling} />
          </div>
          {shown && !tumbling && <div className="cr-sum mono">{d[0] + d[1]}</div>}
        </div>

        {/* The betting felt — click a zone to place chips for the next roll. */}
        <button
          className={`cr-zone field${pendingFor('field') ? ' active' : ''}`}
          disabled={!open || tumbling}
          onClick={() => addProp('field')}
        >
          <span className="cr-zlabel">FIELD</span>
          <span className="cr-zsub">2 · 3 · 4 · 9 · 10 · 11 · 12 <em>(2 &amp; 12 pay double)</em></span>
          {pendingFor('field') > 0 && <ChipStack amount={pendingFor('field')} />}
        </button>
        <div className="cr-props">
          {CRAPS_PROPS.map(p => (
            <button
              key={p.type}
              className={`cr-zone prop${pendingFor(p.type) ? ' active' : ''}`}
              disabled={!open || tumbling}
              onClick={() => addProp(p.type)}
            >
              <span className="cr-zlabel">{p.label}</span>
              <span className="cr-zsub">{p.sub} · <em>{p.pay}</em></span>
              {pendingFor(p.type) > 0 && <ChipStack amount={pendingFor(p.type)} />}
            </button>
          ))}
        </div>

        {/* Pass line — the come-out call to action when the dice are OFF. */}
        <button
          className={`cr-pass${open ? ' locked' : ''}`}
          disabled={open || !me || (!open && amt > bankroll)}
          onClick={open ? undefined : comeOut}
        >
          PASS LINE {open
            ? `· ${usd(v.pass)} WORKING${point ? ` · POINT ${point}` : ''}`
            : (amt > bankroll ? '· NOT ENOUGH CHIPS' : `· COME OUT ${usd(amt)}`)}
        </button>

        <div className="cr-rail">
          <BetInput amt={amt} setAmt={setAmt} />
          {open
            ? <button className="btn gold big" disabled={tumbling} onClick={roll}>🎲 ROLL{propsTotal ? ` · +${usd(propsTotal)}` : ''}</button>
            : <span className="hint">↑ place the pass line to start the dice</span>}
        </div>

        {(v?.last_props || []).map((p, i) => (
          <div key={i} className="kv" style={{ maxWidth: 460, margin: '4px auto' }}>
            <span>{p.type}</span><span style={{ color: p.won ? 'var(--pk-gold)' : 'var(--pk-dim)' }}>{p.won ? `+${usd(p.paid)}` : `-${usd(p.amount)}`}</span>
          </div>
        ))}
        {round?.status === 'settled' && !tumbling && (
          <div style={{ textAlign: 'center', marginTop: 8 }}>
            <span className={v.outcome === 'win' ? 'ok' : 'err'} style={{ fontSize: 16 }}>
              {v.outcome === 'win' ? `WINNER · +${usd(round.paid)}` : 'SEVEN OUT'}
            </span>
            <SeedLine seed={round.seed} />
          </div>
        )}
        {err && <div className="err" style={{ textAlign: 'center' }}>{err}</div>}
        {!me && <div className="hint" style={{ textAlign: 'center' }}>Sign in to play.</div>}
      </div>
    </div>
  );
}

/* ════════════════════════════════════════════════════════ VIDEO POKER ═══ */
const VP_LADDER = [
  ['royal_flush', 'ROYAL FLUSH', 800], ['straight_flush', 'STRAIGHT FLUSH', 50],
  ['four_kind', 'FOUR OF A KIND', 25], ['full_house', 'FULL HOUSE', 9],
  ['flush', 'FLUSH', 6], ['straight', 'STRAIGHT', 4],
  ['three_kind', 'THREE OF A KIND', 3], ['two_pair', 'TWO PAIR', 2], ['jacks_or_better', 'JACKS OR BETTER', 1],
];

export function VideoPokerGame({ me, refresh }) {
  const [amt, setAmt] = useState(100);
  const [round, setRound] = useState(null);
  const [held, setHeld] = useState([]);
  const [err, setErr] = useState('');
  const { banner, session, flash } = useOutcome();
  const bankroll = me?.chips ?? 0;

  useEffect(() => { api.get('/api/casino/videopoker').then(d => d.open && setRound(d.open)).catch(() => {}); }, []);

  const deal = async () => {
    setErr(''); setHeld([]);
    if (amt > bankroll) { setErr(`Not enough chips — the bet is ${usd(amt)} but you have ${usd(bankroll)}.`); return; }
    try { const r = await api.post('/api/casino/start', { game: 'videopoker', amount: amt }); setRound(r); sfx('deal'); refresh(); }
    catch (e) { setErr(e.message); }
  };
  const draw = async () => {
    setErr('');
    const mask = held.reduce((m, i) => m | (1 << i), 0);
    try {
      const r = await api.post('/api/casino/act', { round_id: round.round_id, action: 'draw', mask });
      setRound(r); flash((r.paid || 0) - (r.wagered || 0)); refresh();
    } catch (e) { setErr(e.message); }
  };
  const toggle = (i) => round?.status === 'open' && setHeld(h => h.includes(i) ? h.filter(x => x !== i) : [...h, i]);

  const v = round?.view;
  const won = round?.status === 'settled' && round.paid > 0;
  return (
    <div className={`vp-cab${won ? ' win' : ''}`}>
      <OutcomeBanner banner={banner} session={session} />
      <div className="vp-marquee">⛧ JACKS OR BETTER · FULL PAY 9/6 ⛧</div>
      <div className="vp-glass">
        {VP_LADDER.map(([k, label, pay]) => (
          <div key={k} className={`vp-payrow${round?.status === 'settled' && v?.result === k ? ' hit' : ''}`}>
            <span>{label}</span><span>{pay}×</span>
          </div>
        ))}
      </div>
      <div className="vp-screen">
        <div className="vp-cards">
          {(v?.hand || ['??', '??', '??', '??', '??']).map((c, i) => (
            <div key={`${round?.round_id || 'idle'}-${i}`} className="vp-slot bj-deal" style={{ animationDelay: `${i * 0.09}s` }} onClick={() => toggle(i)}>
              <span style={{ display: 'block', transform: held.includes(i) ? 'translateY(-7px)' : 'none', transition: '.12s' }}>
                <Card code={c} />
              </span>
              {round?.status === 'open' && <span className={`vp-held${held.includes(i) ? ' on' : ''}`}>HELD</span>}
            </div>
          ))}
        </div>
        {round?.status === 'settled' && (
          <div className={`vp-readout ${round.paid > 0 ? 'ok' : 'err'}`}>
            {String(v.result).replace(/_/g, ' ').toUpperCase()} {round.paid > 0 ? `· WIN ${usd(round.paid)}` : ''}
          </div>
        )}
      </div>
      <div className="vp-deck">
        {round?.status === 'open' ? (
          <>
            <div className="vp-holdrow">
              {[0, 1, 2, 3, 4].map(i => (
                <button key={i} className={`vp-btn${held.includes(i) ? ' lit' : ''}`} onClick={() => toggle(i)}>HOLD</button>
              ))}
            </div>
            <button className="vp-draw" onClick={draw}>DRAW</button>
          </>
        ) : (
          <div style={{ display: 'flex', gap: 12, alignItems: 'center', justifyContent: 'center' }}>
            <BetInput amt={amt} setAmt={setAmt} />
            <button className="vp-draw" disabled={!me || amt > bankroll} onClick={deal}>{amt > bankroll ? 'NO CHIPS' : 'DEAL'}</button>
          </div>
        )}
      </div>
      {round?.status === 'settled' && <div style={{ textAlign: 'center', paddingBottom: 10 }}><SeedLine seed={round.seed} /></div>}
      {err && <div className="err" style={{ textAlign: 'center', paddingBottom: 10 }}>{err}</div>}
      {!me && <div className="hint" style={{ textAlign: 'center', paddingBottom: 12 }}>Sign in to play.</div>}
    </div>
  );
}

/* ═══════════════════════════════════════════════════════════════ SLOTS ═══ */
export function SlotsGame({ me, refresh }) {
  const [amt, setAmt] = useState(100);
  const [reels, setReels] = useState(['⛧', '⛧', '⛧']);
  const [res, setRes] = useState(null);
  const [seed, setSeed] = useState(null);
  const [rolling, setRolling] = useState(false);
  const [err, setErr] = useState('');
  const { banner, session, flash } = useOutcome();
  const bankroll = me?.chips ?? 0;

  const pull = async () => {
    setErr(''); setRes(null);
    if (amt > bankroll) { setErr(`Not enough chips — the pull is ${usd(amt)} but you have ${usd(bankroll)}.`); return; }
    setRolling(true);
    sfx('bet');
    const flicker = setInterval(() => {
      setReels(['🍒', '🍋', '🔔', '🐍', '7️⃣', '💀', '⛧'].sort(() => Math.random() - 0.5).slice(0, 3));
    }, 80);
    try {
      const r = await api.post('/api/casino/play', { game: 'slots', amount: amt });
      setTimeout(() => {
        clearInterval(flicker);
        setReels(r.result.reels); setRes(r.result); setSeed(r.seed); setRolling(false);
        flash((r.result.paid || 0) - amt);
        refresh();
      }, 700);
    } catch (e) { clearInterval(flicker); setRolling(false); setErr(e.message); }
  };

  return (
    <div className="vp-cab" style={{ maxWidth: 480 }}>
      <OutcomeBanner banner={banner} session={session} />
      <div className="vp-marquee">⛧ BEAST REELS ⛧</div>
      <div className="vp-screen" style={{ textAlign: 'center' }}>
        <div style={{ fontSize: 64, letterSpacing: 18, margin: '14px 0', filter: rolling ? 'blur(2px)' : 'none' }}>
          {reels.join('')}
        </div>
        {res && <div className={`vp-readout ${res.paid > 0 ? 'ok' : 'err'}`}>{res.label} {res.paid > 0 ? `· +${usd(res.paid)}` : ''}</div>}
      </div>
      <div className="vp-deck" style={{ display: 'flex', gap: 10, justifyContent: 'center', alignItems: 'center' }}>
        <BetInput amt={amt} setAmt={setAmt} />
        <button className="vp-draw" disabled={rolling || !me || amt > bankroll} onClick={pull}>{rolling ? '…' : amt > bankroll ? 'NO CHIPS' : 'PULL'}</button>
      </div>
      <div className="hint" style={{ textAlign: 'center', paddingBottom: 10 }}>⛧ 150× · 💀 60× · 7️⃣ 40× · 🐍 14× · 🔔 10× · 🍋 5× · 🍒 3× · two 🍒 2× · one 🍒 half back</div>
      <div style={{ textAlign: 'center', paddingBottom: 10 }}><SeedLine seed={seed} /></div>
      {err && <div className="err" style={{ textAlign: 'center', paddingBottom: 10 }}>{err}</div>}
    </div>
  );
}

export function CasinoGamePage({ game, me, refresh, A }) {
  const def = GAMES.find(g => g.id === game);
  if (!def) return <div className="wrap"><div className="center-msg">No such game in the pit.</div></div>;
  // Render each game with a STABLE component identity (not an inline arrow) so
  // a parent re-render — e.g. the refresh() that fires after a round settles —
  // never remounts the game and wipes its result. The last result stays in the
  // viewport until the player starts a new game with the primary button.
  let inner = null;
  switch (game) {
    case 'roulette_eu': inner = <RouletteGame variant="roulette_eu" me={me} refresh={refresh} />; break;
    case 'roulette_us': inner = <RouletteGame variant="roulette_us" me={me} refresh={refresh} />; break;
    case 'blackjack': inner = <BlackjackGame me={me} refresh={refresh} />; break;
    case 'craps': inner = <CrapsGame me={me} refresh={refresh} />; break;
    case 'videopoker': inner = <VideoPokerGame me={me} refresh={refresh} />; break;
    case 'slots': inner = <SlotsGame me={me} refresh={refresh} />; break;
  }
  return (
    <div className="wrap">
      <div className="toprow">
        <div><A href="/casino" className="badge">← THE PIT</A> <strong style={{ marginLeft: 10, fontSize: 18 }}>{def.icon} {def.name}</strong></div>
        {me && <span className="chips-pill">{usd(me.chips)}</span>}
      </div>
      {inner}
    </div>
  );
}
