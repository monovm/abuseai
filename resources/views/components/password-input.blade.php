@props(['id' => null, 'placeholder' => null])

<div x-data="{ show: false }" style="position:relative;">
    <input
        :type="show ? 'text' : 'password'"
        @if($id) id="{{ $id }}" @endif
        @if($placeholder) placeholder="{{ $placeholder }}" @endif
        autocomplete="off"
        {{ $attributes->merge(['style' => 'width:100%; padding:6px 32px 6px 10px; border:1px solid #e5e7eb; border-radius:6px; font-size:13px;']) }}>
    <button type="button"
        x-on:click="show = !show"
        x-text="show ? 'Hide' : 'Show'"
        :aria-label="show ? 'Hide password' : 'Show password'"
        style="position:absolute; right:6px; top:50%; transform:translateY(-50%); background:none; border:none; color:var(--primary); font-size:11px; cursor:pointer; padding:2px 4px;"></button>
</div>
