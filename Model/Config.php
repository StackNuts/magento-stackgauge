<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Model\ScopeInterface;
use StackNuts\StackGauge\Api\DeclaresCadenceInterface;

/**
 * Reads StackGauge's own store-scoped system config (Stores > Configuration > Advanced >
 * StackGauge), decrypting the two encrypted fields on the way out.
 */
class Config
{
    private const XML_PATH_ENABLED = 'stacknuts_stackgauge/general/enabled';
    private const XML_PATH_ENDPOINT_URL = 'stacknuts_stackgauge/general/endpoint_url';
    private const XML_PATH_SITE_ID = 'stacknuts_stackgauge/general/site_id';
    private const XML_PATH_API_KEY = 'stacknuts_stackgauge/general/api_key';
    private const XML_PATH_HMAC_SECRET = 'stacknuts_stackgauge/general/hmac_secret';
    private const XML_PATH_DISABLED_REPORTERS = 'stacknuts_stackgauge/general/disabled_reporters';
    private const XML_PATH_ADDITIONAL_LOG_FILES = 'stacknuts_stackgauge/general/additional_log_files';
    private const XML_PATH_LOG_LEVEL = 'stacknuts_stackgauge/log/log_level';
    private const XML_PATH_SLOTS = 'stacknuts_stackgauge/cadence/%s_slots';
    private const MAX_SLOTS = 12;
    private const XML_PATH_SELF_PROBE_ENABLED = 'stacknuts_stackgauge/general/self_probe_enabled';

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param EncryptorInterface $encryptor
     * @param Json $json
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor,
        private readonly Json $json
    ) {
    }

    /**
     * Whether StackGauge is enabled for this store.
     *
     * @param int|null $storeId
     */
    public function isEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * The dashboard endpoint URL, or null if not configured.
     *
     * @param int|null $storeId
     */
    public function getEndpointUrl(?int $storeId = null): ?string
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_ENDPOINT_URL, ScopeInterface::SCOPE_STORE, $storeId);

        return $value !== null && $value !== '' ? (string)$value : null;
    }

    /**
     * The dashboard's site identifier for this store, or null if not configured.
     *
     * @param int|null $storeId
     */
    public function getSiteId(?int $storeId = null): ?string
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_SITE_ID, ScopeInterface::SCOPE_STORE, $storeId);

        return $value !== null && $value !== '' ? (string)$value : null;
    }

    /**
     * The api_key field's backend_model only encrypts on save via the admin form.
     *
     * ScopeConfigInterface returns the raw ciphertext straight from core_config_data/env.php,
     * so it must be decrypted here.
     *
     * @param int|null $storeId
     */
    public function getApiKey(?int $storeId = null): ?string
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_API_KEY, ScopeInterface::SCOPE_STORE, $storeId);

        return $value ? $this->encryptor->decrypt($value) : null;
    }

    /**
     * The HMAC secret used to sign outbound payloads, decrypted, or null if not configured.
     *
     * @param int|null $storeId
     */
    public function getHmacSecret(?int $storeId = null): ?string
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_HMAC_SECRET, ScopeInterface::SCOPE_STORE, $storeId);

        return $value ? $this->encryptor->decrypt($value) : null;
    }

    /**
     * Reporter codes checked in the admin "Disabled Reporters" multiselect - every reporter
     * actually registered with Model\ReporterPool, built-in or third-party alike, since that's
     * what the admin dropdown's own options are now derived from (see
     * Model\System\Config\Source\ReporterList). Nothing checked (the default) means every
     * reporter runs: a brand-new reporter a future release ships, or a third-party module
     * registers, has no way to appear in an admin's already-saved selection, so an opt-in list
     * would leave it silently off until someone remembers to go re-check it. Opt-out means
     * zero action is ever needed for a reporter to start running.
     *
     * Deliberately just the raw checked list - Model\ReporterPool does its own filtering
     * directly against this, rather than this class computing "every reporter minus these"
     * itself, which would need to know the full universe of registered reporters and create a
     * circular dependency with ReporterPool (which already depends on this class).
     *
     * @param int|null $storeId
     * @return string[]
     */
    public function getDisabledReporterCodes(?int $storeId = null): array
    {
        $value = (string)$this->scopeConfig->getValue(
            self::XML_PATH_DISABLED_REPORTERS,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }

    /**
     * How many evenly spaced runs a cadence tier is split into (1 to 12).
     *
     * @param string $cadence One of DeclaresCadenceInterface::CADENCE_*.
     */
    public function getSlotCount(string $cadence): int
    {
        $key = $cadence === DeclaresCadenceInterface::CADENCE_DAILY ? 'daily' : 'hourly';
        $value = (int)$this->scopeConfig->getValue(sprintf(self::XML_PATH_SLOTS, $key));

        return min(self::MAX_SLOTS, max(1, $value));
    }

    /**
     * Log files (relative to var/log) LogReporter shows a recent-lines tail for.
     *
     * Each has an admin-given display name. A row containing a path separator or ".." is
     * dropped rather than passed through, since
     * LogReporter turns this straight into a Filesystem::readFile() call and this free text
     * is admin-typed. A blank "name" falls back to the filename itself.
     *
     * @param int|null $storeId
     * @return array<string, string> filename => display name
     */
    public function getMonitoredLogFiles(?int $storeId = null): array
    {
        $raw = $this->scopeConfig->getValue(self::XML_PATH_ADDITIONAL_LOG_FILES, ScopeInterface::SCOPE_STORE, $storeId);
        if (!$raw) {
            return [];
        }

        try {
            $rows = $this->json->unserialize((string)$raw);
        } catch (\InvalidArgumentException) {
            return [];
        }

        if (!is_array($rows)) {
            return [];
        }

        $files = [];
        foreach ($rows as $row) {
            $file = trim((string)($row['file'] ?? ''));
            if ($file === '' || str_contains($file, '/') || str_contains($file, '\\') || str_contains($file, '..')) {
                continue;
            }

            $name = trim((string)($row['name'] ?? ''));
            $files[$file] = $name !== '' ? $name : $file;
        }

        return $files;
    }

    /**
     * The admin-configured minimum log level - one of LogLevel::LEVEL_*.
     *
     * @param int|null $storeId
     */
    public function getLogLevel(?int $storeId = null): int
    {
        return (int)$this->scopeConfig->getValue(self::XML_PATH_LOG_LEVEL, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * Whether the storefront self-probe (see Model\StorefrontProbe) runs as part of the heartbeat.
     *
     * @param int|null $storeId
     */
    public function isSelfProbeEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_SELF_PROBE_ENABLED, ScopeInterface::SCOPE_STORE, $storeId);
    }
}
