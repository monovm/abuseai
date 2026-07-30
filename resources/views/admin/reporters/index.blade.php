@extends('layouts.admin')
@section('header', 'Reporters')
@section('title', 'Reporters - ' . config('app.name', 'Abuse AI'))

@section('content')
    @livewire('admin.reporter-manager')
@endsection
