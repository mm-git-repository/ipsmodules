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
            @GSGW_RefreshIpsScheduleOverview($gatewayId);
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
    protected function loadScheduleRules(): array
    {
        $raw = $this->ReadPropertyString('ScheduleRules');
        $rules = json_decode($raw, true);

        return is_array($rules) ? $rules : [];
    }

    /**
     * @param list<array<string, mixed>> $rules
     * @return list<string>
     */
    protected function activeScheduleLines(array $rules): array
    {
        $lines = [];
        $map = ['mo' => 'Mo', 'tu' => 'Di', 'we' => 'Mi', 'th' => 'Do', 'fr' => 'Fr', 'sa' => 'Sa', 'so' => 'So'];
        foreach ($rules as $rule) {
            if (!is_array($rule) || empty($rule['active'])) {
                continue;
            }
            $days = [];
            foreach ($map as $k => $label) {
                if (!empty($rule[$k])) {
                    $days[] = $label;
                }
            }
            $valve = isset($rule['valve']) ? ('V' . $rule['valve'] . ' ') : '';
            $lines[] = trim($valve . ($rule['start'] ?? '?') . '–' . ($rule['end'] ?? '?') . ' ' . implode(',', $days));
        }

        return $lines;
    }

    protected function isNowInRule(array $rule, int $nowTs): bool
    {
        if (empty($rule['active'])) {
            return false;
        }
        $w = (int) date('N', $nowTs);
        $dayKeys = [1 => 'mo', 2 => 'tu', 3 => 'we', 4 => 'th', 5 => 'fr', 6 => 'sa', 7 => 'so'];
        $key = $dayKeys[$w] ?? 'mo';
        if (empty($rule[$key])) {
            return false;
        }
        $start = $this->parseHm((string) ($rule['start'] ?? '00:00'));
        $end = $this->parseHm((string) ($rule['end'] ?? '00:00'));
        if ($start === null || $end === null) {
            return false;
        }
        $minutes = ((int) date('G', $nowTs)) * 60 + (int) date('i', $nowTs);
        if ($end <= $start) {
            return $minutes >= $start || $minutes < $end;
        }

        return $minutes >= $start && $minutes < $end;
    }

    protected function parseHm(string $hm): ?int
    {
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', trim($hm), $m)) {
            return null;
        }
        $h = (int) $m[1];
        $min = (int) $m[2];
        if ($h > 23 || $min > 59) {
            return null;
        }

        return $h * 60 + $min;
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
