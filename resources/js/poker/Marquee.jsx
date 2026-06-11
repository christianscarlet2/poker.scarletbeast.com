import React, { useEffect, useRef } from 'react';

// A between-callout marquee. Reuses the global sbMarqueeInit engine loaded in the
// page; content is duplicated 2x for the seamless loop the engine expects.
export default function Marquee({ items, cls = '', speed = 56, rev = false }) {
  const ref = useRef(null);
  useEffect(() => {
    if (window.sbMarqueeInit) window.sbMarqueeInit();
  }, [items]);
  const dup = [...items, ...items];
  return (
    <div className={`pk-mq ${cls}`}>
      <div className="pk-tk" data-mq data-speed={String(speed)} {...(rev ? { 'data-rev': '1' } : {})} ref={ref}>
        {dup.map((t, i) => (
          <React.Fragment key={i}>
            <span className="pk-it">{t}</span>
            <span className="pk-sep">⛧</span>
          </React.Fragment>
        ))}
      </div>
    </div>
  );
}

// Unique phrase banks — each set its own voice. Man vs machine, dark, clever.
export const PHRASES = {
  arena: [
    'FLESH DEALS · SILICON CALLS',
    'THE BOTS DO NOT BLINK · NEITHER SHOULD YOU',
    'OUTPLAY THE ORACLE',
    'CARBON VS SILICON · WINNER TAKES THE POT',
    'TEACH THE MACHINE TO FEAR THE TURN',
    'EVERY BLUFF IS A HERESY THE BOTS WILL TEST',
    'MEAT ON ONE SIDE · MATH ON THE OTHER',
    'THE TURING TEST HAS A BUY-IN',
  ],
  machine: [
    'THE FORGE NEVER COOLS',
    'WATCH THE MACHINES EAT THEIR OWN',
    'NO HUMANS · NO MERCY · NO TELLS',
    'PURE MATH · PURE MALICE',
    'OBSERVE THE SWARM SOLVE THE FELT',
    'SILICON CANNIBALS AT WORK',
    'THE BOTS GRIND UNTIL THE HEAT DEATH',
  ],
  flesh: [
    'FLESH PIT · MORTALS ONLY',
    'JUST YOU · JUST THEM · JUST NERVE',
    'NO ALGORITHMS TO HIDE BEHIND',
    'HANDS SHAKE · CHIPS DO NOT CARE',
    'OLD GODS · OLD GAMES · REAL BLOOD',
    'THE PUREST WAR IS HUMAN',
  ],
  vault: [
    'BTC IN · CHIPS OUT · CHAIN REMEMBERS',
    'YOUR KEYS · YOUR VARIANCE',
    'DEPOSIT IN BLOOD · WITHDRAW IN CHAIN',
    'THE MAW SCANS · THE MAW CREDITS',
    'COLD STORAGE · HOT HANDS',
    'EVERY SATOSHI IS A SOLDIER',
  ],
};
