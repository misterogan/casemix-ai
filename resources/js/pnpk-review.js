// Tinjau PNPK: pratinjau PDF (pdfjs-dist di-bundle) yang melompat ke halaman butir yang diklik.
import * as pdfjs from 'pdfjs-dist';
import workerUrl from 'pdfjs-dist/build/pdf.worker.min.mjs?url';

pdfjs.GlobalWorkerOptions.workerSrc = workerUrl;

const root = document.querySelector('[data-pnpk-review]');
if (root) {
  const canvas = root.querySelector('[data-canvas]');
  const info = root.querySelector('[data-info]');
  let doc = null;
  let hal = Math.max(1, parseInt(root.dataset.hal || '1', 10));
  let tugas = null;

  const render = async (n) => {
    if (!doc) return;
    hal = Math.min(Math.max(1, n), doc.numPages);
    const page = await doc.getPage(hal);
    const lebar = canvas.parentElement.clientWidth - 16;
    const vp0 = page.getViewport({ scale: 1 });
    const vp = page.getViewport({ scale: Math.max(0.5, lebar / vp0.width) * (window.devicePixelRatio || 1) });
    canvas.width = vp.width;
    canvas.height = vp.height;
    canvas.style.width = `${vp.width / (window.devicePixelRatio || 1)}px`;
    tugas?.cancel();
    tugas = page.render({ canvasContext: canvas.getContext('2d'), viewport: vp });
    try { await tugas.promise; } catch { /* dibatalkan */ }
    info.textContent = `hal. ${hal} / ${doc.numPages}`;
  };

  // Ambil PDF utuh lewat fetch (cookie sesi ikut, tanpa range request) lalu serahkan datanya ke pdf.js.
  fetch(root.dataset.pdfUrl, { credentials: 'same-origin', redirect: 'error', headers: { Accept: 'application/pdf' } })
    .then((r) => {
      if (!r.ok) throw new Error(`HTTP ${r.status} saat memuat PDF`);
      const tipe = r.headers.get('content-type') || '';
      if (!tipe.includes('application/pdf')) throw new Error(`Content-Type tidak terduga: ${tipe}`);
      return r.arrayBuffer();
    })
    .then((buf) => pdfjs.getDocument({ data: new Uint8Array(buf) }).promise)
    .then((d) => { doc = d; render(hal); })
    .catch((e) => {
      console.error('[pnpk-review] PDF tidak dapat dimuat:', e);
      info.textContent = 'PDF tidak dapat dimuat — pakai "Buka PDF"';
    });

  root.querySelector('[data-prev]').addEventListener('click', () => render(hal - 1));
  root.querySelector('[data-next]').addEventListener('click', () => render(hal + 1));
  root.addEventListener('click', (e) => {
    const b = e.target.closest('[data-goto]');
    if (b) render(parseInt(b.dataset.goto, 10));
  });
}
