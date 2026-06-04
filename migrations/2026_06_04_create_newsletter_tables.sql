-- Newsletter System Tables
-- Created: 2026-06-04
-- For admin newsletter dashboard functionality

-- ═════════════════════════════════════════════════════════════════
-- Newsletter Campaigns Table
-- ═════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `newsletter_campaigns` (
  `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `title` varchar(255) NOT NULL,
  `content` longtext NOT NULL,
  `sender_name` varchar(255),
  `sender_email` varchar(255),
  `status` enum('draft','scheduled','sending','sent','paused') DEFAULT 'draft',
  `sent_date` datetime,
  `scheduled_at` datetime,
  `sent_at` datetime,
  `recipient_count` int(11) DEFAULT 0,
  `open_rate` decimal(5,2) DEFAULT 0,
  `click_rate` decimal(5,2) DEFAULT 0,
  `created_by` varchar(255) NOT NULL,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `status` (`status`),
  INDEX `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ═════════════════════════════════════════════════════════════════
-- Newsletter Subscribers Table
-- ═════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `newsletter_subscribers` (
  `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `email` varchar(255) NOT NULL UNIQUE,
  `user_id` int(11),
  `status` enum('active','unsubscribed','bounced') DEFAULT 'active',
  `research_interests` text,
  `geography` varchar(255),
  `institution` varchar(255),
  `role` enum('researcher','funder','both'),
  `funding_preference` varchar(255),
  `subscribed_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `unsubscribed_at` datetime,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `email` (`email`),
  INDEX `status` (`status`),
  INDEX `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ═════════════════════════════════════════════════════════════════
-- Newsletter Templates Table
-- ═════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `newsletter_templates` (
  `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `name` varchar(255) NOT NULL,
  `content` longtext NOT NULL,
  `created_by` varchar(255) NOT NULL,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ═════════════════════════════════════════════════════════════════
-- Campaign Sends Table (track individual sends)
-- ═════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `newsletter_sends` (
  `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `campaign_id` int(11) NOT NULL,
  `subscriber_id` int(11) NOT NULL,
  `email` varchar(255) NOT NULL,
  `status` enum('queued','sending','sent','bounced','failed') DEFAULT 'queued',
  `sent_at` datetime,
  `error_message` text,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`campaign_id`) REFERENCES `newsletter_campaigns`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`subscriber_id`) REFERENCES `newsletter_subscribers`(`id`) ON DELETE CASCADE,
  INDEX `campaign_subscriber` (`campaign_id`, `subscriber_id`),
  INDEX `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ═════════════════════════════════════════════════════════════════
-- Email Opens Tracking Table
-- ═════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `newsletter_opens` (
  `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `campaign_id` int(11) NOT NULL,
  `subscriber_id` int(11) NOT NULL,
  `opened_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `user_agent` varchar(500),
  `ip_address` varchar(45),
  FOREIGN KEY (`campaign_id`) REFERENCES `newsletter_campaigns`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`subscriber_id`) REFERENCES `newsletter_subscribers`(`id`) ON DELETE CASCADE,
  INDEX `campaign_subscriber` (`campaign_id`, `subscriber_id`),
  INDEX `opened_at` (`opened_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ═════════════════════════════════════════════════════════════════
-- Link Clicks Tracking Table
-- ═════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `newsletter_clicks` (
  `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `campaign_id` int(11) NOT NULL,
  `subscriber_id` int(11) NOT NULL,
  `url` varchar(2048) NOT NULL,
  `clicked_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `user_agent` varchar(500),
  `ip_address` varchar(45),
  FOREIGN KEY (`campaign_id`) REFERENCES `newsletter_campaigns`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`subscriber_id`) REFERENCES `newsletter_subscribers`(`id`) ON DELETE CASCADE,
  INDEX `campaign_subscriber_url` (`campaign_id`, `subscriber_id`, `url`(255)),
  INDEX `clicked_at` (`clicked_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ═════════════════════════════════════════════════════════════════
-- Audience Segments (for targeting)
-- ═════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `newsletter_segments` (
  `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `name` varchar(255) NOT NULL,
  `description` text,
  `filters` json,
  `subscriber_count` int(11) DEFAULT 0,
  `created_by` varchar(255) NOT NULL,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ═════════════════════════════════════════════════════════════════
-- Sample Data
-- ═════════════════════════════════════════════════════════════════

-- Insert sample MJML template
INSERT IGNORE INTO `newsletter_templates` (`name`, `content`, `created_by`, `created_at`) VALUES (
  'Professional Newsletter',
  '<mjml>
  <mj-body>
    <mj-section>
      <mj-column>
        <mj-image width="300px" src="https://facthub.org/logo.png"></mj-image>
      </mj-column>
    </mj-section>
    <mj-section background-color="#f9fafb">
      <mj-column>
        <mj-text font-size="24px" font-weight="bold" color="#0066cc">
          {{campaign_title}}
        </mj-text>
        <mj-divider border-color="#e5e7eb"></mj-divider>
      </mj-column>
    </mj-section>
    <mj-section>
      <mj-column>
        <mj-text font-size="16px" color="#111827">
          {{campaign_content}}
        </mj-text>
        <mj-button href="{{cta_url}}" background-color="#0066cc">
          {{cta_text}}
        </mj-button>
      </mj-column>
    </mj-section>
    <mj-section background-color="#f3f4f6">
      <mj-column>
        <mj-text font-size="12px" color="#6b7280" align="center">
          You received this because you are subscribed to {{list_name}}.<br/>
          <a href="{{unsubscribe_url}}">Unsubscribe</a>
        </mj-text>
      </mj-column>
    </mj-section>
  </mj-body>
</mjml>',
  'admin@facthub.org',
  NOW()
);

-- Insert sample research template
INSERT IGNORE INTO `newsletter_templates` (`name`, `content`, `created_by`, `created_at`) VALUES (
  'Research Updates Template',
  '<mjml>
  <mj-body>
    <mj-section>
      <mj-column>
        <mj-text font-size="20px" font-weight="bold">
          Research Updates - {{month}} {{year}}
        </mj-text>
      </mj-column>
    </mj-section>
    <mj-section>
      <mj-column>
        <mj-text font-weight="bold" color="#0066cc">Featured Research</mj-text>
        <mj-text>{{featured_research}}</mj-text>
        <mj-button href="{{research_link}}">View Research</mj-button>
      </mj-column>
    </mj-section>
    <mj-section>
      <mj-column>
        <mj-text font-weight="bold" color="#0066cc">Funding Opportunities</mj-text>
        <mj-text>{{funding_info}}</mj-text>
      </mj-column>
    </mj-section>
  </mj-body>
</mjml>',
  'admin@facthub.org',
  NOW()
);

-- Sample segment for active researchers
INSERT IGNORE INTO `newsletter_segments` (`name`, `description`, `filters`, `created_by`, `created_at`) VALUES (
  'Active Researchers',
  'All active researcher accounts with verified email',
  JSON_OBJECT('role', 'researcher', 'status', 'active', 'verified', true),
  'admin@facthub.org',
  NOW()
);

-- Sample segment for funding-interested
INSERT IGNORE INTO `newsletter_segments` (`name`, `description`, `filters`, `created_by`, `created_at`) VALUES (
  'Funding Interest',
  'Researchers interested in funding opportunities',
  JSON_OBJECT('role', 'researcher', 'funding_preference', 'interested'),
  'admin@facthub.org',
  NOW()
);
