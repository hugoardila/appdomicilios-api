<?php

declare(strict_types=1);

require_once __DIR__ . '/config/payments.php';

$payload = array_merge($_GET, $_POST);

if ($payload === []) {
    respond([
        'success' => false,
        'message' => 'No llegaron datos de confirmacion.',
    ], 400);
}

$referenceCode = trim((string) ($payload['x_extra1'] ?? $payload['x_id_invoice'] ?? ''));
$transactionState = trim((string) ($payload['x_cod_response'] ?? $payload['x_cod_transaction_state'] ?? ''));
$transactionId = trim((string) ($payload['x_transaction_id'] ?? ''));
$providerReference = trim((string) ($payload['x_ref_payco'] ?? ''));
$amount = (int) round((float) ($payload['x_amount'] ?? 0));

if ($referenceCode === '') {
    respond([
        'success' => false,
        'message' => 'La confirmacion no trae referencia de recarga.',
    ], 422);
}

if (!epayco_signature_is_valid($payload)) {
    respond([
        'success' => false,
        'message' => 'La firma de confirmacion no es valida.',
    ], 403);
}

try {
    $pdo = db();
    $pdo->beginTransaction();

    $topupStmt = $pdo->prepare(
        'SELECT *
         FROM wallet_topups
         WHERE reference_code = :reference_code
         LIMIT 1
         FOR UPDATE'
    );
    $topupStmt->execute(['reference_code' => $referenceCode]);
    $topup = $topupStmt->fetch();

    if (!$topup) {
        $pdo->rollBack();
        respond([
            'success' => false,
            'message' => 'No encontramos la recarga solicitada.',
        ], 404);
    }

    $status = match ($transactionState) {
        '1' => 'approved',
        '2', '4' => 'rejected',
        default => 'pending',
    };

    if ($topup['status'] === 'approved') {
        $pdo->commit();
        respond([
            'success' => true,
            'message' => 'La recarga ya estaba aprobada.',
        ]);
    }

    $updateTopupStmt = $pdo->prepare(
        'UPDATE wallet_topups
         SET
            status = :status,
            provider_transaction_id = :provider_transaction_id,
            provider_reference = :provider_reference,
            response_payload = :response_payload,
            confirmed_at = CASE WHEN :status_confirmed = "approved" THEN NOW() ELSE confirmed_at END
         WHERE id = :id'
    );
    $updateTopupStmt->execute([
        'status' => $status,
        'status_confirmed' => $status,
        'provider_transaction_id' => $transactionId,
        'provider_reference' => $providerReference,
        'response_payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'id' => $topup['id'],
    ]);

    if ($status === 'approved') {
        if ((int) $topup['amount'] !== $amount) {
            throw new RuntimeException('El monto confirmado no coincide con la recarga registrada.');
        }

        settle_deferred_charges_from_topup(
            $pdo,
            (int) $topup['user_id'],
            (int) $topup['id'],
            (int) $topup['amount']
        );
    }

    $pdo->commit();

    respond([
        'success' => true,
        'message' => 'Confirmacion recibida.',
    ]);
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    respond([
        'success' => false,
        'message' => 'No fue posible procesar la confirmacion.',
        'error' => $exception->getMessage(),
    ], 500);
}
