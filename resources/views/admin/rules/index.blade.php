@extends('layouts.admin')
@section('header', 'Automation Rules')
@section('title', 'Rules - ' . config('app.name', 'Abuse AI'))

@section('content')
    @livewire('admin.rule-editor')
@endsection
