<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Domain\Push\Delivery;

use Illuminate\Support\Facades\Http;
use MarinSolutions\CheckybotLaravel\Domain\Push\Contracts\PushProviderResult;
use MarinSolutions\CheckybotLaravel\Domain\Push\Models\PushOperation;
use Throwable;

final class LegacyWebhookChannel
{
    public function send(PushOperation $operation, string $message): PushProviderResult
    {
        if (app()->environment(['testing', 'harness']) && getenv('CHECKYBOT_LEGACY_WEBHOOK_FAKE') === 'accepted') {
            return PushProviderResult::accepted(null);
        }

        $url = (string) config('checkybot.push.legacy_webhook.url', '');
        if ($url === '' || ! str_starts_with($url, 'https://')) {
            return PushProviderResult::failed('not_configured');
        }

        try {
            $response = Http::asJson()->timeout((int) config('checkybot.push.legacy_webhook.timeout_seconds', 10))->post($url, [
                'operation_id' => $operation->operation_id,
                'phase' => $operation->phase,
                'severity' => 'critical',
                'thread_key' => $operation->thread_key,
                'message' => mb_substr($message, 0, 180),
            ]);
        } catch (Throwable) {
            return PushProviderResult::retry('transport');
        }

        if ($response->status() === 429 || $response->serverError()) {
            return PushProviderResult::retry('provider_unavailable');
        }

        return $response->successful()
            ? PushProviderResult::accepted(null)
            : PushProviderResult::failed('provider_rejected');
    }
}
