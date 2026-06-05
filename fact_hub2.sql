-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: localhost
-- Generation Time: May 19, 2026 at 01:19 PM
-- Server version: 10.4.28-MariaDB
-- PHP Version: 8.2.4

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `fact_hub2`
--

-- --------------------------------------------------------

--
-- Table structure for table `ai_summaries`
--

CREATE TABLE `ai_summaries` (
  `id` int(11) NOT NULL,
  `entity_type` enum('researcher','funding_call') NOT NULL,
  `entity_id` int(11) NOT NULL,
  `summary` text NOT NULL,
  `model_used` varchar(80) NOT NULL,
  `prompt_hash` varchar(64) NOT NULL,
  `token_input` int(11) NOT NULL DEFAULT 0,
  `token_output` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `ai_summaries`
--

INSERT INTO `ai_summaries` (`id`, `entity_type`, `entity_id`, `summary`, `model_used`, `prompt_hash`, `token_input`, `token_output`, `created_at`) VALUES
(7, 'researcher', 3, 'Judercio Jose Nhauche is a Master\'s student at Ashesi University specializing in sustainable agriculture and food security, with a focus on precision agriculture and data-driven farming solutions for smallholder farmers in Sub-Saharan Africa. His research interests include climate-smart farming practices such as efficient irrigation systems, soil health monitoring, and crop optimization through data analytics. He is also committed to advancing women\'s participation in agriculture and rural development, and is open to co-advising opportunities.', 'claude-sonnet-4-6', 'd43130469ae1ac8cf875575c6ab3b30ac664c9ac34f832e16678af0a4f42fbab', 219, 117, '2026-05-01 00:20:52'),
(8, 'researcher', 6, 'Kingston Afua is a pharmacist interested in food security and nutrition. With expertise in affordability of healthy and sustainable diets and food system solutions, they focus on connecting healthcare and food security. They are open to co-advising and have a strong commitment to research that impacts food systems and nutrition.', 'manual', 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855', 0, 0, '2026-05-14 16:25:58'),
(9, 'researcher', 11, 'Maria Garcia is a Research Scientist at University of Madrid. I specialize in sustainable agriculture and food systems Their research focuses on Food Security, Nutrition & Health. Key topics include: agriculture, sustainability, nutrition.', 'auto-generated', '', 0, 0, '2026-05-14 16:30:24'),
(10, 'researcher', 12, 'Ahmed Hassan is a Assistant Professor at University of Cairo. Working on climate-resilient crops and food security in Africa Their research focuses on Ecosystems & Biodiversity. Key topics include: climate, resilience, agriculture.', 'auto-generated', '', 0, 0, '2026-05-14 16:30:34'),
(11, 'researcher', 13, 'John Doe is a Researcher at Cambridge. Just a researcher Their research focuses on Food Security, Nutrition & Health. Key topics include: food security.', 'auto-generated', '', 0, 0, '2026-05-15 01:51:18'),
(12, 'researcher', 14, 'LocalTest Researcher Key topics include: food security.', 'auto-generated', '', 0, 0, '2026-05-18 16:09:26'),
(13, 'researcher', 15, 'LocalTest2 Researcher Key topics include: food security.', 'auto-generated', '', 0, 0, '2026-05-18 16:10:43'),
(14, 'researcher', 16, 'LocalTest3 Researcher Key topics include: food security.', 'auto-generated', '', 0, 0, '2026-05-18 16:12:02'),
(15, 'researcher', 17, 'LocalTest4 Researcher Key topics include: food security.', 'auto-generated', '', 0, 0, '2026-05-18 16:12:14'),
(16, 'researcher', 18, 'LocalTest5 Researcher Key topics include: food security.', 'auto-generated', '', 0, 0, '2026-05-18 16:12:40'),
(17, 'researcher', 19, 'LocalTest6 Researcher Key topics include: food security.', 'auto-generated', '', 0, 0, '2026-05-18 16:12:55'),
(18, 'researcher', 20, 'LocalTest7 Researcher Key topics include: food security.', 'auto-generated', '', 0, 0, '2026-05-18 16:13:04'),
(19, 'researcher', 21, 'LocalTest8 Researcher Key topics include: food security.', 'auto-generated', '', 0, 0, '2026-05-18 16:13:16'),
(20, 'researcher', 22, 'LocalTest9 Researcher Key topics include: food security.', 'auto-generated', '', 0, 0, '2026-05-18 16:13:38'),
(21, 'researcher', 23, 'LocalTest10 Researcher Key topics include: food security.', 'auto-generated', '', 0, 0, '2026-05-18 16:14:03'),
(22, 'researcher', 24, 'LocalTest11 Researcher Key topics include: food security.', 'auto-generated', '', 0, 0, '2026-05-18 16:14:33'),
(23, 'researcher', 25, 'FinalTest Researcher Key topics include: food security.', 'auto-generated', '', 0, 0, '2026-05-18 16:15:11'),
(24, 'researcher', 26, 'FinalTest2 Researcher Key topics include: food security.', 'auto-generated', '', 0, 0, '2026-05-18 16:15:25'),
(25, 'researcher', 27, 'Final Tester Key topics include: food security.', 'auto-generated', '', 0, 0, '2026-05-18 16:15:48'),
(26, 'researcher', 28, 'Judercio Nhauche is a Research Data at Massachusetts Institute of Technology (MIT). Graduate Business Analytics student and research data analyst focused on food systems, agricultural innovation, AI-driven research matching, and global development analytics. Currently supporting the FACT Alliance at MIT J-WAFS through research coordination, funding discovery, and analytics initiatives. Their research focuses on Markets. Key topics include: ai for food systems, agricultural data analytics, research funding, food security, predictive analytics.', 'auto-generated', '', 0, 0, '2026-05-18 16:34:53'),
(27, 'researcher', 29, 'Complete Test is a Research Data & Analytics Intern at MIT &  Extra Spaces Key topics include: ai for food systems, agricultural data analytics, research funding.', 'auto-generated', '', 0, 0, '2026-05-18 16:35:24'),
(28, 'researcher', 30, 'Judercio Nhauche is a Research Data & Analytics Intern at Massachusetts Institute of Technology (MIT). Graduate Business Analytics student and research data analyst focused on food systems, agricultural innovation, AI-driven research matching, and global development analytics. Currently supporting the FACT Alliance at MIT J-WAFS through research coordination, funding discovery, and analytics initiatives. Key topics include: ai for food systems, agricultural data analytics, research funding, food security, predictive analytics.', 'auto-generated', '', 0, 0, '2026-05-18 16:37:33');

-- --------------------------------------------------------

--
-- Table structure for table `api_balances`
--

CREATE TABLE `api_balances` (
  `id` int(11) NOT NULL,
  `provider` varchar(50) NOT NULL,
  `total_budget` decimal(10,2) DEFAULT NULL,
  `remaining_balance` decimal(10,2) DEFAULT NULL,
  `usage_this_month` decimal(10,2) NOT NULL DEFAULT 0.00,
  `usage_trend_pct` decimal(5,2) DEFAULT NULL,
  `status` enum('active','paused','emergency','suspended') DEFAULT 'active',
  `last_checked_at` datetime DEFAULT NULL,
  `last_check_error` text DEFAULT NULL,
  `checked_by` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `api_usage`
--

CREATE TABLE `api_usage` (
  `id` int(11) NOT NULL,
  `model` varchar(80) NOT NULL,
  `purpose` varchar(120) NOT NULL,
  `token_input` int(11) NOT NULL DEFAULT 0,
  `token_output` int(11) NOT NULL DEFAULT 0,
  `cost_usd` decimal(10,6) NOT NULL DEFAULT 0.000000,
  `duration_ms` int(11) NOT NULL DEFAULT 0,
  `status` enum('ok','error','retry') NOT NULL DEFAULT 'ok',
  `error_code` varchar(20) DEFAULT NULL,
  `triggered_by` varchar(200) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `endpoint` varchar(255) DEFAULT NULL,
  `method` varchar(10) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `api_usage`
--

INSERT INTO `api_usage` (`id`, `model`, `purpose`, `token_input`, `token_output`, `cost_usd`, `duration_ms`, `status`, `error_code`, `triggered_by`, `created_at`, `endpoint`, `method`) VALUES
(1, 'claude-haiku-4-5-20251001', 'search_parse', 0, 0, 0.000000, 125, 'error', '401', 'verification', '2026-04-30 22:26:43', NULL, NULL),
(2, 'claude-haiku-4-5-20251001', 'search_parse', 102, 51, 0.000286, 1033, 'ok', NULL, 'verification', '2026-04-30 22:27:48', NULL, NULL),
(3, 'claude-haiku-4-5-20251001', 'search_parse', 102, 47, 0.000270, 916, 'ok', NULL, 'verification', '2026-04-30 22:28:29', NULL, NULL),
(4, 'claude-sonnet-4-6', 'researcher_summary', 144, 90, 0.001782, 2251, 'ok', NULL, 'worker:generate_summary', '2026-04-30 23:58:24', NULL, NULL),
(5, 'claude-sonnet-4-6', 'researcher_summary', 144, 88, 0.001752, 2270, 'ok', NULL, 'test', '2026-04-30 23:59:51', NULL, NULL),
(6, 'claude-sonnet-4-6', 'researcher_summary', 144, 89, 0.001767, 2191, 'ok', NULL, 'test', '2026-05-01 00:00:19', NULL, NULL),
(7, 'claude-sonnet-4-6', 'researcher_summary', 144, 92, 0.001812, 2344, 'ok', NULL, 'test', '2026-05-01 00:00:41', NULL, NULL),
(8, 'claude-sonnet-4-6', 'researcher_summary', 144, 91, 0.001797, 1917, 'ok', NULL, 'test', '2026-05-01 00:19:21', NULL, NULL),
(9, 'claude-sonnet-4-6', 'researcher_summary', 211, 129, 0.002568, 2224, 'ok', NULL, 'test', '2026-05-01 00:19:56', NULL, NULL),
(10, 'claude-sonnet-4-6', 'researcher_summary', 157, 85, 0.001746, 2168, 'ok', NULL, 'worker:generate_summary', '2026-05-01 00:20:29', NULL, NULL),
(11, 'claude-sonnet-4-6', 'researcher_summary', 219, 117, 0.002412, 1912, 'ok', NULL, 'test', '2026-05-01 00:20:52', NULL, NULL),
(12, 'claude-haiku-4-5-20251001', 'search_parse', 98, 33, 0.000210, 913, 'ok', NULL, 'search:juderciojosenhauche@gmail.com', '2026-05-01 13:45:25', NULL, NULL),
(13, 'claude-haiku-4-5-20251001', 'search_parse', 98, 34, 0.000214, 834, 'ok', NULL, 'search:juderciojosenhauche@gmail.com', '2026-05-01 13:45:47', NULL, NULL),
(14, 'claude-haiku-4-5-20251001', 'search_parse', 96, 34, 0.000213, 583, 'ok', NULL, 'search:juderciojosenhauche@gmail.com', '2026-05-01 13:46:23', NULL, NULL),
(15, 'claude-haiku-4-5-20251001', 'search_parse', 104, 34, 0.000219, 767, 'ok', NULL, 'search:juderciojosenhauche@gmail.com', '2026-05-01 13:46:48', NULL, NULL),
(16, 'claude-haiku-4-5-20251001', 'search_parse', 104, 34, 0.000219, 901, 'ok', NULL, 'search:juderciojosenhauche@gmail.com', '2026-05-01 13:47:03', NULL, NULL),
(17, 'claude-haiku-4-5-20251001', 'search_parse', 105, 35, 0.000224, 714, 'ok', NULL, 'search:juderciojosenhauche@gmail.com', '2026-05-01 13:47:08', NULL, NULL),
(18, 'claude-haiku-4-5-20251001', 'search_parse', 98, 35, 0.000218, 737, 'ok', NULL, 'search:juderciojosenhauche@gmail.com', '2026-05-01 13:47:24', NULL, NULL),
(19, 'claude-haiku-4-5-20251001', 'search_parse', 98, 35, 0.000218, 776, 'ok', NULL, 'search:juderciojosenhauche@gmail.com', '2026-05-01 13:47:45', NULL, NULL),
(20, 'claude-haiku-4-5-20251001', 'search_parse', 300, 107, 0.000668, 1293, 'ok', NULL, 'search:judercionhauche@gmail.com', '2026-05-01 15:16:02', NULL, NULL),
(21, 'claude-haiku-4-5-20251001', 'search_parse', 300, 105, 0.000660, 1096, 'ok', NULL, 'search:judercionhauche@gmail.com', '2026-05-01 15:16:31', NULL, NULL),
(22, 'claude-haiku-4-5-20251001', 'search_parse', 300, 108, 0.000672, 1532, 'ok', NULL, 'search:judercionhauche@gmail.com', '2026-05-10 00:50:25', NULL, NULL),
(23, 'claude-haiku-4-5-20251001', 'search_parse', 300, 102, 0.000648, 1032, 'ok', NULL, 'search:judercionhauche@gmail.com', '2026-05-10 00:50:41', NULL, NULL),
(24, 'claude-haiku-4-5-20251001', 'search_parse', 300, 55, 0.000460, 1170, 'ok', NULL, 'search:judercionhauche@gmail.com', '2026-05-10 00:50:50', NULL, NULL),
(25, 'claude-haiku-4-5-20251001', 'search_parse', 301, 144, 0.000817, 1357, 'ok', NULL, 'search:sixt@mit.edu', '2026-05-10 01:38:54', NULL, NULL),
(26, 'claude-haiku-4-5-20251001', 'search_parse', 305, 70, 0.000524, 808, 'ok', NULL, 'search:sixt@mit.edu', '2026-05-10 01:39:22', NULL, NULL),
(27, 'claude-haiku-4-5-20251001', 'search_parse', 301, 124, 0.000737, 1280, 'ok', NULL, 'search:sixt@mit.edu', '2026-05-10 01:52:04', NULL, NULL),
(28, 'claude-haiku-4-5-20251001', 'search_parse', 300, 76, 0.000544, 1041, 'ok', NULL, 'search:sixt@mit.edu', '2026-05-10 01:52:43', NULL, NULL),
(29, 'claude-haiku-4-5-20251001', 'search_parse', 300, 49, 0.000436, 887, 'ok', NULL, 'search:sixt@mit.edu', '2026-05-10 02:03:31', NULL, NULL),
(30, 'claude-haiku-4-5-20251001', 'search_parse', 300, 85, 0.000580, 1293, 'ok', NULL, 'search:sixt@mit.edu', '2026-05-10 02:04:05', NULL, NULL),
(31, 'claude-haiku-4-5-20251001', 'search_parse', 304, 58, 0.000475, 2409, 'ok', NULL, 'search:sixt@mit.edu', '2026-05-10 02:04:26', NULL, NULL),
(32, 'claude-haiku-4-5-20251001', 'search_parse', 310, 82, 0.000576, 1605, 'ok', NULL, 'search:judercionhauche@gmail.com', '2026-05-11 23:51:52', NULL, NULL),
(33, 'claude-haiku-4-5-20251001', 'search_parse', 301, 83, 0.000573, 1055, 'ok', NULL, 'search:juderciojosenhauche@gmail.com', '2026-05-12 00:19:53', NULL, NULL),
(34, 'claude-haiku-4-5-20251001', 'search_parse', 302, 61, 0.000486, 1195, 'ok', NULL, 'search:judercionhauche@gmail.com', '2026-05-14 19:18:35', NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `audit_log`
--

CREATE TABLE `audit_log` (
  `id` int(11) NOT NULL,
  `actor_email` varchar(200) NOT NULL,
  `actor_role` varchar(50) NOT NULL DEFAULT 'admin',
  `action` varchar(100) NOT NULL,
  `target_type` varchar(50) DEFAULT NULL,
  `target_id` int(11) DEFAULT NULL,
  `target_email` varchar(200) DEFAULT NULL,
  `detail` text DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `old_status` varchar(30) DEFAULT NULL,
  `new_status` varchar(30) DEFAULT NULL,
  `reason` varchar(500) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `audit_log`
--

INSERT INTO `audit_log` (`id`, `actor_email`, `actor_role`, `action`, `target_type`, `target_id`, `target_email`, `detail`, `ip`, `created_at`, `old_status`, `new_status`, `reason`) VALUES
(1, 'system', 'researcher', 'login_failed', 'authentication', NULL, 'judercionhauche@gmail.com', 'Invalid credentials from IP: ::1', '::1', '2026-05-12 00:19:21', NULL, NULL, NULL),
(2, 'system', 'researcher', 'login_failed', 'authentication', NULL, 'judercionhauche@gmail.com', 'Invalid credentials from IP: ::1', '::1', '2026-05-12 00:19:27', NULL, NULL, NULL),
(3, 'system', 'researcher', 'login_failed', 'authentication', NULL, 'judercionhauche@gmail.com', 'Invalid credentials from IP: ::1', '::1', '2026-05-12 00:19:35', NULL, NULL, NULL),
(4, 'judercionhauche@gmail.com', 'admin', 'delete_researcher', 'researcher', 3, 'juderciojosenhauche@gmail.com', 'Judercio Jose Nhauche', '::1', '2026-05-14 15:04:10', NULL, NULL, NULL),
(5, 'judercionhauche@gmail.com', 'admin', 'delete_researcher', 'researcher', 4, 'sixt@mit.edu', 'Greg Sixt', '::1', '2026-05-14 15:04:15', NULL, NULL, NULL),
(6, 'judercionhauche@gmail.com', 'admin', 'delete_user', 'user', 5, 'sixt@mit.edu', 'role: researcher', '::1', '2026-05-14 15:06:12', NULL, NULL, NULL),
(7, 'judercionhauche@gmail.com', 'admin', 'delete_user', 'user', 4, 'juderciojosenhauche@gmail.com', 'role: researcher', '::1', '2026-05-14 15:06:23', NULL, NULL, NULL),
(8, 'system', 'researcher', 'login_failed', 'authentication', NULL, 'juderciojosenhauche@gmail.com', 'Invalid credentials from IP: ::1', '::1', '2026-05-14 15:06:29', NULL, NULL, NULL),
(9, 'system', 'researcher', 'login_failed', 'authentication', NULL, 'juderciojosenhauche@gmail.com', 'Invalid credentials from IP: ::1', '::1', '2026-05-14 15:22:21', NULL, NULL, NULL),
(10, 'system', 'researcher', 'login_failed', 'authentication', NULL, 'juderciojosenhauche@gmail.com', 'Invalid credentials from IP: ::1', '::1', '2026-05-14 15:22:24', NULL, NULL, NULL),
(11, 'system', 'researcher', 'login_failed', 'authentication', NULL, 'judercionhauche@gmail.com', 'Invalid credentials from IP: ::1', '::1', '2026-05-14 15:22:31', NULL, NULL, NULL),
(12, 'system', 'researcher', 'login_failed', 'authentication', NULL, 'judercionhauche@gmail.com', 'Invalid credentials from IP: ::1', '::1', '2026-05-14 15:22:40', NULL, NULL, NULL),
(13, 'judercionhauche@gmail.com', 'admin', 'soft_delete_user', 'user', 6, 'juderciojosenhauche@gmail.com', 'James Rodriguez', '::1', '2026-05-14 15:43:20', NULL, NULL, NULL),
(14, 'judercionhauche@gmail.com', 'admin', 'delete_researcher', 'researcher', 11, NULL, NULL, '::1', '2026-05-14 17:39:09', NULL, NULL, NULL),
(15, 'judercionhauche@gmail.com', 'admin', 'delete_researcher', 'researcher', 6, NULL, NULL, '::1', '2026-05-14 17:39:13', NULL, NULL, NULL),
(16, 'judercionhauche@gmail.com', 'admin', 'delete_researcher', 'researcher', 12, NULL, NULL, '::1', '2026-05-14 17:39:50', NULL, NULL, NULL),
(17, 'judercionhauche@gmail.com', 'admin', 'soft_delete_researcher', 'researcher', 11, 'mariagarcia@example.com', 'Maria Garcia', '::1', '2026-05-14 18:02:50', NULL, NULL, NULL),
(18, 'judercionhauche@gmail.com', 'admin', 'soft_delete_researcher', 'researcher', 12, 'ahmedhassan@example.com', 'Ahmed Hassan', '::1', '2026-05-14 18:02:54', NULL, NULL, NULL),
(19, 'judercionhauche@gmail.com', 'admin', 'soft_delete_researcher', 'researcher', 12, 'ahmedhassan@example.com', 'Ahmed Hassan', '::1', '2026-05-14 18:02:59', NULL, NULL, NULL),
(20, 'judercionhauche@gmail.com', 'admin', 'soft_delete_researcher', 'researcher', 12, 'ahmedhassan@example.com', 'Ahmed Hassan', '::1', '2026-05-14 18:03:05', NULL, NULL, NULL),
(21, 'judercionhauche@gmail.com', 'admin', 'soft_delete_researcher', 'researcher', 11, 'mariagarcia@example.com', 'Maria Garcia', '::1', '2026-05-14 18:03:14', NULL, NULL, NULL),
(22, 'judercionhauche@gmail.com', 'admin', 'delete_researcher', 'researcher', 13, NULL, NULL, '::1', '2026-05-15 01:56:59', NULL, NULL, NULL),
(23, 'judercionhauche@gmail.com', 'admin', 'soft_delete_researcher', 'researcher', 13, 'judercio.nhauche@cleanplateclubs.com', 'John Doe', '::1', '2026-05-15 01:57:09', NULL, NULL, NULL),
(24, 'system', 'researcher', 'login_failed', 'authentication', NULL, 'judercio.nhauche@cleanplateclubs.com', 'Invalid credentials from IP: ::1', '::1', '2026-05-15 02:00:19', NULL, NULL, NULL),
(25, 'system', 'researcher', 'login_failed', 'authentication', NULL, 'judercio.nhauche@cleanplateclubs.com', 'Invalid credentials from IP: ::1', '::1', '2026-05-15 02:00:24', NULL, NULL, NULL),
(26, 'judercionhauche@gmail.com', 'admin', 'activate_user', 'user', 14, NULL, NULL, '::1', '2026-05-15 02:01:32', NULL, NULL, NULL),
(27, 'system', 'researcher', 'login_failed', 'authentication', NULL, 'judercio.nhauche@cleanplateclubs.com', 'Invalid credentials from IP: ::1', '::1', '2026-05-15 02:01:50', NULL, NULL, NULL),
(28, 'system', 'researcher', 'login_failed', 'authentication', NULL, 'judercio.nhauche@cleanplateclubs.com', 'Invalid credentials from IP: ::1', '::1', '2026-05-15 02:01:55', NULL, NULL, NULL),
(29, 'judercionhauche@gmail.com', 'admin', 'update_role', 'user', 14, 'judercio.nhauche@cleanplateclubs.com', 'researcher → funder', '::1', '2026-05-15 02:03:06', NULL, NULL, NULL),
(30, 'judercionhauche@gmail.com', 'admin', 'update_role', 'user', 14, 'judercio.nhauche@cleanplateclubs.com', 'funder → researcher', '::1', '2026-05-15 02:04:40', NULL, NULL, NULL),
(31, 'judercionhauche@gmail.com', 'admin', 'soft_delete_researcher', 'researcher', 12, 'ahmedhassan@example.com', 'Ahmed Hassan', '::1', '2026-05-15 02:21:05', NULL, NULL, NULL),
(32, 'judercionhauche@gmail.com', 'admin', 'soft_delete_researcher', 'researcher', 13, 'judercio.nhauche@cleanplateclubs.com', 'John Doe', '::1', '2026-05-15 02:21:15', NULL, NULL, NULL),
(33, 'judercionhauche@gmail.com', 'admin', 'soft_delete_researcher', 'researcher', 11, 'mariagarcia@example.com', 'Maria Garcia', '::1', '2026-05-15 02:21:24', NULL, NULL, NULL),
(34, 'judercionhauche@gmail.com', 'admin', 'soft_delete_researcher', 'researcher', 12, 'ahmedhassan@example.com', 'Ahmed Hassan', '::1', '2026-05-15 02:23:41', NULL, NULL, NULL),
(35, 'judercionhauche@gmail.com', 'admin', 'soft_delete_researcher', 'researcher', 6, 'jnhauche@brandeis.edu', 'Kingston Afua', '::1', '2026-05-15 02:23:53', NULL, NULL, NULL),
(36, 'judercionhauche@gmail.com', 'admin', 'soft_delete_researcher', 'researcher', 13, 'judercio.nhauche@cleanplateclubs.com', 'John Doe', '::1', '2026-05-15 02:23:58', NULL, NULL, NULL),
(37, 'judercionhauche@gmail.com', 'admin', 'soft_delete_researcher', 'researcher', 12, 'ahmedhassan@example.com', 'Ahmed Hassan', '::1', '2026-05-15 02:24:19', NULL, NULL, NULL),
(38, 'judercionhauche@gmail.com', 'admin', 'activate_user', 'user', 14, NULL, NULL, '::1', '2026-05-15 02:26:02', NULL, NULL, NULL),
(39, 'judercionhauche@gmail.com', 'admin', 'restore_researcher', 'researcher', 13, 'judercio.nhauche@cleanplateclubs.com', 'John Doe', '::1', '2026-05-15 02:45:47', NULL, NULL, NULL),
(40, 'system', 'researcher', 'login_failed', 'authentication', NULL, 'judercio.nhauche@cleanplateclubs.com', 'Invalid credentials from IP: ::1', '::1', '2026-05-15 02:48:34', NULL, NULL, NULL),
(41, 'system', 'researcher', 'login_failed', 'authentication', NULL, 'judercio.nhauche@cleanplateclubs.com', 'Invalid credentials from IP: ::1', '::1', '2026-05-15 02:49:20', NULL, NULL, NULL),
(42, 'system', 'researcher', 'login_failed', 'authentication', NULL, 'judercio.nhauche@cleanplateclubs.com', 'Invalid credentials from IP: ::1', '::1', '2026-05-15 02:56:19', NULL, NULL, NULL),
(43, 'system', 'admin', 'researcher_signup', '0', 28, 'finaltester@example.com', 'New researcher registration: Final Tester', '::1', '2026-05-18 16:15:48', NULL, NULL, NULL),
(44, 'system', 'admin', 'researcher_signup', '0', 29, 'juderciojosenhauche@gmail.com', 'New researcher registration: Judercio Nhauche', '::1', '2026-05-18 16:34:53', NULL, NULL, NULL),
(45, 'system', 'admin', 'researcher_signup', '0', 30, 'test.complete@example.com', 'New researcher registration: Complete Test', '::1', '2026-05-18 16:35:24', NULL, NULL, NULL),
(46, 'system', 'admin', 'researcher_signup', '0', 34, 'juderciojosenhauche@gmail.com', 'New researcher registration: Judercio Nhauche', '::1', '2026-05-18 16:37:33', NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `balance_alerts`
--

CREATE TABLE `balance_alerts` (
  `id` int(11) NOT NULL,
  `provider` varchar(50) NOT NULL,
  `severity` enum('warning','critical','emergency') NOT NULL,
  `threshold_pct` tinyint(4) NOT NULL,
  `remaining_balance` decimal(10,2) DEFAULT NULL,
  `message` text NOT NULL,
  `sent_to` varchar(255) NOT NULL,
  `sent_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `email_verifications`
--

CREATE TABLE `email_verifications` (
  `id` int(11) NOT NULL,
  `email` varchar(150) NOT NULL,
  `token` varchar(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `resend_count` int(11) NOT NULL DEFAULT 0,
  `last_resent_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `email_verifications`
--

INSERT INTO `email_verifications` (`id`, `email`, `token`, `expires_at`, `used_at`, `resend_count`, `last_resent_at`, `created_at`) VALUES
(2, 'sixt@mit.edu', '32f7c5041108010490b067073d3ed017e45ebd1c3e6c52e8daa50f188b55a972', '2026-04-28 17:25:18', '2026-04-27 17:27:07', 0, NULL, '2026-04-27 15:25:18'),
(3, 'juderciojosenhauche@gmail.com', '61e0fd939c2470d1cc6048e8b955b3dc69ade3df5afc8fe7a7153fea0917790a', '2026-05-15 17:39:00', '2026-05-14 17:41:55', 0, NULL, '2026-05-14 15:39:00'),
(4, 'jnhauche@brandeis.edu', '5dc96a99b23f026544a48114558911a9b0f31923cbf262c566ba54c5d1fe1e2d', '2026-05-15 17:57:19', NULL, 0, NULL, '2026-05-14 15:57:19'),
(5, 'simpletest@example.com', 'fdbb1163b670b01b1bc8cc8561fa82f3b817185e17dfc33804230c06697d79bc', '2026-05-15 18:02:23', NULL, 0, NULL, '2026-05-14 16:02:23'),
(6, 'successtest@example.com', '4be23e0e725e0452d98f42e471dcdcfe5ac546371534a143d3a7b317d2e029b3', '2026-05-15 18:10:06', NULL, 0, NULL, '2026-05-14 16:10:06'),
(7, 'verifytest@example.com', 'efefb16bf742eed719c9d5608973203be335bb497313fbc458d37a84d3e1afc8', '2026-05-15 18:10:13', NULL, 0, NULL, '2026-05-14 16:10:13'),
(8, 'finalsuccess@example.com', '85343405a829cb7c5c0e96223f3e28727f1054d17c41f6b805c9a16868805aed', '2026-05-15 18:10:41', NULL, 0, NULL, '2026-05-14 16:10:41'),
(9, 'mariagarcia@example.com', 'a89380f07122ed9a8e6c2de92c43d2ea3c475244235204df5ee6668ff9488679', '2026-05-15 18:28:11', NULL, 0, NULL, '2026-05-14 16:28:11'),
(10, 'ahmedhassan@example.com', '67b8d200ad1935f48ff9b75c33a2cb9fc31fb440c093cfcbf33d0a56c6cee5cf', '2026-05-15 18:30:34', NULL, 0, NULL, '2026-05-14 16:30:34'),
(11, 'judercio.nhauche@cleanplateclubs.com', '711b59f0e3cf37f51ea314f254045febbb37670b7c67115844be448d7249ff93', '2026-05-16 03:51:18', NULL, 0, NULL, '2026-05-15 01:51:18'),
(12, 'localtest@example.com', 'bb75d8e87405aacb4281cf3e621037502df1b4ffece7009e367b0cbd161d1e95', '2026-05-19 18:09:26', NULL, 0, NULL, '2026-05-18 16:09:26'),
(13, 'localtest2@example.com', '93fd9a25b9d2f8bfce5b1f9086f47039cfacef6c7792123a80de60ce811510eb', '2026-05-19 18:10:43', NULL, 0, NULL, '2026-05-18 16:10:43'),
(14, 'localtest3@example.com', 'f7ab987a25025c901a35d7d7e7a919e5def6d248ac1f0badcf36e2f66eb9e7b2', '2026-05-19 18:12:02', NULL, 0, NULL, '2026-05-18 16:12:02'),
(15, 'localtest4@example.com', 'cfbb480954feaf70103a3419292661ae6e4985ebc77872f72dfdf1972acdc0e6', '2026-05-19 18:12:14', NULL, 0, NULL, '2026-05-18 16:12:14'),
(16, 'localtest5@example.com', '0ee6ca784ee01187e709a035d93ba8c34fcd00676e890d5e6315c87748deb1f3', '2026-05-19 18:12:40', NULL, 0, NULL, '2026-05-18 16:12:40'),
(17, 'localtest6@example.com', '9dfb61fd4188a6bf364a486ced64a5284bd49a5894127784971d1881bae8d5db', '2026-05-19 18:12:55', NULL, 0, NULL, '2026-05-18 16:12:55'),
(18, 'localtest7@example.com', 'a6948145749f42ccb55a700fb2d84fa534d56d7f61364a9b5e4c1e5940381fe2', '2026-05-19 18:13:04', NULL, 0, NULL, '2026-05-18 16:13:04'),
(19, 'localtest8@example.com', '5ce4ecd2102b14e2b8dea1b4dd8d697246a0b23e7971a8ad17b8d0778140f8b9', '2026-05-19 18:13:16', NULL, 0, NULL, '2026-05-18 16:13:16'),
(20, 'localtest9@example.com', 'bfecefe791f731432abf9713e2a8c825f9f0b35f56199343cd8f540ee59a7ab9', '2026-05-19 18:13:38', NULL, 0, NULL, '2026-05-18 16:13:38'),
(21, 'localtest10@example.com', '65f94d0a6a4ddb58bdc8f94a56ef17f6ded84a1414efa525fac91d57ddf1b623', '2026-05-19 18:14:03', NULL, 0, NULL, '2026-05-18 16:14:03'),
(22, 'localtest11@example.com', '895fa2a45764fe7983492a833eda46af38635554bb9cf93ff772404332ae6251', '2026-05-19 18:14:33', NULL, 0, NULL, '2026-05-18 16:14:33'),
(23, 'finaltest@example.com', '8a0e8956a5baae08d77c77d10f095471c1020ec65c99d42d4980a534f0c9f51c', '2026-05-19 18:15:11', NULL, 0, NULL, '2026-05-18 16:15:11'),
(24, 'finaltest2@example.com', '220850e1d2513b6fd16e831a54160424101d69454c49a063be69ce13f5c1e14a', '2026-05-19 18:15:25', NULL, 0, NULL, '2026-05-18 16:15:25'),
(25, 'finaltester@example.com', '9e26a8a24eb0acdf60e9a567f86075d83d1e2b7da423e18aeed996f9e79daa56', '2026-05-19 18:15:48', NULL, 0, NULL, '2026-05-18 16:15:48'),
(26, 'juderciojosenhauche@gmail.com', '334fe41dbda743bfda91934420850c07581b5061b0b2f14c005a864614e91ffc', '2026-05-19 18:34:53', NULL, 0, NULL, '2026-05-18 16:34:53'),
(27, 'test.complete@example.com', 'd0111f2865c1ae5509c1187206d97aa040c0fb0e44665a5dc5b310dda2b5d65d', '2026-05-19 18:35:24', NULL, 0, NULL, '2026-05-18 16:35:24'),
(28, 'geography.test@example.com', '638aba262c897b682dd526abf38171e46781f88c87b28b5833c14b71bd17b0d8', '2026-05-19 18:36:24', NULL, 0, NULL, '2026-05-18 16:36:24'),
(29, 'juderciojosenhauche@gmail.com', 'f3c0188eb5adb70b87bef21a7e115a9adaecb99f35a8bc210a95101ce5ab3a22', '2026-05-19 18:36:34', NULL, 0, NULL, '2026-05-18 16:36:34'),
(30, 'debug.test@example.com', '4980cdb84786b59191c06f1070396c541248811bdcda6855db571e9641276be8', '2026-05-19 18:36:56', NULL, 0, NULL, '2026-05-18 16:36:56'),
(31, 'juderciojosenhauche@gmail.com', '8bb8d038b7218a15b25a3f0d289406262693eb465a7893e54d4311dfab05d9ea', '2026-05-19 18:37:33', NULL, 0, NULL, '2026-05-18 16:37:33');

-- --------------------------------------------------------

--
-- Table structure for table `funders`
--

CREATE TABLE `funders` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `status` enum('active','inactive','deleted') NOT NULL DEFAULT 'active',
  `first_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `organization` varchar(200) DEFAULT NULL,
  `org_type` varchar(100) DEFAULT NULL,
  `country` varchar(100) DEFAULT NULL,
  `website_url` varchar(255) DEFAULT NULL,
  `bio` text DEFAULT NULL,
  `topics` text DEFAULT NULL,
  `geography` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  `deactivated_at` timestamp NULL DEFAULT NULL,
  `restored_at` timestamp NULL DEFAULT NULL,
  `organization_name` varchar(255) DEFAULT NULL,
  `contact_name` varchar(255) DEFAULT NULL,
  `department` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `funding_calls`
--

CREATE TABLE `funding_calls` (
  `id` int(11) NOT NULL,
  `title` varchar(255) DEFAULT NULL,
  `funder` varchar(255) DEFAULT NULL,
  `deadline` date DEFAULT NULL,
  `status` varchar(50) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `topics` text DEFAULT NULL,
  `geography` text DEFAULT NULL,
  `amount` varchar(100) DEFAULT NULL,
  `url` varchar(255) DEFAULT NULL,
  `added_by_email` varchar(200) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  `deleted_by` varchar(150) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `job_queue`
--

CREATE TABLE `job_queue` (
  `id` int(11) NOT NULL,
  `job_type` enum('compute_matches','generate_summary','send_notification','send_digest','check_balance','fetch_orcid_publications','send_weekly_digests','generate_embedding') NOT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`payload`)),
  `status` enum('pending','running','done','failed') NOT NULL DEFAULT 'pending',
  `attempts` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `max_attempts` tinyint(3) UNSIGNED NOT NULL DEFAULT 3,
  `last_error` text DEFAULT NULL,
  `locked_at` datetime DEFAULT NULL,
  `locked_by` varchar(100) DEFAULT NULL,
  `run_after` datetime NOT NULL DEFAULT current_timestamp(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `idempotency_key` varchar(64) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `job_queue`
--

INSERT INTO `job_queue` (`id`, `job_type`, `payload`, `status`, `attempts`, `max_attempts`, `last_error`, `locked_at`, `locked_by`, `run_after`, `created_at`, `updated_at`, `idempotency_key`) VALUES
(1, 'send_notification', '{\"to\":\"test@example.com\",\"subject\":\"Test\"}', 'done', 1, 3, NULL, NULL, 'MBPvonJudercio:33447', '2026-04-30 14:46:39', '2026-04-30 18:46:39', '2026-04-30 18:47:07', NULL),
(2, 'generate_summary', '{\"entity_type\":\"researcher\",\"entity_id\":3}', 'done', 1, 3, NULL, NULL, 'MBPvonJudercio:39065', '2026-04-30 19:58:21', '2026-04-30 23:58:21', '2026-04-30 23:58:24', NULL),
(3, 'generate_summary', '{\"entity_type\":\"researcher\",\"entity_id\":3}', 'done', 1, 3, NULL, NULL, 'MBPvonJudercio:41628', '2026-04-30 20:15:30', '2026-05-01 00:15:30', '2026-05-01 00:15:30', NULL),
(4, 'generate_summary', '{\"entity_type\":\"researcher\",\"entity_id\":3}', 'done', 1, 3, NULL, NULL, 'MBPvonJudercio:40218', '2026-04-30 20:16:25', '2026-05-01 00:16:25', '2026-05-01 00:16:26', NULL),
(5, 'generate_summary', '{\"entity_type\":\"researcher\",\"entity_id\":3}', 'done', 1, 3, NULL, NULL, 'MBPvonJudercio:36939', '2026-04-30 20:18:45', '2026-05-01 00:18:45', '2026-05-01 00:18:45', NULL),
(6, 'generate_summary', '{\"entity_type\":\"researcher\",\"entity_id\":3}', 'done', 1, 3, NULL, NULL, 'MBPvonJudercio:38761', '2026-04-30 20:20:27', '2026-05-01 00:20:27', '2026-05-01 00:20:29', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `match_scores`
--

CREATE TABLE `match_scores` (
  `id` int(11) NOT NULL,
  `funding_call_id` int(11) NOT NULL,
  `researcher_id` int(11) NOT NULL,
  `score_keyword` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `score_ai` tinyint(3) UNSIGNED DEFAULT NULL,
  `explanation` text DEFAULT NULL,
  `model_used` varchar(80) DEFAULT NULL,
  `computed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `notified_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `messages`
--

CREATE TABLE `messages` (
  `id` int(11) NOT NULL,
  `sender_email` varchar(200) DEFAULT NULL,
  `sender_name` varchar(200) DEFAULT NULL,
  `recipient_type` enum('network','individual') DEFAULT NULL,
  `recipient_email` varchar(200) DEFAULT NULL,
  `recipient_name` varchar(200) DEFAULT NULL,
  `subject` varchar(255) DEFAULT NULL,
  `body` text DEFAULT NULL,
  `message_type` varchar(100) DEFAULT NULL,
  `funding_call_id` int(11) DEFAULT NULL,
  `funding_call_title` varchar(255) DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `thread_id` int(11) DEFAULT NULL,
  `parent_id` int(11) DEFAULT NULL,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `messages`
--

INSERT INTO `messages` (`id`, `sender_email`, `sender_name`, `recipient_type`, `recipient_email`, `recipient_name`, `subject`, `body`, `message_type`, `funding_call_id`, `funding_call_title`, `is_read`, `created_at`, `thread_id`, `parent_id`, `is_deleted`, `deleted_at`) VALUES
(11, 'judercionhauche@gmail.com', 'Judercio Jose Nhauche', 'individual', 'judercio.nhauche@cleanplateclubs.com', 'John Doe', 'Just Greeting', 'hi', 'general', 0, '', 1, '2026-05-15 02:48:16', 11, NULL, 1, '2026-05-14 22:55:19'),
(12, 'judercio.nhauche@cleanplateclubs.com', 'John Doe', 'individual', 'judercionhauche@gmail.com', 'Judercio Jose Nhauche', 'Re: Just Greeting', 'hello', 'general', NULL, NULL, 1, '2026-05-15 02:49:53', 11, 11, 0, NULL),
(13, 'judercionhauche@gmail.com', 'Judercio Jose Nhauche', 'individual', 'judercio.nhauche@cleanplateclubs.com', 'John Doe', 'Funding Call', 'hi man wussup?', 'opportunity-share', 0, '', 1, '2026-05-15 02:55:54', 13, NULL, 1, '2026-05-14 23:04:06'),
(14, 'judercio.nhauche@cleanplateclubs.com', 'John Doe', 'individual', 'judercionhauche@gmail.com', 'Judercio Jose Nhauche', 'Re: Funding Call', 'Good and you bro?', 'opportunity-share', NULL, NULL, 1, '2026-05-15 02:56:48', 13, 13, 0, NULL),
(15, 'judercionhauche@gmail.com', 'Judercio Jose Nhauche', 'individual', 'judercio.nhauche@cleanplateclubs.com', 'John Doe', 'Greeting', 'heyoooo', 'general', 0, '', 1, '2026-05-15 03:04:26', 15, NULL, 0, NULL),
(16, 'judercio.nhauche@cleanplateclubs.com', 'John Doe', 'individual', 'judercionhauche@gmail.com', 'Judercio Jose Nhauche', 'Re: Greeting', 'yessirrr', 'general', NULL, NULL, 0, '2026-05-15 03:06:26', 15, 15, 0, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `password_resets`
--

CREATE TABLE `password_resets` (
  `id` int(11) NOT NULL,
  `email` varchar(150) NOT NULL,
  `token` varchar(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `password_resets`
--

INSERT INTO `password_resets` (`id`, `email`, `token`, `expires_at`, `used_at`, `created_at`) VALUES
(7, 'judercionhauche@gmail.com', '3c853b03f831a4d1f79778be657859b0b2d63338df0fae880fb28478cc552e1a', '2026-05-12 03:06:28', NULL, '2026-05-12 00:06:28');

-- --------------------------------------------------------

--
-- Table structure for table `rate_limits`
--

CREATE TABLE `rate_limits` (
  `key` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `rate_limits`
--

INSERT INTO `rate_limits` (`key`, `created_at`) VALUES
('password_reset_judercionhauche@gmail.com', '2026-05-12 00:02:26'),
('password_reset_judercionhauche@gmail.com', '2026-05-12 00:06:28'),
('search_recipients_3', '2026-05-11 23:54:17'),
('search_recipients_3', '2026-05-11 23:54:19'),
('search_recipients_3', '2026-05-15 02:47:58'),
('search_recipients_3', '2026-05-15 02:47:58'),
('search_recipients_3', '2026-05-15 02:55:30'),
('search_recipients_3', '2026-05-15 03:04:13'),
('search_recipients_3', '2026-05-15 03:04:14');

-- --------------------------------------------------------

--
-- Table structure for table `researchers`
--

CREATE TABLE `researchers` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `status` enum('active','inactive','deleted') NOT NULL DEFAULT 'active',
  `first_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `institution` varchar(200) DEFAULT NULL,
  `department` varchar(200) DEFAULT NULL,
  `title` varchar(150) DEFAULT NULL,
  `bio` text DEFAULT NULL,
  `focus_area` varchar(150) DEFAULT NULL,
  `focus_area_detail` varchar(200) DEFAULT NULL,
  `topics` text DEFAULT NULL,
  `geography` text DEFAULT NULL,
  `co_advising` tinyint(1) DEFAULT 0,
  `co_advising_details` text DEFAULT NULL,
  `profile_url` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `website_url` varchar(255) DEFAULT NULL,
  `orcid_id` varchar(50) DEFAULT NULL,
  `google_scholar_url` varchar(255) DEFAULT NULL,
  `notify_matches` tinyint(1) NOT NULL DEFAULT 1,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  `deactivated_at` timestamp NULL DEFAULT NULL,
  `restored_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `researchers`
--

INSERT INTO `researchers` (`id`, `user_id`, `status`, `first_name`, `last_name`, `email`, `institution`, `department`, `title`, `bio`, `focus_area`, `focus_area_detail`, `topics`, `geography`, `co_advising`, `co_advising_details`, `profile_url`, `created_at`, `website_url`, `orcid_id`, `google_scholar_url`, `notify_matches`, `updated_at`, `deleted_at`, `deactivated_at`, `restored_at`) VALUES
(6, 7, 'deleted', 'Kingston', 'Afua', 'jnhauche@brandeis.edu', 'UCT', 'Pharmacy', 'Student', 'I am a pharmacist interested in helping people', 'Food Security, Nutrition & Health|Markets & Trade', 'Affordability of Healthy and Sustainable Diets, Forecasting food insecurity/shocks/vulnerabilities, Food issues requiring solutions outside the food system, Migration & displacement', 'Food Security', '0', 1, 'Would like to advice students in food systems', 'https://judercionhauche.github.io/final/index.html', '2026-05-14 15:57:19', 'https://jwafs.mit.edu/', '0000', '', 1, '2026-05-15 02:23:53', '2026-05-15 02:23:53', NULL, NULL),
(11, 12, 'deleted', 'Maria', 'Garcia', 'mariagarcia@example.com', 'University of Madrid', 'Environmental Science', 'Research Scientist', 'I specialize in sustainable agriculture and food systems', 'Food Security, Nutrition & Health', '', 'agriculture,sustainability,nutrition', '0', 0, '', '', '2026-05-14 16:28:11', '', '', '', 1, '2026-05-15 02:21:24', '2026-05-15 02:21:24', NULL, NULL),
(12, 13, 'deleted', 'Ahmed', 'Hassan', 'ahmedhassan@example.com', 'University of Cairo', 'Agriculture', 'Assistant Professor', 'Working on climate-resilient crops and food security in Africa', 'Ecosystems & Biodiversity', '', 'climate,resilience,agriculture', '0', 0, '', '', '2026-05-14 16:30:34', '', '', '', 1, '2026-05-15 02:24:19', '2026-05-15 02:24:19', NULL, NULL),
(13, 14, 'active', 'John', 'Doe', 'judercio.nhauche@cleanplateclubs.com', 'Cambridge', 'Business', 'Researcher', 'Just a researcher', 'Food Security, Nutrition & Health', 'Food environments', 'Food Security', '0', 0, '', 'https://judercionhauche.github.io/final/index.html', '2026-05-15 01:51:18', 'https://jwafs.mit.edu/', '0000', '', 1, '2026-05-15 02:45:47', NULL, NULL, '2026-05-15 02:45:47'),
(14, 15, 'active', 'LocalTest', 'Researcher', 'localtest@example.com', 'Test University', '', '', '', '', '', 'Food Security', '0', 0, '', '', '2026-05-18 16:09:26', '', '', '', 1, '2026-05-18 16:09:26', NULL, NULL, NULL),
(15, 16, 'active', 'LocalTest2', 'Researcher', 'localtest2@example.com', 'Test University', '', '', '', '', '', 'Food Security', '0', 0, '', '', '2026-05-18 16:10:43', '', '', '', 1, '2026-05-18 16:10:43', NULL, NULL, NULL),
(16, 17, 'active', 'LocalTest3', 'Researcher', 'localtest3@example.com', 'Test University', '', '', '', '', '', 'Food Security', '0', 0, '', '', '2026-05-18 16:12:02', '', '', '', 1, '2026-05-18 16:12:02', NULL, NULL, NULL),
(17, 18, 'active', 'LocalTest4', 'Researcher', 'localtest4@example.com', 'Test University', '', '', '', '', '', 'Food Security', '0', 0, '', '', '2026-05-18 16:12:14', '', '', '', 1, '2026-05-18 16:12:14', NULL, NULL, NULL),
(18, 19, 'active', 'LocalTest5', 'Researcher', 'localtest5@example.com', 'Test University', '', '', '', '', '', 'Food Security', '0', 0, '', '', '2026-05-18 16:12:40', '', '', '', 1, '2026-05-18 16:12:40', NULL, NULL, NULL),
(19, 20, 'active', 'LocalTest6', 'Researcher', 'localtest6@example.com', 'Test University', '', '', '', '', '', 'Food Security', '0', 0, '', '', '2026-05-18 16:12:55', '', '', '', 1, '2026-05-18 16:12:55', NULL, NULL, NULL),
(20, 21, 'active', 'LocalTest7', 'Researcher', 'localtest7@example.com', 'Test University', '', '', '', '', '', 'Food Security', '0', 0, '', '', '2026-05-18 16:13:04', '', '', '', 1, '2026-05-18 16:13:04', NULL, NULL, NULL),
(21, 22, 'active', 'LocalTest8', 'Researcher', 'localtest8@example.com', 'Test University', '', '', '', '', '', 'Food Security', '0', 0, '', '', '2026-05-18 16:13:16', '', '', '', 1, '2026-05-18 16:13:16', NULL, NULL, NULL),
(22, 23, 'active', 'LocalTest9', 'Researcher', 'localtest9@example.com', 'Test University', '', '', '', '', '', 'Food Security', '0', 0, '', '', '2026-05-18 16:13:38', '', '', '', 1, '2026-05-18 16:13:38', NULL, NULL, NULL),
(23, 24, 'active', 'LocalTest10', 'Researcher', 'localtest10@example.com', 'Test University', '', '', '', '', '', 'Food Security', '0', 0, '', '', '2026-05-18 16:14:03', '', '', '', 1, '2026-05-18 16:14:03', NULL, NULL, NULL),
(24, 25, 'active', 'LocalTest11', 'Researcher', 'localtest11@example.com', 'Test University', '', '', '', '', '', 'Food Security', '0', 0, '', '', '2026-05-18 16:14:33', '', '', '', 1, '2026-05-18 16:14:33', NULL, NULL, NULL),
(25, 26, 'active', 'FinalTest', 'Researcher', 'finaltest@example.com', 'Test University', '', '', '', '', '', 'Food Security', '0', 0, '', '', '2026-05-18 16:15:11', '', '', '', 1, '2026-05-18 16:15:11', NULL, NULL, NULL),
(26, 27, 'active', 'FinalTest2', 'Researcher', 'finaltest2@example.com', 'Test University', '', '', '', '', '', 'Food Security', '0', 0, '', '', '2026-05-18 16:15:25', '', '', '', 1, '2026-05-18 16:15:25', NULL, NULL, NULL),
(27, 28, 'active', 'Final', 'Tester', 'finaltester@example.com', 'MIT', '', '', '', '', '', 'Food Security', '0', 0, '', '', '2026-05-18 16:15:48', '', '', '', 1, '2026-05-18 16:15:48', NULL, NULL, NULL),
(29, 30, 'active', 'Complete', 'Test', 'test.complete@example.com', 'MIT &  Extra Spaces', 'Business Analytics & Food Systems Research', 'Research Data & Analytics Intern', '', '', '', 'AI for food systems, agricultural data analytics, research funding', '0', 1, '', 'https://www.mit.edu', '2026-05-18 16:35:24', 'https://jwafs.mit.edu', '0000-0001-2345-6789', 'https://scholar.google.com', 1, '2026-05-18 16:35:24', NULL, NULL, NULL),
(30, 34, 'active', 'Judercio', 'Nhauche', 'juderciojosenhauche@gmail.com', 'Massachusetts Institute of Technology (MIT)', 'Business Analytics & Food Systems Research', 'Research Data & Analytics Intern', 'Graduate Business Analytics student and research data analyst focused on food systems, agricultural innovation, AI-driven research matching, and global development analytics. Currently supporting the FACT Alliance at MIT J-WAFS through research coordination, funding discovery, and analytics initiatives.', '', '', 'AI for food systems, agricultural data analytics, research funding, food security, predictive analytics', 'Sub-Saharan Africa, Mozambique, Kenya, Ghana', 1, '', 'https://www.mit.edu', '2026-05-18 16:37:33', 'https://jwafs.mit.edu', '0000-0001-2345-6789', 'https://scholar.google.com', 1, '2026-05-18 16:37:33', NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `saved_opportunities`
--

CREATE TABLE `saved_opportunities` (
  `id` int(11) NOT NULL,
  `researcher_email` varchar(200) DEFAULT NULL,
  `researcher_name` varchar(200) DEFAULT NULL,
  `funding_call_id` int(11) DEFAULT NULL,
  `funding_call_title` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `search_logs`
--

CREATE TABLE `search_logs` (
  `id` int(11) NOT NULL,
  `user_email` varchar(255) DEFAULT NULL,
  `query` varchar(500) NOT NULL,
  `parsed_topics` text DEFAULT NULL,
  `parsed_geos` text DEFAULT NULL,
  `fallback` tinyint(1) DEFAULT 0,
  `fc_count` int(11) DEFAULT 0,
  `r_count` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `search_logs`
--

INSERT INTO `search_logs` (`id`, `user_email`, `query`, `parsed_topics`, `parsed_geos`, `fallback`, `fc_count`, `r_count`, `created_at`) VALUES
(1, 'judercionhauche@gmail.com', 'Research about climate', 'climate, climate change', '', 0, 0, 2, '2026-05-01 15:16:02'),
(2, 'judercionhauche@gmail.com', 'Research about money', 'economics, finance', '', 0, 0, 0, '2026-05-01 15:16:31'),
(3, 'judercionhauche@gmail.com', 'food security Africa', 'food security', 'africa', 0, 0, 2, '2026-05-10 00:50:25'),
(4, 'judercionhauche@gmail.com', 'Anything about food', 'food, food security, nutrition', '', 0, 0, 2, '2026-05-10 00:50:41'),
(5, 'judercionhauche@gmail.com', 'Anything about security', 'security', '', 0, 0, 2, '2026-05-10 00:50:50'),
(6, 'sixt@mit.edu', 'climate funding East Africa', 'climate change, climate finance, climate funding', 'east africa, kenya, tanzania, uganda, ethiopia, rwanda', 0, 0, 1, '2026-05-10 01:38:55'),
(7, 'sixt@mit.edu', 'I am looking for a researcher t MIT', '', '', 0, 0, 1, '2026-05-10 01:39:24'),
(8, 'sixt@mit.edu', 'climate funding East Africa', 'climate change, climate finance, climate funding', 'east africa, africa', 0, 0, 1, '2026-05-10 01:52:06'),
(9, 'sixt@mit.edu', 'Researcher at MIT', '', '', 0, 0, 1, '2026-05-10 01:52:44'),
(10, 'sixt@mit.edu', 'Researchers at MIT', '', '', 0, 0, 1, '2026-05-10 02:03:32'),
(11, 'sixt@mit.edu', 'Judercio', 'judiciary, judicial systems, law, legal systems', '', 0, 0, 0, '2026-05-10 02:04:06'),
(12, 'sixt@mit.edu', 'Judercio Jose Nhauche', '', '', 0, 0, 1, '2026-05-10 02:04:27'),
(13, 'judercionhauche@gmail.com', 'Hi I want more information about reaearchers at MIT', '', '', 0, 0, 1, '2026-05-11 23:51:54'),
(14, 'juderciojosenhauche@gmail.com', 'health researchers Ghana', 'health research', 'ghana', 0, 0, 0, '2026-05-12 00:19:55'),
(15, 'judercionhauche@gmail.com', 'Are there any researchers', '', '', 0, 0, 0, '2026-05-14 19:18:36');

-- --------------------------------------------------------

--
-- Table structure for table `search_sessions`
--

CREATE TABLE `search_sessions` (
  `id` int(11) NOT NULL,
  `session_key` varchar(64) NOT NULL,
  `user_id` int(11) NOT NULL,
  `turns` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT '[]' CHECK (json_valid(`turns`)),
  `last_topics` text DEFAULT NULL,
  `last_geos` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `search_sessions`
--

INSERT INTO `search_sessions` (`id`, `session_key`, `user_id`, `turns`, `last_topics`, `last_geos`, `created_at`, `updated_at`) VALUES
(7, '1ec54199ac6c18fff31eb40bebb41a80', 3, '[{\"user\":\"Hi I want more information about reaearchers at MIT\",\"assistant\":\"I found 1 researcher at MIT on the platform: Greg Sixt. I also have MIT\'s institution profile available, which lists 1 researcher currently registered. If you\'d like to learn more about Greg\'s research focus or other details, or if you\'re looking for researchers in a specific field at MIT, let me know!\",\"parsed\":{\"topics\":[],\"geographies\":[],\"intent\":\"find_researcher\"}}]', NULL, NULL, '2026-05-11 23:51:51', '2026-05-11 23:51:54'),
(8, 'de0262852aa64d064ff26bbab335df44', 4, '[{\"user\":\"health researchers Ghana\",\"assistant\":\"I didn\'t find any health researchers currently listed in Ghana on the FACT Alliance Hub platform. There are also no active funding calls for health research in Ghana at the moment. You might want to check back soon as new researchers and opportunities are added regularly, or explore researchers from neighboring West African countries if you\'re open to regional collaboration.\",\"parsed\":{\"topics\":[\"health research\"],\"geographies\":[\"ghana\"],\"intent\":\"find_researcher\"}}]', NULL, NULL, '2026-05-12 00:19:52', '2026-05-12 00:19:55'),
(9, 'd5e4e33870ed86f57189011046d4034b', 3, '[{\"user\":\"Are there any researchers\",\"assistant\":\"I didn\'t find any researchers in the FACT Alliance Hub database at this moment. The platform may not have researchers registered yet, or you might want to try a more specific search using a researcher\'s name, institution, topic area, or geographic location to narrow down your query.\",\"parsed\":{\"topics\":[],\"geographies\":[],\"intent\":\"unknown\"}}]', NULL, NULL, '2026-05-14 19:18:33', '2026-05-14 19:18:36');

-- --------------------------------------------------------

--
-- Table structure for table `tags`
--

CREATE TABLE `tags` (
  `id` int(11) NOT NULL,
  `name` varchar(100) DEFAULT NULL,
  `tag_type` enum('topic','geography') DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tags`
--

INSERT INTO `tags` (`id`, `name`, `tag_type`) VALUES
(1, 'Climate Change', 'topic'),
(2, 'Navigation', 'topic'),
(3, 'North', 'geography'),
(4, 'South Afruca', 'geography'),
(5, 'Food Security', 'topic'),
(6, 'Africa And Europe', 'geography'),
(7, 'Americas', 'geography'),
(8, 'Asia', 'geography'),
(9, 'China', 'geography'),
(10, 'Agriculture', 'topic'),
(11, 'Sustainability', 'topic'),
(12, 'Nutrition', 'topic'),
(13, 'Europe', 'geography'),
(14, 'Africa', 'geography'),
(15, 'Climate', 'topic'),
(16, 'Resilience', 'topic'),
(17, 'Middle East', 'geography'),
(18, 'Global', 'geography'),
(19, 'Ai For Food Systems', 'topic'),
(20, 'Agricultural Data Analytics', 'topic'),
(21, 'Research Funding', 'topic'),
(22, 'Predictive Analytics', 'topic'),
(23, 'Sub-saharan Africa', 'geography'),
(24, 'Mozambique', 'geography'),
(25, 'Kenya', 'geography'),
(26, 'Ghana', 'geography'),
(27, 'Ai', 'topic'),
(28, 'Analytics', 'topic'),
(29, 'Data Science', 'topic');

-- --------------------------------------------------------

--
-- Table structure for table `unsubscribe_tokens`
--

CREATE TABLE `unsubscribe_tokens` (
  `token` varchar(64) NOT NULL,
  `email` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  `role` enum('admin','researcher','funder') NOT NULL DEFAULT 'researcher',
  `status` enum('active','inactive','deleted','unverified') NOT NULL DEFAULT 'active',
  `session_token` varchar(64) DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `deactivated_at` timestamp NULL DEFAULT NULL,
  `restored_at` timestamp NULL DEFAULT NULL,
  `last_status_change_at` timestamp NULL DEFAULT NULL,
  `status_changed_by` varchar(150) DEFAULT NULL,
  `deletion_reason` varchar(500) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `name`, `email`, `password`, `role`, `status`, `session_token`, `deleted_at`, `deactivated_at`, `restored_at`, `last_status_change_at`, `status_changed_by`, `deletion_reason`) VALUES
(3, 'Judercio Jose Nhauche', 'judercionhauche@gmail.com', '$2y$10$8u42JCVGS7v4jSPicBjJ1.Iqwp5pOEYnVCOy7TtUpMdI9l31rRWnS', 'admin', 'active', '6e34168146f4f14cf93a21dcfdb182ee0b02413b421a7d018d90d9bbd0077dd4', NULL, NULL, NULL, NULL, NULL, NULL),
(7, 'Kingston Afua', 'jnhauche@brandeis.edu', '$2y$10$OGBq/92zlaN7QvPKY1ER.uzNk79.WfAJbKH6yZ0vy6DgFPtEe5WAW', 'researcher', 'inactive', NULL, NULL, '2026-05-15 02:23:53', NULL, '2026-05-15 02:23:53', 'judercionhauche@gmail.com', NULL),
(8, 'Simple Test', 'simpletest@example.com', '$2y$10$YXgwmdovqCUvurrNWlSGZ.48oTltOSkLZPDKxHLYMpWV8HPijQCXW', 'researcher', 'unverified', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(9, 'Success Test', 'successtest@example.com', '$2y$10$dyHiT7hut68NqAEWOricbuwkVY6zUB832AHo2oybVTwOQeKTwr6IW', 'researcher', 'unverified', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(10, 'Verify Test', 'verifytest@example.com', '$2y$10$0StWzyWT5daZkpTEuDsBpOZYNpHp2mRZZN27iMR0M7a0IT/oH9VV.', 'researcher', 'unverified', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(11, 'Final Success', 'finalsuccess@example.com', '$2y$10$x.Isg6u1cVb5jPi6U340DuGcaK30lhcQTdd2yF9GxxgvkWGBYFrI2', 'researcher', 'unverified', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(12, 'Maria Garcia', 'mariagarcia@example.com', '$2y$10$FpnGLDIevzFDYMmNYwJBvO3Rgi8pbgLuSy1m2QSBfjv79n9pFEkvi', 'researcher', 'inactive', NULL, NULL, '2026-05-15 02:21:24', NULL, '2026-05-15 02:21:24', 'judercionhauche@gmail.com', NULL),
(13, 'Ahmed Hassan', 'ahmedhassan@example.com', '$2y$10$jADaYbaYXbPDaBP/ObcvVex74v1kLwFsnLY4ww1BSqC38X/84SvKa', 'researcher', 'inactive', NULL, NULL, '2026-05-15 02:24:19', NULL, '2026-05-15 02:24:19', 'judercionhauche@gmail.com', NULL),
(14, 'John Doe', 'judercio.nhauche@cleanplateclubs.com', '$2y$10$RXw40kkYS/.IWXxvBY.d1u.Eaj4pTlVYm6ZJdIANiE618doLCwYv.', 'researcher', 'active', 'bebb7bb6f0c8091efaa087d2762e0e4c1ecf6b4170a70c16e9920e3ba24eb139', NULL, NULL, NULL, '2026-05-15 02:45:47', 'judercionhauche@gmail.com', NULL),
(15, 'LocalTest Researcher', 'localtest@example.com', '$2y$10$7uKpQuW3j8o2BftO8pXcSu8qHQCUIAsZssoWIS2BuhP6NDA5BxXBi', 'researcher', 'unverified', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(16, 'LocalTest2 Researcher', 'localtest2@example.com', '$2y$10$5fOFNtDUFMxHojQvceCvo.27O2QkcS4WzXj59vEAGAN0Mg0fQ7kES', 'researcher', 'unverified', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(17, 'LocalTest3 Researcher', 'localtest3@example.com', '$2y$10$/B7YaJCKnHa1c7hdHuQPwesxghPIiD/xxPOjeF9GEVZCVube8y.Iy', 'researcher', 'unverified', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(18, 'LocalTest4 Researcher', 'localtest4@example.com', '$2y$10$A391fCjOtEj3cWh78DMWNOAkQY147i456lN9ouFG8hgTjEpEKuz8i', 'researcher', 'unverified', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(19, 'LocalTest5 Researcher', 'localtest5@example.com', '$2y$10$ZrlyGbVNJPzwIQ5jxCLze.w.hL5Y4YaIrX4GbdaY3rFAQC.0OwDpm', 'researcher', 'unverified', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(20, 'LocalTest6 Researcher', 'localtest6@example.com', '$2y$10$asgtQMqo3mTJJbGTdObkdu8l546mHxRoHhQKQ9QERr32r7RdCs8.O', 'researcher', 'unverified', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(21, 'LocalTest7 Researcher', 'localtest7@example.com', '$2y$10$fr3mjKuPJChI6.edjMYSGOFSuU.FtZ.BzGJhUdMVWEw8jyMj5HoLy', 'researcher', 'unverified', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(22, 'LocalTest8 Researcher', 'localtest8@example.com', '$2y$10$wzHTlHnUUXTcETtkolw0DOQGTwlWc3pIw77X/hzdHPfBiw.P15cEG', 'researcher', 'unverified', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(23, 'LocalTest9 Researcher', 'localtest9@example.com', '$2y$10$.GLJgEJ35xzLUCzaUaChueQLdrWV7J97vAZe1SnYTgXGaeFEraQK6', 'researcher', 'unverified', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(24, 'LocalTest10 Researcher', 'localtest10@example.com', '$2y$10$c.iDudtBumx3fE.zzJUIn.9la2CbMMvaSZsjVLZuOkePlIemuDoGS', 'researcher', 'unverified', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(25, 'LocalTest11 Researcher', 'localtest11@example.com', '$2y$10$0eWH9RSzC.uNfaCaotHhN.HBswi2oDIOaZcYVx4BAjWbeYU0tkhj6', 'researcher', 'unverified', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(26, 'FinalTest Researcher', 'finaltest@example.com', '$2y$10$DTn9TGSdri41d5pGzdKbLOv59v9ZuC/YQxbtaMnONAHtYIXHnbZQe', 'researcher', 'unverified', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(27, 'FinalTest2 Researcher', 'finaltest2@example.com', '$2y$10$JkOsYGHF3ld0S2oHHblAruyzYLgKyhljS5NCbadPYAamdSqg2sEsm', 'researcher', 'unverified', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(28, 'Final Tester', 'finaltester@example.com', '$2y$10$20a2l4GJVgICbgp8fLNe8.D2eR9xS656r8ZsdL9fLzUdSBwBQhjv.', 'researcher', 'unverified', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(30, 'Complete Test', 'test.complete@example.com', '$2y$10$XxnZyyBc.sSC0Xs50TI1XeonCq3R/zvi/i2V7Rdrql9tGZhsY7tYW', 'researcher', 'unverified', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(31, 'Geography Test', 'geography.test@example.com', '$2y$10$gxRojl8zKGTz66nHrM5ZLeejsR73/nbp.eJo57U/zoxapzO5jsnli', 'researcher', 'unverified', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(33, 'Debug Test', 'debug.test@example.com', '$2y$10$CmksvHEaliT0IgazedmXPurm5lGJr3fDQX7B00AGvvcsCdMDDezgq', 'researcher', 'unverified', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(34, 'Judercio Nhauche', 'juderciojosenhauche@gmail.com', '$2y$10$/aXJ9rcSIigAXHz9dV1cieKhj0eZkBJ4NOLKiIbSSOBQGvfeHFdju', 'researcher', 'unverified', NULL, NULL, NULL, NULL, NULL, NULL, NULL);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `ai_summaries`
--
ALTER TABLE `ai_summaries`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_entity` (`entity_type`,`entity_id`);

--
-- Indexes for table `api_balances`
--
ALTER TABLE `api_balances`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_provider` (`provider`),
  ADD KEY `idx_ab_status` (`status`),
  ADD KEY `idx_ab_checked` (`last_checked_at`);

--
-- Indexes for table `api_usage`
--
ALTER TABLE `api_usage`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_au_time` (`created_at`),
  ADD KEY `idx_au_purpose` (`purpose`);

--
-- Indexes for table `audit_log`
--
ALTER TABLE `audit_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_audit_actor` (`actor_email`),
  ADD KEY `idx_audit_action` (`action`),
  ADD KEY `idx_audit_time` (`created_at`),
  ADD KEY `idx_actor` (`actor_email`),
  ADD KEY `idx_action` (`action`),
  ADD KEY `idx_time` (`created_at`),
  ADD KEY `idx_email` (`target_email`);

--
-- Indexes for table `balance_alerts`
--
ALTER TABLE `balance_alerts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ba_provider` (`provider`),
  ADD KEY `idx_ba_severity` (`severity`),
  ADD KEY `idx_ba_sent_at` (`sent_at`);

--
-- Indexes for table `email_verifications`
--
ALTER TABLE `email_verifications`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `token` (`token`),
  ADD KEY `idx_token` (`token`),
  ADD KEY `idx_email` (`email`);

--
-- Indexes for table `funders`
--
ALTER TABLE `funders`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_funder_status` (`status`);
ALTER TABLE `funders` ADD FULLTEXT KEY `ft_f_search` (`first_name`,`last_name`,`organization`,`bio`,`topics`,`geography`);

--
-- Indexes for table `funding_calls`
--
ALTER TABLE `funding_calls`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_deleted_at` (`deleted_at`);
ALTER TABLE `funding_calls` ADD FULLTEXT KEY `ft_fc_search` (`title`,`funder`,`description`,`topics`,`geography`);
ALTER TABLE `funding_calls` ADD FULLTEXT KEY `ft_funding_search` (`title`,`description`,`topics`,`geography`);

--
-- Indexes for table `job_queue`
--
ALTER TABLE `job_queue`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `idempotency_key` (`idempotency_key`),
  ADD KEY `idx_jq_dispatch` (`status`,`run_after`,`attempts`),
  ADD KEY `idx_jq_type` (`job_type`,`status`);

--
-- Indexes for table `match_scores`
--
ALTER TABLE `match_scores`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_fc_r` (`funding_call_id`,`researcher_id`),
  ADD KEY `idx_ms_fc` (`funding_call_id`),
  ADD KEY `idx_ms_r` (`researcher_id`),
  ADD KEY `idx_ms_ai` (`score_ai`);

--
-- Indexes for table `messages`
--
ALTER TABLE `messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_msg_thread` (`thread_id`),
  ADD KEY `idx_msg_recipient` (`recipient_email`,`is_deleted`,`is_read`),
  ADD KEY `idx_msg_sender` (`sender_email`,`is_deleted`),
  ADD KEY `idx_msg_deleted_at` (`is_deleted`,`deleted_at`);

--
-- Indexes for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_token` (`token`),
  ADD KEY `idx_email` (`email`),
  ADD KEY `idx_used_at` (`used_at`);

--
-- Indexes for table `rate_limits`
--
ALTER TABLE `rate_limits`
  ADD KEY `idx_key_time` (`key`,`created_at`),
  ADD KEY `idx_created` (`created_at`);

--
-- Indexes for table `researchers`
--
ALTER TABLE `researchers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_researcher_status` (`status`);
ALTER TABLE `researchers` ADD FULLTEXT KEY `ft_r_search` (`first_name`,`last_name`,`institution`,`bio`,`topics`,`geography`);

--
-- Indexes for table `saved_opportunities`
--
ALTER TABLE `saved_opportunities`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `search_logs`
--
ALTER TABLE `search_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_sl_time` (`created_at`);

--
-- Indexes for table `search_sessions`
--
ALTER TABLE `search_sessions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_sk` (`session_key`),
  ADD KEY `idx_ss_user` (`user_id`),
  ADD KEY `idx_ss_updated` (`updated_at`);

--
-- Indexes for table `tags`
--
ALTER TABLE `tags`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `unsubscribe_tokens`
--
ALTER TABLE `unsubscribe_tokens`
  ADD PRIMARY KEY (`token`),
  ADD KEY `idx_email` (`email`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_deleted_at` (`deleted_at`),
  ADD KEY `idx_session_token` (`session_token`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `ai_summaries`
--
ALTER TABLE `ai_summaries`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=29;

--
-- AUTO_INCREMENT for table `api_balances`
--
ALTER TABLE `api_balances`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `api_usage`
--
ALTER TABLE `api_usage`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=35;

--
-- AUTO_INCREMENT for table `audit_log`
--
ALTER TABLE `audit_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=47;

--
-- AUTO_INCREMENT for table `balance_alerts`
--
ALTER TABLE `balance_alerts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `email_verifications`
--
ALTER TABLE `email_verifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=32;

--
-- AUTO_INCREMENT for table `funders`
--
ALTER TABLE `funders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `funding_calls`
--
ALTER TABLE `funding_calls`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `job_queue`
--
ALTER TABLE `job_queue`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `match_scores`
--
ALTER TABLE `match_scores`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `messages`
--
ALTER TABLE `messages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `password_resets`
--
ALTER TABLE `password_resets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `researchers`
--
ALTER TABLE `researchers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=31;

--
-- AUTO_INCREMENT for table `saved_opportunities`
--
ALTER TABLE `saved_opportunities`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `search_logs`
--
ALTER TABLE `search_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `search_sessions`
--
ALTER TABLE `search_sessions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `tags`
--
ALTER TABLE `tags`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=30;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=35;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
