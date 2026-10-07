@extends('errors.layout')
@section('code', '429')
@section('title', __('Too many requests'))
@section('message', __('Please wait a moment and try again.'))
@section('actions')
    <a class="btn" href="{{ url('/') }}">{{ __('Back to the home page') }}</a>
@endsection
