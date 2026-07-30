@props(['column', 'sortBy', 'sortDir' => 'desc'])

@php
    $isActive = $sortBy === $column;
    $ariaSort = $isActive ? ($sortDir === 'asc' ? 'ascending' : 'descending') : 'none';
    $arrow = $isActive ? ($sortDir === 'asc' ? ' ▲' : ' ▼') : '';
@endphp

{{-- wire:click calls applySort() (not sortBy) so it doesn't collide with
     the parent component's public string $sortBy property — Livewire's JS
     proxy resolves $wire.sortBy to that string value, making the method
     uncallable from the frontend. --}}
<th {{ $attributes->merge([
        'wire:click' => "applySort('{$column}')",
        'role' => 'button',
        'tabindex' => '0',
        'aria-sort' => $ariaSort,
        'style' => 'cursor:pointer; user-select:none;',
        'onkeydown' => "if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); \$wire.applySort('{$column}'); }",
    ]) }}>{{ $slot }}{{ $arrow }}</th>
