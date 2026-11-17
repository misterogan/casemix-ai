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
  hapus?.addEventListener('click', (e) => { e.preventDefault(); input.value = ''; input.focus({ preventScroll: true }); segera(); });

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
// ≥1024px: dua panel setinggi sisa layar (kepala halaman tidak ikut digulir). <1024px: tinggi otomatis.
function aturTinggiPanel(root) {
  const grid = root.querySelector('[data-panel-grid]');
  if (!grid) return;
  const lebar = window.matchMedia('(min-width: 1024px)');
  const atur = () => {
    if (!lebar.matches) return grid.style.removeProperty('--tinggi-panel');
    const atas = grid.getBoundingClientRect().top + window.scrollY;
    grid.style.setProperty('--tinggi-panel', `${Math.max(320, window.innerHeight - atas - 24)}px`);
  };
  atur();
  window.addEventListener('resize', atur);
}

function initDocs() {
  const root = document.querySelector('[data-docs]');
  if (!root) return;
  aturTinggiPanel(root);
  if (root.dataset.active !== '1') return;
  const list = root.querySelector('[data-docs-list]');
  if (!list) return;
  const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  // Jangan menggulir otomatis bila pengguna baru saja (3 detik) menggulir daftar ini sendiri
  let terakhirDigulir = 0;
  const wadah = list.closest('[data-gulir-file]') || list;
  ['wheel', 'touchmove', 'pointerdown', 'keydown'].forEach((ev) =>
    wadah.addEventListener(ev, () => { terakhirDigulir = Date.now(); }, { passive: true }));

  // Gulir HANYA kolom panel file (bukan window); tanpa scrollIntoView. Kepala sticky diperhitungkan.
  const tampakkan = (el) => {
    if (Date.now() - terakhirDigulir < 3000 || wadah.scrollHeight <= wadah.clientHeight) return;
    const kepala = wadah.querySelector('.sticky')?.offsetHeight || 0;
    const atas = el.getBoundingClientRect().top - wadah.getBoundingClientRect().top + wadah.scrollTop;
    if (atas - kepala >= wadah.scrollTop && atas + el.offsetHeight <= wadah.scrollTop + wadah.clientHeight) return;
    wadah.scrollTo({ top: Math.max(0, atas - kepala - 12), behavior: reduce ? 'auto' : 'smooth' });
  };

  const buka = (el) => {
    el.open = true;
    if (!reduce) {
      el.querySelector('[data-doc-body]')?.animate(
        [{ opacity: 0, transform: 'translateY(-4px)' }, { opacity: 1, transform: 'none' }],
        { duration: 200, easing: 'ease-out' },
      );
    }
    tampakkan(el);
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

    // Posisi gulir dipertahankan saat isi diganti (tidak melompat ke atas)
    const posisiDaftar = wadah.scrollTop;
    const baruSelesai = [];
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
        if (d.status === 'selesai') baruSelesai.push(baru);
        else if (wasOpen) baru.open = true;
      } else {
        list.append(baru);
      }
    });
    wadah.scrollTop = posisiDaftar;
    baruSelesai.forEach(buka);

    const progres = root.querySelector('[data-docs-progress]');
    if (progres) progres.textContent = `${j.done} dari ${j.total} file selesai`;
    const bar = root.querySelector('[data-docs-bar]');
    if (bar) {
      bar.setAttribute('aria-valuenow', j.done);
      bar.firstElementChild.style.width = `${j.total ? Math.round((j.done / j.total) * 100) : 0}%`;
    }

    if (j.active) return setTimeout(tick, 3000);

    root.dataset.active = '0';
    // Daftar periksa dan saran DPJP dirender di server: muat ulang halaman agar status terbaru tampil
    if (root.dataset.muatUlang === '1' && j.summary_html) return window.location.reload();
    root.querySelectorAll('[data-lock]').forEach((b) => { b.disabled = false; });
    const ulangi = root.querySelector('[data-resynth]');
    if (ulangi) ulangi.hidden = !j.can_resynth;
    const panel = document.getElementById('detail-folder');
    if (panel && j.summary_html) {
      const posisiPanel = panel.scrollTop;
      panel.innerHTML = j.summary_html;
      panel.scrollTop = posisiPanel;
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

// Salin teks ke clipboard: <button data-salin="teks" data-salin-ok="Tersalin">
document.addEventListener('click', async (e) => {
  const b = e.target.closest('[data-salin]');
  if (!b) return;
  const teks = b.dataset.salin;
  try {
    await navigator.clipboard.writeText(teks);
  } catch (_) {
    const t = Object.assign(document.createElement('textarea'), { value: teks });
    t.setAttribute('readonly', '');
    t.style.position = 'fixed';
    t.style.opacity = '0';
    document.body.append(t);
    t.select();
    document.execCommand('copy');
    t.remove();
  }
  const label = b.dataset.label ?? b.textContent;
  b.dataset.label = label;
  b.textContent = b.dataset.salinOk || 'Tersalin';
  const kabar = document.querySelector('[data-kabar]');
  if (kabar) kabar.textContent = (b.dataset.salinOk || 'Tersalin') + '.';
  setTimeout(() => { b.textContent = label; }, 2000);
});

// Tutup notifikasi
document.addEventListener('click', (e) => {
  if (e.target.closest('[data-dismiss]')) e.target.closest('[data-flash]')?.remove();
});

// Pratinjau dokumen dalam modal: <a href="..." data-preview="nama file">. Klik tengah/Ctrl tetap membuka tab.
let pratinjau;
function modalPratinjau() {
  if (pratinjau) return pratinjau;
  pratinjau = document.createElement('dialog');
  pratinjau.className = 'p-0 rounded-lg shadow-xl backdrop:bg-black/50';
  pratinjau.style.cssText = 'position:fixed;inset:0;margin:auto;width:90vw;height:90vh;max-width:none;max-height:none';
  pratinjau.innerHTML = `<div class="flex h-full flex-col bg-white">
    <div class="flex items-center gap-3 border-b px-4 py-2">
      <h2 class="min-w-0 flex-1 truncate font-medium" data-judul></h2>
      <a data-tab target="_blank" rel="noopener" class="text-sm hover:underline">Buka di tab baru ↗</a>
      <button type="button" data-tutup class="rounded border px-3 py-1 text-sm hover:bg-gray-100">Tutup</button>
    </div>
    <div class="min-h-0 flex-1 bg-gray-100" data-isi></div>
  </div>`;
  pratinjau.addEventListener('click', (e) => {
    if (e.target === pratinjau || e.target.closest('[data-tutup]')) pratinjau.close();
  });
  pratinjau.addEventListener('close', () => {
    const isi = pratinjau.querySelector('[data-isi]');
    isi.querySelectorAll('iframe').forEach((f) => { f.src = 'about:blank'; });
    isi.replaceChildren();
    window.scrollTo(0, Number(pratinjau.dataset.scrollY || 0));
  });
  document.body.append(pratinjau);
  return pratinjau;
}

// Klik <summary> accordion: jangan ikuti tautan hash (window tidak boleh tergulir)
document.addEventListener('click', (e) => {
  if (e.target.closest('summary a[href^="#"]')) e.preventDefault();
});

document.addEventListener('click', async (e) => {
  const a = e.target.closest('a[data-preview]');
  if (!a || e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return;
  e.preventDefault();
  const d = modalPratinjau();
  const href = a.href;
  d.querySelector('[data-judul]').textContent = a.dataset.preview || a.textContent.trim();
  d.querySelector('[data-tab]').href = href;
  const isi = d.querySelector('[data-isi]');
  const nama = (a.dataset.preview || '').toLowerCase();
  let gambar = /\.(png|jpe?g|gif|webp|bmp|svg)$/.test(nama);
  if (!gambar && !/\.pdf$/.test(nama)) {
    try {
      const r = await fetch(href.split('#')[0], { method: 'HEAD', credentials: 'same-origin' });
      gambar = (r.headers.get('Content-Type') || '').startsWith('image/');
    } catch (_) { /* anggap PDF */ }
  }
  const el = gambar
    ? Object.assign(document.createElement('img'), { src: href, alt: a.dataset.preview || '', className: 'mx-auto h-full w-full object-contain' })
    : Object.assign(document.createElement('iframe'), { src: href, title: a.dataset.preview || 'Dokumen', className: 'h-full w-full border-0' });
  isi.replaceChildren(el);
  if (!d.open) { d.dataset.scrollY = String(window.scrollY); d.showModal(); }
});
