@extends('layouts.admin')
@section('header', 'Customer: ' . $customer->email)
@section('title', 'Customer - ' . config('app.name', 'Abuse AI'))

@section('content')
    @livewire('admin.customer-profile', ['customer' => $customer])
@endsection
