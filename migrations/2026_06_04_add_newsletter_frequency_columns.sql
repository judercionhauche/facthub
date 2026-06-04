-- Add frequency column to newsletter_subscribers table
-- This migration adds support for email frequency preferences
-- Created: 2026-06-04

ALTER TABLE `newsletter_subscribers`
ADD COLUMN `frequency` enum('immediate', 'daily', 'weekly', 'never') DEFAULT 'weekly' AFTER `status`;

-- Commit message for documentation purposes:
-- Add frequency column to newsletter_subscribers for email delivery preferences (immediate, daily, weekly, never)
