@props(['claim', 'animate' => false])
<div @class(['stamp', 'stamp-enter' => $animate]) role="img" aria-label="Disetujui oleh {{ $claim->approver?->name }} pada {{ $claim->approved_at?->translatedFormat('d F Y H:i') }}">
    <span class="text-lg">DISETUJUI</span>
    <small>{{ $claim->approver?->name }}</small>
    <small class="num">{{ $claim->approved_at?->format('d/m/Y H:i') }}</small>
</div>
