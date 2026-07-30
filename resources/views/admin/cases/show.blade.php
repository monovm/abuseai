@extends('layouts.admin')
@section('header', $case->case_number)
@section('title', $case->case_number . ' - ' . config('app.name', 'Abuse AI'))

@section('content')
    @livewire('admin.case-detail', ['case' => $case])
@endsection
