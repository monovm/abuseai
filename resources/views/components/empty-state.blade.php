@props(['title' => 'Nothing here yet', 'message' => null, 'colspan' => null, 'action' => null])

@if($colspan)
    <tr><td colspan="{{ $colspan }}" style="text-align:center; padding:2.5rem 1rem;">
        <div style="font-size:14px; font-weight:600; color:#374151;">{{ $title }}</div>
        @if($message)
            <div style="font-size:12.5px; color:var(--text-muted); margin-top:4px;">{{ $message }}</div>
        @endif
        @if($action)
            <div style="margin-top:12px;">{{ $action }}</div>
        @endif
    </td></tr>
@else
    <div style="text-align:center; padding:2.5rem 1rem;">
        <div style="font-size:14px; font-weight:600; color:#374151;">{{ $title }}</div>
        @if($message)
            <div style="font-size:12.5px; color:var(--text-muted); margin-top:4px;">{{ $message }}</div>
        @endif
        @if($action)
            <div style="margin-top:12px;">{{ $action }}</div>
        @endif
    </div>
@endif
