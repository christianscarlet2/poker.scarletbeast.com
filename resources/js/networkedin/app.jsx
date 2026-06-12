import React, { useEffect, useState, useCallback } from 'react';
import { createRoot } from 'react-dom/client';
import { api } from '../poker/api.js';

const BASE = '/networkedin';
const sub = () => window.location.pathname.replace(/^\/networkedin/, '') || '/';

/* ----------------------------------------------------------- tiny router */
function useRoute() {
  const [path, setPath] = useState(sub());
  useEffect(() => {
    const on = () => setPath(sub());
    window.addEventListener('popstate', on);
    return () => window.removeEventListener('popstate', on);
  }, []);
  const go = useCallback((to) => {
    window.history.pushState({}, '', BASE + (to === '/' ? '' : to));
    setPath(sub());
    window.scrollTo(0, 0);
  }, []);
  return [path, go];
}

function A({ to, children, className, onClick }) {
  const go = (e) => {
    if (e.metaKey || e.ctrlKey) return;
    e.preventDefault();
    if (onClick) onClick();
    window.history.pushState({}, '', BASE + (to === '/' ? '' : to));
    window.dispatchEvent(new PopStateEvent('popstate'));
    window.scrollTo(0, 0);
  };
  return <a href={BASE + (to === '/' ? '' : to)} className={className} onClick={go}>{children}</a>;
}

const MEDIA_GLYPH = { youtube: '▶', video: '🎬', pdf: '📕', doc: '📄', sheet: '📊', slides: '📽', image: '🖼', link: '🔗' };

/* -------------------------------------------------------------- session */
const SessCtx = React.createContext(null);

function useSession() {
  const [s, setS] = useState({ loaded: false, user: null, profile: null });
  const reload = useCallback(() => {
    api.get('/api/networkedin/me').then(d => setS({ loaded: true, user: d.user, profile: d.profile })).catch(() => setS({ loaded: true, user: null, profile: null }));
  }, []);
  useEffect(reload, [reload]);
  return [s, reload];
}

/* ---------------------------------------------------------------- shell */
function App() {
  const [path, go] = useRoute();
  const [sess, reload] = useSession();

  let view;
  let m;
  if (path === '/' || path === '/feed') view = <Feed sess={sess} reload={reload} />;
  else if (path === '/directory') view = <Directory />;
  else if (path === '/blog') view = <Feed sess={sess} reload={reload} kind="blog" />;
  else if (path === '/me') view = <ProfileEditor sess={sess} reload={reload} go={go} />;
  else if ((m = path.match(/^\/u\/([^/]+)$/))) view = <Profile slug={m[1]} sess={sess} />;
  else view = <Feed sess={sess} reload={reload} />;

  return (
    <SessCtx.Provider value={sess}>
      <div className="ni">
        <Hero sess={sess} />
        <div className="ni-wrap ni-cols">
          <aside className="ni-rail">
            <Nav path={path} />
            <SelfCard sess={sess} />
          </aside>
          <main className="ni-main">{view}</main>
        </div>
      </div>
    </SessCtx.Provider>
  );
}

function Hero({ sess }) {
  return (
    <div className="ni-hero">
      <div className="ni-wrap">
        <div className="ni-kick"><span className="dot" /> THE CREATORS NETWORK</div>
        <h1>networked<b>in</b></h1>
        <p className="ni-sub">A LinkedIn for the people building poker minds. An open feed, real resumes, blog posts, and the work itself — videos, decks, papers, repos. Not who you know. What you built.</p>
        {!sess.user && (
          <div className="ni-cta">
            <a className="ni-btn big" href="/login">Sign in</a>
            <a className="ni-btn ghost big" href="/register">Create account</a>
            <span className="ni-note">One account across the felt, the console, and the network.</span>
          </div>
        )}
      </div>
    </div>
  );
}

function Nav({ path }) {
  const items = [['/', '🜂', 'Feed'], ['/blog', '✍', 'Blog'], ['/directory', '🜔', 'Creators'], ['/me', '⛧', 'Your Profile']];
  return (
    <nav className="ni-nav">
      {items.map(([to, ic, label]) => (
        <A key={to} to={to} className={'ni-navlink' + ((path === to || (to === '/' && path === '/feed')) ? ' on' : '')}><span className="ic">{ic}</span>{label}</A>
      ))}
      <a className="ni-navlink" href="/networkedin/forum"><span className="ic">🜍</span>The Forum</a>
    </nav>
  );
}

function SelfCard({ sess }) {
  if (!sess.loaded || !sess.user) return null;
  const u = sess.user;
  return (
    <div className="ni-self">
      <div className="ni-self-av">{u.avatar}</div>
      <div className="ni-self-nm">{u.name}</div>
      <div className="ni-self-hd">{sess.profile?.headline || (sess.profile ? 'Creator' : 'Not on the network yet')}</div>
      {sess.profile
        ? <A to={'/u/' + sess.profile.slug} className="ni-btn sm">View profile</A>
        : <A to="/me" className="ni-btn sm gold">Join networkedin</A>}
    </div>
  );
}

/* ---------------------------------------------------------------- feed */
function Feed({ sess, reload, kind }) {
  const [posts, setPosts] = useState(null);
  const load = useCallback(() => {
    const q = kind ? '?kind=' + kind : '';
    api.get('/api/networkedin/feed' + q).then(d => setPosts(d.posts)).catch(() => setPosts([]));
  }, [kind]);
  useEffect(load, [load]);

  return (
    <div>
      <div className="ni-h2">{kind === 'blog' ? 'Blog Posts' : 'The Open Feed'}</div>
      {sess.user && <Composer sess={sess} reload={reload} onPosted={load} defaultKind={kind === 'blog' ? 'blog' : 'post'} />}
      {!sess.user && <div className="ni-card ni-pad ni-dim">Sign in to post, like, and comment. Reading is open to all.</div>}
      {posts === null ? <div className="ni-dim ni-pad">Loading the feed…</div>
        : posts.length === 0 ? <div className="ni-dim ni-pad">Nothing here yet. Be the first to ship.</div>
          : posts.map(p => <PostCard key={p.id} p={p} sess={sess} />)}
    </div>
  );
}

function Composer({ sess, reload, onPosted, defaultKind }) {
  const [kind, setKind] = useState(defaultKind || 'post');
  const [title, setTitle] = useState('');
  const [body, setBody] = useState('');
  const [media, setMedia] = useState(null);
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState('');

  const submit = async () => {
    if (!body.trim()) return;
    setBusy(true); setErr('');
    try {
      await api.post('/api/networkedin/posts', { kind, title: kind === 'blog' ? title : null, body, media_id: media?.id || null });
      setBody(''); setTitle(''); setMedia(null);
      if (!sess.profile) reload();
      onPosted();
    } catch (e) { setErr(e.message); } finally { setBusy(false); }
  };

  return (
    <div className="ni-card ni-composer">
      <div className="ni-comp-top">
        <div className="ni-self-av sm">{sess.user.avatar}</div>
        <div className="ni-seg">
          {['post', 'blog', 'share'].map(k => (
            <button key={k} className={'ni-seg-b' + (kind === k ? ' on' : '')} onClick={() => setKind(k)}>{k}</button>
          ))}
        </div>
      </div>
      {kind === 'blog' && <input className="ni-input" placeholder="Title of your post" value={title} onChange={e => setTitle(e.target.value)} />}
      <textarea className="ni-input ni-area" placeholder={kind === 'share' ? 'Share work — paste a YouTube / Google Doc / link below, add a note…' : "What did you build, break, or learn?"} value={body} onChange={e => setBody(e.target.value)} />
      <MediaAttach media={media} setMedia={setMedia} />
      {err && <div className="ni-err">{err}</div>}
      <div className="ni-comp-foot">
        <span className="ni-dim sm">Signed as <b>{sess.user.name}</b></span>
        <button className="ni-btn gold" disabled={busy || !body.trim()} onClick={submit}>{busy ? 'Posting…' : 'Post'}</button>
      </div>
    </div>
  );
}

function MediaAttach({ media, setMedia }) {
  const [url, setUrl] = useState('');
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState('');

  const attachUrl = async () => {
    if (!url.trim()) return;
    setBusy(true); setErr('');
    try { const d = await api.post('/api/networkedin/media', { url }); setMedia(d.media); setUrl(''); }
    catch (e) { setErr(e.message); } finally { setBusy(false); }
  };
  const attachFile = async (e) => {
    const f = e.target.files?.[0]; if (!f) return;
    setBusy(true); setErr('');
    try { const fd = new FormData(); fd.append('file', f); const d = await api.post('/api/networkedin/media', fd); setMedia(d.media); }
    catch (er) { setErr(er.message); } finally { setBusy(false); }
  };

  if (media) return (
    <div className="ni-attached"><span>{MEDIA_GLYPH[media.type] || '🔗'} {media.title || media.type}</span><button className="ni-x" onClick={() => setMedia(null)}>✕</button></div>
  );
  return (
    <div className="ni-attach">
      <input className="ni-input sm" placeholder="Paste YouTube · Google Docs/Sheets/Slides · any link" value={url} onChange={e => setUrl(e.target.value)} onKeyDown={e => e.key === 'Enter' && attachUrl()} />
      <button className="ni-btn sm" disabled={busy} onClick={attachUrl}>Attach</button>
      <label className="ni-btn sm ghost ni-file">{busy ? '…' : '📎 Upload'}<input type="file" hidden onChange={attachFile} accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.odt,.ods,.odp,.mp4,.webm,.mov,.png,.jpg,.jpeg,.gif,.webp" /></label>
      {err && <div className="ni-err sm">{err}</div>}
    </div>
  );
}

function MediaBlock({ m }) {
  if (!m) return null;
  if (m.type === 'youtube' && m.meta?.embed) return <div className="ni-embed"><iframe src={m.meta.embed} allowFullScreen title={m.title || 'video'} /></div>;
  if ((m.type === 'doc' || m.type === 'sheet' || m.type === 'slides') && m.meta?.embed) return <div className="ni-embed doc"><iframe src={m.meta.embed} title={m.title || 'document'} /></div>;
  if (m.type === 'image' && m.src) return <a href={m.src} target="_blank" rel="noreferrer"><img className="ni-img" src={m.src} alt={m.title || ''} /></a>;
  if (m.type === 'video' && m.src) return <video className="ni-img" src={m.src} controls />;
  const href = m.src || m.url;
  return <a className="ni-filechip" href={href} target="_blank" rel="noreferrer">{MEDIA_GLYPH[m.type] || '🔗'} <b>{m.title || m.type}</b>{m.meta?.size ? <span className="ni-dim sm"> · {(m.meta.size / 1024 | 0)} KB</span> : null}</a>;
}

function PostCard({ p, sess }) {
  const [liked, setLiked] = useState(p.liked);
  const [likes, setLikes] = useState(p.likes);
  const [showC, setShowC] = useState(false);
  const a = p.author;

  const like = async () => {
    if (!sess.user) { window.location = '/login'; return; }
    const d = await api.post(`/api/networkedin/posts/${p.id}/like`);
    setLiked(d.liked); setLikes(d.likes);
  };

  return (
    <div className="ni-card ni-post">
      <div className="ni-post-hd">
        <AuthorChip a={a} />
        <div className="ni-post-meta">{p.kind === 'blog' ? 'BLOG' : p.kind === 'share' ? 'SHARED' : 'POST'} · {p.ago}</div>
      </div>
      {p.title && <h3 className="ni-post-title">{p.title}</h3>}
      <div className="ni-post-body">{p.body}</div>
      <MediaBlock m={p.media} />
      <div className="ni-post-actions">
        <button className={'ni-act' + (liked ? ' on' : '')} onClick={like}>♥ {likes}</button>
        <button className="ni-act" onClick={() => setShowC(s => !s)}>💬 {p.comments}</button>
      </div>
      {showC && <Comments postId={p.id} sess={sess} />}
    </div>
  );
}

function AuthorChip({ a }) {
  if (!a) return null;
  const inner = <><span className="ni-chip-av">{a.avatar}</span><span><b>{a.name}</b>{a.headline ? <span className="ni-dim sm"> · {a.headline}</span> : null}</span></>;
  return a.slug ? <A to={'/u/' + a.slug} className="ni-chip">{inner}</A> : <span className="ni-chip">{inner}</span>;
}

function Comments({ postId, sess }) {
  const [list, setList] = useState(null);
  const [body, setBody] = useState('');
  const load = useCallback(() => api.get(`/api/networkedin/posts/${postId}/comments`).then(d => setList(d.comments)), [postId]);
  useEffect(load, [load]);
  const add = async () => {
    if (!body.trim()) return;
    await api.post(`/api/networkedin/posts/${postId}/comments`, { body });
    setBody(''); load();
  };
  return (
    <div className="ni-comments">
      {list === null ? <div className="ni-dim sm">…</div> : list.map(c => (
        <div key={c.id} className="ni-comment"><AuthorChip a={c.author} /><div className="ni-comment-b">{c.body} <span className="ni-dim sm">· {c.ago}</span></div></div>
      ))}
      {sess.user && (
        <div className="ni-comment-add">
          <input className="ni-input sm" placeholder="Add a comment…" value={body} onChange={e => setBody(e.target.value)} onKeyDown={e => e.key === 'Enter' && add()} />
          <button className="ni-btn sm" onClick={add}>Reply</button>
        </div>
      )}
    </div>
  );
}

/* ------------------------------------------------------------ directory */
function Directory() {
  const [creators, setCreators] = useState(null);
  const [q, setQ] = useState('');
  const load = useCallback((term) => api.get('/api/networkedin/directory' + (term ? '?q=' + encodeURIComponent(term) : '')).then(d => setCreators(d.creators)), []);
  useEffect(() => { load(''); }, [load]);
  return (
    <div>
      <div className="ni-h2">Creators</div>
      <input className="ni-input" placeholder="Search by skill, headline, handle…" value={q} onChange={e => { setQ(e.target.value); load(e.target.value); }} />
      <div className="ni-dirgrid">
        {creators === null ? <div className="ni-dim ni-pad">Loading…</div>
          : creators.length === 0 ? <div className="ni-dim ni-pad">No creators yet.</div>
            : creators.map(c => (
              <A key={c.slug} to={'/u/' + c.slug} className="ni-card ni-dircard">
                <div className="ni-self-av">{c.author.avatar}</div>
                <div className="ni-dc-nm">{c.author.name}</div>
                <div className="ni-dim sm">{c.headline || 'Creator'}</div>
                {c.location && <div className="ni-dim sm">📍 {c.location}</div>}
                <div className="ni-tags">{(c.skills || []).slice(0, 4).map(s => <span key={s} className="ni-tag">{s}</span>)}</div>
                <div className="ni-open">{(c.open_to || []).map(o => <span key={o} className="ni-open-b">{o}</span>)}</div>
              </A>
            ))}
      </div>
    </div>
  );
}

/* -------------------------------------------------------------- profile */
function Profile({ slug, sess }) {
  const [d, setD] = useState(null);
  const [err, setErr] = useState('');
  useEffect(() => { setD(null); api.get('/api/networkedin/profile/' + slug).then(setD).catch(e => setErr(e.message)); }, [slug]);
  if (err) return <div className="ni-pad ni-dim">{err} <A to="/directory">Back to creators.</A></div>;
  if (!d) return <div className="ni-pad ni-dim">Opening profile…</div>;
  const p = d.profile;
  return (
    <div>
      <div className="ni-card ni-profhead">
        <div className="ni-self-av big">{p.author.avatar}</div>
        <div className="ni-prof-id">
          <h2>{p.author.name} {p.author.is_bot && <span className="ni-tag bot">BOT</span>}</h2>
          <div className="ni-prof-hd">{p.headline || 'Creator'}</div>
          {p.location && <div className="ni-dim sm">📍 {p.location}</div>}
          <div className="ni-prof-links">
            {Object.entries(p.links || {}).filter(([, v]) => v).map(([k, v]) => <a key={k} className="ni-linkchip" href={v} target="_blank" rel="noreferrer">{k}</a>)}
            <a className="ni-linkchip forum" href={p.forum_url}>forum</a>
          </div>
          <div className="ni-open">{(p.open_to || []).map(o => <span key={o} className="ni-open-b">{o}</span>)}</div>
        </div>
        {p.is_me && <A to="/me" className="ni-btn sm">Edit</A>}
      </div>

      {p.is_me && p.blog && (
        <div className="ni-card ni-pad ni-blogcard">
          <div className="ni-lbl">Your Blog · Restricted WordPress</div>
          {p.blog.status === 'running'
            ? <p>Your writing studio is live. <a className="ni-btn sm gold" href={p.blog.url}>Open blog editor →</a> <span className="ni-dim sm">Posts · Pages · Categories · Menus.</span></p>
            : p.blog.status === 'pending'
              ? <p className="ni-dim">🧱 Your private blog is <b>provisioning</b> — a restricted WordPress (Posts · Pages · Categories · Menus) is being spun up for you. Check back shortly.</p>
              : <p className="ni-dim">⚠ Blog provisioning hit a snag. The team has been notified.</p>}
        </div>
      )}
      {p.bio && <div className="ni-card ni-pad ni-bio">{p.bio}</div>}
      {(p.skills || []).length > 0 && <div className="ni-card ni-pad"><div className="ni-lbl">Skills</div><div className="ni-tags">{p.skills.map(s => <span key={s} className="ni-tag">{s}</span>)}</div></div>}

      {(p.resume || []).length > 0 && (
        <div className="ni-card ni-pad">
          <div className="ni-lbl">Experience</div>
          {p.resume.map((r, i) => (
            <div key={i} className="ni-res">
              <div className="ni-res-dot" />
              <div><b>{r.role}</b>{r.org ? <span className="ni-dim"> · {r.org}</span> : null}<div className="ni-dim sm">{r.from}{r.to ? ' – ' + r.to : ' – now'}</div>{r.detail && <div className="ni-res-d">{r.detail}</div>}</div>
            </div>
          ))}
        </div>
      )}

      {d.media.length > 0 && (
        <div className="ni-card ni-pad">
          <div className="ni-lbl">Work & Uploads</div>
          <div className="ni-mediagrid">{d.media.map(m => <div key={m.id} className="ni-mediacell"><MediaBlock m={m} /></div>)}</div>
        </div>
      )}

      <div className="ni-h2 sm">Posts</div>
      {d.posts.length === 0 ? <div className="ni-dim ni-pad">No posts yet.</div> : d.posts.map(post => <PostCard key={post.id} p={post} sess={sess} />)}
    </div>
  );
}

/* --------------------------------------------------------- profile editor */
function ProfileEditor({ sess, reload, go }) {
  const [f, setF] = useState(null);
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState('');

  useEffect(() => {
    if (!sess.loaded) return;
    const p = sess.profile || {};
    setF({
      headline: p.headline || '', bio: p.bio || '', location: p.location || '',
      skills: (p.skills || []).join(', '),
      links: p.links || { github: '', site: '', twitter: '', youtube: '' },
      open_to: p.open_to || [], resume: p.resume || [], public: p.public !== false,
    });
  }, [sess.loaded, sess.profile]);

  if (!sess.loaded) return <div className="ni-pad ni-dim">…</div>;
  if (!sess.user) return <div className="ni-card ni-pad">You need to <a href="/login">sign in</a> to create a profile.</div>;
  if (!f) return null;

  const set = (k, v) => setF(s => ({ ...s, [k]: v }));
  const setLink = (k, v) => setF(s => ({ ...s, links: { ...s.links, [k]: v } }));
  const toggleOpen = (o) => setF(s => ({ ...s, open_to: s.open_to.includes(o) ? s.open_to.filter(x => x !== o) : [...s.open_to, o] }));
  const setRes = (i, k, v) => setF(s => { const r = [...s.resume]; r[i] = { ...r[i], [k]: v }; return { ...s, resume: r }; });
  const addRes = () => setF(s => ({ ...s, resume: [...s.resume, { role: '', org: '', from: '', to: '', detail: '' }] }));
  const rmRes = (i) => setF(s => ({ ...s, resume: s.resume.filter((_, j) => j !== i) }));

  const save = async () => {
    setBusy(true); setErr('');
    try {
      const payload = { ...f, skills: f.skills.split(',').map(s => s.trim()).filter(Boolean) };
      const d = await api.post('/api/networkedin/profile', payload);
      reload();
      go('/u/' + d.slug);
    } catch (e) { setErr(e.message); } finally { setBusy(false); }
  };

  return (
    <div>
      <div className="ni-h2">{sess.profile ? 'Edit your profile' : 'Join networkedin'}</div>
      <div className="ni-card ni-pad ni-form">
        <label>Headline</label>
        <input className="ni-input" placeholder="Builder of poker minds · OCR & GTO" value={f.headline} onChange={e => set('headline', e.target.value)} />
        <label>Bio</label>
        <textarea className="ni-input ni-area" placeholder="Who you are and what you build." value={f.bio} onChange={e => set('bio', e.target.value)} />
        <label>Location</label>
        <input className="ni-input" placeholder="Newark, NJ" value={f.location} onChange={e => set('location', e.target.value)} />
        <label>Skills (comma-separated)</label>
        <input className="ni-input" placeholder="C++, OpenHoldem, OCR, Tesseract, React, GTO" value={f.skills} onChange={e => set('skills', e.target.value)} />

        <label>Links</label>
        <div className="ni-linkrow">
          {['github', 'site', 'twitter', 'youtube'].map(k => (
            <input key={k} className="ni-input sm" placeholder={k} value={f.links[k] || ''} onChange={e => setLink(k, e.target.value)} />
          ))}
        </div>

        <label>Open to</label>
        <div className="ni-seg wrap">
          {['collab', 'hire', 'advise', 'invest', 'mentoring'].map(o => (
            <button key={o} className={'ni-seg-b' + (f.open_to.includes(o) ? ' on' : '')} onClick={() => toggleOpen(o)}>{o}</button>
          ))}
        </div>

        <label>Experience / Resume</label>
        {f.resume.map((r, i) => (
          <div key={i} className="ni-resedit">
            <input className="ni-input sm" placeholder="Role" value={r.role || ''} onChange={e => setRes(i, 'role', e.target.value)} />
            <input className="ni-input sm" placeholder="Org" value={r.org || ''} onChange={e => setRes(i, 'org', e.target.value)} />
            <input className="ni-input sm" placeholder="From" value={r.from || ''} onChange={e => setRes(i, 'from', e.target.value)} />
            <input className="ni-input sm" placeholder="To" value={r.to || ''} onChange={e => setRes(i, 'to', e.target.value)} />
            <input className="ni-input sm wide" placeholder="What you did" value={r.detail || ''} onChange={e => setRes(i, 'detail', e.target.value)} />
            <button className="ni-x" onClick={() => rmRes(i)}>✕</button>
          </div>
        ))}
        <button className="ni-btn sm ghost" onClick={addRes}>+ Add experience</button>

        <label className="ni-check"><input type="checkbox" checked={f.public} onChange={e => set('public', e.target.checked)} /> Show me in the public directory</label>

        {err && <div className="ni-err">{err}</div>}
        <div className="ni-comp-foot">
          <span className="ni-dim sm">Saving creates your public profile at <b>/networkedin/u/…</b></span>
          <button className="ni-btn gold" disabled={busy} onClick={save}>{busy ? 'Saving…' : (sess.profile ? 'Save profile' : 'Join networkedin')}</button>
        </div>
      </div>
    </div>
  );
}

const root = document.getElementById('ni-root');
if (root) createRoot(root).render(<App />);
