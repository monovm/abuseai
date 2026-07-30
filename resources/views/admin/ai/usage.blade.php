@extends('layouts.admin')
@section('header', 'AI Usage')
@section('title', 'AI Usage - ' . config('app.name', 'Abuse AI'))

@section('content')
    @livewire('admin.ai-usage')
@endsection
