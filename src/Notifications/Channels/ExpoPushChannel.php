<?php

declare(strict_types=1);

namespace MarinSolutions\CheckybotLaravel\Notifications\Channels;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use MarinSolutions\CheckybotLaravel\Domain\Push\Contracts\PushProviderResult;
use MarinSolutions\CheckybotLaravel\Domain\Push\Models\PushDevice;
use Throwable;

final class ExpoPushChannel
{
    /** @param array<string, mixed> $safePayload */
    public function send(PushDevice $device, array $safePayload): PushProviderResult
    {
        if (app()->environment(['testing', 'harness'])) {
            $fake = getenv('CHECKYBOT_EXPO_DELIVERY_FAKE');
            if ($fake === 'accepted') {
                return PushProviderResult::accepted('fake-'.substr(hash('sha256', $device->public_id), 0, 20));
            }
            if ($fake === 'retrying') {
                return PushProviderResult::retry('provider_unavailable');
            }
            if (is_string($fake) && str_starts_with($fake, 'deactivated:')) {
                return hash_equals(substr($fake, strlen('deactivated:')), $device->public_id)
                    ? PushProviderResult::deactivated()
                    : PushProviderResult::accepted('fake-'.substr(hash('sha256', $device->public_id), 0, 20));
            }
        }

        try {
            $request = Http::acceptJson()
                ->asJson()
                ->timeout((int) config('checkybot.push.expo.timeout_seconds', 10));
            $accessToken = (string) config('checkybot.push.expo.access_token', '');
            if ($accessToken !== '') {
                $request = $request->withToken($accessToken);
            }
            $response = $request->post(
                (string) config('checkybot.push.expo.endpoint', 'https://exp.host/--/api/v2/push/send'),
                ['to' => $device->expo_push_token, ...$safePayload],
            );
        } catch (ConnectionException) {
            return PushProviderResult::retry('transport');
        } catch (Throwable) {
            return PushProviderResult::retry('transport');
        }

        if ($response->status() === 429 || $response->serverError()) {
            $retryAfter = filter_var($response->header('Retry-After'), FILTER_VALIDATE_INT);

            return PushProviderResult::retry('provider_unavailable', is_int($retryAfter) ? $retryAfter : null);
        }
        if (! $response->successful()) {
            return PushProviderResult::failed('provider_rejected');
        }

        $ticket = $response->json('data.0');
        if (! is_array($ticket)) {
            return PushProviderResult::failed('malformed_response');
        }
        if (($ticket['status'] ?? null) === 'ok') {
            return PushProviderResult::accepted(is_string($ticket['id'] ?? null) ? $ticket['id'] : null);
        }
        $detail = $ticket['details']['error'] ?? $ticket['details'] ?? null;
        if ($detail === 'DeviceNotRegistered') {
            return PushProviderResult::deactivated();
        }
        if (in_array($detail, ['MessageRateExceeded', 'ExpoError'], true)) {
            return PushProviderResult::retry('provider_unavailable');
        }

        return PushProviderResult::failed(is_string($detail) && preg_match('/^[A-Za-z0-9_.-]{1,80}$/', $detail) ? $detail : 'provider_rejected');
    }
}
