@extends('errors.layout')
@section('code', '503')
@section('title', __('We will be right back'))
@section('message', __('Qistas is being updated. Please try again in a few minutes.'))
@section('actions')
    <a class="btn" href="{{ url('/') }}">{{ __('Back to the home page') }}</a>
@endsection
