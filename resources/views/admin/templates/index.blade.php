@extends('layouts.admin')
@section('header', 'Email Templates')
@section('title', 'Templates - ' . config('app.name', 'Abuse AI'))

@section('content')
    @livewire('admin.template-editor')
@endsection
