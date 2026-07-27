<?php

declare(strict_types=1);

/**
 * Internal helper container so IP-Symcon treats the shared folder
 * as a valid module directory inside the library.
 */
class GardenaSmartShared extends IPSModuleStrict
{
    private const MODULE_VERSION = '1.0';
    private const MODULE_BUILD = 9;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterVariableString('ModuleVersion', 'Modulversion', '', 1);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->SetValue('ModuleVersion', self::MODULE_VERSION . ' (Build ' . self::MODULE_BUILD . ')');
        $this->SetSummary('Interne Hilfsbibliothek');
    }

    public function GetConfigurationForm(): string
    {
        return json_encode([
            'elements' => [
                [
                    'type'    => 'Label',
                    'caption' => 'Interne Gardena-Hilfsbibliothek. Keine Instanz erforderlich.',
                ],
            ],
            'status' => [
                ['code' => 102, 'icon' => 'active', 'caption' => 'Aktiv'],
            ],
        ], JSON_UNESCAPED_UNICODE) ?: '{}';
    }
}
