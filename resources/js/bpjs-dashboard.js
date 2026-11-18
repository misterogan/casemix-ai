import Chart from 'chart.js/auto';

const el = document.getElementById('bpjs-data');
if (el) {
    const data = JSON.parse(el.textContent);
    const css = getComputedStyle(document.documentElement);
    const token = (name, fallback) => css.getPropertyValue(name).trim() || fallback;
    const warna = {
        proses: token('--color-triage-amber', '#D99A0B'),
        pending: token('--color-triage-red', '#C8352E'),
        klaim: token('--color-triage-green', '#1E8A5A'),
        netral: token('--color-ink-muted', '#5B6880'),
    };
    const label = { proses: 'Proses verifikasi', pending: 'Pending verifikasi', klaim: 'Klaim layak' };

    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        Chart.defaults.animation = false;
    }
    Chart.defaults.font.family = css.getPropertyValue('font-family') || Chart.defaults.font.family;

    const rupiah = new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 });
    const angka = new Intl.NumberFormat('id-ID');
    const ringkas = (v) => {
        const a = Math.abs(v);
        if (a >= 1e9) return angka.format(+(v / 1e9).toFixed(1)) + ' M';
        if (a >= 1e6) return angka.format(+(v / 1e6).toFixed(1)) + ' jt';
        if (a >= 1e3) return angka.format(+(v / 1e3).toFixed(0)) + ' rb';
        return angka.format(v);
    };
    const pergi = (url) => { if (url) window.location.href = url; };

    // Tombol "Lihat tabel": tampilkan data grafik sebagai tabel.
    document.querySelectorAll('[data-lihat-tabel]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const tabel = document.getElementById(btn.getAttribute('aria-controls'));
            const buka = tabel.hidden;
            tabel.hidden = !buka;
            btn.setAttribute('aria-expanded', String(buka));
            btn.textContent = buka ? 'Sembunyikan tabel' : 'Lihat tabel';
        });
    });

    const donut = (id, d, colors) => {
        const canvas = document.getElementById(id);
        if (!canvas) return;
        const total = d.counts.reduce((a, b) => a + b, 0);
        new Chart(canvas, {
            type: 'doughnut',
            data: { labels: d.labels, datasets: [{ data: d.counts, backgroundColor: colors, borderWidth: 2 }] },
            options: {
                cutout: '65%',
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom' },
                    tooltip: {
                        callbacks: {
                            label: (c) => {
                                const pct = total ? ((c.raw * 100) / total).toFixed(1).replace('.', ',') : '0';
                                return `${c.label}: ${angka.format(c.raw)} (${pct}%) · ${rupiah.format(d.rp[c.dataIndex])}`;
                            },
                        },
                    },
                },
                onClick: (e, els) => { if (els.length) pergi(d.links[els[0].index]); },
                onHover: (e, els) => { e.native.target.style.cursor = els.length ? 'pointer' : 'default'; },
            },
        });
    };

    donut('chart-status', data.donut_status, data.donut_status.keys.map((k) => warna[k]));
    donut('chart-jenis', data.donut_jenis, [warna.netral, token('--color-brand', '#1A5FD0')]);

    const t = data.tren;
    const tren = document.getElementById('chart-tren');
    if (tren) {
        const keys = data.status_keys;
        const datasets = keys.map((k) => ({
            type: 'bar', label: label[k], data: t[k], backgroundColor: warna[k], stack: 'status', yAxisID: 'y', order: 2,
        }));
        datasets.push({
            type: 'line', label: '% pending', data: t.pct_pending, borderColor: warna.netral, backgroundColor: warna.netral,
            borderDash: [4, 3], pointRadius: 3, yAxisID: 'y1', order: 1,
        });
        if (t.go_live !== null && t.go_live !== undefined) {
            const maks = Math.max(1, ...t.label.map((_, i) => keys.reduce((a, k) => a + t[k][i], 0)));
            const x = t.label[t.go_live];
            datasets.push({
                type: 'line', label: 'CERMAT mulai', data: [{ x, y: 0 }, { x, y: maks }], borderColor: token('--color-brand', '#1A5FD0'),
                borderWidth: 2, pointRadius: 0, yAxisID: 'y', order: 0, isGoLive: true,
            });
        }
        new Chart(tren, {
            data: { labels: t.label, datasets },
            options: {
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                scales: {
                    x: { stacked: true },
                    y: { stacked: true, beginAtZero: true, title: { display: true, text: 'Jumlah klaim' } },
                    y1: { position: 'right', beginAtZero: true, max: 100, grid: { drawOnChartArea: false },
                        ticks: { callback: (v) => v + '%' }, title: { display: true, text: '% pending' } },
                },
                plugins: {
                    tooltip: {
                        filter: (i) => !i.dataset.isGoLive,
                        callbacks: {
                            label: (c) => c.dataset.yAxisID === 'y1'
                                ? `% pending: ${String(c.raw).replace('.', ',')}%`
                                : `${c.dataset.label}: ${angka.format(c.raw)}`,
                            footer: (items) => {
                                const i = items[0]?.dataIndex;
                                const tot = keys.reduce((a, k) => a + t[k][i], 0);
                                return `Total: ${angka.format(tot)}` + (i === t.go_live ? ' · CERMAT mulai' : '');
                            },
                        },
                    },
                },
                onClick: (e, _els, chart) => {
                    const hit = chart.getElementsAtEventForMode(e, 'nearest', { intersect: true }, true)
                        .find((h) => chart.data.datasets[h.datasetIndex].type === 'bar');
                    if (hit) pergi(t.links[hit.index][hit.datasetIndex]);
                },
            },
        });
    }

    const nilai = document.getElementById('chart-nilai');
    if (nilai) {
        new Chart(nilai, {
            type: 'bar',
            data: {
                labels: t.label,
                datasets: [
                    { label: 'Diajukan', data: t.diajukan, backgroundColor: warna.netral },
                    { label: 'Disetujui', data: t.disetujui, backgroundColor: warna.klaim },
                ],
            },
            options: {
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                scales: { y: { beginAtZero: true, ticks: { callback: (v) => ringkas(v) } } },
                plugins: { tooltip: { callbacks: { label: (c) => `${c.dataset.label}: ${rupiah.format(c.raw)}` } } },
            },
        });
    }

    // Toggle Jumlah | Nilai pada daftar INA-CBG teratas.
    document.querySelectorAll('[data-toggle-grup]').forEach((grup) => {
        grup.querySelectorAll('[data-toggle]').forEach((btn) => {
            btn.addEventListener('click', () => {
                const pilih = btn.dataset.toggle;
                grup.querySelectorAll('[data-toggle]').forEach((b) => {
                    const aktif = b === btn;
                    b.setAttribute('aria-pressed', String(aktif));
                    b.classList.toggle('bg-brand-soft', aktif);
                    b.classList.toggle('text-brand', aktif);
                    b.classList.toggle('font-medium', aktif);
                    b.classList.toggle('text-ink-muted', !aktif);
                });
                grup.querySelectorAll('[data-toggle-isi]').forEach((isi) => { isi.hidden = isi.dataset.toggleIsi !== pilih; });
            });
        });
    });

    const garis = (id, labels, sets, opsiY = {}) => {
        const canvas = document.getElementById(id);
        if (!canvas || !labels) return;
        new Chart(canvas, {
            type: 'line',
            data: { labels, datasets: sets.map((s) => ({ pointRadius: 3, spanGaps: true, ...s })) },
            options: {
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                scales: { y: { beginAtZero: true, ...opsiY } },
            },
        });
    };

    if (data.los) {
        garis('chart-los', data.los.label, [
            { label: 'Median (hari)', data: data.los.median, borderColor: token('--color-brand', '#1A5FD0'), backgroundColor: token('--color-brand', '#1A5FD0') },
            { label: 'Rata-rata (hari)', data: data.los.rata, borderColor: warna.netral, backgroundColor: warna.netral, borderDash: [4, 3] },
        ]);
    }
    if (data.readmisi) {
        garis('chart-readmisi', data.readmisi.label, [
            { label: '% readmisi ≤30 hari', data: data.readmisi.pct, borderColor: warna.proses, backgroundColor: warna.proses },
        ], { ticks: { callback: (v) => v + '%' } });
    }
}
