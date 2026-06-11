import React, { createContext, useContext, useState, useCallback } from 'react';

// The house ledger is integer USD cents. Players see real cash; at a felt they
// can flip any amount between $ and big blinds (BB) with a click.
const UnitCtx = createContext({ unit: 'cash', toggle: () => {} });

export function UnitProvider({ children }) {
  const [unit, setUnit] = useState(() => localStorage.getItem('pk_unit') || 'cash');
  const toggle = useCallback(() => {
    setUnit((u) => {
      const n = u === 'cash' ? 'bb' : 'cash';
      localStorage.setItem('pk_unit', n);
      return n;
    });
  }, []);
  return <UnitCtx.Provider value={{ unit, toggle }}>{children}</UnitCtx.Provider>;
}
export function useUnit() { return useContext(UnitCtx); }

// cents -> "$1,234.56"
export function usd(cents) {
  const v = (cents || 0) / 100;
  return '$' + v.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
// cents -> "12.5 BB" given the table's big blind in cents
export function bb(cents, bbCents) {
  if (!bbCents) return usd(cents);
  const v = (cents || 0) / bbCents;
  return (Number.isInteger(v) ? v : v.toFixed(1)) + ' BB';
}
// dollars string for inputs
export function dollars(cents) { return ((cents || 0) / 100).toFixed(2); }

// A clickable amount that toggles the whole UI between $ and BB. Pass bbCents to
// enable the BB view (omit it in lobby/vault where there's no single blind).
export function Money({ c, bbCents, className, plain }) {
  const { unit, toggle } = useUnit();
  const txt = (unit === 'bb' && bbCents) ? bb(c, bbCents) : usd(c);
  if (plain) return <span className={className}>{txt}</span>;
  return (
    <span
      className={className}
      style={{ cursor: 'pointer', borderBottom: '1px dotted rgba(216,178,90,.4)' }}
      title="Click to flip $ / BB"
      onClick={(e) => { e.stopPropagation(); toggle(); }}
    >{txt}</span>
  );
}
