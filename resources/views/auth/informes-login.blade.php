<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Iniciar sesión | HR Motor</title>
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
        <h1>Iniciar sesión</h1>
        <p>Accede a la plataforma de HR Motor</p>

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

        <form method="POST" action="{{ route('login.post') }}">
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
            >

            <label for="password">Contraseña</label>
            <input
                id="password"
                name="password"
                type="password"
                autocomplete="current-password"
                required
            >

            <div class="login-options">
                <label class="remember">
                    <input type="checkbox" name="remember" value="1">
                    <span>Recordarme</span>
                </label>

                <a href="{{ route('password.request') }}">¿Olvidaste tu contraseña?</a>
            </div>

            <button type="submit">Entrar</button>
        </form>
    </section>
</main>
</body>
</html>
