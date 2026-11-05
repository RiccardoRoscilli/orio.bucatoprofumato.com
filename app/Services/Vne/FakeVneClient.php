<?php

namespace App\Services\Vne;

use Illuminate\Support\Facades\Cache;

/**
 * Cassa simulata per provare orio senza la macchina. Il pagamento avanza col tempo
 * (inserimento denaro, poi esito dopo ~5 secondi) secondo lo scenario scelto dalla
 * finestra di incasso: completato, resto non erogato oppure annullato dal cliente.
 */
class FakeVneClient extends VneClient
{
    public const SCENARIOS = [
        'completed' => 'Pagamento completato',
        'notCompleted' => 'Resto non erogato',
        'customer_cancel' => 'Annullato alla cassa',
    ];

    protected function send(array $payload): array
    {
        return match ((int) $payload['tipo']) {
            1 => $this->startPayment($payload),
            2 => $this->pollPayment($payload),
            3 => $this->cancelPayment($payload),
            5 => $this->pendingList(),
            20 => $this->machineStatus(),
            60 => $this->cashClosure(),
            default => ['tipo' => $payload['tipo'], 'req_status' => 2, 'mess' => 101],
        };
    }

    public static function setScenario(string $id, string $scenario): void
    {
        $payment = Cache::get("vne_fake:{$id}");

        if ($payment && isset(self::SCENARIOS[$scenario])) {
            $payment['scenario'] = $scenario;
            Cache::put("vne_fake:{$id}", $payment, now()->addDay());
        }
    }

    private function startPayment(array $payload): array
    {
        $pending = Cache::get('vne_fake:pending', []);

        if ($pending) {
            return ['tipo' => 1, 'req_status' => 2, 'mess' => 105];
        }

        $id = time() . str_pad((string) random_int(0, 999), 3, '0', STR_PAD_LEFT);

        Cache::put("vne_fake:{$id}", [
            'amount' => (int) $payload['importo'],
            'card' => (bool) ($payload['credit_card'] ?? false),
            'started' => microtime(true),
            'scenario' => 'completed',
            'cancel' => null,
            'final' => null,
        ], now()->addDay());
        Cache::put('vne_fake:pending', [$id], now()->addDay());

        return ['tipo' => 1, 'importo' => (int) $payload['importo'], 'req_status' => 1, 'id' => $id];
    }

    private function pollPayment(array $payload): array
    {
        $id = (string) $payload['id'];
        $payment = Cache::get("vne_fake:{$id}");

        if (! $payment) {
            return ['tipo' => 2, 'req_status' => 2, 'mess' => 104];
        }

        if ($payment['final']) {
            return ['tipo' => 2, 'req_status' => 1, 'id' => $id, 'payment_status' => 1, 'payment_details' => $payment['final']];
        }

        $amount = $payment['amount'];
        $elapsed = microtime(true) - $payment['started'];
        // Il cliente inserisce banconote da 5 € fino a coprire l'importo
        $target = $payment['card'] ? $amount : (int) (ceil($amount / 500) * 500);
        $inserted = $payment['card'] ? 0 : min($target, (int) (floor($elapsed / 1.2) * 500));

        if ($payment['cancel']) {
            $final = match (true) {
                $inserted === 0 => ['amount' => $amount, 'inserted' => 0, 'rest' => 0, 'status' => 'deleted'],
                $payment['cancel'] === 1 => ['amount' => $amount, 'inserted' => $inserted, 'rest' => 0, 'status' => 'partial'],
                default => ['amount' => $amount, 'inserted' => $inserted, 'rest' => $inserted, 'status' => 'returned'],
            };

            return $this->finish($id, $payment, $final + ['not_returned' => 0, 'cancelled_by' => config('orio.vne.op_name')]);
        }

        if ($elapsed < 5) {
            return ['tipo' => 2, 'req_status' => 1, 'id' => $id, 'payment_status' => 2, 'payment_details' => [
                'amount' => $amount, 'inserted' => $inserted, 'rest' => 0, 'status' => 'pending', 'not_returned' => 0,
            ]];
        }

        $final = match ($payment['scenario']) {
            'customer_cancel' => ['amount' => $amount, 'inserted' => $target, 'rest' => $target, 'status' => 'returned', 'cancelled_by' => 'cliente'],
            'notCompleted' => ['amount' => $amount, 'inserted' => $target + 1000, 'rest' => max(0, $target + 1000 - $amount - 70), 'status' => 'notCompleted'],
            default => $payment['card']
                ? ['amount' => $amount, 'inserted' => -2, 'rest' => 0, 'status' => 'completed']
                : ['amount' => $amount, 'inserted' => $target, 'rest' => $target - $amount, 'status' => 'completed'],
        };

        // Con resto non erogato il pagamento resta pendente finché non si annulla o si fa refill
        if ($final['status'] === 'notCompleted') {
            return ['tipo' => 2, 'req_status' => 1, 'id' => $id, 'payment_status' => 2, 'payment_details' => $final + ['not_returned' => 0]];
        }

        return $this->finish($id, $payment, $final + ['not_returned' => 0]);
    }

    private function finish(string $id, array $payment, array $final): array
    {
        $payment['final'] = $final;
        Cache::put("vne_fake:{$id}", $payment, now()->addDay());
        Cache::forget('vne_fake:pending');

        if (in_array($final['status'], ['completed', 'partial'], true)) {
            $totals = Cache::get('vne_fake:totals', ['in' => 0, 'out' => 0, 'payments' => 0, 'since' => time()]);
            $totals['in'] += max(0, $final['inserted']);
            $totals['out'] += $final['rest'];
            $totals['payments'] += $final['status'] === 'completed' ? $final['amount'] : $final['inserted'];
            Cache::forever('vne_fake:totals', $totals);
        }

        return ['tipo' => 2, 'req_status' => 1, 'id' => $id, 'payment_status' => 1, 'payment_details' => $final];
    }

    private function cancelPayment(array $payload): array
    {
        $id = (string) $payload['id'];
        $payment = Cache::get("vne_fake:{$id}");

        if (! $payment || ($payment['final'] && $payment['final']['status'] !== 'notCompleted')) {
            return ['tipo' => 3, 'req_status' => 1, 'payment_status' => 3];
        }

        $payment['cancel'] = (int) $payload['tipo_annullamento'];
        Cache::put("vne_fake:{$id}", $payment, now()->addDay());

        return ['tipo' => 3, 'req_status' => 1, 'payment_status' => 2];
    }

    private function pendingList(): array
    {
        $pending = Cache::get('vne_fake:pending', []);
        $list = [];
        foreach ($pending as $i => $id) {
            $list["id_{$i}"] = $id;
        }

        return $list
            ? ['tipo' => 5, 'list_length' => count($list), 'pending_list' => $list]
            : ['tipo' => 5, 'list_length' => 0];
    }

    private function machineStatus(): array
    {
        return [
            'tipo' => 20,
            'recycler' => [
                'error' => 0, 'mess' => 'OK',
                'banconota_1' => ['valore' => 500, 'quantita' => 25, 'alert' => 0, 'min_level' => 20, 'max_level' => 100],
                'banconota_2' => ['valore' => 1000, 'quantita' => 4, 'alert' => 1, 'min_level' => 20, 'max_level' => 80],
                'banconota_3' => ['valore' => 2000, 'quantita' => 12, 'alert' => 0, 'min_level' => 10, 'max_level' => 60],
                'banconota_4' => ['valore' => 5000, 'quantita' => 0, 'alert' => 2, 'min_level' => 0, 'max_level' => 30],
                'stacker' => 34000,
                'totalInRecycle' => 40500,
            ],
            'hopper' => [
                'error' => 0, 'mess' => 'OK',
                'moneta_10' => 80, 'moneta_10_alert' => 0,
                'moneta_20' => 75, 'moneta_20_alert' => 0,
                'moneta_50' => 18, 'moneta_50_alert' => 1,
                'moneta_100' => 120, 'moneta_100_alert' => 0,
                'moneta_200' => 90, 'moneta_200_alert' => 0,
                'totalInRecycle' => 46100,
                'stacker' => 1250,
            ],
        ];
    }

    private function cashClosure(): array
    {
        $totals = Cache::get('vne_fake:totals', ['in' => 0, 'out' => 0, 'payments' => 0, 'since' => time()]);
        Cache::forever('vne_fake:totals', ['in' => 0, 'out' => 0, 'payments' => 0, 'since' => time()]);

        return [
            'tipo' => 60, 'req_status' => 1,
            'date' => time(), 'start_date' => $totals['since'],
            'total_in' => $totals['in'], 'total_out' => $totals['out'],
            'total_payments' => $totals['payments'],
            'total_operator_in' => 0, 'total_operator_out' => 0,
            'total_content' => 86600 + $totals['payments'],
            'petty_cash' => '86600',
            'cash_income' => $totals['payments'],
        ];
    }
}
