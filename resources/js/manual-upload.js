// Unggah dokumen manual: antrean file, unggah per file via XHR dengan progress, coba lagi per file,
// lalu "Unggah dan analisa" memicu analisa dan mengarah ke halaman folder.
const MAX = 25 * 1024 * 1024;
const MAX_FILES = 20;
const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';
const ukuran = (b) => (b >= 1048576 ? `${(b / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(b / 1024))} KB`);

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
  let terakhir = '';
  f('no_sep').addEventListener('input', async () => {
    const sep = f('no_sep').value.trim();
    existing.hidden = true;
    info.hidden = true;
    ['no_rm', 'jenis_rawat', 'tgl_pulang'].forEach((n) => { f(n).readOnly = false; f(n).disabled = false; });
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
        if (j.vclaim.no_rm) { f('no_rm').value = j.vclaim.no_rm; f('no_rm').readOnly = true; }
        if (j.vclaim.jenis_rawat) { f('jenis_rawat').value = j.vclaim.jenis_rawat; f('jenis_rawat').disabled = true; }
        if (j.vclaim.tgl_pulang) { f('tgl_pulang').value = j.vclaim.tgl_pulang; f('tgl_pulang').readOnly = true; }
        info.hidden = false;
        info.textContent = `Data VClaim: RM ${j.vclaim.no_rm ?? '—'} · ${j.vclaim.jenis_rawat === 'inap' ? 'Rawat inap' : 'Rawat jalan'} · pulang ${j.vclaim.tgl_pulang ?? '—'} (terkunci)`;
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

  const tampilPesan = (t) => { pesan.hidden = !t; pesan.textContent = t ?? ''; };
  const perbarui = () => {
    const ada = up.items.length > 0;
    submit.disabled = !ada || up.items.some((i) => i.state !== 'ok');
  };

  // Unggah berurutan: file pertama membuat folder, berikutnya memakai folder_id yang sama.
  let antre = Promise.resolve();
  const unggah = (it) => { antre = antre.then(() => kirim(it)); return antre; };

  const kirim = (it) => new Promise((resolve) => {
    if (!up.items.includes(it)) return resolve();
    const err = up.folderId ? null : up.validate();
    if (err) { tandaGagal(it, err); return resolve(); }
    it.state = 'uploading';
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
    xhr.upload.onprogress = (e) => { if (e.lengthComputable) it.el.querySelector('[data-bar]').style.width = `${Math.round((e.loaded / e.total) * 100)}%`; };
    xhr.onload = () => {
      let j = {};
      try { j = JSON.parse(xhr.responseText); } catch { /* bukan JSON */ }
      if (j.folder_id) up.folderId = String(j.folder_id);
      if (xhr.status === 201) {
        it.state = 'ok';
        it.el.querySelector('[data-bar]').style.width = '100%';
        it.el.querySelector('[data-jenis]').disabled = true;
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

  root.querySelector('[data-input]').addEventListener('change', (e) => { tambah([...e.target.files]); e.target.value = ''; });
  const drop = root.querySelector('[data-drop]');
  ['dragenter', 'dragover'].forEach((t) => drop.addEventListener(t, (e) => { e.preventDefault(); drop.classList.add('border-biru-aktif'); }));
  ['dragleave', 'drop'].forEach((t) => drop.addEventListener(t, (e) => { e.preventDefault(); drop.classList.remove('border-biru-aktif'); }));
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
