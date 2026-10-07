@extends('errors.layout')
@section('code', '500')
@section('title', __('Something went wrong'))
@section('message', __('It is not your fault. Please try again in a moment.'))
@section('actions')
    <a class="btn" href="{{ url('/') }}">{{ __('Back to the home page') }}</a>
@endsection
