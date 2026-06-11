/**
 * Site-wide mobile-safe marquee engine.
 *
 * Drives every element carrying the special [data-mq] attribute with
 * requestAnimationFrame + translate3d, so marquees scroll reliably on Samsung
 * Galaxy / mobile browsers where pure CSS-keyframe marquees stutter or freeze.
 * The element's CSS animation is left in markup as a no-JS fallback and is
 * switched off here the moment JS takes ownership.
 *
 * Per-track attributes:
 *   data-mq             — marks the scrolling track (required).
 *   data-dur="40"       — seconds for one full loop (fixed-duration mode).
 *   data-speed="58"     — pixels/second (constant-speed mode; wins over data-dur).
 *   data-rev="1"        — scroll in reverse (left→right).
 *
 * The track must contain its content duplicated exactly twice (the engine loops
 * at scrollWidth / 2). Hover over the track's parent pauses motion.
 */
(function () {
  function ready(fn) {
    if (document.readyState !== 'loading') { fn(); }
    else { document.addEventListener('DOMContentLoaded', fn); }
  }

  function drive(tk) {
    if (tk.__sbmq) { return; }
    tk.__sbmq = true;
    // JS owns the motion — kill the CSS keyframe so the two can't fight.
    tk.style.animation = 'none';
    tk.style.willChange = 'transform';
    // NOTE: we intentionally do NOT honor prefers-reduced-motion here. Samsung
    // Galaxy (and many Android phones) ship with "Reduce animations" enabled,
    // which froze every marquee + the SIGNAL bar site-wide. These scrollers are
    // core brand motion, so they always run; only a total lack of rAF opts out.
    if (!window.requestAnimationFrame) { return; }

    var dur = parseFloat(tk.getAttribute('data-dur')) || 0;
    var speed = parseFloat(tk.getAttribute('data-speed')) || 0; // px/sec
    var rev = tk.getAttribute('data-rev') === '1';
    var dist = 0, t0 = null, paused = false, pauseAt = null;
    var host = tk.parentNode;

    function measure() { dist = tk.scrollWidth / 2; }
    measure();
    window.addEventListener('resize', measure);
    if (host && host.addEventListener) {
      host.addEventListener('mouseenter', function () { paused = true; });
      host.addEventListener('mouseleave', function () { paused = false; });
    }

    function step(ts) {
      if (t0 === null) { t0 = ts; }
      if (paused) {
        if (pauseAt === null) { pauseAt = ts; }
        requestAnimationFrame(step);
        return;
      }
      if (pauseAt !== null) { t0 += ts - pauseAt; pauseAt = null; }
      if (!dist) { measure(); } // element may have been hidden (scrollWidth 0) at init
      var x = 0;
      if (dist) {
        var elapsed = (ts - t0) / 1000;
        if (speed) {
          var off = (elapsed * speed) % dist;
          x = rev ? (off - dist) : -off;
        } else {
          var d = dur || 40;
          var p = (elapsed / d) % 1; if (p < 0) { p += 1; }
          x = rev ? (p * dist - dist) : (-p * dist);
        }
      }
      tk.style.transform = 'translate3d(' + x.toFixed(2) + 'px,0,0)';
      requestAnimationFrame(step);
    }
    requestAnimationFrame(step);
  }

  function init() {
    var tracks = document.querySelectorAll('[data-mq]');
    Array.prototype.forEach.call(tracks, drive);
  }

  ready(init);
  // Re-scan shortly after load in case fonts/images shift widths or content is added.
  window.addEventListener('load', function () { setTimeout(init, 60); });
  window.sbMarqueeInit = init; // allow late/dynamic content to re-trigger
})();
