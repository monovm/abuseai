@extends('layouts.admin')
@section('header', 'Email Inbox')
@section('title', 'Email Inbox - ' . config('app.name', 'Abuse AI'))

@section('content')
    @livewire('admin.email-inbox')
@endsection
