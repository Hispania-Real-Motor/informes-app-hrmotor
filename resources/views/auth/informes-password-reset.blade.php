<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Restablecer contraseña | HR Motor</title>
    <link rel="icon" href="/brand/favicon.ico" sizes="any">
    <link rel="shortcut icon" href="/brand/favicon.ico">
    @include('partials.font-assets')
    @vite(['resources/css/app.css'])
</head>
<body class="login-page">
<div class="login-background" aria-hidden="true"></div>
<main class="login-wrapper">
    <div class="login-brand">
        <img src="{{ asset('brand/logo-horizontal.svg') }}" alt="HR Motor">
    </div>

    <section class="login-card">
        <h1>Restablecer contraseña</h1>
        <p>Define una contraseña nueva para la plataforma de Informes HR Motor.</p>

        @if (! $tokenIsValid)
            <div class="login-error">
                El enlace no es válido o ha caducado.
            </div>
        @endif

        @if ($errors->any())
            <div class="login-error">
                {{ $errors->first() }}
            </div>
        @endif

        <form method="POST" action="{{ route('password.update') }}">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">

            <label for="password">Nueva contraseña</label>
            <input
                id="password"
                name="password"
                type="password"
                autocomplete="new-password"
                required
                @disabled(! $tokenIsValid)
            >

            <label for="password_confirmation">Confirmar contraseña</label>
            <input
                id="password_confirmation"
                name="password_confirmation"
                type="password"
                autocomplete="new-password"
                required
                @disabled(! $tokenIsValid)
            >

            <button type="submit" @disabled(! $tokenIsValid)>Cambiar contraseña</button>
        </form>

        <p class="login-secondary-link">
            <a href="{{ route('login') }}">Volver al login</a>
        </p>
    </section>
</main>
</body>
</html>
