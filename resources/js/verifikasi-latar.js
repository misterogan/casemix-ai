// Verifikasi latar belakang: polling status berkas (hanya selama ada yang antre/berjalan/menjeda) dan verifikasi massal.
const AKTIF = ['antre', 'berjalan', 'menjeda'];

function elemenAktif() {
  return [...document.querySelectorAll('[data-verif]')].filter((el) => AKTIF.includes(el.dataset.verifStatus));
}

function initPolling() {
  const awal = elemenAktif();
  if (!awal.length) return;
  const url = awal[0].dataset.verifUrl;
  const tick = async () => {
    const aktif = elemenAktif();
    if (!aktif.length) return; // semua selesai/dijeda: polling berhenti
    try {
      const ids = aktif.map((el) => el.dataset.verif).join(',');
      const r = await fetch(`${url}?ids=${encodeURIComponent(ids)}`, { headers: { Accept: 'application/json' }, cache: 'no-store' });
      if (!r.ok) return setTimeout(tick, 6000);
      const j = await r.json();
      j.berkas.forEach((b) => {
        document.querySelectorAll(`[data-verif="${b.id}"]`).forEach((el) => { el.outerHTML = b.html; });
      });
      if (j.aktif) setTimeout(tick, 3000);
    } catch {
      setTimeout(tick, 6000);
    }
  };
  setTimeout(tick, 3000);
}

function initMassal() {
  const form = document.getElementById('form-massal');
  if (!form) return;
  const tombol = form.querySelector('[data-verifikasi-terpilih]');
  const jumlah = form.querySelector('[data-jumlah-terpilih]');
  const semua = form.querySelector('[data-pilih-semua]');
  const kotak = () => [...document.querySelectorAll('input[data-pilih-berkas]')];
  const perbarui = () => {
    const n = kotak().filter((c) => c.checked).length;
    tombol.disabled = n === 0;
    jumlah.textContent = n ? `(${n})` : '';
    if (semua) semua.checked = n > 0 && n === kotak().length;
  };
  document.addEventListener('change', (e) => {
    if (e.target === semua) kotak().forEach((c) => { c.checked = semua.checked; });
    if (e.target === semua || e.target.matches?.('input[data-pilih-berkas]')) perbarui();
  });
  perbarui();

  let lolos = false;
  form.addEventListener('submit', async (e) => {
    if (lolos) return;
    e.preventDefault();
    const usd = (v) => (v === null || v === undefined ? 'tanpa batas' : `US$${Number(v).toFixed(2)}`);
    try {
      const r = await fetch(form.dataset.perkiraanUrl, { method: 'POST', body: new FormData(form), headers: { Accept: 'application/json' } });
      const j = await r.json();
      if (!r.ok) return window.alert(Object.values(j.errors || {}).flat()[0] || j.message || 'Gagal menghitung perkiraan.');
      if (!j.boleh) return window.alert(j.pesan);
      if (!j.diproses) return window.alert(`Tidak ada berkas yang perlu diverifikasi (${j.dilewati} dilewati: sudah selesai, sedang berjalan, atau tidak ada di FTP).`);
      let pesan = `${j.diproses} berkas akan diverifikasi di latar belakang`
        + (j.dilanjutkan ? ` (${j.dilanjutkan} dilanjutkan dari file yang belum selesai)` : '')
        + (j.dilewati ? `, ${j.dilewati} dilewati` : '') + '.\n'
        + `Perkiraan biaya: ${usd(j.perkiraan_usd)} (rata-rata ${usd(j.rata_usd)} per berkas). Sisa anggaran AI bulan ini: ${usd(j.sisa_usd)}.`;
      if (form.querySelector('[data-ulang]')?.checked) pesan += '\n\nBerkas yang sudah selesai akan DIANALISA ULANG dan memakai token AI lagi.';
      if (!window.confirm(`${pesan}\n\nLanjutkan?`)) return;
      lolos = true;
      tombol.disabled = true;
      form.submit();
    } catch {
      window.alert('Gagal menghitung perkiraan biaya. Coba lagi.');
    }
  });
}

initPolling();
initMassal();
