<?php

namespace App\Services\Pos;

use Illuminate\Support\Facades\Cache;

/**
 * Terminale simulato per provare Orio senza SmartPOS: il pagamento si chiude dopo ~6 secondi
 * secondo lo scenario scelto in cassa (pagato, carta rifiutata, annullato sul POS).
 */
class FakePos extends CloudPos
{
    public const SCENARIOS = [
        'completed' => 'Pagamento riuscito',
        'declined' => 'Carta rifiutata',
        'cancelled' => 'Annullato sul POS',
    ];

    public static function setScenario(string $orderId, string $scenario): void
    {
        $order = Cache::get("pos_fake:{$orderId}");
        if ($order && isset(self::SCENARIOS[$scenario])) {
            $order['scenario'] = $scenario;
            $order['started'] = now()->getPreciseTimestamp(3) / 1000;
            Cache::put("pos_fake:{$orderId}", $order, now()->addDay());
        }
    }

    protected function send(string $method, string $path, array $body = []): array
    {
        if ($method === 'POST' && $path === '/orders') {
            Cache::put("pos_fake:{$body['order_id']}", ['amount' => $body['amount'], 'started' => now()->getPreciseTimestamp(3) / 1000, 'scenario' => 'completed', 'status' => 'initialized'], now()->addDay());

            return [200, ['success' => true, 'data' => ['order_id' => $body['order_id'], 'session_id' => 'fake', 'events_token' => 'fake']]];
        }

        preg_match('#^/orders/([^/]+)(/cancel)?$#', $path, $m);
        $orderId = rawurldecode($m[1] ?? '');
        $order = Cache::get("pos_fake:{$orderId}");
        if (! $order) {
            return [404, ['success' => false, 'error_code' => 1006, 'error_info' => 'Invalid transaction ID']];
        }

        if (! empty($m[2])) {
            if ($order['status'] !== 'completed') {
                $order['status'] = 'cancelled';
            }
        } elseif (in_array($order['status'], ['initialized', 'declined'], true) && now()->getPreciseTimestamp(3) / 1000 - $order['started'] > 6) {
            $order['status'] = $order['scenario'];
        }
        Cache::put("pos_fake:{$orderId}", $order, now()->addDay());

        return [200, ['success' => true, 'data' => array_filter([
            'order_id' => $orderId,
            'status' => $order['status'],
            'amount' => $order['amount'],
            'transaction_id' => $order['status'] === 'completed' ? 'FAKE' . crc32($orderId) : null,
        ])]];
    }
}
