@props(['keys' => ['success', 'error', 'warning']])

@foreach((array) $keys as $key)
    @if(session($key))
        @php
            $styles = [
                'success' => ['bg' => '#d1fae5', 'border' => '#6ee7b7', 'text' => '#065f46'],
                'error'   => ['bg' => '#fef2f2', 'border' => '#fecaca', 'text' => '#991b1b'],
                'warning' => ['bg' => '#fefce8', 'border' => '#fde68a', 'text' => '#92400e'],
            ];
            $s = $styles[$key] ?? $styles['success'];
        @endphp
        <div role="alert"
             style="padding:10px 14px; background:{{ $s['bg'] }}; border:1px solid {{ $s['border'] }}; border-radius:6px; margin-bottom:12px; font-size:13px; color:{{ $s['text'] }};">
            {{ session($key) }}
        </div>
    @endif
@endforeach
