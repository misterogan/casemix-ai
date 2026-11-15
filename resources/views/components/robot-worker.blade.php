{{-- Ilustrasi dekoratif: robot memeriksa berkas di ban berjalan (SVG + CSS, tanpa gambar) --}}
<div {{ $attributes->merge(['class' => 'robot-kerja']) }}>
    <svg viewBox="0 0 220 112" class="h-[112px] w-full" aria-hidden="true" focusable="false">
        <defs>
            <pattern id="rb-titik" width="12" height="12" patternUnits="userSpaceOnUse">
                <circle cx="2" cy="2" r="1" class="fill-garis-g"/>
            </pattern>
            <clipPath id="rb-bingkai"><rect x="0" y="0" width="220" height="112" rx="12"/></clipPath>
        </defs>
        <g clip-path="url(#rb-bingkai)">
            <rect width="220" height="112" class="fill-biru-muda" opacity=".45"/>
            <rect class="rb-titik" x="-24" y="0" width="268" height="112" fill="url(#rb-titik)" opacity=".7"/>

            {{-- Lantai + ban berjalan --}}
            <line x1="0" y1="102" x2="220" y2="102" class="stroke-garis-g" stroke-width="1.5"/>
            <rect x="104" y="88" width="112" height="8" rx="4" class="fill-abu-muda stroke-garis-g" stroke-width="1"/>
            <circle cx="112" cy="92" r="2.2" class="fill-abu" opacity=".5"/>
            <circle cx="160" cy="92" r="2.2" class="fill-abu" opacity=".5"/>
            <circle cx="208" cy="92" r="2.2" class="fill-abu" opacity=".5"/>
            <line x1="114" y1="96" x2="114" y2="102" class="stroke-garis-g" stroke-width="2"/>
            <line x1="206" y1="96" x2="206" y2="102" class="stroke-garis-g" stroke-width="2"/>

            {{-- Dokumen yang meluncur --}}
            <g class="rb-dokumen">
                <rect x="138" y="54" width="28" height="34" rx="3" fill="#fff" class="stroke-garis-g" stroke-width="1.5"/>
                <path d="M158 54v7h8" fill="none" class="stroke-garis-g" stroke-width="1.5"/>
                <rect x="143" y="64" width="12" height="2.5" rx="1.2" class="fill-garis-g"/>
                <rect x="143" y="70" width="18" height="2.5" rx="1.2" class="fill-garis-g"/>
                <rect x="143" y="76" width="15" height="2.5" rx="1.2" class="fill-garis-g"/>
                <g class="rb-centang">
                    <circle cx="162" cy="83" r="7" class="fill-sesuai"/>
                    <path d="M158.8 83l2.2 2.2 4-4.4" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </g>
            </g>
            <rect class="rb-sinar fill-biru" x="134" y="56" width="36" height="2.5" rx="1.25"/>

            {{-- Robot --}}
            <g class="rb-robot">
                <line x1="62" y1="22" x2="62" y2="12" class="stroke-abu" stroke-width="2" stroke-linecap="round"/>
                <circle class="rb-lampu fill-biru" cx="62" cy="10" r="3.5"/>
                <rect x="42" y="22" width="40" height="30" rx="10" class="fill-biru"/>
                <rect x="48" y="28" width="28" height="17" rx="6" fill="#fff"/>
                <rect class="rb-mata rb-mata-1 fill-biru-aktif" x="54" y="33" width="5" height="7" rx="2.5"/>
                <rect class="rb-mata rb-mata-2 fill-biru-aktif" x="65" y="33" width="5" height="7" rx="2.5"/>
                <rect x="46" y="55" width="32" height="30" rx="8" class="fill-biru-muda stroke-biru" stroke-width="2"/>
                <circle cx="62" cy="67" r="4" class="fill-biru" opacity=".35"/>
                <path d="M78 64h14l8-4" fill="none" class="stroke-abu" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                <path d="M46 64l-8 8" fill="none" class="stroke-abu" stroke-width="3" stroke-linecap="round"/>
                <rect x="50" y="85" width="8" height="15" rx="3" class="fill-abu"/>
                <rect x="66" y="85" width="8" height="15" rx="3" class="fill-abu"/>
            </g>
        </g>
    </svg>
    <p class="rb-teks relative mt-2 h-4 text-center text-xs text-abu">
        <span>Memeriksa kelengkapan berkas…</span>
        <span>Mencocokkan identitas &amp; SEP…</span>
        <span>Menyiapkan rekomendasi…</span>
    </p>
</div>
