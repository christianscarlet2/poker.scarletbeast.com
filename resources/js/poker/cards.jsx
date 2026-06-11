import React from 'react';

// Render a single card code like "As", "Td", "??" (back), or "" (empty slot).
export function Card({ code, sm }) {
  const cls = `card${sm ? ' sm' : ''}`;
  if (!code || code === '??') {
    return <div className={`${cls} back`}>★</div>;
  }
  const rank = code[0] === 'T' ? '10' : code[0];
  const suit = code[1];
  const sym = { s: '♠', h: '♥', d: '♦', c: '♣' }[suit] || '';
  const red = suit === 'h' || suit === 'd';
  return (
    <div className={`${cls} ${red ? 'red' : 'blk'}`}>
      {rank}{sym}
    </div>
  );
}

export function Board({ cards, sm }) {
  const slots = [];
  for (let i = 0; i < 5; i++) {
    const c = cards && cards[i];
    slots.push(c ? <Card key={i} code={c} sm={sm} /> : <div key={i} className={`card${sm ? ' sm' : ''}`} style={{ opacity: .12 }}>·</div>);
  }
  return <div className="board">{slots}</div>;
}

// chips -> human ("12,500" or "1.2k" when big)
export function chips(n) {
  if (n === undefined || n === null) return '0';
  return n.toLocaleString('en-US');
}
