<?php

declare(strict_types=1);

$reference = trim((string) ($_GET['reference'] ?? ($_REQUEST['x_extra1'] ?? '')));
$response = trim((string) ($_REQUEST['x_response'] ?? 'Pendiente'));
$reason = trim((string) ($_REQUEST['x_response_reason_text'] ?? 'Estamos validando la transaccion.'));
$amount = trim((string) ($_REQUEST['x_amount'] ?? ''));

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Estado de recarga</title>
    <style>
        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f7f1e7;
            color: #2f2a25;
            display: grid;
            place-items: center;
            min-height: 100vh;
        }
        .card {
            width: min(92vw, 480px);
            background: #fffaf1;
            border-radius: 24px;
            padding: 28px;
            box-shadow: 0 18px 40px rgba(82, 49, 24, 0.16);
        }
        .muted {
            color: #6a5b4c;
        }
    </style>
</head>
<body>
    <div class="card">
        <h1>Estado de la recarga</h1>
        <p><strong>Resultado:</strong> <?php echo htmlspecialchars($response, ENT_QUOTES, 'UTF-8'); ?></p>
        <p><strong>Detalle:</strong> <?php echo htmlspecialchars($reason, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php if ($amount !== ''): ?>
            <p><strong>Monto:</strong> <?php echo htmlspecialchars($amount, ENT_QUOTES, 'UTF-8'); ?> COP</p>
        <?php endif; ?>
        <?php if ($reference !== ''): ?>
            <p class="muted">Referencia: <?php echo htmlspecialchars($reference, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endif; ?>
        <p class="muted">Puedes volver a la app. El saldo final se confirma con el registro del backend.</p>
    </div>
</body>
</html>
