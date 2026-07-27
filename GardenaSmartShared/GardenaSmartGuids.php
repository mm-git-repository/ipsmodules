<?php

declare(strict_types=1);

/**
 * Shared GUIDs for Gardena Smart Gateway dataflow and modules.
 */
final class GardenaSmartGuids
{
    public const LIBRARY = '{078F2CCC-248B-E9F8-37A2-89E15868706B}';

    public const GATEWAY = '{8F3A2C1D-9E4B-4A7C-B2D1-6E5F4A3B2C1D}';
    public const VALVE = '{8F3A2C1D-9E4B-4A7C-B2D1-6E5F4A3B2C1E}';
    public const POWER = '{8F3A2C1D-9E4B-4A7C-B2D1-6E5F4A3B2C1F}';
    public const SENSOR = '{8F3A2C1D-9E4B-4A7C-B2D1-6E5F4A3B2C20}';

    /** Parent → Child (state / events) */
    public const TX_CHILDREN = '{8F3A2C1D-9E4B-4A7C-B2D1-6E5F4A3B2C30}';

    /** Child → Parent (commands) */
    public const TX_PARENT = '{8F3A2C1D-9E4B-4A7C-B2D1-6E5F4A3B2C31}';
}
