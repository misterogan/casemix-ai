// Status analisa di halaman folder: polling tiap 3 detik selama antre/berjalan.
// "Sedang dianalisa…" hanya tampil di panel hasil; saat selesai/gagal hasil dirender tanpa memuat ulang halaman.
function init(el) {
  const panel = document.getElementById('detail-folder');
  if (!panel) return;
  const tick = async () => {
    try {
      const r = await fetch(el.dataset.statusAnalisaUrl, { headers: { Accept: 'application/json' }, cache: 'no-store' });
      if (!r.ok) return setTimeout(tick, 6000);
      const j = await r.json();
      if (j.active) {
        el.hidden = false;
        el.textContent = 'Sedang dianalisa…';
        return setTimeout(tick, 3000);
      }
      el.hidden = true;
      if (j.html) panel.innerHTML = j.html;
    } catch {
      setTimeout(tick, 6000);
    }
  };
  if (el.dataset.active === '1') {
    el.hidden = false;
    tick();
  }
}

document.querySelectorAll('[data-status-analisa-url]').forEach(init);
