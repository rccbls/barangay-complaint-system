<?php
/** Shared HTTP test helpers (cookie-aware client that does not follow redirects). */
declare(strict_types=1);

if (!defined('TEST_PASSWORD')) {
    define('TEST_PASSWORD', 'Synthetic-Test-Pass-123');
}

/** Minimal cookie-aware HTTP client that does NOT follow redirects. */
final class Client
{
    public array $cookies = [];

    public function __construct(private string $base)
    {
    }

    public function req(string $method, string $path, array $form = [], ?array $cookieOverride = null): array
    {
        $headers = ['Accept: text/html'];
        $jar = $cookieOverride ?? $this->cookies;
        if ($jar) {
            $pairs = [];
            foreach ($jar as $k => $v) {
                $pairs[] = "$k=$v";
            }
            $headers[] = 'Cookie: ' . implode('; ', $pairs);
        }
        $content = '';
        if ($method === 'POST') {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            $content = http_build_query($form);
        }
        $ctx = stream_context_create(['http' => [
            'method' => $method, 'header' => implode("\r\n", $headers), 'content' => $content,
            'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 30,
        ]]);
        $body = @file_get_contents($this->base . ltrim($path, '/'), false, $ctx);
        if (empty($http_response_header)) {
            throw new RuntimeException('Cannot reach ' . $this->base . ' - is the web server running?');
        }
        $status = 0;
        $location = null;
        $setCookies = [];
        foreach ($http_response_header as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $status = (int) $m[1];
            } elseif (stripos($h, 'Location:') === 0) {
                $location = trim(substr($h, 9));
            } elseif (stripos($h, 'Set-Cookie:') === 0) {
                $setCookies[] = trim(substr($h, 11));
            }
        }
        if ($cookieOverride === null) {
            foreach ($setCookies as $sc) {
                [$pair] = explode(';', $sc, 2);
                [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
                $expired = preg_match('/expires=([^;]+)/i', $sc, $m) && strtotime($m[1]) < time();
                if ($expired || $value === '' || $value === 'deleted') {
                    unset($this->cookies[$name]);
                } else {
                    $this->cookies[$name] = $value;
                }
            }
        }
        return ['status' => $status, 'location' => $location, 'body' => (string) $body, 'cookies' => $setCookies, 'headers' => $http_response_header];
    }

    public function get(string $path): array
    {
        return $this->req('GET', $path);
    }

    public function post(string $path, array $form): array
    {
        return $this->req('POST', $path, $form);
    }

    public function session(): ?string
    {
        return $this->cookies['BCS_SESSION'] ?? null;
    }
}

function csrf_from(string $html): string
{
    return preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $html, $m) ? $m[1] : '';
}

function sign_in(Client $c, string $user, string $pass = TEST_PASSWORD): array
{
    $token = csrf_from($c->get('login.php')['body']);
    return $c->post('login.php', ['csrf_token' => $token, 'username' => $user, 'password' => $pass]);
}
