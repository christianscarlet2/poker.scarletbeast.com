// The felt's voice — synthesized WebAudio, zero assets, works identically in
// the web, desktop (Electron) and mobile (WebView) skins. Each table action
// gets a distinct, short, non-grating cue. Mutable via the 🔊 toggle
// (persisted). Browsers gate audio behind a user gesture; we resume the
// context on first interaction.
let ctx = null;
let muted = (() => { try { return localStorage.getItem('sbp_sound') === 'off'; } catch (e) { return false; } })();

function ac() {
  if (!ctx) {
    const AC = window.AudioContext || window.webkitAudioContext;
    if (!AC) return null;
    ctx = new AC();
    const resume = () => { ctx.resume(); };
    window.addEventListener('pointerdown', resume, { once: true });
    window.addEventListener('keydown', resume, { once: true });
  }
  return ctx;
}

export function soundMuted() { return muted; }
export function toggleSound() {
  muted = !muted;
  try { localStorage.setItem('sbp_sound', muted ? 'off' : 'on'); } catch (e) {}
  if (!muted) play('check'); // audible confirmation
  return !muted;
}

/** One synthesized tone. */
function tone(freq, dur, { type = 'sine', gain = 0.12, when = 0, slide = 0 } = {}) {
  const c = ac();
  if (!c || muted) return;
  const t0 = c.currentTime + when;
  const o = c.createOscillator();
  const g = c.createGain();
  o.type = type;
  o.frequency.setValueAtTime(freq, t0);
  if (slide) o.frequency.exponentialRampToValueAtTime(Math.max(20, freq + slide), t0 + dur);
  g.gain.setValueAtTime(0, t0);
  g.gain.linearRampToValueAtTime(gain, t0 + 0.008);
  g.gain.exponentialRampToValueAtTime(0.0001, t0 + dur);
  o.connect(g).connect(c.destination);
  o.start(t0);
  o.stop(t0 + dur + 0.05);
}

/** White-noise burst (card flicks, chip rustle, explosions). */
function noise(dur, { gain = 0.1, when = 0, lowpass = 4000 } = {}) {
  const c = ac();
  if (!c || muted) return;
  const t0 = c.currentTime + when;
  const len = Math.max(1, Math.floor(c.sampleRate * dur));
  const buf = c.createBuffer(1, len, c.sampleRate);
  const data = buf.getChannelData(0);
  for (let i = 0; i < len; i++) data[i] = (Math.random() * 2 - 1) * (1 - i / len);
  const src = c.createBufferSource();
  src.buffer = buf;
  const f = c.createBiquadFilter();
  f.type = 'lowpass';
  f.frequency.value = lowpass;
  const g = c.createGain();
  g.gain.setValueAtTime(gain, t0);
  g.gain.exponentialRampToValueAtTime(0.0001, t0 + dur);
  src.connect(f).connect(g).connect(c.destination);
  src.start(t0);
}

const CUES = {
  // chips sliding in: two quick clinks
  bet: () => { tone(1750, 0.05, { type: 'triangle', gain: 0.10 }); tone(2100, 0.06, { type: 'triangle', gain: 0.08, when: 0.06 }); },
  raise: () => { tone(1750, 0.05, { type: 'triangle', gain: 0.11 }); tone(2300, 0.06, { type: 'triangle', gain: 0.10, when: 0.05 }); tone(2700, 0.07, { type: 'triangle', gain: 0.08, when: 0.10 }); },
  call: () => { tone(1600, 0.05, { type: 'triangle', gain: 0.09 }); tone(1900, 0.05, { type: 'triangle', gain: 0.07, when: 0.05 }); },
  check: () => { noise(0.04, { gain: 0.12, lowpass: 1200 }); noise(0.04, { gain: 0.10, when: 0.09, lowpass: 1100 }); }, // knock knock
  fold: () => noise(0.12, { gain: 0.07, lowpass: 2600 }),                      // card muck flick
  draw: () => { noise(0.06, { gain: 0.07, lowpass: 3000 }); noise(0.06, { gain: 0.07, when: 0.08, lowpass: 3200 }); },
  deal: () => { for (let i = 0; i < 3; i++) noise(0.05, { gain: 0.06, when: i * 0.07, lowpass: 3500 }); },
  win: () => { tone(880, 0.10, { gain: 0.10 }); tone(1108.7, 0.10, { gain: 0.10, when: 0.09 }); tone(1318.5, 0.18, { gain: 0.12, when: 0.18 }); },
  your_turn: () => { tone(987.8, 0.09, { gain: 0.12 }); tone(1480, 0.12, { gain: 0.10, when: 0.10 }); },
  // the bomb: sub-bass drop + debris
  bomb: () => {
    tone(110, 0.7, { type: 'sawtooth', gain: 0.22, slide: -80 });
    noise(0.5, { gain: 0.22, lowpass: 900 });
    noise(0.8, { gain: 0.10, when: 0.18, lowpass: 500 });
    tone(55, 0.9, { type: 'sine', gain: 0.25, slide: -25 });
  },
  ante: () => tone(1500, 0.05, { type: 'triangle', gain: 0.07 }),
  bring_in: () => tone(1500, 0.05, { type: 'triangle', gain: 0.07 }),
  post_sb: () => tone(1400, 0.04, { type: 'triangle', gain: 0.05 }),
  post_bb: () => tone(1450, 0.04, { type: 'triangle', gain: 0.06 }),
};

export function play(cue) {
  (CUES[cue] || (() => {}))();
}

/**
 * Diff a hand view against the previous one and voice what changed:
 * new actions, street deals, showdown wins, bomb pots, your turn.
 */
export function voiceHandDiff(prev, next, mySeat = null) {
  if (!next) return;
  if (!prev || prev.hand_no !== next.hand_no) {
    if ((next.actions || []).some(a => a.action === 'bomb_ante')) play('bomb');
    else play('deal');
  } else {
    const pa = (prev.actions || []).length;
    const na = (next.actions || []).length;
    if (na > pa) {
      // voice the newest action only — avoids machine-gun stacks on catch-up
      const a = next.actions[na - 1];
      play(a.action === 'bomb_ante' ? 'bomb' : a.action);
    }
    if (prev.street !== next.street && ['flop', 'turn', 'river', 'fourth', 'fifth', 'sixth', 'seventh', 'postdraw'].includes(next.street)) {
      play('deal');
    }
    if (prev.street !== 'complete' && next.street === 'complete' && (next.winners || []).length) {
      play('win');
    }
  }
  if (mySeat != null && next.to_act === mySeat && (!prev || prev.to_act !== mySeat) && next.street !== 'complete') {
    play('your_turn');
  }
}

/** 🔊 toggle for the app bar / table chrome. */
export function soundLabel() { return muted ? '🔇' : '🔊'; }
