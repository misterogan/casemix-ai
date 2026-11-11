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

// Polling status pemeriksaan AI: <div data-poll="url">
document.addEventListener('DOMContentLoaded', () => {
  const el = document.querySelector('[data-poll]');
  if (!el) return;
  const tick = async () => {
    try {
      const r = await fetch(el.dataset.poll, { headers: { Accept: 'application/json' } });
      const j = await r.json();
      if (!j.active) return window.location.reload();
    } catch (_) { /* coba lagi */ }
    setTimeout(tick, 4000);
  };
  setTimeout(tick, 4000);
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
