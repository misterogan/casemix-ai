// Indikator pindah halaman: bilah progres langsung, overlay robot bila > 350 ms (min. tampil 400 ms).
// Opt-out: data-no-loader pada tautan/form. Permintaan fetch/XHR tidak memicu.
const TEKS = ['Menyiapkan halaman…', 'Membaca data klaim…', 'Merapikan angka…'];
const bilah = document.querySelector('[data-muat-bilah]');
const overlay = document.querySelector('[data-muat-overlay]');
const teks = overlay?.querySelector('[data-muat-teks]');
let tunda, maju, ganti, aman, sejak = 0, lebar = 0;

function bersih() {
  [tunda, maju, aman].forEach(clearTimeout);
  clearInterval(ganti);
}

function sembunyi() {
  bersih();
  if (!bilah || !overlay) return;
  const sisa = sejak ? Math.max(0, 400 - (Date.now() - sejak)) : 0;
  setTimeout(() => {
    overlay.hidden = true;
    sejak = 0;
    bilah.style.width = '100%';
    setTimeout(() => { bilah.classList.remove('aktif'); bilah.style.width = '0'; lebar = 0; }, 250);
  }, sisa);
}

function mulai() {
  if (!bilah || !overlay) return;
  bersih();
  lebar = 8;
  bilah.classList.add('aktif');
  bilah.style.width = `${lebar}%`;
  const langkah = () => {
    lebar = Math.min(90, lebar + (90 - lebar) * 0.15);
    bilah.style.width = `${lebar}%`;
    if (lebar < 89.5) maju = setTimeout(langkah, 300);
  };
  maju = setTimeout(langkah, 100);
  tunda = setTimeout(() => {
    overlay.hidden = false;
    sejak = Date.now();
    let i = 0;
    if (teks) teks.textContent = TEKS[0];
    ganti = setInterval(() => { if (teks) teks.textContent = TEKS[++i % TEKS.length]; }, 1500);
  }, 350);
  aman = setTimeout(() => {
    clearInterval(ganti);
    if (teks) teks.textContent = 'Masih memuat… periksa koneksi';
    setTimeout(sembunyi, 2500);
  }, 20000);
}

document.addEventListener('click', (e) => {
  if (e.defaultPrevented || e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return;
  const a = e.target.closest('a[href]');
  if (!a || a.closest('[data-no-loader]') || a.hasAttribute('download') || a.hasAttribute('data-csv')) return;
  if ((a.target && a.target !== '_self') || a.hasAttribute('data-preview') || a.hasAttribute('data-partial')) return;
  const url = new URL(a.href, location.href);
  if (url.origin !== location.origin || !/^https?:$/.test(url.protocol)) return;
  if (url.hash && url.pathname === location.pathname && url.search === location.search) return;
  if (a.getAttribute('href').startsWith('#') || /\.csv($|\?)|\/csv\b|unduh|download/i.test(url.pathname)) return;
  mulai();
});

// Didaftarkan saat bubble, setelah handler konfirmasi; form yang dicegah (fetch/konfirmasi batal) diabaikan.
document.addEventListener('submit', (e) => {
  const f = e.target;
  if (f.closest('[data-no-loader]') || f.hasAttribute('data-cari-form') || (f.target && f.target !== '_self')) return;
  const aksi = (e.submitter?.getAttribute('formaction') || f.action || '').toLowerCase();
  if (/csv|unduh|download/.test(aksi)) return;
  setTimeout(() => { if (!e.defaultPrevented) mulai(); }, 0);
});

window.addEventListener('pageshow', sembunyi);
// Bila beforeunload dibatalkan (dialog "Tetap di halaman"), sembunyikan. Handler ini terdaftar paling awal,
// jadi pemeriksaan ditunda sampai semua handler lain dan dialog selesai.
window.addEventListener('beforeunload', (e) => {
  setTimeout(() => { if (e.defaultPrevented || e.returnValue) sembunyi(); }, 0);
});
