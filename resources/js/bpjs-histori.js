// Panel "Riwayat pelayanan peserta": ambil JSON tanpa memuat ulang halaman.
// Delegasi di document agar tetap jalan setelah isi folder ditukar oleh polling.
const BATAS_MS = 45000;

const el = (tag, cls, text) => {
    const n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined && text !== null) n.textContent = String(text);
    return n;
};
const tgl = (d) => (d ? d.split('-').reverse().join('/') : '—');
const chip = (cls, text) => el('span', `chip ${cls}`, text);

function skeleton(wadah) {
    wadah.replaceChildren();
    wadah.setAttribute('aria-busy', 'true');
    const box = el('div', 'space-y-2');
    for (let i = 0; i < 4; i++) {
        box.append(el('div', 'h-5 rounded bg-abu-muda motion-safe:animate-pulse'));
    }
    wadah.append(box);
}

function gagal(wadah, pesan, ulang) {
    wadah.replaceChildren();
    const box = el('div', 'flex flex-wrap items-center justify-between gap-3 rounded-md border border-kritis/25 bg-kritis-muda px-3 py-2 text-xs text-kritis');
    box.setAttribute('role', 'alert');
    box.append(el('span', '', pesan));
    const b = el('button', 'btn-secondary text-xs', 'Coba lagi');
    b.type = 'button';
    b.addEventListener('click', ulang);
    box.append(b);
    wadah.append(box);
}

function render(wadah, d) {
    wadah.replaceChildren();
    const info = el('div', 'mb-3 flex flex-wrap items-center gap-2 text-xs text-ink-muted');
    info.append(el('span', '', `Diambil ${d.fetched_label}`));
    if (d.from_cache) info.append(chip('chip-belum', 'dari simpanan'));
    const per = el('button', 'text-xs font-medium text-brand hover:underline', 'Perbarui');
    per.type = 'button';
    per.dataset.historiPerbarui = '';
    info.append(per);
    wadah.append(info);

    const dl = el('dl', 'mb-3 grid grid-cols-4 gap-2 text-center');
    [['total', 'Total'], ['inap', 'Rawat inap'], ['jalan', 'Rawat jalan'], ['readmisi', 'Readmisi']].forEach(([k, l]) => {
        const c = el('div', 'rounded-md bg-paper px-2 py-1.5');
        c.append(el('dt', 'text-[11px] text-ink-muted', l), el('dd', 'tabular-nums font-semibold text-ink', d.ringkasan[k]));
        dl.append(c);
    });
    wadah.append(dl);

    if (!d.items.length) {
        wadah.append(el('p', 'text-xs text-ink-muted', `Tidak ada riwayat pelayanan dalam rentang ${tgl(d.rentang.mulai)}–${tgl(d.rentang.akhir)}.`));
        return;
    }
    const scroll = el('div', 'overflow-x-auto');
    const t = el('table', 'w-full text-xs');
    const head = el('tr');
    ['Tgl SEP', 'Tgl pulang', 'Jenis', 'PPK', 'Diagnosa', 'Tanda'].forEach((h, i, a) => head.append(el('th', `py-1 font-medium${i < a.length - 1 ? ' pr-2' : ''}`, h)));
    const thead = el('thead', 'text-left text-ink-muted');
    thead.append(head);
    const tbody = el('tbody', 'divide-y divide-gray-100');
    d.items.forEach((r) => {
        const tr = el('tr', r.sep_ini ? 'bg-brand-soft' : r.readmisi ? 'bg-kritis-muda' : '');
        if (r.readmisi) tr.dataset.readmisi = '';
        const td = (v, cls = 'py-1 pr-2') => el('td', cls, v);
        const ppk = td(r.ppk_pelayanan);
        if (r.rs_ini) ppk.append(' ', chip('chip-proses ml-1', 'RS ini'));
        const tanda = el('td', 'py-1');
        if (r.sep_ini) tanda.append(chip('chip-proses', 'SEP ini'));
        if (r.readmisi) tanda.append(chip('chip-kritis', 'Kemungkinan readmisi'));
        tr.append(td(tgl(r.tgl_sep), 'py-1 pr-2 tabular-nums'), td(tgl(r.tgl_plg_sep), 'py-1 pr-2 tabular-nums'), td(r.jns_pelayanan), ppk, td(r.diagnosa), tanda);
        tbody.append(tr);
    });
    t.append(thead, tbody);
    scroll.append(t);
    wadah.append(scroll);
}

async function ambil(panel, refresh) {
    const tombol = panel.querySelector('[data-histori-ambil]');
    const wadah = panel.querySelector('[data-histori-hasil]');
    if (!tombol || !wadah || tombol.disabled) return;
    const label = tombol.textContent;
    tombol.disabled = true;
    tombol.replaceChildren(el('span', 'inline-block h-3 w-3 rounded-full border-2 border-current border-t-transparent motion-safe:animate-spin'), 'Mengambil riwayat…');
    skeleton(wadah);

    const ctl = new AbortController();
    const timer = setTimeout(() => ctl.abort(), BATAS_MS);
    try {
        const body = new FormData();
        if (refresh) body.append('refresh', '1');
        const res = await fetch(panel.dataset.url, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body,
            credentials: 'same-origin',
            signal: ctl.signal,
        });
        const d = await res.json().catch(() => ({}));
        if (!res.ok || !d.ok) throw new Error(d.message || `Gagal mengambil riwayat (${res.status}).`);
        render(wadah, d);
    } catch (e) {
        gagal(wadah, e.name === 'AbortError' ? 'Waktu habis, coba lagi.' : e.message, () => ambil(panel, refresh));
    } finally {
        clearTimeout(timer);
        wadah.removeAttribute('aria-busy');
        tombol.textContent = label;
        tombol.disabled = false;
    }
}

if (!window.__bpjsHistori) {
    window.__bpjsHistori = true;
    document.addEventListener('click', (e) => {
        const t = e.target instanceof Element ? e.target : null;
        const panel = t?.closest('[data-histori]');
        if (!panel) return;
        if (t.closest('[data-histori-ambil]')) ambil(panel, false);
        else if (t.closest('[data-histori-perbarui]')) ambil(panel, true);
    });
}
