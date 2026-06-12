// Thin fetch wrapper — session cookie + CSRF, JSON in/out. Same origin.
const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';

async function req(method, url, body) {
  const opts = {
    method,
    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    credentials: 'same-origin',
  };
  if (body !== undefined) {
    opts.headers['X-CSRF-TOKEN'] = CSRF;
    if (body instanceof FormData) {
      opts.body = body; // browser sets the multipart boundary header
    } else {
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(body);
    }
  }
  const res = await fetch(url, opts);
  let data = null;
  try { data = await res.json(); } catch (e) { /* non-json */ }
  if (!res.ok) {
    const msg = (data && (data.error || data.message)) || `HTTP ${res.status}`;
    const err = new Error(msg);
    err.status = res.status;
    err.data = data;
    throw err;
  }
  return data;
}

export const api = {
  get: (u) => req('GET', u),
  post: (u, b) => req('POST', u, b || {}),
  put: (u, b) => req('PUT', u, b || {}),
  del: (u) => req('DELETE', u),
};
