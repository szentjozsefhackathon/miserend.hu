/*
 * Migrations for existing databases.
 * These ALTER TABLE statements are safe to run multiple times (IF NOT EXISTS guards where possible).
 * For fresh Docker setups, 02-schema.sql already includes these columns.
 */

USE miserend;

-- Add boundaries_checked_at to templomok table
-- Tracks when the boundary check (checkBoundariesForOne) was last run for each church.
-- NULL means never checked. Used by checkBoundaries() to prioritize which churches to process next.
-- Reset to NULL automatically when lat/lon coordinates change (Church::save()).
ALTER TABLE `templomok`
    ADD COLUMN IF NOT EXISTS `boundaries_checked_at` TIMESTAMP NULL DEFAULT NULL AFTER `updated_at`;

-- Index for efficient ordering in checkBoundaries() query
ALTER TABLE `templomok`
    ADD INDEX IF NOT EXISTS `boundaries_checked_at` (`boundaries_checked_at`);
