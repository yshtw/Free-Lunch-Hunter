<?php
declare(strict_types=1);

/** Report inconsistencies without repairing data or exposing account details. */
function reconcile_wallets(?int $userId = null): array
{
    return read_snapshot(function () use ($userId): array {
        $parameters = $userId === null ? [] : [$userId];
        $filter = $userId === null ? '' : ' WHERE u.user_id=?';
        $accounts = (int) query('SELECT COUNT(*) FROM user_account u' . $filter, $parameters)->fetchColumn();
        $orderFilter = $userId === null ? '' : ' WHERE o.user_id=?';
        $orders = (int) query('SELECT COUNT(*) FROM membership_orders o' . $orderFilter, $parameters)->fetchColumn();
        $issues = [];
        $add = static function (string $check, array $rows) use (&$issues): void {
            foreach ($rows as $row) {
                $issues[] = ['check' => $check, ...$row];
            }
        };
        $add('missing_wallet', query('SELECT u.user_id FROM user_account u
            LEFT JOIN premium p ON p.user_id=u.user_id' . $filter .
            ($filter === '' ? ' WHERE' : ' AND') . ' p.user_id IS NULL ORDER BY u.user_id', $parameters)->fetchAll());
        $add('wallet_balance', query('SELECT u.user_id,p.money AS balance,
            COALESCE(SUM(w.amount),0) AS ledger_balance,p.money-COALESCE(SUM(w.amount),0) AS difference
            FROM user_account u JOIN premium p ON p.user_id=u.user_id
            LEFT JOIN wallet_entries w ON w.user_id=u.user_id' . $filter . '
            GROUP BY u.user_id,p.money HAVING p.money<>COALESCE(SUM(w.amount),0)
            ORDER BY u.user_id', $parameters)->fetchAll());
        $add('membership_debit', query('SELECT o.user_id,o.order_id,o.amount AS order_amount,
            COUNT(w.entry_id) AS debit_count,COALESCE(SUM(w.amount),0) AS ledger_amount
            FROM membership_orders o LEFT JOIN wallet_entries w
                ON w.user_id=o.user_id AND w.action=\'membership\' AND w.reference=o.order_id' . $orderFilter . '
            GROUP BY o.user_id,o.order_id,o.amount
            HAVING COUNT(w.entry_id)<>1 OR COALESCE(SUM(w.amount),0)<>-o.amount
            ORDER BY o.user_id,o.order_id', $parameters)->fetchAll());
        $entryFilter = $userId === null ? '' : ' AND w.user_id=?';
        $add('orphan_membership_debit', query('SELECT w.user_id,w.entry_id,w.reference AS order_id
            FROM wallet_entries w LEFT JOIN membership_orders o
                ON o.user_id=w.user_id AND o.order_id=w.reference
            WHERE w.action=\'membership\' AND o.order_id IS NULL' . $entryFilter . '
            ORDER BY w.user_id,w.entry_id', $parameters)->fetchAll());
        // Administrators can have zero or one historical order: granting the role
        // neither purchases Premium nor removes an already purchased entitlement.
        $add('membership_entitlement', query('SELECT u.user_id,p.premium AS membership,
            COUNT(o.order_id) AS order_count FROM user_account u JOIN premium p ON p.user_id=u.user_id
            LEFT JOIN membership_orders o ON o.user_id=u.user_id' . $filter . '
            GROUP BY u.user_id,p.premium HAVING COUNT(o.order_id)>1
                OR (p.premium=0 AND COUNT(o.order_id)<>0) OR (p.premium=1 AND COUNT(o.order_id)<>1)
            ORDER BY u.user_id', $parameters)->fetchAll());
        return [
            'status' => $issues ? 'drift' : 'ok',
            'checked_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'accounts_checked' => $accounts,
            'orders_checked' => $orders,
            'issues' => $issues,
        ];
    });
}
