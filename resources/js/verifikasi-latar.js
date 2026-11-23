// Verifikasi latar belakang: polling status berkas (hanya selama ada yang antre/berjalan/menjeda) dan verifikasi massal.
const AKTIF = ['antre', 'berjalan', 'menjeda'];

function elemenAktif() {
  return [...document.querySelectorAll('[data-verif]')].filter((el) => AKTIF.includes(el.dataset.verifStatus));
}

// Polling selalu hidup: tiap 3 detik memindai DOM; hanya memanggil server bila ada berkas antre/berjalan/menjeda.
// Jadi baris yang baru menjadi "menjeda" (atau dimuat lewat pencarian) tetap diikuti sampai "dijeda" dan tombolnya berganti.
function ganti(id, html) {
  document.querySelectorAll(`[data-verif="${id}"]`).forEach((el) => { el.outerHTML = html; });
}

function initPolling() {
  const tick = async () => {
    const aktif = elemenAktif();
    if (aktif.length) {
      try {
        const ids = [...new Set(aktif.map((el) => el.dataset.verif))].join(',');
        const r = await fetch(`${aktif[0].dataset.verifUrl}?ids=${encodeURIComponent(ids)}`, { headers: { Accept: 'application/json' }, cache: 'no-store' });
        if (r.ok) (await r.json()).berkas.forEach((b) => ganti(b.id, b.html));
      } catch { /* coba lagi di putaran berikutnya */ }
    }
    setTimeout(tick, 3000);
  };
  setTimeout(tick, 3000);
}

// Tombol Jeda/Lanjutkan: kirim lewat fetch, ganti status + tombol langsung (tanpa memuat ulang). Tanpa JS: form POST biasa.
function initAksi() {
  document.addEventListener('submit', async (e) => {
    const form = e.target.closest?.('form[data-verif-aksi]');
    if (!form) return;
    e.preventDefault();
    const wadah = form.closest('[data-verif]');
    form.querySelector('button')?.setAttribute('disabled', '');
    try {
      const r = await fetch(form.action, { method: 'POST', body: new FormData(form), headers: { Accept: 'application/json' } });
      const j = await r.json();
      if (!r.ok) throw new Error(j.message || r.status);
      // Halaman detail: setelah Lanjutkan muat ulang supaya daftar file ikut dipantau (GET detail tidak memulai apa pun)
      if (j.ok && form.hasAttribute('data-lanjut') && document.querySelector('[data-docs]')) return window.location.reload();
      if (wadah) ganti(wadah.dataset.verif, j.html);
      if (!j.ok && j.pesan) window.alert(j.pesan);
    } catch {
      form.submit();
    }
  });
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
initAksi();
initMassal();
