@extends('layouts.admin')
@section('header', 'IP Inventory')
@section('title', 'IP Inventory - ' . config('app.name', 'Abuse AI'))

@section('content')
    @livewire('admin.ip-manager')
@endsection
