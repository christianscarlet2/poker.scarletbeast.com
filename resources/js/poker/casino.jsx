import React, { useState, useEffect } from 'react';
import { api } from './api.js';
import { Card } from './cards.jsx';
import { usd } from './money.jsx';
import { play as sfx } from './sounds.js';

/**
 * The pit beyond the felt — roulette (US/EU), blackjack, craps, video poker,
 * and the Beast Reels. Every round provably fair: the seed prints on settle.
 */

const GAMES = [
  { id: 'roulette_eu', icon: '🎡', name: 'European Roulette', blurb: 'One zero. 2.7% for the house — the merciful wheel.' },
  { id: 'roulette_us', icon: '🛞', name: 'American Roulette', blurb: 'Zero and double-zero. The house bites twice.' },
  { id: 'blackjack', icon: '🃏', name: 'Blackjack', blurb: 'Six decks, dealer stands all 17s, naturals pay 3:2.' },
  { id: 'craps', icon: '🎲', name: 'Craps', blurb: 'Pass line and the loud one-roll props. The bones decide.' },
  { id: 'videopoker', icon: '🖥️', name: 'Video Poker', blurb: 'Jacks or Better, full-pay 9/6. Hold and pray.' },
  { id: 'slots', icon: '🎰', name: 'Beast Reels', blurb: 'Three reels, one line, the ⛧ pays 150:1.' },
];

export function CasinoLobby({ A }) {
  return (
    <div className="wrap">
      <div className="toprow"><h2 style={{ margin: 0 }}>The Pit</h2><span className="mono" style={{ color: 'var(--pk-dim)' }}>provably fair · seeds revealed on settle · house edge printed on the door</span></div>
      <div className="tbl-list">
        {GAMES.map(g => (
          <div key={g.id} className="tcard">
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
              <div className="nm">{g.icon} {g.name}</div>
            </div>
            <div className="meta"><span>{g.blurb}</span></div>
            <A href={`/casino/${g.id}`} className="btn" style={{ textAlign: 'center' }}>Play</A>
          </div>
        ))}
      </div>
    </div>
  );
}

/* ---------------------------------------------------------------- helpers */
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

function useRefreshChips() {
  // bumping /api/me after settles keeps the app-bar pill honest
  return async (refresh) => { try { await refresh(); } catch (e) {} };
}

/* ---------------------------------------------------------------- roulette */
const ROULETTE_OUTSIDE = [
  ['red', '♥ RED', 1], ['black', '♠ BLACK', 1], ['odd', 'ODD', 1], ['even', 'EVEN', 1],
  ['low', '1–18', 1], ['high', '19–36', 1],
  ['dozen1', '1st 12', 2], ['dozen2', '2nd 12', 2], ['dozen3', '3rd 12', 2],
  ['col1', 'COL 1', 2], ['col2', 'COL 2', 2], ['col3', 'COL 3', 2],
];
const RED_NUMS = [1, 3, 5, 7, 9, 12, 14, 16, 18, 19, 21, 23, 25, 27, 30, 32, 34, 36];

export function RouletteGame({ variant, me, refresh }) {
  const [amt, setAmt] = useState(100);
  const [book, setBook] = useState([]);          // pending bets
  const [spin, setSpin] = useState(null);        // last result
  const [seed, setSeed] = useState(null);
  const [err, setErr] = useState('');
  const [spinning, setSpinning] = useState(false);

  const add = (type, selection = '') => setBook(b => [...b, { type, selection, amount: amt }]);
  const total = book.reduce((s, b) => s + b.amount, 0);

  const fire = async () => {
    setErr(''); setSpinning(true); setSpin(null);
    try {
      const r = await api.post('/api/casino/play', { game: variant, bets: book });
      setTimeout(() => {
        setSpin(r.result); setSeed(r.seed); setSpinning(false); setBook([]);
        sfx(r.result.total_paid > 0 ? 'win' : 'fold');
        refresh();
      }, 900);
      sfx('bet');
    } catch (e) { setErr(e.message); setSpinning(false); }
  };

  const numbers = ['0', ...(variant === 'roulette_us' ? ['00'] : []), ...Array.from({ length: 36 }, (_, i) => String(i + 1))];

  return (
    <div className="panel">
      <BetInput amt={amt} setAmt={setAmt} />
      <div className="hint" style={{ margin: '10px 0 4px' }}>Straight numbers pay 35:1</div>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(44px, 1fr))', gap: 4 }}>
        {numbers.map(n => {
          const red = RED_NUMS.includes(+n);
          return (
            <button key={n} className="btn ghost" style={{
              padding: '8px 0', textAlign: 'center',
              color: n === '0' || n === '00' ? '#3a6' : red ? 'var(--pk-scs)' : 'var(--pk-bone)',
            }} onClick={() => add('straight', n)}>{n}</button>
          );
        })}
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
          <button className="btn gold big" style={{ marginTop: 10, width: '100%' }} disabled={spinning || !me} onClick={fire}>
            {spinning ? 'The wheel turns…' : `SPIN · ${usd(total)}`}
          </button>
        </div>
      )}

      {spin && (
        <div style={{ marginTop: 14, textAlign: 'center' }}>
          <div style={{ fontSize: 44, fontWeight: 800, color: spin.pocket === '0' || spin.pocket === '00' ? '#3a6' : RED_NUMS.includes(+spin.pocket) ? 'var(--pk-scs)' : 'var(--pk-bone)' }}>
            {spin.pocket}
          </div>
          {spin.results.map((r, i) => (
            <div key={i} className="kv"><span>{r.type}{r.selection ? ` ${r.selection}` : ''}</span>
              <span style={{ color: r.won ? 'var(--pk-gold)' : 'var(--pk-dim)' }}>{r.won ? `+${usd(r.paid)}` : `-${usd(r.amount)}`}</span></div>
          ))}
          <SeedLine seed={seed} />
        </div>
      )}
      {err && <div className="err">{err}</div>}
      {!me && <div className="hint">Sign in to play.</div>}
    </div>
  );
}

/* --------------------------------------------------------------- blackjack */
export function BlackjackGame({ me, refresh }) {
  const [amt, setAmt] = useState(500);
  const [round, setRound] = useState(null);
  const [err, setErr] = useState('');

  useEffect(() => { api.get('/api/casino/blackjack').then(d => d.open && setRound(d.open)).catch(() => {}); }, []);

  const deal = async () => {
    setErr('');
    try { const r = await api.post('/api/casino/start', { game: 'blackjack', amount: amt }); setRound(r); sfx('deal'); refresh(); }
    catch (e) { setErr(e.message); }
  };
  const act = async (action) => {
    setErr('');
    try {
      const r = await api.post('/api/casino/act', { round_id: round.round_id, action });
      setRound(r); sfx(action === 'hit' ? 'deal' : 'check');
      if (r.status === 'settled') { sfx(r.paid > 0 ? 'win' : 'fold'); refresh(); }
    } catch (e) { setErr(e.message); }
  };

  const v = round?.view;
  return (
    <div className="panel">
      {!round || round.status === 'settled' ? (
        <div style={{ display: 'flex', gap: 10, alignItems: 'center' }}>
          <BetInput amt={amt} setAmt={setAmt} />
          <button className="btn gold" disabled={!me} onClick={deal}>DEAL</button>
        </div>
      ) : null}
      {v && (
        <div style={{ marginTop: 14 }}>
          <div className="hint">Dealer {v.dealer_total != null ? `· ${v.dealer_total}` : ''}</div>
          <div style={{ display: 'flex', gap: 5 }}>{v.dealer.map((c, i) => <Card key={i} code={c} />)}</div>
          <div className="hint" style={{ marginTop: 12 }}>You · {v.player_total}</div>
          <div style={{ display: 'flex', gap: 5 }}>{v.player.map((c, i) => <Card key={i} code={c} />)}</div>
          {round.status === 'open' && v.phase === 'player' && (
            <div style={{ display: 'flex', gap: 8, marginTop: 14 }}>
              <button className="btn" onClick={() => act('hit')}>Hit</button>
              <button className="btn gold" onClick={() => act('stand')}>Stand</button>
              {v.can_double && <button className="btn ghost" onClick={() => act('double')}>Double</button>}
            </div>
          )}
          {round.status === 'settled' && (
            <div style={{ marginTop: 12 }}>
              <span className={round.paid > 0 ? 'ok' : 'err'} style={{ fontSize: 16 }}>
                {String(v.outcome).toUpperCase()} {round.paid > 0 ? `· +${usd(round.paid)}` : `· -${usd(round.wagered)}`}
              </span>
              <SeedLine seed={round.seed} />
            </div>
          )}
        </div>
      )}
      {err && <div className="err">{err}</div>}
      {!me && <div className="hint">Sign in to play.</div>}
    </div>
  );
}

/* ------------------------------------------------------------------- craps */
const DIE = ['', '⚀', '⚁', '⚂', '⚃', '⚄', '⚅'];
export function CrapsGame({ me, refresh }) {
  const [amt, setAmt] = useState(200);
  const [round, setRound] = useState(null);
  const [props, setProps] = useState([]);
  const [err, setErr] = useState('');

  useEffect(() => { api.get('/api/casino/craps').then(d => d.open && setRound(d.open)).catch(() => {}); }, []);

  const comeOut = async () => {
    setErr('');
    try { const r = await api.post('/api/casino/start', { game: 'craps', amount: amt }); setRound(r); sfx('bet'); refresh(); }
    catch (e) { setErr(e.message); }
  };
  const roll = async () => {
    setErr('');
    try {
      const r = await api.post('/api/casino/act', { round_id: round.round_id, action: 'roll', props });
      setRound(r); setProps([]); sfx('check');
      if (r.status === 'settled') { sfx(r.view.outcome === 'win' ? 'win' : 'fold'); refresh(); }
    } catch (e) { setErr(e.message); }
  };
  const addProp = (type) => setProps(p => [...p, { type, amount: amt }]);

  const v = round?.view;
  const last = v?.rolls?.length ? v.rolls[v.rolls.length - 1] : null;
  return (
    <div className="panel">
      {!round || round.status === 'settled' ? (
        <div style={{ display: 'flex', gap: 10, alignItems: 'center', flexWrap: 'wrap' }}>
          <BetInput amt={amt} setAmt={setAmt} />
          <button className="btn gold" disabled={!me} onClick={comeOut}>PASS LINE · COME OUT</button>
        </div>
      ) : null}
      {v && (
        <div style={{ marginTop: 12 }}>
          <div className="kv"><span>Pass line</span><span>{usd(v.pass)}{v.point ? ` · point ${v.point}` : ' · come-out'}</span></div>
          {last && <div style={{ fontSize: 56, textAlign: 'center', margin: '8px 0' }}>{DIE[last[0]]} {DIE[last[1]]} <span className="mono" style={{ fontSize: 20, color: 'var(--pk-gold)' }}>= {last[0] + last[1]}</span></div>}
          {(v.last_props || []).map((p, i) => (
            <div key={i} className="kv"><span>{p.type}</span><span style={{ color: p.won ? 'var(--pk-gold)' : 'var(--pk-dim)' }}>{p.won ? `+${usd(p.paid)}` : `-${usd(p.amount)}`}</span></div>
          ))}
          {round.status === 'open' && (
            <>
              <div className="hint" style={{ marginTop: 8 }}>One-roll props (ride the next roll): Field 1:1 (2,12 pay 2:1) · Any 7 4:1 · Yo 15:1 · Any Craps 7:1</div>
              <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
                {['field', 'any7', 'yo', 'anycraps'].map(t => <button key={t} className="btn ghost" onClick={() => addProp(t)}>{t.toUpperCase()}</button>)}
              </div>
              {props.map((p, i) => <div key={i} className="kv"><span>{p.type} · {usd(p.amount)}</span><button className="badge" onClick={() => setProps(props.filter((_, j) => j !== i))}>✕</button></div>)}
              <button className="btn gold big" style={{ marginTop: 10, width: '100%' }} onClick={roll}>🎲 ROLL</button>
            </>
          )}
          {round.status === 'settled' && (
            <div style={{ marginTop: 10 }}>
              <span className={v.outcome === 'win' ? 'ok' : 'err'} style={{ fontSize: 16 }}>
                {v.outcome === 'win' ? `WINNER · +${usd(round.paid)}` : 'SEVEN OUT'}
              </span>
              <SeedLine seed={round.seed} />
            </div>
          )}
        </div>
      )}
      {err && <div className="err">{err}</div>}
      {!me && <div className="hint">Sign in to play.</div>}
    </div>
  );
}

/* ------------------------------------------------------------- video poker */
const VP_LADDER = [
  ['Royal Flush', '800'], ['Straight Flush', '50'], ['Four of a Kind', '25'], ['Full House', '9'],
  ['Flush', '6'], ['Straight', '4'], ['Three of a Kind', '3'], ['Two Pair', '2'], ['Jacks or Better', '1'],
];
export function VideoPokerGame({ me, refresh }) {
  const [amt, setAmt] = useState(100);
  const [round, setRound] = useState(null);
  const [held, setHeld] = useState([]);
  const [err, setErr] = useState('');

  useEffect(() => { api.get('/api/casino/videopoker').then(d => d.open && setRound(d.open)).catch(() => {}); }, []);

  const deal = async () => {
    setErr(''); setHeld([]);
    try { const r = await api.post('/api/casino/start', { game: 'videopoker', amount: amt }); setRound(r); sfx('deal'); refresh(); }
    catch (e) { setErr(e.message); }
  };
  const draw = async () => {
    setErr('');
    const mask = held.reduce((m, i) => m | (1 << i), 0);
    try {
      const r = await api.post('/api/casino/act', { round_id: round.round_id, action: 'draw', mask });
      setRound(r); sfx(r.paid > 0 ? 'win' : 'fold'); refresh();
    } catch (e) { setErr(e.message); }
  };
  const toggle = (i) => setHeld(h => h.includes(i) ? h.filter(x => x !== i) : [...h, i]);

  const v = round?.view;
  return (
    <div className="panel">
      <div className="row">
        <div>
          {!round || round.status === 'settled' ? (
            <div style={{ display: 'flex', gap: 10, alignItems: 'center' }}>
              <BetInput amt={amt} setAmt={setAmt} />
              <button className="btn gold" disabled={!me} onClick={deal}>DEAL</button>
            </div>
          ) : null}
          {v && (
            <div style={{ marginTop: 14 }}>
              <div style={{ display: 'flex', gap: 6 }}>
                {v.hand.map((c, i) => (
                  <div key={i} style={{ textAlign: 'center', cursor: round.status === 'open' ? 'pointer' : 'default' }}
                    onClick={() => round.status === 'open' && toggle(i)}>
                    <span style={{ display: 'block', transform: held.includes(i) ? 'translateY(-6px)' : 'none', transition: '.12s' }}>
                      <Card code={c} />
                    </span>
                    {round.status === 'open' && <span className="mono" style={{ fontSize: 9, color: held.includes(i) ? 'var(--pk-gold)' : 'var(--pk-dim)' }}>{held.includes(i) ? 'HELD' : 'hold'}</span>}
                  </div>
                ))}
              </div>
              {round.status === 'open' && <button className="btn gold big" style={{ marginTop: 12 }} onClick={draw}>DRAW</button>}
              {round.status === 'settled' && (
                <div style={{ marginTop: 10 }}>
                  <span className={round.paid > 0 ? 'ok' : 'err'} style={{ fontSize: 16 }}>
                    {String(v.result).replace(/_/g, ' ').toUpperCase()} {round.paid > 0 ? `· +${usd(round.paid)}` : `· -${usd(round.wagered)}`}
                  </span>
                  <SeedLine seed={round.seed} />
                </div>
              )}
            </div>
          )}
        </div>
        <div>
          <table className="tbl">
            <thead><tr><th>Hand</th><th>Pays</th></tr></thead>
            <tbody>{VP_LADDER.map(([h, p]) => <tr key={h}><td>{h}</td><td className="mono">{p}×</td></tr>)}</tbody>
          </table>
        </div>
      </div>
      {err && <div className="err">{err}</div>}
      {!me && <div className="hint">Sign in to play.</div>}
    </div>
  );
}

/* ------------------------------------------------------------------- slots */
export function SlotsGame({ me, refresh }) {
  const [amt, setAmt] = useState(100);
  const [reels, setReels] = useState(['⛧', '⛧', '⛧']);
  const [res, setRes] = useState(null);
  const [seed, setSeed] = useState(null);
  const [rolling, setRolling] = useState(false);
  const [err, setErr] = useState('');

  const pull = async () => {
    setErr(''); setRes(null); setRolling(true);
    sfx('bet');
    const flicker = setInterval(() => {
      setReels(['🍒', '🍋', '🔔', '🐍', '7️⃣', '💀', '⛧'].sort(() => Math.random() - 0.5).slice(0, 3));
    }, 80);
    try {
      const r = await api.post('/api/casino/play', { game: 'slots', amount: amt });
      setTimeout(() => {
        clearInterval(flicker);
        setReels(r.result.reels); setRes(r.result); setSeed(r.seed); setRolling(false);
        sfx(r.result.paid > amt ? 'win' : r.result.paid > 0 ? 'check' : 'fold');
        refresh();
      }, 700);
    } catch (e) { clearInterval(flicker); setRolling(false); setErr(e.message); }
  };

  return (
    <div className="panel" style={{ textAlign: 'center' }}>
      <div style={{ fontSize: 64, letterSpacing: 18, margin: '14px 0', filter: rolling ? 'blur(2px)' : 'none' }}>
        {reels.join('')}
      </div>
      {res && <div className={res.paid > 0 ? 'ok' : 'err'} style={{ fontSize: 15 }}>{res.label} {res.paid > 0 ? `· +${usd(res.paid)}` : ''}</div>}
      <div style={{ display: 'flex', gap: 10, justifyContent: 'center', alignItems: 'center', marginTop: 12 }}>
        <BetInput amt={amt} setAmt={setAmt} />
        <button className="btn gold big" disabled={rolling || !me} onClick={pull}>{rolling ? '…' : 'PULL'}</button>
      </div>
      <div className="hint" style={{ marginTop: 12 }}>⛧ 150× · 💀 60× · 7️⃣ 40× · 🐍 14× · 🔔 10× · 🍋 5× · 🍒 3× · two 🍒 2× · one 🍒 returns half</div>
      <SeedLine seed={seed} />
      {err && <div className="err">{err}</div>}
      {!me && <div className="hint">Sign in to play.</div>}
    </div>
  );
}

export function CasinoGamePage({ game, me, refresh, A }) {
  const def = GAMES.find(g => g.id === game);
  if (!def) return <div className="wrap"><div className="center-msg">No such game in the pit.</div></div>;
  const Inner = {
    roulette_eu: () => <RouletteGame variant="roulette_eu" me={me} refresh={refresh} />,
    roulette_us: () => <RouletteGame variant="roulette_us" me={me} refresh={refresh} />,
    blackjack: () => <BlackjackGame me={me} refresh={refresh} />,
    craps: () => <CrapsGame me={me} refresh={refresh} />,
    videopoker: () => <VideoPokerGame me={me} refresh={refresh} />,
    slots: () => <SlotsGame me={me} refresh={refresh} />,
  }[game];
  return (
    <div className="wrap">
      <div className="toprow">
        <div><A href="/casino" className="badge">← THE PIT</A> <strong style={{ marginLeft: 10, fontSize: 18 }}>{def.icon} {def.name}</strong></div>
        {me && <span className="chips-pill">{usd(me.chips)}</span>}
      </div>
      <Inner />
    </div>
  );
}
