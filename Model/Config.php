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

class Config
{
    private const XML_PATH_ENABLED = 'stacknuts_stackgauge/general/enabled';
    private const XML_PATH_ENDPOINT_URL = 'stacknuts_stackgauge/general/endpoint_url';
    private const XML_PATH_SITE_ID = 'stacknuts_stackgauge/general/site_id';
    private const XML_PATH_API_KEY = 'stacknuts_stackgauge/general/api_key';
    private const XML_PATH_HMAC_SECRET = 'stacknuts_stackgauge/general/hmac_secret';
    private const XML_PATH_REPORTERS = 'stacknuts_stackgauge/general/reporters';
    private const XML_PATH_ADDITIONAL_LOG_FILES = 'stacknuts_stackgauge/general/additional_log_files';
    private const XML_PATH_LOG_LEVEL = 'stacknuts_stackgauge/log/log_level';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor,
        private readonly Json $json
    ) {
    }

    public function isEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getEndpointUrl(?int $storeId = null): ?string
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_ENDPOINT_URL, ScopeInterface::SCOPE_STORE, $storeId);

        return $value !== null && $value !== '' ? (string)$value : null;
    }

    public function getSiteId(?int $storeId = null): ?string
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_SITE_ID, ScopeInterface::SCOPE_STORE, $storeId);

        return $value !== null && $value !== '' ? (string)$value : null;
    }

    /**
     * The api_key field's backend_model only encrypts on save via the admin form -
     * ScopeConfigInterface returns the raw ciphertext straight from core_config_data/env.php,
     * so it must be decrypted here.
     */
    public function getApiKey(?int $storeId = null): ?string
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_API_KEY, ScopeInterface::SCOPE_STORE, $storeId);

        return $value ? $this->encryptor->decrypt($value) : null;
    }

    public function getHmacSecret(?int $storeId = null): ?string
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_HMAC_SECRET, ScopeInterface::SCOPE_STORE, $storeId);

        return $value ? $this->encryptor->decrypt($value) : null;
    }

    /**
     * Built-in reporter codes enabled via the admin "Enabled Reporters" multiselect.
     * Third-party reporters registered through di.xml are not covered by this list -
     * see Api\ReporterInterface for why that's a deliberate v1 boundary.
     *
     * @return string[]
     */
    public function getEnabledReporterCodes(?int $storeId = null): array
    {
        $value = (string)$this->scopeConfig->getValue(self::XML_PATH_REPORTERS, ScopeInterface::SCOPE_STORE, $storeId);

        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }

    /**
     * Log files (relative to var/log) LogReporter shows a recent-lines tail for, each with an
     * admin-given display name. A row containing a path separator or ".." is dropped rather
     * than passed through, since LogReporter turns this straight into a
     * Filesystem::readFile() call and this free text is admin-typed. A blank "name" falls
     * back to the filename itself.
     *
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

    public function getLogLevel(?int $storeId = null): int
    {
        return (int)$this->scopeConfig->getValue(self::XML_PATH_LOG_LEVEL, ScopeInterface::SCOPE_STORE, $storeId);
    }
}
