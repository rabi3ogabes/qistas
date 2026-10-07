@extends('errors.layout')
@section('code', '419')
@section('title', __('Your session expired'))
@section('message', __('For your security, forms expire after a while. Sign in again to continue.'))
@section('actions')
    <a class="btn" href="{{ url('/login') }}">{{ __('Sign in') }}</a>
@endsection
