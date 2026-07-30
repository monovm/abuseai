@extends('layouts.admin')
@section('header', 'Create Case')
@section('title', 'Create Case - ' . config('app.name', 'Abuse AI'))

@section('content')
    @livewire('admin.create-case')
@endsection
