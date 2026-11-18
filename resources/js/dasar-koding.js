// Panel Dasar koding & Dasar sanggahan: catat audit saat menyalin, susun draf sanggahan lewat AI (JSON), lalu edit/unduh.
const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';
const kirim = (url, body) => fetch(url, {
  method: 'POST',
  credentials: 'same-origin',
  headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' },
  body: JSON.stringify(body ?? {}),
});

// Salin dasar koding: penyalinan oleh handler [data-salin] global; di sini hanya dicatat di audit.
document.addEventListener('click', (e) => {
  const b = e.target.closest('[data-salin-audit]');
  if (b) kirim(b.dataset.salinAudit).catch(() => {});
});

document.addEventListener('click', async (e) => {
  const b = e.target.closest('[data-draf-susun]');
  if (!b) return;
  const wadah = b.closest('[data-draf]');
  const galat = wadah.querySelector('[data-draf-galat]');
  const hasil = wadah.querySelector('[data-draf-hasil]');
  const label = b.textContent;
  b.disabled = true;
  b.textContent = 'Menyusun draf…';
  galat.hidden = true;
  try {
    const r = await kirim(wadah.dataset.drafUrl, { butir: JSON.parse(b.dataset.butir || '[]') });
    const j = await r.json().catch(() => ({}));
    if (!r.ok) throw new Error(j.errors?.butir?.[0] || j.message || `HTTP ${r.status}`);
    wadah.querySelector('[data-draf-teks]').value = j.teks;
    hasil.hidden = false;
    wadah.querySelector('[data-draf-teks]').focus();
  } catch (err) {
    galat.textContent = err.message;
    galat.hidden = false;
  } finally {
    b.disabled = false;
    b.textContent = label;
  }
});
