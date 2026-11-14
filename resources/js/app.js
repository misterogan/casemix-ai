// Interaksi kecil tanpa framework.

// Konfirmasi sebelum aksi penting: <form data-confirm="...">
document.addEventListener('submit', (e) => {
  const msg = e.target.dataset.confirm;
  if (msg && !window.confirm(msg)) e.preventDefault();
});

// Cegah klik ganda pada tombol submit
document.addEventListener('submit', (e) => {
  if (e.defaultPrevented) return;
  e.target.querySelectorAll('button[type=submit]').forEach((b) => {
    b.dataset.label = b.innerHTML;
    setTimeout(() => { b.disabled = true; }, 0);
  });
});

// Format rupiah saat mengetik: <input data-rupiah>
document.addEventListener('input', (e) => {
  if (!e.target.matches('[data-rupiah]')) return;
  const digits = e.target.value.replace(/\D/g, '');
  e.target.value = digits ? Number(digits).toLocaleString('id-ID') : '';
});

// Tampilkan/sembunyikan berdasarkan pilihan: <select data-toggle-target="#id" data-toggle-value="inap">
function syncToggles() {
  document.querySelectorAll('[data-show-when]').forEach((el) => {
    const [name, value] = el.dataset.showWhen.split('=');
    const input = document.querySelector(`[name="${name}"]`);
    el.hidden = !input || input.value !== value;
  });
}
document.addEventListener('change', syncToggles);
document.addEventListener('DOMContentLoaded', syncToggles);

const AJAX = { 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html' };

// Fade-in 200 ms setelah konten ditukar (dimatikan oleh prefers-reduced-motion di CSS)
function tampilkan(el) {
  el.classList.remove('muncul');
  void el.offsetWidth;
  el.classList.add('muncul');
}

// Teks tahapan perkiraan yang berganti tiap ±8 detik: <p data-tahap='["…","…"]' data-lewat="detik">
function initTahap(root = document) {
  root.querySelectorAll('[data-tahap]').forEach((el) => {
    const tahap = JSON.parse(el.dataset.tahap);
    let i = Math.min(tahap.length - 1, Math.floor((Number(el.dataset.lewat) || 0) / 8));
    el.textContent = tahap[i];
    const t = setInterval(() => {
      if (!el.isConnected || i >= tahap.length - 1) return clearInterval(t);
      el.textContent = tahap[++i];
    }, 8000);
  });
}

// Polling status proses AI: <div data-poll="url">
// Dengan data-poll-partial + data-poll-swap: isi ditukar tanpa memuat ulang. Tanpa itu: halaman dimuat ulang.
async function pollSelesai(el) {
  const target = el.dataset.pollSwap && document.querySelector(el.dataset.pollSwap);
  if (!target || !el.dataset.pollPartial) return window.location.reload();
  try {
    const r = await fetch(el.dataset.pollPartial, { headers: AJAX, cache: 'no-store' });
    if (!r.ok) throw new Error(r.status);
    target.innerHTML = await r.text();
    tampilkan(target);
    const kabar = document.querySelector('[data-kabar]');
    if (kabar) kabar.textContent = 'Pemeriksaan AI selesai. Hasil sudah ditampilkan.';
    initTahap(target);
    initPoll(target);
  } catch (_) {
    window.location.reload();
  }
}

function initPoll(root = document) {
  root.querySelectorAll('[data-poll]').forEach((el) => {
    // Halaman verifikasi per file punya polling sendiri (initDocs)
    if (document.querySelector('[data-docs]') && el.closest('#detail-folder')) return;
    const tick = async () => {
      if (!el.isConnected) return;
      try {
        const r = await fetch(el.dataset.poll, { headers: { Accept: 'application/json' }, cache: 'no-store' });
        const j = await r.json();
        if (!j.active) return pollSelesai(el);
      } catch (_) { /* coba lagi */ }
      setTimeout(tick, 4000);
    };
    setTimeout(tick, 4000);
  });
}

// Pencarian langsung di Verifikasi berkas: <div data-cari-wadah>. Tanpa JS, form GET biasa tetap bekerja.
function initCari() {
  const wadah = document.querySelector('[data-cari-wadah]');
  if (!wadah) return;
  const form = wadah.querySelector('[data-cari-form]');
  const input = form.querySelector('[data-cari-input]');
  const hapus = form.querySelector('[data-cari-hapus]');
  const hasil = wadah.querySelector('[data-hasil]');
  const kerangka = wadah.querySelector('template[data-kerangka]');
  let timer;
  let ctrl;

  const urlForm = () => {
    const u = new URL(form.action, window.location.href);
    new FormData(form).forEach((v, k) => { const s = String(v).trim(); if (s) u.searchParams.set(k, s); });
    return u;
  };

  const aturStatus = (url) => {
    const st = url.searchParams.get('status');
    let h = form.querySelector('input[name=status]');
    if (!st) return h?.remove();
    if (!h) { h = Object.assign(document.createElement('input'), { type: 'hidden', name: 'status' }); form.prepend(h); }
    h.value = st;
  };

  const aturMode = () => {
    wadah.dataset.mode = input.value.trim() ? 'hasil' : 'beranda';
    if (hapus) hapus.hidden = !input.value;
  };

  const muat = async (url) => {
    ctrl?.abort();
    ctrl = new AbortController();
    const list = hasil.querySelector('[data-list]');
    if (list && kerangka) list.replaceChildren(kerangka.content.cloneNode(true));
    hasil.setAttribute('aria-busy', 'true');
    try {
      const r = await fetch(url, { headers: AJAX, signal: ctrl.signal, cache: 'no-store' });
      if (!r.ok) throw new Error(r.status);
      hasil.innerHTML = await r.text();
      hasil.setAttribute('aria-busy', 'false');
      tampilkan(hasil);
      aturStatus(url);
      window.history.replaceState(null, '', url);
    } catch (e) {
      if (e.name !== 'AbortError') window.location.href = url;
    }
  };

  const segera = () => { clearTimeout(timer); aturMode(); muat(urlForm()); };

  input.addEventListener('input', () => {
    aturMode();
    clearTimeout(timer);
    timer = setTimeout(() => muat(urlForm()), 300);
  });
  input.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && input.value) { e.preventDefault(); input.value = ''; segera(); }
  });
  form.addEventListener('submit', (e) => { e.preventDefault(); segera(); });
  hapus?.addEventListener('click', (e) => { e.preventDefault(); input.value = ''; input.focus(); segera(); });

  // Tab filter dan paginasi dimuat di tempat
  hasil.addEventListener('click', (e) => {
    const a = e.target.closest('a[data-partial]');
    if (!a || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    e.preventDefault();
    muat(new URL(a.href));
    if (a.closest('.halaman-g')) wadah.scrollIntoView({ block: 'start' });
  });
}

// Verifikasi per file: <section data-docs data-status-url data-active>. Tiap 3 detik hanya item yang
// statusnya berubah yang diganti; item yang baru selesai dibuka halus (200 ms). Berhenti saat analisa selesai.
function initDocs() {
  const root = document.querySelector('[data-docs]');
  if (!root || root.dataset.active !== '1') return;
  const list = root.querySelector('[data-docs-list]');
  if (!list) return;
  const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  const buka = (el) => {
    el.open = true;
    if (reduce) return;
    el.querySelector('[data-doc-body]')?.animate(
      [{ opacity: 0, transform: 'translateY(-4px)' }, { opacity: 1, transform: 'none' }],
      { duration: 200, easing: 'ease-out' },
    );
  };

  const tick = async () => {
    let j;
    try {
      const r = await fetch(root.dataset.statusUrl, { headers: { Accept: 'application/json' }, cache: 'no-store' });
      if (!r.ok) throw new Error(r.status);
      j = await r.json();
    } catch (_) {
      return setTimeout(tick, 3000);
    }

    j.docs.forEach((d) => {
      const lama = list.querySelector(`[data-doc="${d.id}"]`);
      if (lama && lama.dataset.docStatus === d.status) return;
      const t = document.createElement('template');
      t.innerHTML = d.html.trim();
      const baru = t.content.firstElementChild;
      if (!baru || baru.tagName !== 'DETAILS') return;
      // Hanya isi di dalam container daftar yang diganti; tidak pernah di luar daftar (mis. di atas kepala halaman)
      if (lama && list.contains(lama)) {
        const wasOpen = lama.open;
        lama.replaceWith(baru);
        if (d.status === 'selesai') buka(baru);
        else if (wasOpen) baru.open = true;
      } else {
        list.append(baru);
      }
    });

    const progres = root.querySelector('[data-docs-progress]');
    if (progres) progres.textContent = `${j.done} dari ${j.total} file selesai`;
    const bar = root.querySelector('[data-docs-bar]');
    if (bar) {
      bar.setAttribute('aria-valuenow', j.done);
      bar.firstElementChild.style.width = `${j.total ? Math.round((j.done / j.total) * 100) : 0}%`;
    }

    if (j.active) return setTimeout(tick, 3000);

    root.dataset.active = '0';
    root.querySelectorAll('[data-lock]').forEach((b) => { b.disabled = false; });
    const ulangi = root.querySelector('[data-resynth]');
    if (ulangi) ulangi.hidden = !j.can_resynth;
    const panel = document.getElementById('detail-folder');
    if (panel && j.summary_html) {
      panel.innerHTML = j.summary_html;
      tampilkan(panel);
      initTahap(panel);
    }
    const kabar = document.querySelector('[data-kabar]');
    if (kabar) kabar.textContent = 'Pemeriksaan AI selesai. Rekomendasi keseluruhan sudah ditampilkan.';
  };
  setTimeout(tick, 3000);
}

// Konfirmasi sebelum kirim: <form data-konfirmasi="pesan">
document.addEventListener('submit', (e) => {
  const pesan = e.target.dataset?.konfirmasi;
  if (pesan && !window.confirm(pesan)) e.preventDefault();
});

document.addEventListener('DOMContentLoaded', () => {
  initTahap();
  initPoll();
  initDocs();
  initCari();
});

// Pilih semua checkbox: <input type=checkbox data-check-all="name-prefix">
document.addEventListener('change', (e) => {
  const prefix = e.target.dataset?.checkAll;
  if (!prefix) return;
  document.querySelectorAll(`input[type=checkbox][name^="${prefix}"]`).forEach((c) => { if (!c.disabled) c.checked = e.target.checked; });
});

// Tutup notifikasi
document.addEventListener('click', (e) => {
  if (e.target.closest('[data-dismiss]')) e.target.closest('[data-flash]')?.remove();
});
