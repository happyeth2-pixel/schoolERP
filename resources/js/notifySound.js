// A short bell "ding" (Web Audio oscillator, no audio file to ship or license) -- once per notification,
// for someone who cannot read the badge or the text, not just a visual change they might never look at.

let audioCtx = null;

function ctx() {
    if (!audioCtx) {
        const Ctor = window.AudioContext || window.webkitAudioContext;
        if (!Ctor) return null;
        audioCtx = new Ctor();
    }
    // Browsers suspend a newly created context until a real user gesture; a notification can arrive at
    // any time, so resume it defensively every time (a no-op once it's already running).
    if (audioCtx.state === 'suspended') audioCtx.resume().catch(() => {});
    return audioCtx;
}

/** One short, pleasant two-note "ding", entirely synthesized. */
export function playBell() {
    const c = ctx();
    if (!c) return;

    const ring = (freq, startIn, duration) => {
        const osc = c.createOscillator();
        const gain = c.createGain();
        osc.type = 'sine';
        osc.frequency.value = freq;
        osc.connect(gain);
        gain.connect(c.destination);

        const start = c.currentTime + startIn;
        gain.gain.setValueAtTime(0, start);
        gain.gain.linearRampToValueAtTime(0.18, start + 0.015);
        gain.gain.exponentialRampToValueAtTime(0.0001, start + duration);
        osc.start(start);
        osc.stop(start + duration + 0.05);
    };

    ring(880, 0, 0.35);
    ring(1320, 0.08, 0.4);
}

/** The one call a listener needs. */
export function announce() {
    playBell();
}
