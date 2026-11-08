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

namespace MultiFlexi\Action;

use MultiFlexi\Application;
use MultiFlexi\Credential;

/**
 * Trigger a Semaphore UI task (template) via the Semaphore REST API.
 *
 * Endpoint and API token are taken from the Semaphore credential prototype
 * (SEMAPHORE_URL + SEMAPHORE_TOKEN) selected in the action configuration.
 * Project ID and template ID are configured per RunTemplate.
 *
 * @author vitex
 *
 * @no-named-arguments
 */
class Semaphore extends \MultiFlexi\CommonAction
{
    /**
     * Default job environment keys forwarded to the Semaphore task.
     * EVENT_* variables (injected by EventRule env_mapping) are always forwarded.
     */
    public const DEFAULT_ENV_KEYS = 'EVENT_INVERSION,EVENT_EVIDENCE,EVENT_OPERATION,EVENT_RECORD_ID,EVENT_DOCUMENT_URI,ABRAFLEXI_COMPANY,ABRAFLEXI_CUSTOMER,INVOICE_ID,DOCUMENTID,CUSTOMER';

    /**
     * Module Caption.
     *
     * @return string
     */
    public static function name()
    {
        return _('Semaphore');
    }

    /**
     * Module Description.
     *
     * @return string
     */
    public static function description()
    {
        return _('Trigger Semaphore UI task (template) via REST API');
    }

    /**
     * Is this Action Suitable for Application.
     */
    #[\Override]
    public static function usableForApp(Application $app): bool
    {
        return \is_object($app);
    }

    /**
     * Resolve Semaphore endpoint and API token.
     *
     * Priority: explicitly selected credential > job environment
     * (SEMAPHORE_URL/SEMAPHORE_TOKEN injected from a credential assigned
     * to the RunTemplate) > action config fields url/token.
     *
     * @return array{url: string, token: string}
     */
    public function resolveSemaphoreEndpoint(\MultiFlexi\Job $job): array
    {
        $url = '';
        $token = '';

        $credentialId = (int) $this->getDataValue('credential');

        if ($credentialId > 0) {
            $credential = new Credential($credentialId);

            if ($credential->getMyKey()) {
                $data = $credential->getData();
                $url = (string) ($data['SEMAPHORE_URL'] ?? '');
                $token = (string) ($data['SEMAPHORE_TOKEN'] ?? '');
            }
        }

        if ($url === '' || $token === '') {
            $env = $job->getEnv();
            $url = $url !== '' ? $url : (string) ($env['SEMAPHORE_URL'] ?? '');
            $token = $token !== '' ? $token : (string) ($env['SEMAPHORE_TOKEN'] ?? '');
        }

        if ($url === '') {
            $url = (string) $this->getDataValue('url');
        }

        if ($token === '') {
            $token = (string) $this->getDataValue('token');
        }

        return ['url' => rtrim($url, '/'), 'token' => $token];
    }

    /**
     * Build the environment map forwarded to the Semaphore task.
     *
     * @return array<string, string>
     */
    public function buildTaskEnvironment(\MultiFlexi\Job $job): array
    {
        $env = $job->getEnv();

        $environment = [
            'JOB_ID' => (string) $job->getMyKey(),
            'RUNTEMPLATE_ID' => (string) $this->runtemplate->getMyKey(),
            'EXITCODE' => (string) $job->getDataValue('exitcode'),
        ];

        // Static extra environment from the action config (JSON object)
        $extra = $this->getDataValue('environment');

        if (!empty($extra)) {
            $decoded = json_decode((string) $extra, true);

            if (\is_array($decoded)) {
                foreach ($decoded as $key => $value) {
                    if (\is_scalar($value)) {
                        $environment[(string) $key] = (string) $value;
                    }
                }
            }
        }

        // Job env keys listed in env_keys (comma separated); empty = defaults
        $keys = $this->getDataValue('env_keys');
        $keys = ($keys === null || $keys === '') ? self::DEFAULT_ENV_KEYS : (string) $keys;

        foreach (array_map('trim', explode(',', $keys)) as $key) {
            if ($key !== '' && isset($env[$key]) && !isset($environment[$key])) {
                $environment[$key] = (string) $env[$key];
            }
        }

        // Always forward EVENT_* variables (event-driven jobs)
        foreach ($env as $key => $value) {
            if (str_starts_with((string) $key, 'EVENT_') && !isset($environment[$key])) {
                $environment[$key] = (string) $value;
            }
        }

        return $environment;
    }

    /**
     * Build the Semaphore "add task" request payload.
     *
     * @return array<string, mixed>
     */
    public function buildTaskPayload(\MultiFlexi\Job $job): array
    {
        $payload = [
            'template_id' => (int) $this->getDataValue('template_id'),
        ];

        $environment = $this->buildTaskEnvironment($job);

        if ($environment !== []) {
            // Semaphore expects the task environment as a JSON-encoded string
            $payload['environment'] = (string) json_encode($environment);
        }

        return $payload;
    }

    /**
     * Perform Action - launch a Semaphore task from a template.
     */
    #[\Override]
    public function perform(\MultiFlexi\Job $job): void
    {
        $endpoint = $this->resolveSemaphoreEndpoint($job);
        $projectId = (int) $this->getDataValue('project_id');
        $templateId = (int) $this->getDataValue('template_id');

        if ($endpoint['url'] === '' || $endpoint['token'] === '' || $projectId <= 0 || $templateId <= 0) {
            $this->addStatusMessage(
                _('Semaphore credential (endpoint + token), project_id and template_id are required'),
                'error',
            );

            return;
        }

        $data = (string) json_encode($this->buildTaskPayload($job));

        $ch = curl_init();
        curl_setopt($ch, \CURLOPT_URL, $endpoint['url'].'/api/project/'.$projectId.'/tasks');
        curl_setopt($ch, \CURLOPT_HTTPHEADER, [
            'Authorization: Bearer '.$endpoint['token'],
            'Content-Type: application/json',
            'Accept: application/json',
        ]);
        curl_setopt($ch, \CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, \CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, \CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, \CURLOPT_USERAGENT, \Ease\Shared::appName().' '.\Ease\Shared::appVersion());
        curl_setopt($ch, \CURLOPT_VERBOSE, (bool) \Ease\Shared::cfg('API_DEBUG', false));

        $response = curl_exec($ch);
        $curlInfo = curl_getinfo($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError !== '') {
            $this->addStatusMessage(sprintf(_('Semaphore request failed: %s'), $curlError), 'error');

            return;
        }

        // Semaphore returns 201 Created when the task is queued
        $success = ($curlInfo['http_code'] === 201);
        $message = $success
            ? sprintf(_('Semaphore task for template #%d in project #%d queued'), $templateId, $projectId)
            : sprintf(_('Semaphore returned HTTP %d: %s'), (int) $curlInfo['http_code'], (string) $response);
        $this->addStatusMessage($message, $success ? 'success' : 'error');
    }

    /**
     * Initial data for action configuration.
     *
     * @param string $mode Mode
     *
     * @return array Default configuration
     */
    #[\Override]
    public function initialData(string $mode): array
    {
        return [
            'credential' => '',
            'project_id' => '',
            'template_id' => '',
            'env_keys' => '',
            'environment' => '',
        ];
    }
}
