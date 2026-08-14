<?php
declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/push_config.php';

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

if (!function_exists('sendPushNotification')) {
    /**
     * Dispatches a push notification to all registered subscribers.
     *
     * @param PDO $pdo Active PDO connection pointing to the Price Sentinel database
     * @param string $title Notification title
     * @param string $body Notification body content
     * @param string $url URL that should open when the notification is clicked
     * @param callable|null $logger Optional logger with signature fn(string $message, string $level): void
     */
    function sendPushNotification(PDO $pdo, string $title, string $body, string $url, callable $logger = null): void
    {
        $log = $logger ?? static function (string $message, string $level = 'INFO'): void {
            $timestamp = date('Y-m-d H:i:s');
            error_log("[{$timestamp}] [{$level}] {$message}");
        };

        try {
            $auth = [
                'VAPID' => [
                    'subject' => VAPID_SUBJECT,
                    'publicKey' => VAPID_PUBLIC_KEY,
                    'privateKey' => VAPID_PRIVATE_KEY,
                ],
            ];

            $webPush = new WebPush($auth);

            $stmt = $pdo->query('SELECT endpoint, p256dh, auth FROM push_subscriptions');
            $subscriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($subscriptions)) {
                $log('No push subscriptions available; skipping notification dispatch.', 'WARNING');
                return;
            }

            foreach ($subscriptions as $sub) {
                if (empty($sub['endpoint']) || empty($sub['p256dh']) || empty($sub['auth'])) {
                    continue; // Skip malformed subscriptions
                }

                $subscription = Subscription::create([
                    'endpoint' => $sub['endpoint'],
                    'publicKey' => $sub['p256dh'],
                    'authToken' => $sub['auth'],
                ]);

                $payload = json_encode([
                    'title' => $title,
                    'body' => $body,
                    'url' => $url,
                    'icon' => 'https://cdn-icons-png.flaticon.com/512/2529/2529521.png',
                ], JSON_UNESCAPED_SLASHES);

                $webPush->queueNotification($subscription, $payload);
            }

            foreach ($webPush->flush() as $report) {
                $endpoint = $report->getRequest()->getUri()->__toString();

                if ($report->isSuccess()) {
                    $log("Message sent successfully for subscription {$endpoint}.", 'INFO');
                } else {
                    $log("Message failed for subscription {$endpoint}: {$report->getReason()}", 'ERROR');

                    if ($report->isSubscriptionExpired()) {
                        $deleteStmt = $pdo->prepare('DELETE FROM push_subscriptions WHERE endpoint = ?');
                        $deleteStmt->execute([$endpoint]);
                        $log("Subscription expired and deleted: {$endpoint}", 'INFO');
                    }
                }
            }
        } catch (Exception $e) {
            $log('Error sending push notification: ' . $e->getMessage(), 'ERROR');
        }
    }
}
