import React, { createContext, useContext, useEffect, useMemo, useState } from 'react';

/**
 * Skins — one brand, three postures.
 *
 *   web      the shop window: editorial hero, marquees, the full pitch
 *   desktop  the war room: dense grinder terminal, titlebar, hotkeys (mac/win/linux)
 *   mobile   the pocket felt: one-thumb play, bottom dock, tab nav (iphone/android)
 *
 * Resolution order: ?skin= override (persisted) → saved preference → platform
 * auto-detect (Electron preload / RN WebView / mobile UA) → web.
 */

const SKINS = ['web', 'desktop', 'mobile'];
const KEY = 'sbp_skin';

function detect() {
  if (typeof window === 'undefined') return 'web';
  // Hard platform signals first — the apps inject these in preload.
  if (window.scarletbeastDesktop && window.scarletbeastDesktop.isDesktop) return 'desktop';
  if (window.scarletbeastApp) return 'mobile';
  // Mobile browsers get the touch skin too.
  if (/iPhone|iPad|iPod|Android/i.test(navigator.userAgent)) return 'mobile';
  return 'web';
}

function initial() {
  try {
    const q = new URLSearchParams(window.location.search).get('skin');
    if (q && SKINS.includes(q)) { localStorage.setItem(KEY, q); return q; }
    if (q === 'auto') { localStorage.removeItem(KEY); return detect(); }
    const saved = localStorage.getItem(KEY);
    if (saved && SKINS.includes(saved)) return saved;
  } catch (e) { /* private mode etc. */ }
  return detect();
}

const SkinCtx = createContext({ skin: 'web', setSkin: () => {}, auto: 'web' });
export function useSkin() { return useContext(SkinCtx); }

export function SkinProvider({ children }) {
  const [skin, setSkinState] = useState(initial);
  const auto = useMemo(detect, []);

  const setSkin = (s) => {
    if (s === 'auto') {
      try { localStorage.removeItem(KEY); } catch (e) {}
      setSkinState(detect());
      return;
    }
    if (!SKINS.includes(s)) return;
    try { localStorage.setItem(KEY, s); } catch (e) {}
    setSkinState(s);
  };

  // The class rides on <body> so fixed-position chrome (docks, titlebar)
  // inherits it without threading props through every page.
  useEffect(() => {
    document.body.classList.remove(...SKINS.map(s => `skin-${s}`));
    document.body.classList.add(`skin-${skin}`);
    return () => document.body.classList.remove(`skin-${skin}`);
  }, [skin]);

  return <SkinCtx.Provider value={{ skin, setSkin, auto }}>{children}</SkinCtx.Provider>;
}

/** Compact selector for the app bar: cycle web → desktop → mobile. */
export function SkinSwitcher() {
  const { skin, setSkin } = useSkin();
  const next = SKINS[(SKINS.indexOf(skin) + 1) % SKINS.length];
  const glyph = { web: '🌐', desktop: '🖥', mobile: '📱' }[skin];
  return (
    <button
      className="badge skin-switch"
      title={`Skin: ${skin} — click for ${next}`}
      onClick={() => setSkin(next)}
    >{glyph} {skin.toUpperCase()}</button>
  );
}

/**
 * War-room titlebar (desktop skin). A slim command strip: drag region for the
 * Electron shell, brand stamp, live UTC clock — the grinder's heads-up bar.
 */
export function DesktopTitlebar() {
  const [now, setNow] = useState(() => new Date());
  useEffect(() => {
    const id = setInterval(() => setNow(new Date()), 1000);
    return () => clearInterval(id);
  }, []);
  const hh = String(now.getUTCHours()).padStart(2, '0');
  const mm = String(now.getUTCMinutes()).padStart(2, '0');
  const ss = String(now.getUTCSeconds()).padStart(2, '0');
  return (
    <div className="dt-titlebar">
      <span className="dt-brand">♠ SCARLET BEAST <i>//</i> WAR ROOM</span>
      <span className="dt-spacer" />
      <span className="dt-clock">{hh}:{mm}:{ss} UTC</span>
      <span className="dt-keys">F fold · C check/call · R raise</span>
    </div>
  );
}

/**
 * Pocket-felt tab dock (mobile skin). Primary navigation under the thumb;
 * hidden on live table pages where the action bar owns the bottom edge.
 */
export function MobileNav({ path, go, me }) {
  const tabs = [
    ['/', '♠', 'Felts'],
    ['/players', '🦈', 'Sharks'],
    [me ? '/wallet' : '/login', '🩸', me ? 'Vault' : 'Enter'],
  ];
  return (
    <nav className="mb-dock">
      {tabs.map(([href, glyph, label]) => {
        const on = path === href || (href !== '/' && path.startsWith(href));
        return (
          <a key={href} href={href} className={`mb-tab${on ? ' on' : ''}`}
            onClick={(e) => { e.preventDefault(); go(href); }}>
            <span className="g">{glyph}</span>
            <span className="l">{label}</span>
          </a>
        );
      })}
    </nav>
  );
}
