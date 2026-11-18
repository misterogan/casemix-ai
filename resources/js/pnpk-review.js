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

  pdfjs.getDocument({ url: root.dataset.pdfUrl, withCredentials: true }).promise
    .then((d) => { doc = d; render(hal); })
    .catch(() => { info.textContent = 'PDF tidak dapat dimuat'; });

  root.querySelector('[data-prev]').addEventListener('click', () => render(hal - 1));
  root.querySelector('[data-next]').addEventListener('click', () => render(hal + 1));
  root.addEventListener('click', (e) => {
    const b = e.target.closest('[data-goto]');
    if (b) render(parseInt(b.dataset.goto, 10));
  });
}
