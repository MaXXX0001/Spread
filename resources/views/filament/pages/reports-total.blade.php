@php
    $values = $total->toArray();
@endphp

{{-- Inline layout: the panel has no custom theme, so arbitrary Tailwind classes are not compiled. --}}
<x-filament::section heading="Total for the period" :description="'Money in ' . $currency">
    <dl style="display: grid; grid-template-columns: repeat(auto-fill, minmax(7rem, 1fr)); gap: 1rem;">
        @foreach ($metrics as $key => $label)
            <div>
                <dt style="font-size: 0.875rem; opacity: 0.7;">{{ $label }}</dt>
                <dd style="font-weight: 600;" data-total="{{ $key }}">{{ $values[$key] ?? '—' }}</dd>
            </div>
        @endforeach
    </dl>
</x-filament::section>
