@extends('errors.layout')
@section('code', '404')
@section('title', __('Page not found'))
@section('message', __('The page you are looking for does not exist or has moved.'))
@section('actions')
    <a class="btn" href="{{ url('/') }}">{{ __('Back to the home page') }}</a>
@endsection
