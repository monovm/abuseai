@extends('layouts.admin')
@section('header', 'AbuseIPDB')
@section('title', 'AbuseIPDB - ' . config('app.name', 'Abuse AI'))

@section('content')
    @livewire('admin.abuse-ip-db-check')
@endsection
