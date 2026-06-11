import React, { useState, useEffect } from 'react';
import { Card, Board } from './cards.jsx';
import { Money, usd, bb } from './money.jsx';

// Seat positions around the oval (percent of felt box), for up to 9 seats,
// laid out clockwise from bottom-center (the hero seat).
const SEAT_POS = {
  2: [[50, 92], [50, 8]],
  6: [[50, 92], [12, 70], [12, 26], [50, 8], [88, 26], [88, 70]],
  9: [[50, 93], [20, 88], [6, 56], [12, 22], [34, 7], [66, 7], [88, 22], [94, 56], [80, 88]],
};

function posFor(maxSeats, seatNo, total) {
  const key = maxSeats <= 2 ? 2 : maxSeats <= 6 ? 6 : 9;
  const ring = SEAT_POS[key];
  const idx = (seatNo - 1) % ring.length;
  return ring[idx];
}

// Games with no community board (stud + draw families).
const NO_BOARD = ['stud', 'razz', 'draw5'];

export default function Felt({ state, mySeat, observer }) {
  if (!state || !state.hand) {
    return <div className="center-msg">The felt is dark. Waiting for souls…</div>;
  }
  const h = state.hand;
  const t = state.table;
  const game = h.game || t.game || 'nlhe';
  const winners = {};
  (h.winners || []).forEach(w => {
    // A seat can scoop both halves of a hi-lo pot — accumulate.
    const prev = winners[w.seat];
    winners[w.seat] = prev
      ? { ...w, amount: prev.amount + w.amount, hand: prev.hand, both: true }
      : w;
  });
  const bbc = t.bb;
  const gameTag = t.game_short || (t.game_name ? t.game_name : null);

  return (
    <div className="felt">
      <div className="center">
        {!NO_BOARD.includes(game) && <Board cards={h.board} />}
        <div className="pot">POT · <Money c={h.pot} bbCents={bbc} /></div>
        <div className="street">
          {gameTag ? `${gameTag} · ` : ''}{(h.street || '').toUpperCase()}{h.hand_no ? ` · HAND #${h.hand_no}` : ''}
        </div>
      </div>
      {Object.values(h.players).map((p) => {
        const [x, y] = posFor(t.max_seats, p.seat, t.max_seats);
        const isActor = h.to_act === p.seat;
        const win = winners[p.seat];
        const nCards = (p.hole?.length || 0) + (p.up?.length || 0);
        return (
          <div
            key={p.seat}
            className={`seat${isActor ? ' act' : ''}${p.is_bot ? ' bot' : ''}${p.in_hand ? '' : ' folded'}`}
            style={{ left: `${x}%`, top: `${y}%` }}
          >
            {t.button === p.seat && <div className="dealer">D</div>}
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
            {p.committed_street > 0 && <div className="bet">bet <Money c={p.committed_street} bbCents={bbc} plain /></div>}
            {win && (
              <div className="winner">
                +<Money c={win.amount} bbCents={bbc} plain />
                {win.pot_kind && !win.both ? ` ${win.pot_kind.toUpperCase()} ·` : ''}
                {win.both ? ' SCOOP ·' : ''} {win.hand || 'WINS'}
              </div>
            )}
          </div>
        );
      })}
    </div>
  );
}

// The action bar — only shown to a seated player whose turn it is.
export function ActionBar({ state, onAct, busy }) {
  const h = state.hand;
  const you = state.you;
  const [raiseTo, setRaiseTo] = useState(0);
  const [discards, setDiscards] = useState([]); // hole indices marked for the muck

  const myTurn = you && you.seat_no != null && h && h.to_act === you.seat_no;
  const legal = (myTurn && h.legal) || {};
  const bbc = state.table.bb;

  useEffect(() => {
    if (legal.raise) setRaiseTo(legal.raise.min_to);
    else if (legal.bet) setRaiseTo(legal.bet.min);
    setDiscards([]);
  }, [h && h.to_act, h && h.street]);

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
