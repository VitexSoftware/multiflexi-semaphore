<?php

declare(strict_types=1);

/**
 * This file is part of the MultiFlexi package
 *
 * https://multiflexi.eu/
 *
 * (c) Vítězslav Dvořák <http://vitexsoftware.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace MultiFlexi\CredentialProtoType;

/**
 * Semaphore UI REST API credential prototype.
 *
 * Supplies SEMAPHORE_URL + SEMAPHORE_TOKEN to MultiFlexi applications and
 * actions (e.g. MultiFlexi\Action\Semaphore which triggers Semaphore tasks).
 *
 * @author Vitex <info@vitexsoftware.cz>
 *
 * @no-named-arguments
 */
class Semaphore extends \MultiFlexi\CredentialProtoType implements \MultiFlexi\credentialTypeInterface, \MultiFlexi\checkableCredentialInterface
{
    public static string $logo = 'Semaphore.svg';

    public function __construct()
    {
        parent::__construct();

        $urlField = new \MultiFlexi\ConfigField('SEMAPHORE_URL', 'url', _('Semaphore Endpoint URI'), _('Semaphore UI base URL (without /api suffix)'));
        $urlField->setHint('https://semaphore.proxy.spojenet.cz')->setValue('');

        $tokenField = new \MultiFlexi\ConfigField('SEMAPHORE_TOKEN', 'password', _('Semaphore API Token'), _('Semaphore API token (Bearer) from user API keys'));
        $tokenField->setHint('')->setValue('');

        $this->configFieldsProvided->addField($urlField);
        $this->configFieldsProvided->addField($tokenField);
    }

    #[\Override]
    public function prepareConfigForm(): void
    {
    }

    public function name(): string
    {
        return _('Semaphore UI');
    }

    public function description(): string
    {
        return _('Semaphore UI REST API credentials (endpoint + API token) for triggering Ansible playbooks');
    }

    #[\Override]
    public function uuid(): string
    {
        return 'd9fecb43-8518-4686-9156-f26035da39f8';
    }

    #[\Override]
    public function logo(): string
    {
        return self::$logo;
    }

    /**
     * curl error codes that indicate a TLS/certificate problem rather than a
     * transient network failure. Literal values on purpose: the CURLE_*
     * constants are only defined when ext-curl is loaded, and this class is
     * instantiated by `credential-prototype:sync` in minimal environments
     * (e.g. package build/test containers) where ext-curl may be absent.
     */
    private const TLS_ERRNOS = [
        35, // CURLE_SSL_CONNECT_ERROR
        51, // CURLE_PEER_FAILED_VERIFICATION
        58, // CURLE_SSL_CERTPROBLEM
        59, // CURLE_SSL_CIPHER
        60, // CURLE_SSL_CACERT
        77, // CURLE_SSL_CACERT_BADFILE
        82, // CURLE_SSL_CRL_BADFILE
        83, // CURLE_SSL_ISSUER_ERROR
    ];

    #[\Override]
    public function checkAvailability(): \MultiFlexi\CredentialCheckResult
    {
        if (!\function_exists('curl_init')) {
            return new \MultiFlexi\CredentialCheckResult(
                \MultiFlexi\CredentialState::Misconfigured,
                _('PHP curl extension is not available on this host'),
                time(),
            );
        }

        $url = (string) ($this->configFieldsProvided->getFieldByCode('SEMAPHORE_URL')?->getValue() ?? '');
        $token = (string) ($this->configFieldsProvided->getFieldByCode('SEMAPHORE_TOKEN')?->getValue() ?? '');

        if ($url === '' || $token === '') {
            $missing = array_keys(array_filter([
                'SEMAPHORE_URL' => $url === '',
                'SEMAPHORE_TOKEN' => $token === '',
            ]));

            return new \MultiFlexi\CredentialCheckResult(
                \MultiFlexi\CredentialState::Misconfigured,
                sprintf(_('Required fields not set: %s'), implode(', ', $missing)),
                time(),
            );
        }

        // /api/user requires a valid API token and returns the current user
        $endpoint = rtrim($url, '/').'/api/user';
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            \CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Authorization: Bearer '.$token,
            ],
            \CURLOPT_CONNECTTIMEOUT => 5,
            \CURLOPT_TIMEOUT => 5,
            \CURLOPT_RETURNTRANSFER => true,
        ]);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $httpCode = (int) curl_getinfo($ch, \CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            if (\in_array($errno, self::TLS_ERRNOS, true)) {
                return new \MultiFlexi\CredentialCheckResult(
                    \MultiFlexi\CredentialState::Misconfigured,
                    sprintf(_('Semaphore server TLS certificate is not trusted: %s'), curl_strerror($errno)),
                    time(),
                );
            }

            return new \MultiFlexi\CredentialCheckResult(
                \MultiFlexi\CredentialState::Unavailable,
                sprintf(_('Semaphore server unreachable: %s'), curl_strerror($errno)),
                time(),
                60,
            );
        }

        if ($httpCode === 401 || $httpCode === 403) {
            return new \MultiFlexi\CredentialCheckResult(
                \MultiFlexi\CredentialState::Misconfigured,
                sprintf(_('Authentication failed (HTTP %d) — check SEMAPHORE_TOKEN'), $httpCode),
                time(),
            );
        }

        if ($httpCode >= 500) {
            return new \MultiFlexi\CredentialCheckResult(
                \MultiFlexi\CredentialState::Unavailable,
                sprintf(_('Semaphore server returned HTTP %d'), $httpCode),
                time(),
                60,
            );
        }

        if ($httpCode !== 200) {
            return new \MultiFlexi\CredentialCheckResult(
                \MultiFlexi\CredentialState::Misconfigured,
                sprintf(_('Unexpected Semaphore response HTTP %d'), $httpCode),
                time(),
            );
        }

        $username = '';

        if (\is_string($body) && $body !== '') {
            $decoded = json_decode($body, true);

            if (\is_array($decoded)) {
                $username = (string) ($decoded['username'] ?? '');
            }
        }

        $detail = $username !== '' ? sprintf(_('Semaphore reachable, token belongs to %s'), $username) : _('Semaphore reachable');

        return new \MultiFlexi\CredentialCheckResult(\MultiFlexi\CredentialState::Available, $detail, time(), 300);
    }
}
