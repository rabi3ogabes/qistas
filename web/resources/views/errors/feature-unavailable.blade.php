{{-- A feature the platform has switched off for now. No upgrade is offered: there is nothing to buy. --}}
@extends('errors.layout')
@section('code', '403')
@section('title', __('That is not available right now.'))
@section('message', __('This part of Qistas is switched off for the moment. Everything you saved is kept.'))
@section('actions')
    <a class="btn" href="{{ url('/app') }}">{{ __('Back to the dashboard') }}</a>
@endsection
