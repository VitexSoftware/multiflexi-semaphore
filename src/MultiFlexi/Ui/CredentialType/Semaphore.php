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

namespace MultiFlexi\Ui\CredentialType;

/**
 * Semaphore UI credential form helper.
 *
 * Validates SEMAPHORE_URL / SEMAPHORE_TOKEN and probes /api/user.
 *
 * @author Vitex <info@vitexsoftware.cz>
 */
class Semaphore extends \MultiFlexi\Ui\CredentialFormHelperPrototype
{
    public function finalize(): void
    {
        $urlField = $this->credential->getFields()->getFieldByCode('SEMAPHORE_URL');
        $tokenField = $this->credential->getFields()->getFieldByCode('SEMAPHORE_TOKEN');

        $url = $urlField ? (string) $urlField->getValue() : '';
        $token = $tokenField ? (string) $tokenField->getValue() : '';

        if ($url === '') {
            $this->addItem(new \Ease\TWB4\Alert('danger', _('Semaphore endpoint URI is not set')));
            parent::finalize();

            return;
        }

        if ($token === '') {
            $this->addItem(new \Ease\TWB4\Alert('danger', _('Semaphore API token is not set')));
            parent::finalize();

            return;
        }

        $probe = self::probeUser($url, $token);

        if (!$probe['success']) {
            $this->addItem(new \Ease\TWB4\Alert('danger', $probe['message']));
            parent::finalize();

            return;
        }

        $this->addItem(new \Ease\TWB4\Alert('success', $probe['message']));
        parent::finalize();
    }

    /**
     * Probe Semaphore /api/user with the configured token.
     *
     * @return array{success: bool, message: string}
     */
    public static function probeUser(string $url, string $token): array
    {
        $ch = curl_init(rtrim($url, '/').'/api/user');
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
            return ['success' => false, 'message' => sprintf(_('Semaphore server unreachable: %s'), curl_strerror($errno))];
        }

        if ($httpCode === 401 || $httpCode === 403) {
            return ['success' => false, 'message' => sprintf(_('Authentication failed (HTTP %d) — check the API token'), $httpCode)];
        }

        if ($httpCode !== 200) {
            return ['success' => false, 'message' => sprintf(_('Unexpected Semaphore response HTTP %d'), $httpCode)];
        }

        $username = '';

        if (\is_string($body) && $body !== '') {
            $decoded = json_decode($body, true);

            if (\is_array($decoded)) {
                $username = (string) ($decoded['username'] ?? '');
            }
        }

        return [
            'success' => true,
            'message' => $username !== ''
                ? sprintf(_('Semaphore API is reachable, token belongs to %s'), $username)
                : _('Semaphore API is reachable and the token is accepted'),
        ];
    }
}
