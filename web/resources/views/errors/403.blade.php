@extends('errors.layout')
@section('code', '403')
@section('title', __('You do not have access to this page'))
@section('message', __('If you think this is a mistake, ask the person who manages your workspace.'))
@section('actions')
    <a class="btn" href="{{ url('/') }}">{{ __('Back to the home page') }}</a>
@endsection
