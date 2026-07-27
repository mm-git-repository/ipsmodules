<?php

declare(strict_types=1);

/**
 * Shared helpers for Gardena child device modules (soft parent via GatewayInstanceID).
 */
trait GardenaSmartChildTrait
{
    protected function notifyGatewaySchedules(): void
    {
        $gatewayId = $this->getGatewayInstanceId();
        if ($gatewayId > 0 && IPS_InstanceExists($gatewayId)) {
            @GSGW_RefreshScheduleOverview($gatewayId);
        }
    }

    protected function getGatewayInstanceId(): int
    {
        try {
            return (int) $this->ReadPropertyInteger('GatewayInstanceID');
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * @param array<string, mixed> $command
     * @return array<string, mixed>
     */
    protected function sendCommandToGateway(array $command): array
    {
        $gatewayId = $this->getGatewayInstanceId();
        if ($gatewayId <= 0 || !IPS_InstanceExists($gatewayId)) {
            return ['ok' => false, 'error' => 'Kein Gateway zugewiesen'];
        }
        $json = @GSGW_DispatchCommand($gatewayId, json_encode($command, JSON_UNESCAPED_UNICODE) ?: '{}');
        $decoded = json_decode((string) $json, true);

        return is_array($decoded) ? $decoded : ['ok' => false, 'error' => 'Keine Antwort vom Gateway'];
    }

    /** @return list<array<string, mixed>> */
    protected function loadDeviceScheduleRules(): array
    {
        // Live attribute is readable immediately (Property needs ApplyChanges)
        try {
            $attr = $this->ReadAttributeString('DeviceScheduleRulesData');
            if (is_string($attr) && $attr !== '') {
                $rules = json_decode($attr, true);
                if (is_array($rules)) {
                    return $rules;
                }
            }
        } catch (Throwable) {
            // attribute may be missing on older Power instances
        }
        try {
            $raw = $this->ReadPropertyString('DeviceScheduleRules');
        } catch (Throwable) {
            return [];
        }
        $rules = json_decode($raw, true);

        return is_array($rules) ? $rules : [];
    }

    protected function saveDeviceScheduleRules(array $rules): void
    {
        $json = json_encode($rules, JSON_UNESCAPED_UNICODE) ?: '[]';
        try {
            $this->WriteAttributeString('DeviceScheduleRulesData', $json);
        } catch (Throwable) {
            // ignore if attribute not registered
        }
        // Keep property in sync for the configuration form (applied on next ApplyChanges)
        try {
            @IPS_SetProperty($this->InstanceID, 'DeviceScheduleRules', $json);
        } catch (Throwable) {
            // ignore
        }
    }

    /** After form ApplyChanges: property is authoritative — copy into live attribute. */
    protected function syncScheduleRulesFromProperty(): void
    {
        try {
            $raw = $this->ReadPropertyString('DeviceScheduleRules');
        } catch (Throwable) {
            return;
        }
        if (!is_string($raw) || $raw === '') {
            return;
        }
        $rules = json_decode($raw, true);
        if (!is_array($rules)) {
            return;
        }
        try {
            $this->WriteAttributeString('DeviceScheduleRulesData', json_encode($rules, JSON_UNESCAPED_UNICODE) ?: '[]');
        } catch (Throwable) {
            // ignore
        }
    }

    /**
     * @param list<array<string, mixed>> $rules
     * @return list<string>
     */
    protected function deviceScheduleLines(array $rules): array
    {
        require_once dirname(__DIR__) . '/GardenaSmartShared/GardenaSmartSchedules.php';

        return GardenaSmartSchedules::formatRulesLines($rules);
    }

    protected function buildVisualizationHtml(string $webDir, string $prefix, array $initial): string
    {
        $htmlPath = $webDir . '/web/device-tile.html';
        $cssPath = $webDir . '/web/device-tile.css';
        $jsPath = $webDir . '/web/device-tile.js';
        if (!is_file($htmlPath) || !is_file($cssPath) || !is_file($jsPath)) {
            return '<div>Web-Kachel fehlt</div>';
        }
        $html = (string) file_get_contents($htmlPath);
        $css = (string) file_get_contents($cssPath);
        $js = (string) file_get_contents($jsPath);
        $json = json_encode($initial, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS);
        if ($json === false) {
            $json = '{}';
        }

        return str_replace(
            ['{{INLINE_CSS}}', '{{INLINE_JS}}', '{{INITIAL_JSON}}', '{{PREFIX}}'],
            [$css, $js, $json, $prefix],
            $html
        );
    }
}
