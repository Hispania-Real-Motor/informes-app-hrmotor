<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Restablecer contraseña | Informes HR Motor</title>
</head>
<body style="margin:0;background:#f1f5f9;color:#1f2944;font-family:Arial,sans-serif">
<div style="max-width:640px;margin:0 auto;padding:24px">
    <div style="background:#1f2944;color:#fff;padding:20px;border-radius:8px">
        <div style="font-size:12px;text-transform:uppercase;letter-spacing:.08em">HR Motor · Informes</div>
        <h1 style="font-size:24px;margin:8px 0 4px">Restablecer contraseña</h1>
        <div>Solicitud de acceso a la plataforma de informes</div>
    </div>

    <div style="background:#fff;border:1px solid #cbd5e1;border-radius:8px;padding:20px;margin-top:16px">
        <p style="margin:0 0 14px">Se ha solicitado un cambio de contraseña para tu acceso a Informes HR Motor.</p>
        <p style="margin:0 0 20px">Utiliza este enlace para establecer una contraseña nueva. Caduca en {{ $expireMinutes }} minutos.</p>

        <p style="margin:0 0 20px">
            <a href="{{ $resetUrl }}" style="display:inline-block;background:#e51a2e;color:#fff;text-decoration:none;font-weight:700;padding:12px 18px;border-radius:8px">
                Restablecer contraseña
            </a>
        </p>

        <p style="margin:0;color:#5f6b7d;font-size:13px">Si no has solicitado este cambio, puedes ignorar este correo.</p>
    </div>
</div>
</body>
</html>
