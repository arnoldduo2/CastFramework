<?php

declare(strict_types=1);

namespace Cast\Contracts;

/**
 * Optional second half of a {@see ModuleStore}: remembers which plan (tier) this install is on. The file store and the database store
 * have it. An app that keeps plans in its own licence table can instead set `modules.tier` in config/modules.php to a function.
 */
interface TierStore
{
    /** The plan name (one of `modules.tiers`), or null when none was saved. */
    public function tier(): ?string;

    public function setTier(string $tier): void;
}
