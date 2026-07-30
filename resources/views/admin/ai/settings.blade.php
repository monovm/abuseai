@extends('layouts.admin')
@section('header', 'AI Settings')
@section('title', 'AI Settings - ' . config('app.name', 'Abuse AI'))

@section('content')
    @livewire('admin.ai-settings')
@endsection
