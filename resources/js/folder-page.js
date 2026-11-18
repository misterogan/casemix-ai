// Halaman verifikasi berkas: penampil dokumen (pdf.js), navigasi bagian, kepala ringkas, centang temuan, pintasan keyboard.
import * as pdfjs from 'pdfjs-dist';
import workerUrl from 'pdfjs-dist/build/pdf.worker.min.mjs?url';

pdfjs.GlobalWorkerOptions.workerSrc = workerUrl;

const root = document.querySelector('[data-folder-page]');
const reduce = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const lebarPenuh = window.matchMedia('(min-width: 1280px)');
const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

// ── Lompat ke bagian: gulir halus + sorot 1,5 detik ──
function lompat(target) {
  if (!target) return;
  target.scrollIntoView({ behavior: reduce() ? 'auto' : 'smooth', block: 'start' });
  if (reduce()) return;
  target.classList.remove('vf-sorot');
  void target.offsetWidth;
  target.classList.add('vf-sorot');
  setTimeout(() => target.classList.remove('vf-sorot'), 1500);
}

document.addEventListener('click', (e) => {
  const a = e.target.closest('a[data-lompat]');
  if (!a) return;
  const t = document.getElementById(a.getAttribute('href').slice(1));
  if (!t) return;
  e.preventDefault();
  history.replaceState(null, '', a.getAttribute('href'));
  lompat(t);
});

// ── Penampil dokumen ──
function initPenampil() {
  const p = document.querySelector('[data-penampil]');
  if (!p) return null;
  const latar = document.querySelector('[data-penampil-latar]');
  const isi = p.querySelector('[data-penampil-isi]');
  const info = p.querySelector('[data-hal-info]');
  const zoom = p.querySelector('[data-zoom]');
  const tabBaru = p.querySelector('[data-penampil-tab-baru]');
  const tabs = [...p.querySelectorAll('[data-tab-file]')];
  const cache = new Map();
  let aktif = null; // { tab, jenis, doc, hal }
  let tugas = null;
  let asal = null;

  const muat = (tab) => {
    if (cache.has(tab)) return cache.get(tab);
    const pr = fetch(tab.dataset.url, { credentials: 'same-origin' })
      .then(async (r) => {
        if (!r.ok) throw new Error(`HTTP ${r.status}`);
        const tipe = r.headers.get('content-type') || '';
        const buf = await r.arrayBuffer();
        if (tipe.includes('pdf') || /\.pdf$/i.test(tab.dataset.nama)) {
          return { jenis: 'pdf', doc: await pdfjs.getDocument({ data: new Uint8Array(buf) }).promise };
        }
        return { jenis: 'img', url: URL.createObjectURL(new Blob([buf], { type: tipe || 'image/*' })) };
      });
    pr.catch(() => cache.delete(tab));
    cache.set(tab, pr);
    return pr;
  };

  const render = async () => {
    if (!aktif) return;
    if (aktif.jenis === 'img') {
      const img = Object.assign(document.createElement('img'), { src: aktif.url, alt: aktif.tab.dataset.nama, className: 'mx-auto max-w-none' });
      img.style.width = zoom.value === 'lebar' ? '100%' : `${Number(zoom.value) * 100}%`;
      isi.replaceChildren(img);
      info.textContent = 'Gambar';
      return;
    }
    const doc = aktif.doc;
    aktif.hal = Math.min(Math.max(1, aktif.hal), doc.numPages);
    const page = await doc.getPage(aktif.hal);
    const vp0 = page.getViewport({ scale: 1 });
    const dpr = window.devicePixelRatio || 1;
    const skala = zoom.value === 'lebar' ? Math.max(0.3, (isi.clientWidth - 16) / vp0.width) : Number(zoom.value);
    const vp = page.getViewport({ scale: skala * dpr });
    const canvas = document.createElement('canvas');
    canvas.width = vp.width;
    canvas.height = vp.height;
    canvas.style.width = `${vp.width / dpr}px`;
    canvas.className = 'mx-auto bg-white shadow';
    canvas.setAttribute('role', 'img');
    canvas.setAttribute('aria-label', `${aktif.tab.dataset.nama}, halaman ${aktif.hal}`);
    tugas?.cancel();
    tugas = page.render({ canvasContext: canvas.getContext('2d'), viewport: vp });
    try { await tugas.promise; } catch { return; }
    isi.replaceChildren(canvas);
    isi.scrollTop = 0;
    info.textContent = `hal. ${aktif.hal} / ${doc.numPages}`;
  };

  const pilihTab = (tab) => tabs.forEach((t) => {
    const ya = t === tab;
    t.setAttribute('aria-selected', ya ? 'true' : 'false');
    t.tabIndex = ya ? 0 : -1;
    if (ya) t.scrollIntoView({ block: 'nearest', inline: 'nearest' });
  });

  const buka = async (tab, hal = 1) => {
    pilihTab(tab);
    tabBaru.href = tab.dataset.url + (hal > 1 ? `#page=${hal}` : '');
    if (aktif?.tab === tab) { aktif.hal = hal; return render(); }
    info.textContent = 'Memuat…';
    try {
      const d = await muat(tab);
      aktif = { tab, hal, ...d };
      await render();
    } catch (err) {
      console.error('[penampil] dokumen tidak dapat dimuat:', err);
      aktif = null;
      info.textContent = '—';
      isi.innerHTML = '<p class="p-4 text-[13px] text-gray-600">Dokumen tidak dapat ditampilkan. Pakai "Buka di tab baru".</p>';
    }
  };

  const bukaDrawer = () => {
    if (lebarPenuh.matches) return;
    asal = document.activeElement;
    p.setAttribute('data-buka', '');
    latar.hidden = false;
  };
  const tutupDrawer = () => {
    if (!p.hasAttribute('data-buka')) return false;
    p.removeAttribute('data-buka');
    latar.hidden = true;
    asal?.focus?.();
    return true;
  };
  latar.addEventListener('click', tutupDrawer);
  p.querySelector('[data-penampil-tutup]')?.addEventListener('click', tutupDrawer);

  tabs.forEach((t) => t.addEventListener('click', () => buka(t)));
  p.querySelector('[role=tablist]').addEventListener('keydown', (e) => {
    const i = tabs.indexOf(document.activeElement);
    if (i < 0 || !['ArrowLeft', 'ArrowRight'].includes(e.key)) return;
    const t = tabs[(i + (e.key === 'ArrowRight' ? 1 : tabs.length - 1)) % tabs.length];
    t.focus();
    buka(t);
  });
  p.querySelector('[data-hal-sebelum]').addEventListener('click', () => { if (aktif?.doc) { aktif.hal -= 1; render(); } });
  p.querySelector('[data-hal-berikut]').addEventListener('click', () => { if (aktif?.doc) { aktif.hal += 1; render(); } });
  zoom.addEventListener('change', render);
  let tunda;
  window.addEventListener('resize', () => { clearTimeout(tunda); tunda = setTimeout(() => { if (zoom.value === 'lebar') render(); }, 200); });

  const tampilkan = (nama, hal, keyboard) => {
    const tab = tabs.find((t) => t.dataset.nama === nama);
    if (!tab) return false;
    bukaDrawer();
    buka(tab, hal);
    if (keyboard) p.focus();
    return true;
  };

  // Bawaan: resume medis (≥1280px dimuat langsung; drawer dimuat saat dibuka)
  const awal = tabs[Number(p.dataset.awal) || 0];
  if (awal) {
    pilihTab(awal);
    if (lebarPenuh.matches) buka(awal);
  }

  return { tampilkan, tutupDrawer };
}

const penampil = root ? initPenampil() : null;

if (penampil) {
  // Bukti di mana pun (a[data-preview]) → penampil; fase capture agar modal pratinjau bawaan tidak ikut terbuka.
  window.addEventListener('click', (e) => {
    const a = e.target.closest('a[data-preview]');
    if (!a || e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return;
    const hal = parseInt((a.hash.match(/page=(\d+)/) || [])[1] || '1', 10);
    if (!penampil.tampilkan(a.dataset.preview, hal, e.detail === 0)) return;
    e.preventDefault();
    e.stopPropagation();
  }, true);

  // Daftar File: klik nama file → buka di penampil (accordion tetap membuka/menutup)
  document.addEventListener('click', (e) => {
    const s = e.target.closest('[data-docs-list] details[data-doc] > summary');
    if (s) penampil.tampilkan(s.parentElement.dataset.nama, 1, e.detail === 0);
  });
}

// ── Navigasi bagian: chip aktif mengikuti posisi gulir ──
function initNav() {
  const chips = [...document.querySelectorAll('[data-nav]')];
  const map = new Map();
  chips.forEach((c) => {
    const t = document.getElementById(`bagian-${c.dataset.nav}`);
    if (!t || !t.textContent.trim()) { c.remove(); return; }
    map.set(t, c);
  });
  if (!('IntersectionObserver' in window) || !map.size) return;
  const terlihat = new Set();
  const io = new IntersectionObserver((entries) => {
    entries.forEach((en) => (en.isIntersecting ? terlihat.add(en.target) : terlihat.delete(en.target)));
    const pertama = [...map.keys()].find((t) => terlihat.has(t));
    if (!pertama) return;
    map.forEach((c, t) => c.setAttribute('aria-current', t === pertama ? 'true' : 'false'));
    map.get(pertama).scrollIntoView({ block: 'nearest', inline: 'nearest' });
  }, { rootMargin: '-200px 0px -55% 0px' });
  map.forEach((_, t) => io.observe(t));
}

// ── Kepala ringkas: muncul setelah judul lewat ──
function initKepala() {
  const judul = document.querySelector('[data-judul-berkas]');
  const ringkas = document.querySelector('[data-kepala-ringkas]');
  if (!judul || !ringkas || !('IntersectionObserver' in window)) return;
  new IntersectionObserver(([en]) => { ringkas.hidden = en.isIntersecting || en.boundingClientRect.top > 0; },
    { rootMargin: '-72px 0px 0px 0px' }).observe(judul);
}

// ── Temuan: centang, progres, filter ──
function initTemuan() {
  const bagian = document.querySelector('[data-temuan-bagian]');
  if (!bagian) return null;
  const items = () => [...bagian.querySelectorAll('[data-temuan]')];
  const sembunyi = bagian.querySelector('[data-sembunyikan-dicek]');
  const filterFile = bagian.querySelector('[data-filter-file]');
  const url = root.dataset.cekUrl;

  const progres = () => {
    const semua = items();
    const n = semua.filter((li) => li.dataset.dicek === '1').length;
    const el = bagian.querySelector('[data-temuan-dicek]');
    if (el) el.textContent = n;
    const bar = bagian.querySelector('[data-temuan-bar]');
    if (bar) {
      bar.setAttribute('aria-valuenow', n);
      bar.firstElementChild.style.width = `${semua.length ? Math.round((n / semua.length) * 100) : 0}%`;
    }
  };
  const saring = () => items().forEach((li) => {
    li.hidden = (sembunyi?.checked && li.dataset.dicek === '1') || (filterFile?.value && li.dataset.dokumen !== filterFile.value);
  });
  sembunyi?.addEventListener('change', saring);
  filterFile?.addEventListener('change', saring);

  const simpan = async (cb) => {
    const li = cb.closest('[data-temuan]');
    const ya = cb.checked;
    li.dataset.dicek = ya ? '1' : '0';
    progres();
    try {
      const r = await fetch(url, {
        method: ya ? 'POST' : 'DELETE',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
        body: JSON.stringify({ key: cb.dataset.cek }),
      });
      if (!r.ok) throw new Error(r.status);
    } catch (_) {
      cb.checked = !ya;
      li.dataset.dicek = ya ? '0' : '1';
      progres();
      const kabar = document.querySelector('[data-kabar]');
      if (kabar) kabar.textContent = 'Tanda cek gagal disimpan. Coba lagi.';
    }
    saring();
  };
  bagian.addEventListener('change', (e) => { if (e.target.matches('[data-cek]')) simpan(e.target); });

  return { items, simpan };
}

// ── Pintasan keyboard: J/K pindah temuan, X centang, ? bantuan ──
function initPintasan(temuan) {
  const bantuan = document.querySelector('[data-bantuan-pintasan]');
  document.querySelector('[data-buka-pintasan]')?.addEventListener('click', () => bantuan?.showModal());
  let fokus = -1;
  const pindah = (arah) => {
    const daftar = temuan.items().filter((li) => !li.hidden);
    if (!daftar.length) return;
    const kini = daftar.indexOf(document.activeElement.closest?.('[data-temuan]'));
    fokus = Math.min(daftar.length - 1, Math.max(0, (kini < 0 ? (arah > 0 ? -1 : daftar.length) : kini) + arah));
    daftar.forEach((li) => li.removeAttribute('data-fokus'));
    const li = daftar[fokus];
    li.setAttribute('data-fokus', '');
    li.focus({ preventScroll: true });
    li.scrollIntoView({ behavior: reduce() ? 'auto' : 'smooth', block: 'center' });
  };
  document.addEventListener('keydown', (e) => {
    if (e.ctrlKey || e.metaKey || e.altKey) return;
    if (e.key === 'Escape' && penampil?.tutupDrawer()) return;
    const t = e.target;
    if (t.closest?.('input, textarea, select, [contenteditable=""], [contenteditable="true"]') || document.querySelector('dialog[open]')) return;
    const k = e.key.toLowerCase();
    if (k === 'j' || k === 'k') { e.preventDefault(); pindah(k === 'j' ? 1 : -1); }
    else if (k === 'x') {
      const cb = document.activeElement.closest?.('[data-temuan]')?.querySelector('[data-cek]');
      if (cb && !cb.disabled) { e.preventDefault(); cb.checked = !cb.checked; temuan.simpan(cb); }
    } else if (e.key === '?') { e.preventDefault(); bantuan?.showModal(); }
  });
}

if (root) {
  initNav();
  initKepala();
  const temuan = initTemuan();
  if (temuan) initPintasan(temuan);
}
