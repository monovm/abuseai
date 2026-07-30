@extends('layouts.admin')
@section('header', 'User Management')
@section('title', 'Users - ' . config('app.name', 'Abuse AI'))

@section('content')
    @livewire('admin.user-manager')
@endsection
