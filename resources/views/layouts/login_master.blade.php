<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    @php($isLegalPage = request()->routeIs('terms_of_use', 'privacy_policy'))
    @php($isPlatformPage = $isLegalPage || request()->routeIs('login', 'password.*'))
    @php($assetUrl = function ($path) use ($isLegalPage) { return $isLegalPage ? '/'.ltrim($path, '/') : asset($path); })
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $isPlatformPage ? 'NexaSMS by Mathias' : config('app.name', 'School Management System') }}</title>

    @include('partials.login.inc_top')
</head>

<body>
@if($isLegalPage)
    @include('partials.login.legal_header')
@else
    @include('partials.login.header')
@endif
@yield('content')
@if($isLegalPage)
    @include('partials.login.legal_footer')
@else
    @include('partials.login.footer')
@endif

</body>

</html>
