<?php

namespace App\Support\Social;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class XPublisher
{
    public function configured(): bool
    {
        foreach (['consumer_key', 'consumer_secret', 'access_token', 'access_secret'] as $key) {
            if (! filled(config('social.x.'.$key))) {
                return false;
            }
        }

        return true;
    }

    public function authorization(string $method, string $url, array $parameters = [], ?string $nonce = null, ?int $timestamp = null): string
    {
        $oauth = [
            'oauth_consumer_key' => config('social.x.consumer_key'),
            'oauth_nonce' => $nonce ?? bin2hex(random_bytes(16)),
            'oauth_signature_method' => 'HMAC-SHA1',
            'oauth_timestamp' => (string) ($timestamp ?? time()),
            'oauth_token' => config('social.x.access_token'),
            'oauth_version' => '1.0',
        ];
        $signing = array_merge($parameters, $oauth);
        ksort($signing, SORT_STRING);
        $pairs = [];
        foreach ($signing as $key => $value) {
            $pairs[] = rawurlencode($key).'='.rawurlencode((string) $value);
        }
        $base = strtoupper($method).'&'.rawurlencode($url).'&'.rawurlencode(implode('&', $pairs));
        $key = rawurlencode((string) config('social.x.consumer_secret')).'&'.rawurlencode((string) config('social.x.access_secret'));
        $oauth['oauth_signature'] = base64_encode(hash_hmac('sha1', $base, $key, true));

        return 'OAuth '.implode(', ', array_map(fn ($key) => rawurlencode($key).'="'.rawurlencode((string) $oauth[$key]).'"', array_keys($oauth)));
    }

    private function request(string $method, string $url)
    {
        if (! $this->configured()) {
            throw new RuntimeException('X credentials have not been configured.');
        }

        // Never automatically retry a write: X may have accepted it before a timeout.
        return Http::withHeaders(['Authorization' => $this->authorization($method, $url)])
            ->acceptJson()->connectTimeout(10)->timeout(30)->withoutRedirecting();
    }

    public function verifyAccount(): string
    {
        $url = 'https://api.x.com/2/users/me';
        $response = $this->request('GET', $url)->get($url);
        $name = $response->json('data.username');
        if (! $response->successful() || ! is_string($name) || strcasecmp($name, config('social.x.username')) !== 0) {
            throw new RuntimeException('X account verification failed. Expected @'.config('social.x.username').'.');
        }

        return $name;
    }

    public function upload(string $png, string $alt): string
    {
        // OAuth 1.0a image upload; the resulting media ID is attached to a v2 post.
        $url = 'https://upload.twitter.com/1.1/media/upload.json';
        $response = $this->request('POST', $url)->attach('media', $png, 'gex-levels.png', ['Content-Type' => 'image/png'])
            ->post($url, ['media_category' => 'tweet_image']);
        $id = $response->json('media_id_string');
        if (! $response->successful() || ! is_string($id) || ! ctype_digit($id)) {
            throw new RuntimeException('X image upload failed (HTTP '.$response->status().').');
        }
        $url = 'https://upload.twitter.com/1.1/media/metadata/create.json';
        $metadata = $this->request('POST', $url)->post($url, ['media_id' => $id, 'alt_text' => ['text' => $alt]]);
        if (! $metadata->successful()) {
            throw new RuntimeException('X image description failed (HTTP '.$metadata->status().').');
        }

        return $id;
    }

    public function publish(string $body, string $mediaId): string
    {
        $url = 'https://api.x.com/2/tweets';
        $response = $this->request('POST', $url)->post($url, ['text' => $body, 'media' => ['media_ids' => [$mediaId]]]);
        $id = $response->json('data.id');
        if (! $response->successful() || ! is_string($id) || ! ctype_digit($id)) {
            throw new RuntimeException('X did not confirm a post ID. Check @GexOptions before attempting anything else.');
        }

        return $id;
    }
}
