import React, { useState, useEffect, useRef, useCallback } from 'react';
import { api } from './api.js';
import { play as sfx } from './sounds.js';

/**
 * Table chat + emote theater. Text scrolls in the panel; emotes perform on
 * the felt — bursts at the sender's seat, or ordnance (rockets, tomatoes,
 * eggs, kisses, punches) flying across the table at a chosen victim.
 */

export const EMOTES = {
  happy: { e: '😀', label: 'Happy' },
  laugh: { e: '😂', label: 'Laugh' },
  sad: { e: '😢', label: 'Sad' },
  cry: { e: '😭', label: 'Cry' },
  angry: { e: '😡', label: 'Angry' },
  rage: { e: '🤬', label: 'Rage' },
  devil: { e: '😈', label: 'Devil' },
  embarrassed: { e: '😳', label: 'Embarrassed' },
  love: { e: '❤️', label: 'Love' },
  hate: { e: '💢', label: 'Hate' },
  clown: { e: '🤡', label: 'Clown' },
  fish: { e: '🐟', label: 'Fish' },
  snake: { e: '🐍', label: 'Snake' },
  fire: { e: '🔥', label: 'On fire' },
  salt: { e: '🧂', label: 'Salty' },
  sleep: { e: '😴', label: 'Zzz' },
  skull: { e: '💀', label: 'Dead' },
  gg: { e: '🤝', label: 'GG' },
  cheers: { e: '🥂', label: 'Cheers' },
  monkey: { e: '🙈', label: 'Can\'t look' },
  brain: { e: '🧠', label: 'Big brain' },
  rocket: { e: '🚀', label: 'Rocket', targeted: true },
  tomato: { e: '🍅', label: 'Tomato', targeted: true },
  egg: { e: '🥚', label: 'Egg', targeted: true },
  kiss: { e: '😘', label: 'Kiss', targeted: true },
  punch: { e: '👊', label: 'Punch', targeted: true },
};

/** Poll the table's chat; expose messages, live effects, and send(). */
export function useTableChat(tableId, enabled = true) {
  const [messages, setMessages] = useState([]);
  const [effects, setEffects] = useState([]);   // recent emotes still animating
  const lastId = useRef(0);
  const booted = useRef(false);

  useEffect(() => {
    if (!enabled) return;
    let dead = false;
    const load = async () => {
      try {
        const d = await api.get(`/api/tables/${tableId}/chat?after=${lastId.current}`);
        if (dead || !d.messages.length) return;
        lastId.current = d.messages[d.messages.length - 1].id;
        setMessages(m => [...m, ...d.messages].slice(-80));
        // Animate only fresh emotes (not the backlog on first load).
        if (booted.current) {
          const emotes = d.messages.filter(m => m.kind === 'emote');
          if (emotes.length) {
            setEffects(fx => [...fx, ...emotes.map(m => ({ ...m, born: Date.now() }))]);
            emotes.forEach(m => sfx(EMOTES[m.body]?.targeted ? 'rocket' : 'emote'));
            setTimeout(() => setEffects(fx => fx.filter(f => Date.now() - f.born < 2600)), 2800);
          }
          if (d.messages.some(m => m.kind === 'chat')) sfx('msg');
        }
        booted.current = true;
      } catch (e) { /* felt went quiet */ }
    };
    load();
    const iv = setInterval(load, 2000);
    return () => { dead = true; clearInterval(iv); };
  }, [tableId, enabled]);

  const send = useCallback(async (payload) => {
    await api.post(`/api/tables/${tableId}/chat`, payload);
  }, [tableId]);

  return { messages, effects, send };
}

export function ChatPanel({ tableId, me, seats, send, messages }) {
  const [text, setText] = useState('');
  const [picker, setPicker] = useState(false);
  const [arming, setArming] = useState(null);   // targeted emote awaiting a victim
  const [err, setErr] = useState('');
  const scroller = useRef(null);

  useEffect(() => {
    scroller.current?.scrollTo(0, 1e9);
  }, [messages.length]);

  const say = async (e) => {
    e.preventDefault();
    if (!text.trim()) return;
    setErr('');
    try { await send({ text }); setText(''); }
    catch (e2) { setErr(e2.message); }
  };
  const emote = async (key) => {
    const def = EMOTES[key];
    if (def.targeted) { setArming(key); return; }
    setPicker(false); setErr('');
    try { await send({ emote: key }); }
    catch (e2) { setErr(e2.message); }
  };
  const launch = async (seatNo) => {
    const key = arming;
    setArming(null); setPicker(false); setErr('');
    try { await send({ emote: key, target_seat: seatNo }); }
    catch (e2) { setErr(e2.message); }
  };

  const occupied = (seats || []).filter(s => s.status !== 'empty');

  return (
    <div className="panel chat-panel">
      <h2>Table Talk</h2>
      <div className="chat-scroll" ref={scroller}>
        {messages.length === 0 && <div className="hint">Silence at the felt. Break it.</div>}
        {messages.map(m => (
          <div key={m.id} className="chat-line">
            <span className="who">{m.username}</span>
            {m.kind === 'chat'
              ? <span className="txt">{m.body}</span>
              : <span className="emo">{EMOTES[m.body]?.e || '✨'} {EMOTES[m.body]?.label?.toLowerCase()}{m.target_seat ? ` → #${m.target_seat}` : ''}</span>}
          </div>
        ))}
      </div>

      {me ? (
        <>
          {arming && (
            <div className="chat-arm">
              <span className="mono">{EMOTES[arming].e} pick a victim:</span>
              {occupied.map(s => (
                <button key={s.seat_no} className="badge" onClick={() => launch(s.seat_no)}>#{s.seat_no} {s.name || ''}</button>
              ))}
              <button className="badge" onClick={() => setArming(null)}>✕</button>
            </div>
          )}
          {picker && !arming && (
            <div className="emote-grid">
              {Object.entries(EMOTES).map(([k, d]) => (
                <button key={k} title={d.label + (d.targeted ? ' (targeted)' : '')}
                  className={d.targeted ? 'tgt' : ''} onClick={() => emote(k)}>{d.e}</button>
              ))}
            </div>
          )}
          <form onSubmit={say} style={{ display: 'flex', gap: 6, marginTop: 8 }}>
            <button type="button" className="btn ghost" style={{ padding: '8px 12px' }}
              title="Expressions" onClick={() => { setPicker(p => !p); setArming(null); }}>😈</button>
            <input value={text} maxLength={140} placeholder="Talk to the felt…"
              onChange={e => setText(e.target.value)} />
            <button className="btn" disabled={!text.trim()}>Send</button>
          </form>
        </>
      ) : <div className="hint">Sign in to talk and taunt.</div>}
      {err && <div className="err">{err}</div>}
    </div>
  );
}
