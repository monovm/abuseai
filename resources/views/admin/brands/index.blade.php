@extends('layouts.admin')
@section('header', 'Brands & Email')
@section('title', 'Brands & Email - ' . config('app.name', 'Abuse AI'))

@section('content')
    @livewire('admin.brand-manager')
@endsection
