<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

const CUSTOMER_FREE_ORDERS_TOTAL = 2;
const COURIER_FREE_TAKES_TOTAL = 3;
const WALLET_MINIMUM_TOP_UP = 10000;
const CUSTOMER_ORDER_FEE = 1500;
const COURIER_ORDER_FEE = 1000;

function wallet_policy_payload(): array
{
    return [
        'customer_free_orders' => CUSTOMER_FREE_ORDERS_TOTAL,
        'courier_free_orders' => COURIER_FREE_TAKES_TOTAL,
        'minimum_top_up' => WALLET_MINIMUM_TOP_UP,
        'customer_order_fee' => CUSTOMER_ORDER_FEE,
        'courier_order_fee' => COURIER_ORDER_FEE,
    ];
}

function fetch_user_row(PDO $pdo, int $userId, bool $forUpdate = false): ?array
{
    $sql = 'SELECT id, role, approval_status, full_name, national_id, phone, email, city, is_active, customer_reputation_score, customer_incidents_count
        FROM users
        WHERE id = :id
        LIMIT 1';

    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute(['id' => $userId]);

    $user = $stmt->fetch();
    return $user ?: null;
}

function ensure_wallet_account(PDO $pdo, array $user): void
{
    $defaults = wallet_defaults_for_role((string) ($user['role'] ?? 'customer'));

    $stmt = $pdo->prepare(
        'INSERT IGNORE INTO wallet_accounts (
            user_id,
            balance,
            customer_free_orders_total,
            customer_free_orders_remaining,
            customer_deferred_charges,
            courier_free_takes_total,
            courier_free_takes_remaining,
            courier_deferred_charges
        ) VALUES (
            :user_id,
            :balance,
            :customer_free_orders_total,
            :customer_free_orders_remaining,
            :customer_deferred_charges,
            :courier_free_takes_total,
            :courier_free_takes_remaining,
            :courier_deferred_charges
        )'
    );
    $stmt->execute([
        'user_id' => $user['id'],
        'balance' => 0,
        'customer_free_orders_total' => $defaults['customer_free_orders_total'],
        'customer_free_orders_remaining' => $defaults['customer_free_orders_remaining'],
        'customer_deferred_charges' => 0,
        'courier_free_takes_total' => $defaults['courier_free_takes_total'],
        'courier_free_takes_remaining' => $defaults['courier_free_takes_remaining'],
        'courier_deferred_charges' => 0,
    ]);
}

function lock_wallet_account(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare(
        'SELECT
            user_id,
            balance,
            customer_free_orders_total,
            customer_free_orders_remaining,
            customer_deferred_charges,
            courier_free_takes_total,
            courier_free_takes_remaining,
            courier_deferred_charges
         FROM wallet_accounts
         WHERE user_id = :user_id
         LIMIT 1
         FOR UPDATE'
    );
    $stmt->execute(['user_id' => $userId]);
    $wallet = $stmt->fetch();

    if (!$wallet) {
        throw new RuntimeException('No se encontro la billetera del usuario.');
    }

    return $wallet;
}

function wallet_summary(PDO $pdo, int $userId, ?string $role = null): array
{
    $user = fetch_user_row($pdo, $userId);
    if (!$user) {
        throw new RuntimeException('Usuario no encontrado para la billetera.');
    }

    ensure_wallet_account($pdo, $user);

    $stmt = $pdo->prepare(
        'SELECT
            balance,
            customer_free_orders_remaining,
            customer_deferred_charges,
            courier_free_takes_remaining,
            courier_deferred_charges
         FROM wallet_accounts
         WHERE user_id = :user_id
         LIMIT 1'
    );
    $stmt->execute(['user_id' => $userId]);
    $wallet = $stmt->fetch() ?: [];

    return [
        'balance' => (int) ($wallet['balance'] ?? 0),
        'minimum_top_up' => WALLET_MINIMUM_TOP_UP,
        'customer_free_orders_remaining' => (int) ($wallet['customer_free_orders_remaining'] ?? 0),
        'courier_free_takes_remaining' => (int) ($wallet['courier_free_takes_remaining'] ?? 0),
        'customer_order_fee' => CUSTOMER_ORDER_FEE,
        'courier_take_fee' => COURIER_ORDER_FEE,
        'customer_deferred_charges' => (int) ($wallet['customer_deferred_charges'] ?? 0),
        'courier_deferred_charges' => (int) ($wallet['courier_deferred_charges'] ?? 0),
        'total_deferred_charges' => (int) ($wallet['customer_deferred_charges'] ?? 0) + (int) ($wallet['courier_deferred_charges'] ?? 0),
    ];
}

function hydrate_user_with_wallet(PDO $pdo, array $user): array
{
    $user['wallet'] = wallet_summary($pdo, (int) $user['id'], (string) ($user['role'] ?? 'customer'));
    $user['courier_rating'] = courier_rating_summary($pdo, $user);
    $user['customer_reputation'] = customer_reputation_summary($user);
    return $user;
}

function courier_rating_summary(PDO $pdo, array $user): array
{
    if (($user['role'] ?? '') !== 'courier') {
        return [
            'average' => 0.0,
            'count' => 0,
            'warning_active' => false,
        ];
    }

    $stmt = $pdo->prepare(
        'SELECT
            COUNT(*) AS rating_count,
            COALESCE(AVG(rating_value), 0) AS rating_average
         FROM courier_ratings
         WHERE courier_id = :courier_id'
    );
    $stmt->execute([
        'courier_id' => (int) $user['id'],
    ]);
    $rating = $stmt->fetch() ?: [];

    $average = round((float) ($rating['rating_average'] ?? 0), 2);
    $count = (int) ($rating['rating_count'] ?? 0);

    return [
        'average' => $average,
        'count' => $count,
        'warning_active' => $count > 0 && $average <= 3.0,
    ];
}

function customer_reputation_summary(array $user): array
{
    $score = round((float) ($user['customer_reputation_score'] ?? 5.0), 2);
    $incidents = (int) ($user['customer_incidents_count'] ?? 0);

    return [
        'score' => $score,
        'incidents_count' => $incidents,
        'warning_active' => $incidents > 0 && $score <= 3.0,
    ];
}

function penalize_customer_reputation_for_order(
    PDO $pdo,
    int $orderId,
    int $customerId,
    int $courierId,
    string $reason
): array {
    $existingStmt = $pdo->prepare(
        'SELECT id
         FROM customer_reputation_events
         WHERE order_id = :order_id
         LIMIT 1'
    );
    $existingStmt->execute(['order_id' => $orderId]);
    if ($existingStmt->fetch()) {
        $user = fetch_user_row($pdo, $customerId, true);
        if (!$user) {
            throw new RuntimeException('No se encontro el cliente para consultar su reputacion.');
        }

        return customer_reputation_summary($user);
    }

    $customer = fetch_user_row($pdo, $customerId, true);
    if (!$customer) {
        throw new RuntimeException('No se encontro el cliente para marcar la novedad.');
    }

    $before = (float) ($customer['customer_reputation_score'] ?? 5.0);
    $after = max(1.0, $before - 2.0);
    $incidents = (int) ($customer['customer_incidents_count'] ?? 0) + 1;

    $updateStmt = $pdo->prepare(
        'UPDATE users
         SET
            customer_reputation_score = :score,
            customer_incidents_count = :incidents
         WHERE id = :customer_id'
    );
    $updateStmt->execute([
        'score' => $after,
        'incidents' => $incidents,
        'customer_id' => $customerId,
    ]);

    $eventStmt = $pdo->prepare(
        'INSERT INTO customer_reputation_events (
            order_id,
            customer_id,
            courier_id,
            event_reason,
            score_before,
            score_after
         ) VALUES (
            :order_id,
            :customer_id,
            :courier_id,
            :event_reason,
            :score_before,
            :score_after
         )'
    );
    $eventStmt->execute([
        'order_id' => $orderId,
        'customer_id' => $customerId,
        'courier_id' => $courierId,
        'event_reason' => $reason,
        'score_before' => $before,
        'score_after' => $after,
    ]);

    return customer_reputation_summary([
        'customer_reputation_score' => $after,
        'customer_incidents_count' => $incidents,
    ]);
}

function apply_customer_charge_for_order(PDO $pdo, int $orderId, array $customer): array
{
    ensure_wallet_account($pdo, $customer);
    $wallet = lock_wallet_account($pdo, (int) $customer['id']);

    if ((int) $wallet['customer_free_orders_remaining'] > 0) {
        $remaining = (int) $wallet['customer_free_orders_remaining'] - 1;
        $deferredCharges = (int) $wallet['customer_deferred_charges'] + CUSTOMER_ORDER_FEE;
        $stmt = $pdo->prepare(
            'UPDATE wallet_accounts
             SET
                customer_free_orders_remaining = :remaining,
                customer_deferred_charges = :deferred_charges
             WHERE user_id = :user_id'
        );
        $stmt->execute([
            'remaining' => $remaining,
            'deferred_charges' => $deferredCharges,
            'user_id' => $customer['id'],
        ]);

        record_wallet_movement(
            $pdo,
            (int) $customer['id'],
            $orderId,
            null,
            'customer_free_order_used',
            0,
            (int) $wallet['balance'],
            'Se uso una orden de bienvenida. Ese cobro quedara pendiente hasta la primera recarga.'
        );

        update_customer_fee_on_order($pdo, $orderId, 'free', 0, false);
        return wallet_summary($pdo, (int) $customer['id']);
    }

    $balance = (int) $wallet['balance'];
    if ($balance < CUSTOMER_ORDER_FEE) {
        throw new DomainException(
            'Ya usaste tus 2 pedidos iniciales. Ahora necesitas recargar saldo para seguir pidiendo en la app.'
        );
    }

    $newBalance = $balance - CUSTOMER_ORDER_FEE;
    $stmt = $pdo->prepare(
        'UPDATE wallet_accounts
         SET balance = :balance
         WHERE user_id = :user_id'
    );
    $stmt->execute([
        'balance' => $newBalance,
        'user_id' => $customer['id'],
    ]);

    record_wallet_movement(
        $pdo,
        (int) $customer['id'],
        $orderId,
        null,
        'customer_order_fee_charge',
        -CUSTOMER_ORDER_FEE,
        $newBalance,
        'Cobro por crear un pedido despues de las ordenes gratis.'
    );

    update_customer_fee_on_order($pdo, $orderId, 'paid', CUSTOMER_ORDER_FEE, false);
    return wallet_summary($pdo, (int) $customer['id']);
}

function apply_courier_charge_for_order(PDO $pdo, int $orderId, array $courier): array
{
    ensure_wallet_account($pdo, $courier);
    $wallet = lock_wallet_account($pdo, (int) $courier['id']);

    if ((int) $wallet['courier_free_takes_remaining'] > 0) {
        $remaining = (int) $wallet['courier_free_takes_remaining'] - 1;
        $deferredCharges = (int) $wallet['courier_deferred_charges'] + COURIER_ORDER_FEE;
        $stmt = $pdo->prepare(
            'UPDATE wallet_accounts
             SET
                courier_free_takes_remaining = :remaining,
                courier_deferred_charges = :deferred_charges
             WHERE user_id = :user_id'
        );
        $stmt->execute([
            'remaining' => $remaining,
            'deferred_charges' => $deferredCharges,
            'user_id' => $courier['id'],
        ]);

        record_wallet_movement(
            $pdo,
            (int) $courier['id'],
            $orderId,
            null,
            'courier_free_take_used',
            0,
            (int) $wallet['balance'],
            'Se uso una toma de bienvenida. Ese cobro quedara pendiente hasta la primera recarga.'
        );

        update_courier_fee_on_order($pdo, $orderId, 'free', 0, false);
        return wallet_summary($pdo, (int) $courier['id']);
    }

    $balance = (int) $wallet['balance'];
    if ($balance < COURIER_ORDER_FEE) {
        throw new DomainException(
            'Ya usaste tus 3 tomas iniciales. Ahora necesitas recargar saldo para seguir tomando pedidos.'
        );
    }

    $newBalance = $balance - COURIER_ORDER_FEE;
    $stmt = $pdo->prepare(
        'UPDATE wallet_accounts
         SET balance = :balance
         WHERE user_id = :user_id'
    );
    $stmt->execute([
        'balance' => $newBalance,
        'user_id' => $courier['id'],
    ]);

    record_wallet_movement(
        $pdo,
        (int) $courier['id'],
        $orderId,
        null,
        'courier_take_fee_charge',
        -COURIER_ORDER_FEE,
        $newBalance,
        'Cobro por tomar un pedido despues de las tomas gratis.'
    );

    update_courier_fee_on_order($pdo, $orderId, 'paid', COURIER_ORDER_FEE, false);
    return wallet_summary($pdo, (int) $courier['id']);
}

function reverse_customer_charge_for_cancelled_order(PDO $pdo, array $order): void
{
    if ((int) ($order['customer_fee_reversed'] ?? 0) === 1) {
        return;
    }

    $customerId = (int) $order['customer_id'];
    $user = fetch_user_row($pdo, $customerId, true);
    if (!$user) {
        throw new RuntimeException('No se encontro el cliente para devolver el saldo.');
    }

    ensure_wallet_account($pdo, $user);
    $wallet = lock_wallet_account($pdo, $customerId);
    $mode = (string) ($order['customer_fee_mode'] ?? 'none');

    if ($mode === 'free') {
        $restored = min(
            (int) $wallet['customer_free_orders_total'],
            (int) $wallet['customer_free_orders_remaining'] + 1
        );
        $deferredCharges = max(
            0,
            (int) $wallet['customer_deferred_charges'] - CUSTOMER_ORDER_FEE
        );
        $stmt = $pdo->prepare(
            'UPDATE wallet_accounts
             SET
                customer_free_orders_remaining = :remaining,
                customer_deferred_charges = :deferred_charges
             WHERE user_id = :user_id'
        );
        $stmt->execute([
            'remaining' => $restored,
            'deferred_charges' => $deferredCharges,
            'user_id' => $customerId,
        ]);

        record_wallet_movement(
            $pdo,
            $customerId,
            (int) $order['id'],
            null,
            'customer_free_order_restored',
            0,
            (int) $wallet['balance'],
            'La orden de bienvenida fue restaurada y el cobro pendiente se elimino porque el pedido se cancelo sin ser tomado.'
        );
    } elseif ($mode === 'paid') {
        $refundAmount = (int) ($order['customer_fee_amount'] ?? CUSTOMER_ORDER_FEE);
        $newBalance = (int) $wallet['balance'] + $refundAmount;

        $stmt = $pdo->prepare(
            'UPDATE wallet_accounts
             SET balance = :balance
             WHERE user_id = :user_id'
        );
        $stmt->execute([
            'balance' => $newBalance,
            'user_id' => $customerId,
        ]);

        record_wallet_movement(
            $pdo,
            $customerId,
            (int) $order['id'],
            null,
            'customer_order_fee_refund',
            $refundAmount,
            $newBalance,
            'Devolucion porque el cliente cancelo antes de que un domiciliario tomara el pedido.'
        );
    }

    $stmt = $pdo->prepare(
        'UPDATE orders
         SET customer_fee_reversed = 1
         WHERE id = :order_id'
    );
    $stmt->execute(['order_id' => $order['id']]);
}

function settle_deferred_charges_from_topup(PDO $pdo, int $userId, int $topupId, int $grossAmount): int
{
    $user = fetch_user_row($pdo, $userId, true);
    if (!$user) {
        throw new RuntimeException('No se encontro el usuario para aplicar la recarga.');
    }

    ensure_wallet_account($pdo, $user);
    $wallet = lock_wallet_account($pdo, $userId);

    $balanceAfterTopup = (int) $wallet['balance'] + $grossAmount;
    $creditStmt = $pdo->prepare(
        'UPDATE wallet_accounts
         SET balance = :balance
         WHERE user_id = :user_id'
    );
    $creditStmt->execute([
        'balance' => $balanceAfterTopup,
        'user_id' => $userId,
    ]);

    record_wallet_movement(
        $pdo,
        $userId,
        null,
        $topupId,
        'topup_credit',
        $grossAmount,
        $balanceAfterTopup,
        'Recarga aprobada por ePayco.'
    );

    $currentBalance = $balanceAfterTopup;

    if ((int) $wallet['customer_deferred_charges'] > 0) {
        $recovery = min((int) $wallet['customer_deferred_charges'], $currentBalance);
        if ($recovery > 0) {
            $currentBalance -= $recovery;
            $stmt = $pdo->prepare(
                'UPDATE wallet_accounts
                 SET
                    balance = :balance,
                    customer_deferred_charges = customer_deferred_charges - :recovery
                 WHERE user_id = :user_id'
            );
            $stmt->execute([
                'balance' => $currentBalance,
                'recovery' => $recovery,
                'user_id' => $userId,
            ]);

            record_wallet_movement(
                $pdo,
                $userId,
                null,
                $topupId,
                'customer_welcome_charge_recovery',
                -$recovery,
                $currentBalance,
                'Se descontaron los pedidos iniciales usados antes de la recarga.'
            );
        }
    }

    $walletAfterCustomer = lock_wallet_account($pdo, $userId);
    $currentBalance = (int) $walletAfterCustomer['balance'];
    if ((int) $walletAfterCustomer['courier_deferred_charges'] > 0) {
        $recovery = min((int) $walletAfterCustomer['courier_deferred_charges'], $currentBalance);
        if ($recovery > 0) {
            $currentBalance -= $recovery;
            $stmt = $pdo->prepare(
                'UPDATE wallet_accounts
                 SET
                    balance = :balance,
                    courier_deferred_charges = courier_deferred_charges - :recovery
                 WHERE user_id = :user_id'
            );
            $stmt->execute([
                'balance' => $currentBalance,
                'recovery' => $recovery,
                'user_id' => $userId,
            ]);

            record_wallet_movement(
                $pdo,
                $userId,
                null,
                $topupId,
                'courier_welcome_charge_recovery',
                -$recovery,
                $currentBalance,
                'Se descontaron las tomas iniciales usadas antes de la recarga.'
            );
        }
    }

    return $currentBalance;
}

function record_wallet_movement(
    PDO $pdo,
    int $userId,
    ?int $orderId,
    ?int $topupId,
    string $movementType,
    int $amount,
    int $balanceAfter,
    string $note
): void {
    $stmt = $pdo->prepare(
        'INSERT INTO wallet_movements (
            user_id,
            order_id,
            topup_id,
            movement_type,
            amount,
            balance_after,
            note
         ) VALUES (
            :user_id,
            :order_id,
            :topup_id,
            :movement_type,
            :amount,
            :balance_after,
            :note
         )'
    );
    $stmt->execute([
        'user_id' => $userId,
        'order_id' => $orderId,
        'topup_id' => $topupId,
        'movement_type' => $movementType,
        'amount' => $amount,
        'balance_after' => $balanceAfter,
        'note' => $note,
    ]);
}

function update_customer_fee_on_order(
    PDO $pdo,
    int $orderId,
    string $mode,
    int $amount,
    bool $reversed
): void {
    $stmt = $pdo->prepare(
        'UPDATE orders
         SET
            service_fee = :service_fee,
            customer_fee_mode = :mode,
            customer_fee_amount = :amount,
            customer_fee_reversed = :reversed
         WHERE id = :order_id'
    );
    $stmt->execute([
        'service_fee' => $amount,
        'mode' => $mode,
        'amount' => $amount,
        'reversed' => $reversed ? 1 : 0,
        'order_id' => $orderId,
    ]);
}

function update_courier_fee_on_order(
    PDO $pdo,
    int $orderId,
    string $mode,
    int $amount,
    bool $reversed
): void {
    $stmt = $pdo->prepare(
        'UPDATE orders
         SET
            courier_fee_mode = :mode,
            courier_fee_amount = :amount,
            courier_fee_reversed = :reversed
         WHERE id = :order_id'
    );
    $stmt->execute([
        'mode' => $mode,
        'amount' => $amount,
        'reversed' => $reversed ? 1 : 0,
        'order_id' => $orderId,
    ]);
}

function wallet_defaults_for_role(string $role): array
{
    if ($role === 'courier') {
        return [
            'customer_free_orders_total' => 0,
            'customer_free_orders_remaining' => 0,
            'courier_free_takes_total' => COURIER_FREE_TAKES_TOTAL,
            'courier_free_takes_remaining' => COURIER_FREE_TAKES_TOTAL,
            'customer_deferred_charges' => 0,
            'courier_deferred_charges' => 0,
        ];
    }

    return [
        'customer_free_orders_total' => CUSTOMER_FREE_ORDERS_TOTAL,
        'customer_free_orders_remaining' => CUSTOMER_FREE_ORDERS_TOTAL,
        'courier_free_takes_total' => 0,
        'courier_free_takes_remaining' => 0,
        'customer_deferred_charges' => 0,
        'courier_deferred_charges' => 0,
    ];
}
