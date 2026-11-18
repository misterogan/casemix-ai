// Unggah dokumen manual: antrean file, unggah per file via XHR dengan progress, coba lagi per file,
// lalu "Unggah dan analisa" memicu analisa dan mengarah ke halaman folder.
const MAX = 25 * 1024 * 1024;
const MAX_FILES = 20;
const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';
const ukuran = (b) => (b >= 1048576 ? `${(b / 1048576).toLocaleString('id-ID', { maximumFractionDigits: 1 })} MB` : `${Math.max(1, Math.round(b / 1024))} KB`);
const tglId = (d) => (d ? new Date(`${d}T00:00:00`).toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' }) : '—');

function initForm(form, up) {
  const f = (n) => form.querySelector(`[data-field="${n}"]`);
  const existing = form.querySelector('[data-existing]');
  const info = form.querySelector('[data-vclaim]');
  up.meta = () => {
    const m = {};
    ['no_sep', 'no_rm', 'jenis_rawat', 'tgl_pulang', 'catatan'].forEach((n) => { if (f(n)?.value) m[n] = f(n).value.trim(); });
    return m;
  };
  up.validate = () => (!f('no_sep').value.trim() && !f('no_rm').value.trim() ? 'No. RM wajib diisi bila No. SEP kosong.' : null);
  const kunci = (n, on) => {
    const l = form.querySelector(`[data-lock="${n}"]`);
    if (l) { l.classList.toggle('hidden', !on); l.classList.toggle('inline-flex', on); }
  };
  form.querySelectorAll('[data-unlock]').forEach((b) => b.addEventListener('click', () => {
    const n = b.dataset.unlock;
    f(n).readOnly = false; f(n).disabled = false; kunci(n, false); f(n).focus();
  }));
  f('no_rm').addEventListener('input', () => up.onChange?.());
  let terakhir = '';
  f('no_sep').addEventListener('input', async () => {
    const sep = f('no_sep').value.trim();
    existing.hidden = true;
    info.hidden = true;
    const count = form.querySelector('[data-sep-count]');
    if (count) count.textContent = `${sep.length}/19`;
    ['no_rm', 'jenis_rawat', 'tgl_pulang'].forEach((n) => { f(n).readOnly = false; f(n).disabled = false; kunci(n, false); });
    up.onChange?.();
    if (sep.length !== 19 || sep === terakhir) return;
    terakhir = sep;
    try {
      const r = await fetch(`${form.dataset.lookupUrl}?no_sep=${encodeURIComponent(sep)}`, { headers: { Accept: 'application/json' } });
      if (!r.ok) return;
      const j = await r.json();
      if (j.folder) {
        existing.hidden = false;
        const a = existing.querySelector('[data-existing-link]');
        a.href = j.folder.url;
        a.textContent = j.folder.nama;
        existing.querySelector('[data-use-existing]').onclick = () => {
          up.folderId = String(j.folder.id);
          existing.querySelector('p').textContent = 'Dokumen akan ditambahkan ke folder yang sudah ada.';
          existing.querySelector('[data-use-existing]').hidden = true;
        };
      }
      if (j.vclaim) {
        if (j.vclaim.no_rm) { f('no_rm').value = j.vclaim.no_rm; f('no_rm').readOnly = true; kunci('no_rm', true); }
        if (j.vclaim.jenis_rawat) { f('jenis_rawat').value = j.vclaim.jenis_rawat; f('jenis_rawat').disabled = true; kunci('jenis_rawat', true); }
        if (j.vclaim.tgl_pulang) { f('tgl_pulang').value = j.vclaim.tgl_pulang; f('tgl_pulang').readOnly = true; kunci('tgl_pulang', true); }
        info.hidden = false;
        info.textContent = `Ditemukan di data BPJS · ${j.vclaim.jenis_rawat === 'jalan' ? 'Rawat jalan' : 'Rawat inap'} · pulang ${tglId(j.vclaim.tgl_pulang)}`;
        up.onChange?.();
      }
    } catch { /* abaikan: isian manual tetap bisa */ }
  });
}

function initUploader(root) {
  const list = root.querySelector('[data-list]');
  const tpl = root.querySelector('[data-item-template]');
  const submit = root.querySelector('[data-submit]');
  const pesan = root.querySelector('[data-pesan]');
  const up = { folderId: root.dataset.folderId || '', meta: () => ({}), validate: () => null, items: [] };
  const form = document.querySelector('[data-manual-form]');
  if (form && !up.folderId) initForm(form, up);
  queueMicrotask(() => up.onChange?.());

  const tampilPesan = (t) => { pesan.hidden = !t; pesan.textContent = t ?? ''; };
  const bar = root.querySelector('[data-status-bar]');
  const ringkas = root.querySelector('[data-ringkasan]');
  const label = (it, t, cls = 'text-abu') => { const el = it.el.querySelector('[data-status]'); if (el) { el.textContent = t; el.className = cls; } };
  const perbarui = () => {
    const ada = up.items.length > 0;
    submit.disabled = !ada || up.items.some((i) => i.state !== 'ok');
    if (ringkas) {
      ringkas.hidden = !ada;
      ringkas.textContent = `${up.items.length} file · ${ukuran(up.items.reduce((n, i) => n + i.file.size, 0))}`;
    }
    if (!bar) return;
    const sedang = up.items.filter((i) => i.state === 'uploading' || i.state === 'antre');
    const selesai = up.items.filter((i) => i.state === 'ok').length;
    const sepEl = form?.querySelector('[data-field="no_sep"]');
    const rmEl = form?.querySelector('[data-field="no_rm"]');
    let t;
    if (!up.folderId && sepEl && !sepEl.value.trim() && !rmEl.value.trim()) t = 'Isi No. SEP atau No. RM';
    else if (!ada) t = 'Tambahkan minimal 1 file';
    else if (sedang.length) t = `Mengunggah ${Math.min(selesai + 1, up.items.length)} dari ${up.items.length}…`;
    else if (up.items.some((i) => i.state === 'gagal')) t = 'Ada file gagal: coba lagi atau hapus dari daftar';
    else t = 'Siap dianalisa';
    bar.replaceChildren();
    if (sedang.length) { const sp = document.createElement('span'); sp.className = 'spinner text-brand'; sp.setAttribute('aria-hidden', 'true'); bar.append(sp); }
    bar.append(t);
  };
  up.onChange = perbarui;

  // Unggah berurutan: file pertama membuat folder, berikutnya memakai folder_id yang sama.
  let antre = Promise.resolve();
  const unggah = (it) => { antre = antre.then(() => kirim(it)); return antre; };

  const kirim = (it) => new Promise((resolve) => {
    if (!up.items.includes(it)) return resolve();
    const err = up.folderId ? null : up.validate();
    if (err) { tandaGagal(it, err); return resolve(); }
    it.state = 'uploading';
    label(it, 'Mengunggah 0%');
    it.el.querySelector('[data-retry]').hidden = true;
    it.el.querySelector('[data-error]').hidden = true;
    const fd = new FormData();
    fd.append('file', it.file);
    if (up.folderId) fd.append('folder_id', up.folderId);
    else {
      const m = up.meta();
      if (form) m.jenis_rawat = form.querySelector('[data-field="jenis_rawat"]').value;
      Object.entries(m).forEach(([k, v]) => fd.append(k, v));
    }
    const jenis = it.el.querySelector('[data-jenis]').value;
    if (jenis) fd.append('jenis_dokumen', jenis);
    const xhr = new XMLHttpRequest();
    xhr.open('POST', root.dataset.storeUrl);
    xhr.setRequestHeader('X-CSRF-TOKEN', csrf());
    xhr.setRequestHeader('Accept', 'application/json');
    xhr.upload.onprogress = (e) => { if (e.lengthComputable) { const pct = Math.round((e.loaded / e.total) * 100); it.el.querySelector('[data-bar]').style.width = `${pct}%`; label(it, `Mengunggah ${pct}%`); } };
    xhr.onload = () => {
      let j = {};
      try { j = JSON.parse(xhr.responseText); } catch { /* bukan JSON */ }
      if (j.folder_id) up.folderId = String(j.folder_id);
      if (xhr.status === 201) {
        it.state = 'ok';
        it.el.querySelector('[data-bar]').style.width = '100%';
        it.el.querySelector('[data-jenis]').disabled = true;
        it.el.querySelector('[data-bar]').classList.replace('bg-brand', 'bg-giok');
        label(it, '✓ Selesai', 'font-medium text-giok');
      } else {
        const first = j.errors ? Object.values(j.errors)[0]?.[0] : null;
        tandaGagal(it, first || j.message || `Gagal (${xhr.status}).`);
      }
      perbarui();
      resolve();
    };
    xhr.onerror = () => { tandaGagal(it, 'Koneksi terputus.'); perbarui(); resolve(); };
    xhr.send(fd);
    perbarui();
  });

  const tandaGagal = (it, t) => {
    it.state = 'gagal';
    label(it, 'Gagal', 'font-medium text-kritis');
    const e = it.el.querySelector('[data-error]');
    e.hidden = false;
    e.textContent = t;
    it.el.querySelector('[data-retry]').hidden = false;
    it.el.querySelector('[data-bar]').style.width = '0';
  };

  const tambah = (files) => {
    tampilPesan(null);
    for (const file of files) {
      if (up.items.length >= MAX_FILES) { tampilPesan('Maksimal 20 file per unggahan.'); break; }
      const el = tpl.content.firstElementChild.cloneNode(true);
      el.querySelector('[data-name]').textContent = file.name;
      el.querySelector('[data-size]').textContent = ukuran(file.size);
      if (file.type.startsWith('image/')) {
        const img = document.createElement('img');
        img.className = 'h-full w-full object-cover';
        img.alt = '';
        img.src = URL.createObjectURL(file);
        el.querySelector('[data-thumb]').replaceChildren(img);
      }
      const it = { file, el, state: 'antre' };
      el.querySelector('[data-jenis]').setAttribute('aria-label', `Jenis dokumen untuk ${file.name}`);
      el.querySelector('[data-remove]').setAttribute('aria-label', `Hapus ${file.name} dari antrean`);
      up.items.push(it);
      list.append(el);
      el.querySelector('[data-remove]').onclick = () => {
        if (it.state === 'uploading') return;
        up.items = up.items.filter((x) => x !== it);
        el.remove();
        perbarui();
      };
      el.querySelector('[data-retry]').onclick = () => unggah(it);
      if (/\.hei[cf]$/i.test(file.name) || /hei[cf]/i.test(file.type)) { tandaGagal(it, 'Ubah foto ke JPG dulu.'); continue; }
      if (file.size > MAX) { tandaGagal(it, 'Ukuran file melebihi 25 MB.'); continue; }
      unggah(it);
    }
    perbarui();
  };

  root.querySelectorAll('[data-input]').forEach((inp) => inp.addEventListener('change', (e) => { tambah([...e.target.files]); e.target.value = ''; }));
  const drop = root.querySelector('[data-drop]');
  const pilih = root.querySelector('[data-input]');
  // Seluruh dropzone bisa diklik dan dibuka dengan Enter/Spasi
  drop.addEventListener('click', (e) => { if (!e.target.closest('[data-pilih]')) pilih.click(); });
  drop.addEventListener('keydown', (e) => { if (e.target === drop && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); pilih.click(); } });
  ['dragenter', 'dragover'].forEach((t) => drop.addEventListener(t, (e) => { e.preventDefault(); drop.classList.add('is-drag'); }));
  ['dragleave', 'drop'].forEach((t) => drop.addEventListener(t, (e) => { e.preventDefault(); drop.classList.remove('is-drag'); }));
  drop.addEventListener('drop', (e) => tambah([...(e.dataTransfer?.files ?? [])]));

  submit.addEventListener('click', async () => {
    if (!up.folderId) return;
    submit.disabled = true;
    try {
      const r = await fetch(root.dataset.analyzeUrl.replace('__ID__', up.folderId), {
        method: 'POST', headers: { 'X-CSRF-TOKEN': csrf(), Accept: 'application/json' },
      });
      const j = await r.json();
      window.location.href = j.redirect;
    } catch {
      tampilPesan('Analisa gagal dimulai. Coba lagi.');
      submit.disabled = false;
    }
  });
}

document.querySelectorAll('[data-uploader]').forEach(initUploader);

// Dropzone panel Dokumen tambahan: buka uploader yang sama, lalu teruskan file (klik/tarik).
document.querySelectorAll('[data-manual-drop]').forEach((z) => {
  const panel = z.closest('[data-dokumen-tambahan]');
  const buka = () => {
    const t = panel.querySelector('[data-tambah]');
    t.hidden = false;
    const b = panel.querySelector('[data-buka-tambah]');
    if (b) b.hidden = true;
    return t.querySelector('[data-input]');
  };
  z.addEventListener('click', () => buka()?.click());
  z.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); buka()?.click(); } });
  ['dragenter', 'dragover'].forEach((t) => z.addEventListener(t, (e) => { e.preventDefault(); z.dataset.drag = ''; }));
  ['dragleave', 'drop'].forEach((t) => z.addEventListener(t, (e) => { e.preventDefault(); delete z.dataset.drag; }));
  z.addEventListener('drop', (e) => {
    const inp = buka();
    if (!inp || !e.dataTransfer?.files?.length) return;
    inp.files = e.dataTransfer.files;
    inp.dispatchEvent(new Event('change'));
  });
});
