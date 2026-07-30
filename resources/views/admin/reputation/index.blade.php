@extends('layouts.admin')
@section('header', 'IP Reputation')
@section('title', 'IP Reputation - ' . config('app.name', 'Abuse AI'))

@section('content')
    @livewire('admin.reputation-dashboard')
@endsection
