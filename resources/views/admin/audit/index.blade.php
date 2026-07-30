@extends('layouts.admin')
@section('header', 'Audit Log')
@section('title', 'Audit Log - ' . config('app.name', 'Abuse AI'))

@section('content')
    @livewire('admin.audit-log-viewer')
@endsection
