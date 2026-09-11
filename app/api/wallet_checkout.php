<?php

declare(strict_types=1);

require_once __DIR__ . '/config/payments.php';

header('Content-Type: text/html; charset=utf-8');

$sessionId = trim((string) ($_GET['session_id'] ?? ''));
$reference = trim((string) ($_GET['reference'] ?? ''));

if ($sessionId === '' || $reference === '') {
    http_response_code(400);
    header('Content-Type: text/html; charset=utf-8');
    echo '<h1>Recarga invalida</h1><p>No llego la sesion del pago.</p>';
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recargar saldo</title>
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
        h1 {
            margin: 0 0 12px;
        }
        p {
            line-height: 1.5;
        }
        button {
            width: 100%;
            border: 0;
            border-radius: 18px;
            padding: 16px;
            background: #b35c2e;
            color: #fff;
            font-size: 16px;
            cursor: pointer;
        }
        .muted {
            color: #6a5b4c;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="card">
        <h1>Recargar saldo</h1>
        <p>Vas a salir al checkout de ePayco para completar la recarga de tu app.</p>
        <p class="muted">Referencia: <?php echo htmlspecialchars($reference, ENT_QUOTES, 'UTF-8'); ?></p>
        <button id="payButton" type="button">Continuar con ePayco</button>
        <p class="muted">Si el checkout no abre solo, toca el botón otra vez.</p>
    </div>

    <script src="https://checkout.epayco.co/checkout-v2.js"></script>
    <script>
        const sessionId = <?php echo json_encode($sessionId, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

        function openCheckout() {
            const checkout = ePayco.checkout.configure({
                sessionId,
                type: "standard",
                test: <?php echo json_encode(EPAYCO_TEST_MODE); ?>
            });
            checkout.open();
        }

        document.getElementById("payButton").addEventListener("click", openCheckout);
        window.addEventListener("load", () => {
            setTimeout(openCheckout, 300);
        });
    </script>
</body>
</html>
