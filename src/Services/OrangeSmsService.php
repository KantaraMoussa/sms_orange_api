<?php

namespace App\Services;

use Mediumart\Orange\SMS\SMS;
use Mediumart\Orange\SMS\Http\SMSClient;
use Exception;

/**
 * Encapsulates all interaction with the mediumart/orange-sms SDK so the rest
 * of the application never depends on it directly (cahier des charges §28).
 */
class OrangeSmsService
{
    private string $clientId;
    private string $clientSecret;
    private string $senderName;
    private string $countryCode;
    private string $tokenCacheFile;
    private string $balanceCacheFile;

    public function __construct(
        string $clientId,
        string $clientSecret,
        string $senderName,
        string $countryCode,
        ?string $tokenCacheFile = null,
        ?string $balanceCacheFile = null
    ) {
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->senderName = $senderName;
        $this->countryCode = $countryCode;
        $this->tokenCacheFile = $tokenCacheFile ?? __DIR__ . '/../../storage/cache/orange_token.json';
        $this->balanceCacheFile = $balanceCacheFile ?? __DIR__ . '/../../storage/cache/orange_balance.json';
    }

    /**
     * Reuse the cached access token while it is valid instead of requesting
     * a new one on every call (cahier des charges §52).
     */
    private function authenticate(): SMSClient
    {
        $cached = $this->readCachedToken();

        if ($cached !== null) {
            return SMSClient::getInstance($cached['access_token']);
        }

        $auth = SMSClient::authorize($this->clientId, $this->clientSecret);

        if (empty($auth['access_token'])) {
            throw new Exception('AUTH_ERROR: Orange did not return an access token.');
        }

        $this->writeCachedToken($auth);

        return SMSClient::getInstance($auth['access_token']);
    }

    private function readCachedToken(): ?array
    {
        if (!is_file($this->tokenCacheFile)) {
            return null;
        }

        $data = json_decode(file_get_contents($this->tokenCacheFile), true);

        if (!is_array($data) || empty($data['access_token']) || empty($data['expires_at'])) {
            return null;
        }

        // Refresh 60s before actual expiry to avoid using a token that dies mid-request.
        if (time() >= ($data['expires_at'] - 60)) {
            return null;
        }

        return $data;
    }

    private function writeCachedToken(array $auth): void
    {
        $dir = dirname($this->tokenCacheFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $payload = [
            'access_token' => $auth['access_token'],
            'expires_at' => time() + (int) ($auth['expires_in'] ?? 3600),
        ];

        file_put_contents($this->tokenCacheFile, json_encode($payload));
    }

    private function sms(): SMS
    {
        return new SMS($this->authenticate());
    }

    /**
     * @throws Exception on API/network failure — caller decides how to classify/log it.
     */
    public function sendSms(string $to, string $message, ?string $from = null): array
    {
        try {
            return $this->sms()
                ->message($message)
                ->from($from ?? $this->senderName)
                ->to($to)
                ->send();
        } catch (Exception $e) {
            throw new Exception('API_ERROR: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Le solde est mis en cache brièvement (§27 : "dernière synchronisation")
     * pour éviter un appel réseau réel à Orange à chaque frappe/filtre dans
     * l'écran Résultats académiques (aperçu en direct) — problème de
     * performance identifié dès l'audit initial (AUDIT.md §7). Passer
     * `$maxAgeSeconds = 0` force une vérification fraîche, utilisé pour le
     * contrôle bloquant avant création réelle d'une campagne (§20).
     */
    public function getBalance(int $maxAgeSeconds = 20): array
    {
        $cached = $this->readCachedBalance($maxAgeSeconds);
        if ($cached !== null) {
            return $cached;
        }

        $balance = $this->sms()->balance($this->countryCode)[0] ?? [];
        $this->writeCachedBalance($balance);

        return $balance;
    }

    private function readCachedBalance(int $maxAgeSeconds): ?array
    {
        if ($maxAgeSeconds <= 0 || !is_file($this->balanceCacheFile)) {
            return null;
        }

        $data = json_decode(file_get_contents($this->balanceCacheFile), true);
        if (!is_array($data) || !isset($data['cached_at'], $data['balance'])) {
            return null;
        }

        if (time() - (int) $data['cached_at'] > $maxAgeSeconds) {
            return null;
        }

        return $data['balance'];
    }

    private function writeCachedBalance(array $balance): void
    {
        $dir = dirname($this->balanceCacheFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        file_put_contents($this->balanceCacheFile, json_encode(['balance' => $balance, 'cached_at' => time()]));
    }

    public function getStatistics(): array
    {
        return $this->sms()->statistics($this->countryCode);
    }

    public function getHistory(): array
    {
        return $this->sms()->ordersHistory($this->countryCode);
    }

    public function getTotalSmsSent(): int
    {
        $stats = $this->getStatistics();
        $countryStats = $stats['partnerStatistics']['statistics'][0]['serviceStatistics'][0]['countryStatistics'] ?? [];

        $total = 0;
        foreach ($countryStats as $stat) {
            $total += $stat['usage'] ?? 0;
        }

        return $total;
    }
}
