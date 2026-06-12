import React, { useState, useEffect, useRef } from 'react';
import { Card, Board } from './cards.jsx';
import { Money, usd, bb } from './money.jsx';
import { useSkin } from './skin.jsx';
import { STAT_INFO } from './statsInfo.js';
import { voiceHandDiff } from './sounds.js';
import { EMOTES } from './chat.jsx';

// Seat positions around the oval (percent of felt box), for up to 9 seats,
// laid out clockwise from bottom-center (the hero seat).
const SEAT_POS = {
  2: [[50, 92], [50, 8]],
  6: [[50, 92], [12, 70], [12, 26], [50, 8], [88, 26], [88, 70]],
  9: [[50, 93], [20, 88], [6, 56], [12, 22], [34, 7], [66, 7], [88, 22], [94, 56], [80, 88]],
};

// Portrait ring (mobile skin): the felt is a tall ellipse, so the seats climb
// the long sides instead of stretching across a wide one. Same clockwise
// order from the bottom-center hero seat.
const SEAT_POS_PORTRAIT = {
  2: [[50, 94], [50, 6]],
  6: [[50, 94], [10, 71], [10, 28], [50, 6], [90, 28], [90, 71]],
  9: [[50, 95], [13, 86], [7, 64], [7, 38], [22, 13], [50, 5], [78, 13], [93, 38], [93, 64]],
};

function posFor(maxSeats, seatNo, portrait) {
  const key = maxSeats <= 2 ? 2 : maxSeats <= 6 ? 6 : 9;
  const ring = (portrait ? SEAT_POS_PORTRAIT : SEAT_POS)[key];
  const idx = (seatNo - 1) % ring.length;
  return ring[idx];
}

// Games with no community board (stud + draw families).
const NO_BOARD = ['stud', 'razz', 'draw5'];

// A seated-but-not-yet-dealt player. Shown the instant someone buys in so the
// chair is visible immediately — no cards, just the avatar/stack and a
// "next hand" tag — until the engine deals them into the coming hand.
function WaitingSeat({ s, maxSeats, portrait, bbc }) {
  const [x, y] = posFor(maxSeats, s.seat_no, portrait);
  const lower = y > 55;
  return (
    <div
      className={`seat waiting${s.is_bot ? ' bot' : ''} ${lower ? 'low' : 'high'}`}
      style={{ left: `${x}%`, top: `${y}%` }}
    >
      <div className="av">{s.avatar || (s.is_bot ? '🤖' : '☠️')}</div>
      <div className="nm">{s.name || `Seat ${s.seat_no}`}{s.is_bot ? ' ⚙' : ''}</div>
      <div className="stk"><Money c={s.stack} bbCents={bbc} /></div>
      <div className="waiting-tag">NEXT HAND</div>
    </div>
  );
}

// PT-style value formatting per computed stat key.
function hudVal(key, v) {
  if (v === null || v === undefined) return '–';
  if (key === 'hands') return v >= 1000 ? (v / 1000).toFixed(1) + 'k' : String(v);
  if (key === 'af' || key === 'bb100') return Number(v).toFixed(1);
  if (key === 'name') return String(v).slice(0, 8);
  return String(v);
}

// Chip stack rendered ON the felt for a live street bet — denomination discs
// scale with the wager, drifting toward the pot from the bettor's seat.
// Pushed well clear of the seat→center corridor the HUD chips occupy.
function FeltChips({ x, y, amount, bbc, portrait }) {
  // Push the chips toward the pot. The portrait (mobile) felt is narrow and its
  // HUD is proportionally wide, so chips go deeper toward center there and the
  // sideways clearance is larger.
  const inward = portrait ? 0.60 : 0.44;
  const side = portrait ? 18 : 13;
  let cx = x + (50 - x) * inward;
  const cy = y + (46 - y) * inward;
  // Seats near the vertical center line share the HUD's column — step the chips
  // sideways (fading out toward the rails), opposite the dealer puck.
  const verticalness = 1 - Math.min(1, Math.abs(x - 50) / 30);
  cx += (x <= 50 ? -1 : 1) * side * verticalness;
  const discs = Math.min(5, 1 + Math.floor(Math.log10(Math.max(1, amount / Math.max(1, bbc)) + 1) * 2));
  return (
    <div className="felt-chips" style={{ left: `${cx}%`, top: `${cy}%` }}>
      <span className="stack">
        {Array.from({ length: discs }, (_, i) => <i key={i} className={`chipd t${(i % 5) + 1}`} style={{ marginTop: i ? -10 : 0 }} />)}
      </span>
      <span className="amt"><Money c={amount} bbCents={bbc} plain /></span>
    </div>
  );
}

// One seat's HUD chip: the uploaded PT4 layout rows, fed live stats. Each
// stat carries a hover codex entry — what it means, and a door to learn more.
// `above` flips the chip on top of the seat (lower-rail seats — keeps the HUD
// on the felt instead of spilling over marquees and action bars below).
function HudChip({ rows, map, stats, above }) {
  if (!stats) return null;
  return (
    <div className={`hud-chip${above ? ' above' : ''}`}>
      {rows.map((row, ri) => (
        <div key={ri} className="hud-row">
          {row.map((item, ii) => {
            const key = map[item.stat];
            if (!key) return null;            // stat we can't compute — drop
            const v = hudVal(key, stats[key]);
            const info = STAT_INFO[key];
            return (
              <span key={ii} className="hud-it">
                {item.label ? <i>{item.label}</i> : null}{v}
                {info && (
                  <span className="hud-tip">
                    <b>{info.title}</b>
                    {info.short}
                    <a href={`/stats-guide#${key}`}>Learn more →</a>
                  </span>
                )}
              </span>
            );
          })}
        </div>
      ))}
    </div>
  );
}

// Emote theater: bursts rise from the sender's seat; ordnance flies across
// the felt to its victim and detonates on arrival.
function EmoteFx({ effects, maxSeats, portrait }) {
  return (effects || []).map(fx => {
    const def = EMOTES[fx.body];
    if (!def) return null;
    const from = fx.from_seat ? posFor(maxSeats, fx.from_seat, portrait) : [50, 99]; // railbirds fire from the rail
    if (def.targeted && fx.target_seat) {
      const to = posFor(maxSeats, fx.target_seat, portrait);
      const ang = Math.atan2(to[1] - from[1], to[0] - from[0]) * 180 / Math.PI;
      return (
        <React.Fragment key={fx.id}>
          <span className="fx-fly" style={{
            '--fx': `${from[0]}%`, '--fy': `${from[1]}%`,
            '--tx': `${to[0]}%`, '--ty': `${to[1]}%`,
            '--rot': `${fx.body === 'rocket' ? ang + 45 : 0}deg`,
          }}>{def.e}</span>
          <span className="fx-impact" style={{ left: `${to[0]}%`, top: `${to[1]}%` }}>
            {fx.body === 'kiss' ? '💋' : fx.body === 'tomato' ? '🟥' : fx.body === 'egg' ? '🍳' : '💥'}
          </span>
        </React.Fragment>
      );
    }
    return <span key={fx.id} className="fx-burst" style={{ left: `${from[0]}%`, top: `${from[1]}%` }}>{def.e}</span>;
  });
}

export default function Felt({ state, mySeat, observer, hud, quiet, effects }) {
  const { skin } = useSkin();
  const [blast, setBlast] = useState(false);
  // The felt's voice: diff each hand update and play what changed.
  const prevHand = useRef(null);
  useEffect(() => {
    const hand = state?.hand;
    if (!hand) return;
    if (!quiet) voiceHandDiff(prevHand.current, hand, mySeat ?? null);
    // A fresh bomb-pot hand detonates on screen.
    const isNewHand = !prevHand.current || prevHand.current.hand_no !== hand.hand_no;
    if (isNewHand && (hand.actions || []).some(a => a.action === 'bomb_ante')) {
      setBlast(true);
      const t = setTimeout(() => setBlast(false), 1600);
      prevHand.current = hand;
      return () => clearTimeout(t);
    }
    prevHand.current = hand;
  }, [state?.hand?.hand_no, (state?.hand?.actions || []).length, state?.hand?.street, state?.hand?.to_act]);

  if (!state || !state.hand) {
    // No hand running yet — but if anyone has bought in, show their chairs so a
    // player who just sat down gets immediate feedback, not a dark void.
    const waiting = (state?.seats || []).filter((s) => s.user_id && s.status !== 'empty');
    if (!waiting.length) {
      return <div className="center-msg">The felt is dark. Waiting for souls…</div>;
    }
    const t = state.table;
    const portrait = skin === 'mobile';
    return (
      <div className="felt">
        <div className="center">
          <div className="street">WAITING FOR PLAYERS…</div>
        </div>
        {waiting.map((s) => (
          <WaitingSeat key={s.seat_no} s={s} maxSeats={t.max_seats} portrait={portrait} bbc={t.bb} />
        ))}
      </div>
    );
  }
  const h = state.hand;
  const t = state.table;
  const portrait = skin === 'mobile';
  const game = h.game || t.game || 'nlhe';
  const winners = {};
  (h.winners || []).forEach(w => {
    // A seat can take several pots (side pots, or both halves of a hi-lo
    // split) — accumulate. "Scoop" only when they dragged BOTH hi and lo.
    const prev = winners[w.seat];
    const kinds = new Set([...(prev?.kinds || []), w.pot_kind].filter(Boolean));
    winners[w.seat] = prev
      ? { ...w, amount: prev.amount + w.amount, hand: prev.hand, kinds, both: kinds.has('hi') && kinds.has('lo') }
      : { ...w, kinds, both: false };
  });
  const bbc = t.bb;
  const gameTag = t.game_short || (t.game_name ? t.game_name : null);
  // Players who have bought in but aren't in the current hand (sat down mid-hand)
  // get a visible chair right away, dealt in when the next hand starts.
  const inHand = new Set(Object.values(h.players).map((p) => p.seat));
  const waiting = (state.seats || []).filter(
    (s) => s.user_id && s.status !== 'empty' && !inHand.has(s.seat_no)
  );

  return (
    <div className="felt">
      <EmoteFx effects={effects} maxSeats={t.max_seats} portrait={portrait} />
      {blast && (
        <div className="bomb-blast">
          <span className="b1">💣</span>
          <span className="b2">💥</span>
          <span className="b3">BOMB POT</span>
        </div>
      )}
      <div className="center">
        {!NO_BOARD.includes(game) && <Board cards={h.board} />}
        <div className="pot">POT · <Money c={h.pot} bbCents={bbc} /></div>
        <div className="street">
          {gameTag ? `${gameTag} · ` : ''}{(h.street || '').toUpperCase()}{h.hand_no ? ` · HAND #${h.hand_no}` : ''}
        </div>
      </div>
      {Object.values(h.players).map((p) => {
        const [x, y] = posFor(t.max_seats, p.seat, portrait);
        const isActor = h.to_act === p.seat;
        const win = winners[p.seat];
        const nCards = (p.hole?.length || 0) + (p.up?.length || 0);
        // HUD placement that never lets two HUDs collide: SIDE-rail seats put
        // their HUD on the OUTWARD vertical side (top-side → up, bottom-side →
        // down) so a rail's two seats diverge instead of converging; CENTER
        // seats (no same-column neighbour, and no room toward the edge) put it
        // on the INWARD side toward the open middle.
        const isSide = Math.abs(x - 50) > 25;
        const hudAbove = isSide ? (y < 46) : (y > 46);
        const lower = y > 55; // (kept for the tooltip-open direction)
        return (
          <React.Fragment key={p.seat}>
            <div
              className={`seat${isActor ? ' act' : ''}${p.is_bot ? ' bot' : ''}${p.in_hand ? '' : ' folded'}${lower ? ' low' : ' high'}`}
              style={{ left: `${x}%`, top: `${y}%` }}
            >
              <div className="av">{p.avatar || (p.is_bot ? '🤖' : '☠️')}</div>
              <div className="nm">{p.name}{p.is_bot ? ' ⚙' : ''}</div>
              <div className="stk"><Money c={p.stack} bbCents={bbc} /></div>
              <div className={`hole${nCards > 4 ? ' many' : ''}`}>
                {p.in_hand && p.hole && p.hole.length
                  ? p.hole.map((c, i) => <Card key={i} code={c} sm />)
                  : null}
                {p.in_hand && p.up && p.up.length
                  ? p.up.map((c, i) => <Card key={`u${i}`} code={c} sm />)
                  : null}
              </div>
              {hud && hud.profile && hud.seats && hud.seats[p.seat] && (
                <HudChip rows={hud.profile.rows} map={hud.map} stats={hud.seats[p.seat]} above={hudAbove} />
              )}
              {win && (
                <div className="winner">
                  +<Money c={win.amount} bbCents={bbc} plain />
                  {win.pot_kind && !win.both ? ` ${win.pot_kind.toUpperCase()} ·` : ''}
                  {win.both ? ' SCOOP ·' : ''} {win.hand || 'WINS'}
                </div>
              )}
            </div>
            {/* Street wager rides the felt as a chip stack, not seat text. */}
            {p.committed_street > 0 && (
              <FeltChips x={x} y={y} amount={p.committed_street} bbc={bbc} portrait={portrait} />
            )}
            {/* The dealer puck sits to the SIDE of the avatar (toward center),
                at the seat's own height. The HUD chip extends screen-vertically
                above/below the avatar and the wagered chips run inward toward
                the pot — so a horizontal sidestep clears both lanes entirely. */}
            {h.button === p.seat && (() => {
              // The HUD always sits ABOVE or BELOW the avatar; the bet stack
              // runs deep inward toward the pot. So the puck just steps to the
              // SIDE at the avatar's own row — beside the avatar, clear of the
              // vertically-offset HUD and the far-inward chips.
              const px = x + (x <= 50 ? 1 : -1) * (portrait ? 10 : 8);
              // Side seats wear the HUD outward, so on the cramped mobile felt
              // nudge the puck inward (off that HUD). Center seats wear it
              // inward — leave the puck on the avatar row, already clear.
              const py = y + (portrait && isSide ? -Math.sign(y - 46) * 4 : 0);
              return <div className="dealer-puck" style={{ left: `${px}%`, top: `${py}%` }}>D</div>;
            })()}
          </React.Fragment>
        );
      })}
      {waiting.map((s) => (
        <WaitingSeat key={`w${s.seat_no}`} s={s} maxSeats={t.max_seats} portrait={portrait} bbc={bbc} />
      ))}
    </div>
  );
}

// The action bar — only shown to a seated player whose turn it is.
export function ActionBar({ state, onAct, busy }) {
  const h = state.hand;
  const you = state.you;
  const { skin } = useSkin();
  const [raiseTo, setRaiseTo] = useState(0);
  const [discards, setDiscards] = useState([]); // hole indices marked for the muck

  const myTurn = you && you.seat_no != null && h && h.to_act === you.seat_no;
  const legal = (myTurn && h.legal) || {};
  const bbc = state.table.bb;
  const botOn = !!(you && you.bot_connected);

  useEffect(() => {
    if (legal.raise) setRaiseTo(legal.raise.min_to);
    else if (legal.bet) setRaiseTo(legal.bet.min);
    setDiscards([]);
  }, [h && h.to_act, h && h.street]);

  // War-room hotkeys (desktop skin): F fold · C check/call · R raise/bet.
  useEffect(() => {
    if (skin !== 'desktop' || !myTurn || busy || botOn) return;
    const onKey = (e) => {
      if (e.target.tagName === 'INPUT' || e.target.tagName === 'SELECT' || e.metaKey || e.ctrlKey || e.altKey) return;
      const k = e.key.toLowerCase();
      if (k === 'f' && legal.fold) onAct('fold');
      else if (k === 'c' && (legal.check || legal.call)) onAct(legal.check ? 'check' : 'call');
      else if (k === 'r' && (legal.raise || legal.bet)) {
        if (legal.raise) onAct('raise', raiseTo || legal.raise.min_to);
        else onAct('bet', raiseTo || legal.bet.min);
      } else return;
      e.preventDefault();
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [skin, myTurn, busy, legal.fold, legal.check, legal.call, legal.raise, legal.bet, raiseTo]);

  if (botOn) {
    return (
      <div className="actbar">
        <div className="center-msg bot-on" style={{ padding: '10px', display: 'flex', alignItems: 'center', gap: 8, justifyContent: 'center' }}>
          <span style={{ fontSize: 18 }}>🤖</span>
          <span>{'Bot connected — it’s playing your seat'}{myTurn ? ' (acting…)' : ''}.</span>
        </div>
      </div>
    );
  }

  if (!myTurn) {
    return (
      <div className="actbar">
        <div className="center-msg" style={{ padding: '8px' }}>
          {you && you.seat_no != null ? 'Watching the others sweat…' : 'Take a seat to enter the war.'}
        </div>
      </div>
    );
  }

  // ---- Draw phase: pick cards to throw away, then draw. -------------------
  if (legal.draw) {
    const hole = h.players[you.seat_no]?.hole || [];
    const toggle = (i) => setDiscards(d => d.includes(i) ? d.filter(x => x !== i) : [...d, i]);
    const mask = discards.reduce((m, i) => m | (1 << i), 0);
    return (
      <div className="actbar">
        <ActionClock deadline={state.act_deadline} timeout={state.table.action_timeout} />
        <span className="mono" style={{ color: 'var(--pk-dim)' }}>Tap cards to discard:</span>
        <div style={{ display: 'flex', gap: 6 }}>
          {hole.map((c, i) => (
            <span key={i} onClick={() => toggle(i)}
              style={{ cursor: 'pointer', opacity: discards.includes(i) ? 0.35 : 1,
                       transform: discards.includes(i) ? 'translateY(-8px)' : 'none', transition: 'all .15s' }}>
              <Card code={c} sm />
            </span>
          ))}
        </div>
        <button className="btn gold" disabled={busy} onClick={() => { onAct('draw', mask); setDiscards([]); }}>
          {discards.length === 0 ? 'Stand Pat' : `Draw ${discards.length}`}
        </button>
      </div>
    );
  }

  const fixedBet = legal.bet && legal.bet.min === legal.bet.max;
  const fixedRaise = legal.raise && legal.raise.min_to === legal.raise.max_to;

  return (
    <div className="actbar">
      <ActionClock deadline={state.act_deadline} timeout={state.table.action_timeout} />
      {legal.fold && <button className="btn" disabled={busy} onClick={() => onAct('fold')}>Fold</button>}
      {legal.check && <button className="btn gold" disabled={busy} onClick={() => onAct('check')}>Check</button>}
      {legal.call && <button className="btn gold" disabled={busy} onClick={() => onAct('call')}>Call {usd(legal.call.amount)}</button>}
      {fixedBet && <button className="btn" disabled={busy} onClick={() => onAct('bet', legal.bet.min)}>Bet {usd(legal.bet.min)}</button>}
      {fixedRaise && <button className="btn" disabled={busy} onClick={() => onAct('raise', legal.raise.min_to)}>Raise to {usd(legal.raise.min_to)}</button>}
      {((legal.bet && !fixedBet) || (legal.raise && !fixedRaise)) && (
        <div className="raise-row">
          <input
            type="range"
            min={legal.bet ? legal.bet.min : legal.raise.min_to}
            max={legal.bet ? legal.bet.max : legal.raise.max_to}
            value={raiseTo}
            onChange={(e) => setRaiseTo(parseInt(e.target.value, 10))}
          />
          <span className="mono" style={{ minWidth: 90 }}>{usd(raiseTo)}<span style={{ color: 'var(--pk-dim)' }}> · {bb(raiseTo, bbc)}</span></span>
          {legal.bet
            ? <button className="btn" disabled={busy} onClick={() => onAct('bet', raiseTo)}>Bet {usd(raiseTo)}</button>
            : <button className="btn" disabled={busy} onClick={() => onAct('raise', raiseTo)}>Raise to {usd(raiseTo)}</button>}
        </div>
      )}
    </div>
  );
}

function ActionClock({ deadline }) {
  const [pct, setPct] = useState(100);
  useEffect(() => {
    if (!deadline) { setPct(100); return; }
    const end = new Date(deadline).getTime();
    const start = Date.now();
    const span = Math.max(1, end - start);
    const id = setInterval(() => {
      const left = end - Date.now();
      setPct(Math.max(0, Math.min(100, (left / span) * 100)));
    }, 120);
    return () => clearInterval(id);
  }, [deadline]);
  return <div className="clock"><i style={{ width: `${pct}%` }} /></div>;
}
