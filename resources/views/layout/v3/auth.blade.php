<!doctype html>
<html lang="{{ __('config.html_language') }}">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow, noarchive, noodp, NoImageIndex, noydir">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <!--
    (AUTH)
    If the base href URL begins with "http://" but you are sure it should start with "https://",
    please visit the following page: https://bit.ly/FF3-broken-base-href
    -->
    <base href="{{ route('index', null, true) }}/">
    {{-- fork: the page's own title when it has one (Register said "Login" otherwise), and the
         product name after it, so the browser tab and the history entry both name the app. --}}
    <title>{{ $pageTitle ?? __('firefly.login_page_title') }} &middot; Firefly III</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes"/>
    <meta name="theme-color" content="#007bff" media="(prefers-color-scheme: light)"/>
    <meta name="theme-color" content="#1a1a1a" media="(prefers-color-scheme: dark)"/>
    <meta name="color-scheme" content="light dark">
    @vite(['sass/app.scss'])
    <x-layout.fav-icons-clean />
    <script nonce="{{ $JS_NONCE }}">
        (() => {
            'use strict'
            document.documentElement.setAttribute('data-bs-theme', (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'))
        })()
    </script>
</head>
<body class="login-page bg-body-secondary">
<div class="login-box">
    {{-- fork: upstream shows this block only on the demo site, which leaves a real install's
         sign-in page with no product name on it at all. The name belongs above the card, where
         the person looking at the page reads it first. --}}
    <div class="login-logo">
        <a href="{{ route('index', null, true) }}">
            <img src="images/logo-session.png" width="41" height="60" alt="Firefly III" title="Firefly III"/><br>
            <strong>Firefly</strong> III
        </a>
    </div>
    @yield('content')
</div>
@vite(['js/pages/blank.js'])
@yield('scripts')

</body>
</html>
