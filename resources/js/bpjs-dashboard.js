import Chart, { palet } from './chart-theme.js';

const el = document.getElementById('bpjs-data');
if (el) {
    const data = JSON.parse(el.textContent);
    const warna = {
        proses: palet.waspada,
        pending: palet.galat,
        tidak_layak: '#7F1D1D',
        dispute: '#6B21A8',
        klaim: palet.sukses,
        lainnya: '#98A2B3',
        netral: '#667085',
    };
    const label = data.status_label || { proses: 'Proses verifikasi', pending: 'Pending verifikasi', klaim: 'Klaim layak' };

    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        Chart.defaults.animation = false;
    }

    const rupiah = new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 });
    const angka = new Intl.NumberFormat('id-ID');
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

    const t = data.tren;

    // Sparkline nilai klaim layak 12 bulan pada kartu hero.
    const spark = document.getElementById('chart-spark');
    if (spark) {
        new Chart(spark, {
            type: 'line',
            data: { labels: t.label, datasets: [{ data: t.diajukan, borderColor: palet.brand, backgroundColor: 'rgba(70,95,255,.12)', fill: true, pointRadius: 0 }] },
            options: {
                maintainAspectRatio: false,
                scales: { x: { display: false }, y: { display: false, beginAtZero: true } },
                plugins: { legend: { display: false }, tooltip: { callbacks: { label: (c) => rupiah.format(c.raw) } } },
                interaction: { mode: 'index', intersect: false },
            },
        });
    }

    const donut = document.getElementById('chart-status');
    if (donut) {
        const d = data.donut_status;
        const total = d.counts.reduce((a, b) => a + b, 0);
        new Chart(donut, {
            type: 'doughnut',
            data: { labels: d.labels, datasets: [{ data: d.counts, backgroundColor: d.keys.map((k) => warna[k]), borderWidth: 2 }] },
            options: {
                cutout: '72%',
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
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
    }

    const trenEl = document.getElementById('chart-tren');
    if (trenEl) {
        const keys = data.status_keys;
        const potong = (n) => (arr) => arr.slice(-n);
        const buat = (n) => {
            const p = potong(n);
            const geser = t.label.length - n;
            const datasets = keys.map((k) => ({
                type: 'bar', label: label[k], data: p(t[k]), backgroundColor: warna[k], stack: 'status', yAxisID: 'y', order: 2, borderRadius: 4,
            }));
            datasets.push({
                type: 'line', label: '% pending', data: p(t.pct_pending), borderColor: warna.netral, backgroundColor: warna.netral,
                borderDash: [4, 3], pointRadius: 3, yAxisID: 'y1', order: 1,
            });
            const gl = t.go_live !== null && t.go_live !== undefined ? t.go_live - geser : -1;
            if (gl >= 0) {
                const maks = Math.max(1, ...t.label.map((_, i) => keys.reduce((a, k) => a + t[k][i], 0)));
                const x = t.label[t.go_live];
                datasets.push({
                    type: 'line', label: 'CERMAT mulai', data: [{ x, y: 0 }, { x, y: maks }], borderColor: palet.brand,
                    borderWidth: 2, pointRadius: 0, yAxisID: 'y', order: 0, isGoLive: true,
                });
            }
            return { labels: p(t.label), datasets, geser, gl };
        };
        let isi = buat(12);
        const chart = new Chart(trenEl, {
            data: { labels: isi.labels, datasets: isi.datasets },
            options: {
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                scales: {
                    x: { stacked: true, grid: { display: false } },
                    y: { stacked: true, beginAtZero: true },
                    y1: { position: 'right', beginAtZero: true, max: 100, grid: { drawOnChartArea: false }, ticks: { callback: (v) => v + '%' } },
                },
                plugins: {
                    legend: { position: 'bottom' },
                    tooltip: {
                        filter: (i) => !i.dataset.isGoLive,
                        callbacks: {
                            label: (c) => c.dataset.yAxisID === 'y1'
                                ? `% pending: ${String(c.raw).replace('.', ',')}%`
                                : `${c.dataset.label}: ${angka.format(c.raw)}`,
                            footer: (items) => {
                                const i = items[0]?.dataIndex;
                                const asli = i + isi.geser;
                                const tot = keys.reduce((a, k) => a + t[k][asli], 0);
                                return `Total: ${angka.format(tot)}` + (asli === t.go_live ? ' · CERMAT mulai' : '');
                            },
                        },
                    },
                },
                onClick: (e, _els, c) => {
                    const hit = c.getElementsAtEventForMode(e, 'nearest', { intersect: true }, true)
                        .find((h) => c.data.datasets[h.datasetIndex].type === 'bar');
                    if (hit) pergi(t.links[hit.index + isi.geser][hit.datasetIndex]);
                },
            },
        });

        // Tab kecil 6 | 12 bulan.
        document.querySelectorAll('[data-tren-rentang] [data-rentang]').forEach((btn) => {
            btn.addEventListener('click', () => {
                isi = buat(Number(btn.dataset.rentang));
                chart.data.labels = isi.labels;
                chart.data.datasets = isi.datasets;
                chart.update();
                btn.parentElement.querySelectorAll('[data-rentang]').forEach((b) => {
                    const aktif = b === btn;
                    b.setAttribute('aria-pressed', String(aktif));
                    ['bg-white', 'font-medium', 'text-gray-800', 'shadow-xs', 'dark:bg-gray-900', 'dark:text-white/90'].forEach((k) => b.classList.toggle(k, aktif));
                    b.classList.toggle('text-gray-500', !aktif);
                });
            });
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
                    ['bg-white', 'font-medium', 'text-gray-800', 'shadow-xs', 'dark:bg-gray-900', 'dark:text-white/90'].forEach((k) => b.classList.toggle(k, aktif));
                    b.classList.toggle('text-gray-500', !aktif);
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
                scales: { x: { grid: { display: false } }, y: { beginAtZero: true, ...opsiY } },
                plugins: { legend: { position: 'bottom' } },
            },
        });
    };

    if (data.los) {
        garis('chart-los', data.los.label, [
            { label: 'Median (hari)', data: data.los.median, borderColor: palet.brand, backgroundColor: palet.brand },
            { label: 'Rata-rata (hari)', data: data.los.rata, borderColor: palet.brandMuda, backgroundColor: palet.brandMuda, borderDash: [4, 3] },
        ]);
    }
    if (data.readmisi) {
        garis('chart-readmisi', data.readmisi.label, [
            { label: '% readmisi ≤30 hari', data: data.readmisi.pct, borderColor: palet.waspada, backgroundColor: palet.waspada },
        ], { ticks: { callback: (v) => v + '%' } });
    }
}
