<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Recuperar contraseña | HR Motor</title>
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
        <h1>Recuperar contraseña</h1>
        <p>Introduce tu correo y, si existe una cuenta activa, recibirás un enlace temporal.</p>

        @if (session('status'))
            <div class="login-status">
                {{ session('status') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="login-error">
                {{ $errors->first() }}
            </div>
        @endif

        <form method="POST" action="{{ route('password.email') }}">
            @csrf

            <label for="email">Correo electrónico</label>
            <input
                id="email"
                name="email"
                type="text"
                value="{{ old('email') }}"
                autocomplete="username"
                inputmode="email"
                required
                autofocus
            >

            <button type="submit">Enviar enlace</button>
        </form>

        <p class="login-secondary-link">
            <a href="{{ route('login') }}">Volver al login</a>
        </p>
    </section>
</main>
</body>
</html>
