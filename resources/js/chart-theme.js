// Tema Chart.js bersama: font Outfit, grid gray-100 tanpa garis sumbu, bar radius 6 (maks 24px),
// tooltip putih ber-border radius 8. Ikut mode gelap (event 'tema-berubah' dari app.js).
import Chart from 'chart.js/auto';

export const palet = {
    brand: '#465FFF',
    brandMuda: '#9CB9FF',
    sukses: '#12B76A',
    waspada: '#F79009',
    galat: '#F04438',
};

const gelap = () => document.documentElement.classList.contains('dark');

function warna() {
    return gelap()
        ? { teks: '#98A2B3', grid: '#1D2939', tipBg: '#101828', tipBorder: '#344054', tipJudul: '#F2F4F7', tipIsi: '#D0D5DD' }
        : { teks: '#667085', grid: '#F2F4F7', tipBg: '#FFFFFF', tipBorder: '#E4E7EC', tipJudul: '#1D2939', tipIsi: '#475467' };
}

function tooltip(w) {
    return {
        backgroundColor: w.tipBg, borderColor: w.tipBorder, borderWidth: 1, cornerRadius: 8, padding: 12,
        titleColor: w.tipJudul, bodyColor: w.tipIsi, footerColor: w.tipIsi, boxPadding: 4, usePointStyle: true,
        titleFont: { family: Chart.defaults.font.family, weight: '600' }, bodyFont: { family: Chart.defaults.font.family },
        caretSize: 0, displayColors: true,
    };
}

function skala(s, w) {
    if (!s) return;
    s.grid = { ...(s.grid || {}), color: w.grid, tickColor: 'transparent' };
    if (s.grid.drawOnChartArea === false) s.grid.color = 'transparent';
    s.border = { ...(s.border || {}), display: false };
    s.ticks = { ...(s.ticks || {}), color: w.teks, padding: 8 };
    if (s.title) s.title = { ...s.title, color: w.teks };
}

export function terapkanDefaults() {
    const w = warna();
    Chart.defaults.font.family = "'Outfit Variable', system-ui, sans-serif";
    Chart.defaults.font.size = 12;
    Chart.defaults.color = w.teks;
    Chart.defaults.borderColor = w.grid;
    Chart.defaults.elements.bar.borderRadius = 6;
    Chart.defaults.elements.bar.borderSkipped = false;
    Chart.defaults.datasets.bar.maxBarThickness = 24;
    Chart.defaults.elements.line.borderWidth = 2;
    Chart.defaults.elements.line.tension = 0.35;
    Chart.defaults.elements.arc.borderColor = gelap() ? '#101828' : '#FFFFFF';
    Chart.defaults.plugins.legend.labels.usePointStyle = true;
    Chart.defaults.plugins.legend.labels.pointStyle = 'circle';
    Chart.defaults.plugins.legend.labels.boxWidth = 8;
    Chart.defaults.plugins.legend.labels.boxHeight = 8;
    Chart.defaults.plugins.legend.labels.color = w.teks;
    Object.assign(Chart.defaults.plugins.tooltip, tooltip(w));
    for (const k of ['linear', 'category', 'time', 'logarithmic']) {
        const d = Chart.defaults.scales[k];
        if (!d) continue;
        d.grid = { ...(d.grid || {}), color: w.grid, tickColor: 'transparent' };
        d.border = { ...(d.border || {}), display: false };
        d.ticks = { ...(d.ticks || {}), color: w.teks };
    }
}

export function terapkanKeGrafik(chart) {
    const w = warna();
    const o = chart.options;
    Object.values(o.scales || {}).forEach((s) => skala(s, w));
    o.plugins = o.plugins || {};
    o.plugins.tooltip = { ...(o.plugins.tooltip || {}), ...tooltip(w), callbacks: o.plugins.tooltip?.callbacks, filter: o.plugins.tooltip?.filter };
    o.plugins.legend = o.plugins.legend || {};
    o.plugins.legend.labels = { ...(o.plugins.legend.labels || {}), color: w.teks, usePointStyle: true, pointStyle: 'circle', boxWidth: 8, boxHeight: 8 };
    chart.data.datasets.forEach((ds) => {
        const tipe = ds.type || chart.config.type;
        if (tipe === 'bar') { ds.borderRadius = ds.borderRadius ?? 6; ds.maxBarThickness = ds.maxBarThickness ?? 24; ds.borderSkipped = false; }
        if (tipe === 'doughnut' || tipe === 'pie') { ds.borderColor = gelap() ? '#101828' : '#FFFFFF'; }
    });
    chart.update('none');
}

export function terapkanSemua() {
    terapkanDefaults();
    Object.values(Chart.instances).forEach(terapkanKeGrafik);
}

terapkanSemua();
document.addEventListener('tema-berubah', terapkanSemua);
// Grafik yang dibuat sesudah modul ini dimuat ikut ditata pada frame berikutnya.
requestAnimationFrame(() => Object.values(Chart.instances).forEach(terapkanKeGrafik));

export default Chart;
