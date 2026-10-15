-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: 127.0.0.1    Database: hrsystem
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `activity_log`
--

DROP TABLE IF EXISTS `activity_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `activity_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `log_name` varchar(255) DEFAULT NULL,
  `description` text NOT NULL,
  `subject_type` varchar(255) DEFAULT NULL,
  `subject_id` bigint(20) unsigned DEFAULT NULL,
  `causer_type` varchar(255) DEFAULT NULL,
  `causer_id` bigint(20) unsigned DEFAULT NULL,
  `properties` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`properties`)),
  `batch_uuid` char(36) DEFAULT NULL,
  `event` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `subject` (`subject_type`,`subject_id`),
  KEY `causer` (`causer_type`,`causer_id`),
  KEY `activity_log_log_name_index` (`log_name`)
) ENGINE=InnoDB AUTO_INCREMENT=111 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `activity_log`
--

LOCK TABLES `activity_log` WRITE;
/*!40000 ALTER TABLE `activity_log` DISABLE KEYS */;
INSERT INTO `activity_log` VALUES (109,'default','created','App\\Models\\Employee',106,NULL,NULL,'{\"attributes\":{\"first_name\":\"System\",\"last_name\":\"Admin\",\"email\":\"admin@hrms.com\",\"status\":\"active\",\"department_id\":2,\"salary\":\"5000.00\"}}',NULL,'created','2026-04-12 07:14:15','2026-04-12 07:14:15'),(110,'default','created','App\\Models\\Employee',107,'App\\Models\\User',284,'{\"attributes\":{\"first_name\":\"jithin\",\"last_name\":\"varkey\",\"email\":\"jithinvarkey@gmail.com\",\"status\":\"probation\",\"department_id\":3,\"salary\":\"10000.00\"}}',NULL,'created','2026-04-13 10:56:12','2026-04-13 10:56:12');
/*!40000 ALTER TABLE `activity_log` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `announcement_categories`
--

DROP TABLE IF EXISTS `announcement_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `announcement_categories` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `slug` varchar(120) NOT NULL,
  `color` varchar(20) DEFAULT NULL,
  `icon` varchar(50) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `announcement_categories_slug_unique` (`slug`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `announcement_categories`
--

LOCK TABLES `announcement_categories` WRITE;
/*!40000 ALTER TABLE `announcement_categories` DISABLE KEYS */;
INSERT INTO `announcement_categories` VALUES (1,'Holiday','holiday','#2E75B6','campaign',1,'2026-06-18 10:01:56','2026-06-18 10:01:56');
/*!40000 ALTER TABLE `announcement_categories` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `announcement_reactions`
--

DROP TABLE IF EXISTS `announcement_reactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `announcement_reactions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `announcement_id` bigint(20) unsigned NOT NULL,
  `employee_id` bigint(20) unsigned NOT NULL,
  `emoji` varchar(16) NOT NULL DEFAULT 'like',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `announcement_reactions_announcement_id_employee_id_unique` (`announcement_id`,`employee_id`),
  KEY `announcement_reactions_employee_id_foreign` (`employee_id`),
  CONSTRAINT `announcement_reactions_announcement_id_foreign` FOREIGN KEY (`announcement_id`) REFERENCES `announcements` (`id`) ON DELETE CASCADE,
  CONSTRAINT `announcement_reactions_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `announcement_reactions`
--

LOCK TABLES `announcement_reactions` WRITE;
/*!40000 ALTER TABLE `announcement_reactions` DISABLE KEYS */;
/*!40000 ALTER TABLE `announcement_reactions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `announcement_reads`
--

DROP TABLE IF EXISTS `announcement_reads`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `announcement_reads` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `announcement_id` bigint(20) unsigned NOT NULL,
  `employee_id` bigint(20) unsigned NOT NULL,
  `read_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `announcement_reads_announcement_id_employee_id_unique` (`announcement_id`,`employee_id`),
  KEY `announcement_reads_employee_id_foreign` (`employee_id`),
  CONSTRAINT `announcement_reads_announcement_id_foreign` FOREIGN KEY (`announcement_id`) REFERENCES `announcements` (`id`) ON DELETE CASCADE,
  CONSTRAINT `announcement_reads_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `announcement_reads`
--

LOCK TABLES `announcement_reads` WRITE;
/*!40000 ALTER TABLE `announcement_reads` DISABLE KEYS */;
/*!40000 ALTER TABLE `announcement_reads` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `announcements`
--

DROP TABLE IF EXISTS `announcements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `announcements` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `category_id` bigint(20) unsigned DEFAULT NULL,
  `title` varchar(200) NOT NULL,
  `body` text DEFAULT NULL,
  `priority` varchar(20) NOT NULL DEFAULT 'normal',
  `audience_type` varchar(20) NOT NULL DEFAULT 'all',
  `target_department_ids` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`target_department_ids`)),
  `target_roles` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`target_roles`)),
  `is_pinned` tinyint(1) NOT NULL DEFAULT 0,
  `is_published` tinyint(1) NOT NULL DEFAULT 1,
  `published_at` timestamp NULL DEFAULT NULL,
  `scheduled_at` timestamp NULL DEFAULT NULL,
  `expires_at` date DEFAULT NULL,
  `attachment_path` varchar(255) DEFAULT NULL,
  `attachment_name` varchar(255) DEFAULT NULL,
  `attachment_mime` varchar(255) DEFAULT NULL,
  `attachment_size` bigint(20) unsigned DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `announcements_category_id_foreign` (`category_id`),
  KEY `announcements_created_by_foreign` (`created_by`),
  KEY `announcements_is_published_published_at_index` (`is_published`,`published_at`),
  CONSTRAINT `announcements_category_id_foreign` FOREIGN KEY (`category_id`) REFERENCES `announcement_categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `announcements_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `announcements`
--

LOCK TABLES `announcements` WRITE;
/*!40000 ALTER TABLE `announcements` DISABLE KEYS */;
/*!40000 ALTER TABLE `announcements` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `app_notifications`
--

DROP TABLE IF EXISTS `app_notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `app_notifications` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `employee_id` bigint(20) unsigned NOT NULL,
  `type` varchar(50) NOT NULL,
  `title` varchar(200) NOT NULL,
  `body` varchar(500) DEFAULT NULL,
  `link` varchar(255) DEFAULT NULL,
  `ref_id` bigint(20) unsigned DEFAULT NULL,
  `read_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `app_notifications_employee_id_read_at_index` (`employee_id`,`read_at`),
  CONSTRAINT `app_notifications_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `app_notifications`
--

LOCK TABLES `app_notifications` WRITE;
/*!40000 ALTER TABLE `app_notifications` DISABLE KEYS */;
/*!40000 ALTER TABLE `app_notifications` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `attendance_devices`
--

DROP TABLE IF EXISTS `attendance_devices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `attendance_devices` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `brand` varchar(50) NOT NULL DEFAULT 'zkteco',
  `ip_address` varchar(45) NOT NULL,
  `port` int(11) NOT NULL DEFAULT 4370,
  `protocol` varchar(20) NOT NULL DEFAULT 'tcp',
  `api_path` varchar(255) DEFAULT NULL,
  `api_key` varchar(255) DEFAULT NULL,
  `username` varchar(100) DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  `timeout_seconds` int(11) NOT NULL DEFAULT 30,
  `employee_number_field` varchar(50) NOT NULL DEFAULT 'employee_code',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_synced_at` timestamp NULL DEFAULT NULL,
  `last_sync_status` varchar(20) DEFAULT NULL,
  `last_sync_count` int(11) DEFAULT NULL,
  `last_sync_error` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `attendance_devices`
--

LOCK TABLES `attendance_devices` WRITE;
/*!40000 ALTER TABLE `attendance_devices` DISABLE KEYS */;
/*!40000 ALTER TABLE `attendance_devices` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `attendance_logs`
--

DROP TABLE IF EXISTS `attendance_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `attendance_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `employee_id` bigint(20) unsigned NOT NULL,
  `date` date NOT NULL,
  `check_in` time DEFAULT NULL,
  `check_out` time DEFAULT NULL,
  `total_minutes` int(11) DEFAULT NULL,
  `status` enum('present','absent','late','half_day','on_leave','holiday') NOT NULL DEFAULT 'present',
  `source` enum('manual','api','biometric','import') NOT NULL DEFAULT 'api',
  `ip_address` varchar(45) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `attendance_logs_employee_id_date_unique` (`employee_id`,`date`),
  CONSTRAINT `attendance_logs_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `attendance_logs`
--

LOCK TABLES `attendance_logs` WRITE;
/*!40000 ALTER TABLE `attendance_logs` DISABLE KEYS */;
INSERT INTO `attendance_logs` VALUES (1,106,'2026-04-13','08:28:41','08:33:49',5,'present','api','127.0.0.1',NULL,'2026-04-13 05:28:41','2026-04-13 05:33:49');
/*!40000 ALTER TABLE `attendance_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `contract_renewal_requests`
--

DROP TABLE IF EXISTS `contract_renewal_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `contract_renewal_requests` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `contract_id` bigint(20) unsigned NOT NULL,
  `employee_id` bigint(20) unsigned NOT NULL,
  `reference` varchar(60) NOT NULL,
  `proposed_start_date` date NOT NULL,
  `proposed_end_date` date DEFAULT NULL,
  `proposed_salary` decimal(12,2) DEFAULT NULL,
  `proposed_type` varchar(30) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `document_path` varchar(500) DEFAULT NULL,
  `document_name` varchar(255) DEFAULT NULL,
  `document_mime` varchar(100) DEFAULT NULL,
  `document_size` bigint(20) unsigned DEFAULT NULL,
  `status` enum('pending','manager_approved','hr_approved','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
  `manager_id` bigint(20) unsigned DEFAULT NULL,
  `manager_approved_by` bigint(20) unsigned DEFAULT NULL,
  `manager_approved_at` timestamp NULL DEFAULT NULL,
  `manager_notes` text DEFAULT NULL,
  `hr_approved_by` bigint(20) unsigned DEFAULT NULL,
  `hr_approved_at` timestamp NULL DEFAULT NULL,
  `hr_notes` text DEFAULT NULL,
  `ceo_approved_by` bigint(20) unsigned DEFAULT NULL,
  `ceo_approved_at` timestamp NULL DEFAULT NULL,
  `ceo_notes` text DEFAULT NULL,
  `rejected_by` bigint(20) unsigned DEFAULT NULL,
  `rejected_at` timestamp NULL DEFAULT NULL,
  `rejected_stage` varchar(20) DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `new_contract_id` bigint(20) unsigned DEFAULT NULL,
  `auto_generated` tinyint(1) NOT NULL DEFAULT 1,
  `notified_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `contract_renewal_requests_reference_unique` (`reference`),
  KEY `contract_renewal_requests_new_contract_id_foreign` (`new_contract_id`),
  KEY `contract_renewal_requests_contract_id_status_index` (`contract_id`,`status`),
  KEY `contract_renewal_requests_employee_id_status_index` (`employee_id`,`status`),
  KEY `contract_renewal_requests_status_index` (`status`),
  CONSTRAINT `contract_renewal_requests_contract_id_foreign` FOREIGN KEY (`contract_id`) REFERENCES `employee_contracts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `contract_renewal_requests_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `contract_renewal_requests_new_contract_id_foreign` FOREIGN KEY (`new_contract_id`) REFERENCES `employee_contracts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `contract_renewal_requests`
--

LOCK TABLES `contract_renewal_requests` WRITE;
/*!40000 ALTER TABLE `contract_renewal_requests` DISABLE KEYS */;
/*!40000 ALTER TABLE `contract_renewal_requests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `contract_renewals`
--

DROP TABLE IF EXISTS `contract_renewals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `contract_renewals` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `contract_id` bigint(20) unsigned NOT NULL,
  `proposed_start_date` date DEFAULT NULL,
  `proposed_end_date` date DEFAULT NULL,
  `status` enum('pending_manager','pending_hr','pending_ceo','approved','rejected') NOT NULL DEFAULT 'pending_manager',
  `rejected_at_stage` varchar(255) DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `requested_by` bigint(20) unsigned DEFAULT NULL,
  `auto_created` tinyint(1) NOT NULL DEFAULT 0,
  `manager_approver_id` bigint(20) unsigned DEFAULT NULL,
  `hr_approver_id` bigint(20) unsigned DEFAULT NULL,
  `ceo_approver_id` bigint(20) unsigned DEFAULT NULL,
  `manager_approved_at` timestamp NULL DEFAULT NULL,
  `hr_approved_at` timestamp NULL DEFAULT NULL,
  `ceo_approved_at` timestamp NULL DEFAULT NULL,
  `rejected_at` timestamp NULL DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `contract_renewals_requested_by_foreign` (`requested_by`),
  KEY `contract_renewals_manager_approver_id_foreign` (`manager_approver_id`),
  KEY `contract_renewals_hr_approver_id_foreign` (`hr_approver_id`),
  KEY `contract_renewals_ceo_approver_id_foreign` (`ceo_approver_id`),
  KEY `contract_renewals_contract_id_status_index` (`contract_id`,`status`),
  KEY `contract_renewals_status_index` (`status`),
  CONSTRAINT `contract_renewals_ceo_approver_id_foreign` FOREIGN KEY (`ceo_approver_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `contract_renewals_contract_id_foreign` FOREIGN KEY (`contract_id`) REFERENCES `contracts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `contract_renewals_hr_approver_id_foreign` FOREIGN KEY (`hr_approver_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `contract_renewals_manager_approver_id_foreign` FOREIGN KEY (`manager_approver_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `contract_renewals_requested_by_foreign` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `contract_renewals`
--

LOCK TABLES `contract_renewals` WRITE;
/*!40000 ALTER TABLE `contract_renewals` DISABLE KEYS */;
/*!40000 ALTER TABLE `contract_renewals` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `contracts`
--

DROP TABLE IF EXISTS `contracts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `contracts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `employee_id` bigint(20) unsigned NOT NULL,
  `contract_type` enum('fixed','unlimited','part_time','freelance') NOT NULL DEFAULT 'fixed',
  `position` varchar(255) DEFAULT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `probation_end` date DEFAULT NULL,
  `salary` decimal(12,2) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `renewal_notified` tinyint(1) NOT NULL DEFAULT 0,
  `renewal_requested` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `contracts_employee_id_is_active_index` (`employee_id`,`is_active`),
  KEY `contracts_end_date_index` (`end_date`),
  CONSTRAINT `contracts_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `contracts`
--

LOCK TABLES `contracts` WRITE;
/*!40000 ALTER TABLE `contracts` DISABLE KEYS */;
/*!40000 ALTER TABLE `contracts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `department_excuse_limits`
--

DROP TABLE IF EXISTS `department_excuse_limits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `department_excuse_limits` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `department_id` bigint(20) unsigned NOT NULL,
  `leave_type_id` bigint(20) unsigned NOT NULL,
  `monthly_hours_limit` decimal(5,2) DEFAULT NULL COMMENT 'NULL = unlimited. Set a value to cap hours per month.',
  `is_limited` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'false = unlimited regardless of monthly_hours_limit',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `dept_leavetype_unique` (`department_id`,`leave_type_id`),
  KEY `department_excuse_limits_leave_type_id_foreign` (`leave_type_id`),
  CONSTRAINT `department_excuse_limits_department_id_foreign` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `department_excuse_limits_leave_type_id_foreign` FOREIGN KEY (`leave_type_id`) REFERENCES `leave_types` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `department_excuse_limits`
--

LOCK TABLES `department_excuse_limits` WRITE;
/*!40000 ALTER TABLE `department_excuse_limits` DISABLE KEYS */;
/*!40000 ALTER TABLE `department_excuse_limits` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `departments`
--

DROP TABLE IF EXISTS `departments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `departments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `code` varchar(20) NOT NULL,
  `description` text DEFAULT NULL,
  `parent_id` bigint(20) unsigned DEFAULT NULL,
  `manager_id` bigint(20) unsigned DEFAULT NULL,
  `headcount_budget` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `departments_code_unique` (`code`),
  KEY `departments_parent_id_foreign` (`parent_id`),
  CONSTRAINT `departments_parent_id_foreign` FOREIGN KEY (`parent_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `departments`
--

LOCK TABLES `departments` WRITE;
/*!40000 ALTER TABLE `departments` DISABLE KEYS */;
INSERT INTO `departments` VALUES (2,'Human Resources','HR',NULL,NULL,NULL,10,1,'2026-04-12 07:14:15','2026-04-12 07:14:15',NULL),(3,'Information Technology','IT',NULL,NULL,NULL,20,1,'2026-04-12 07:14:15','2026-04-12 07:14:15',NULL),(4,'Finance','FIN',NULL,NULL,NULL,8,1,'2026-04-12 07:14:15','2026-04-12 07:14:15',NULL),(5,'Operations','OPS',NULL,NULL,NULL,25,1,'2026-04-12 07:14:15','2026-04-12 07:14:15',NULL),(6,'Sales & Marketing','SM',NULL,NULL,NULL,15,1,'2026-04-12 07:14:15','2026-04-12 07:14:15',NULL),(7,'Legal & Compliance','LEG',NULL,NULL,NULL,5,1,'2026-04-12 07:14:15','2026-04-12 07:14:15',NULL),(8,'Executive','EXE',NULL,NULL,NULL,3,1,'2026-04-12 07:14:15','2026-04-12 07:14:15',NULL);
/*!40000 ALTER TABLE `departments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `designations`
--

DROP TABLE IF EXISTS `designations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `designations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(100) NOT NULL,
  `level` varchar(50) DEFAULT NULL,
  `department_id` bigint(20) unsigned DEFAULT NULL,
  `min_salary` decimal(12,2) DEFAULT NULL,
  `max_salary` decimal(12,2) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `designations_department_id_foreign` (`department_id`),
  CONSTRAINT `designations_department_id_foreign` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `designations`
--

LOCK TABLES `designations` WRITE;
/*!40000 ALTER TABLE `designations` DISABLE KEYS */;
INSERT INTO `designations` VALUES (1,'Chief Executive Officer','executive',8,NULL,NULL,1,'2026-04-12 07:14:15','2026-04-12 07:14:15'),(2,'Chief Technology Officer','executive',3,NULL,NULL,1,'2026-04-12 07:14:15','2026-04-12 07:14:15'),(3,'HR Manager','management',2,NULL,NULL,1,'2026-04-12 07:14:15','2026-04-12 07:14:15'),(4,'HR Officer','staff',2,NULL,NULL,1,'2026-04-12 07:14:15','2026-04-12 07:14:15'),(5,'Software Engineer','staff',3,NULL,NULL,1,'2026-04-12 07:14:15','2026-04-12 07:14:15'),(6,'Senior Software Engineer','senior',3,NULL,NULL,1,'2026-04-12 07:14:15','2026-04-12 07:14:15'),(7,'Finance Manager','management',4,NULL,NULL,1,'2026-04-12 07:14:15','2026-04-12 07:14:15'),(8,'Accountant','staff',4,NULL,NULL,1,'2026-04-12 07:14:15','2026-04-12 07:14:15'),(9,'Operations Manager','management',5,NULL,NULL,1,'2026-04-12 07:14:15','2026-04-12 07:14:15'),(10,'Operations Coordinator','staff',5,NULL,NULL,1,'2026-04-12 07:14:15','2026-04-12 07:14:15');
/*!40000 ALTER TABLE `designations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `device_attendance_logs`
--

DROP TABLE IF EXISTS `device_attendance_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `device_attendance_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `device_id` bigint(20) unsigned NOT NULL,
  `device_employee_number` varchar(50) NOT NULL,
  `employee_id` bigint(20) unsigned DEFAULT NULL,
  `punch_time` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `punch_type` tinyint(4) NOT NULL DEFAULT 0,
  `verification_mode` varchar(20) DEFAULT NULL,
  `processed` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `dal_device_empno_punchtime_unique` (`device_id`,`device_employee_number`,`punch_time`),
  KEY `device_attendance_logs_device_employee_number_punch_time_index` (`device_employee_number`,`punch_time`),
  KEY `device_attendance_logs_employee_id_punch_time_index` (`employee_id`,`punch_time`),
  CONSTRAINT `device_attendance_logs_device_id_foreign` FOREIGN KEY (`device_id`) REFERENCES `attendance_devices` (`id`) ON DELETE CASCADE,
  CONSTRAINT `device_attendance_logs_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `device_attendance_logs`
--

LOCK TABLES `device_attendance_logs` WRITE;
/*!40000 ALTER TABLE `device_attendance_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `device_attendance_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `employee_contracts`
--

DROP TABLE IF EXISTS `employee_contracts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `employee_contracts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `employee_id` bigint(20) unsigned NOT NULL,
  `reference` varchar(50) NOT NULL,
  `type` enum('full_time','part_time','contract','intern','probation','fixed_term','unlimited') NOT NULL DEFAULT 'full_time',
  `status` enum('draft','active','expired','terminated','cancelled') NOT NULL DEFAULT 'draft',
  `start_date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `salary` decimal(12,2) DEFAULT NULL,
  `currency` varchar(3) NOT NULL DEFAULT 'SAR',
  `position` varchar(150) DEFAULT NULL,
  `department_id` bigint(20) unsigned DEFAULT NULL,
  `terms` text DEFAULT NULL,
  `pdf_path` varchar(500) DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `approved_by` bigint(20) unsigned DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `renewal_requested` tinyint(1) NOT NULL DEFAULT 0,
  `renewal_notified` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `employee_contracts_reference_unique` (`reference`),
  KEY `employee_contracts_department_id_foreign` (`department_id`),
  KEY `employee_contracts_created_by_foreign` (`created_by`),
  KEY `employee_contracts_approved_by_foreign` (`approved_by`),
  KEY `employee_contracts_employee_id_status_index` (`employee_id`,`status`),
  KEY `employee_contracts_status_index` (`status`),
  CONSTRAINT `employee_contracts_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `employee_contracts_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `employee_contracts_department_id_foreign` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `employee_contracts_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `employee_contracts`
--

LOCK TABLES `employee_contracts` WRITE;
/*!40000 ALTER TABLE `employee_contracts` DISABLE KEYS */;
/*!40000 ALTER TABLE `employee_contracts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `employee_documents`
--

DROP TABLE IF EXISTS `employee_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `employee_documents` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `employee_id` bigint(20) unsigned NOT NULL,
  `title` varchar(100) NOT NULL,
  `type` enum('contract','id','certificate','visa','passport','medical','other') NOT NULL DEFAULT 'other',
  `file_path` varchar(255) NOT NULL,
  `file_name` varchar(255) DEFAULT NULL,
  `mime_type` varchar(100) DEFAULT NULL,
  `file_size` bigint(20) unsigned DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `is_verified` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `employee_documents_employee_id_foreign` (`employee_id`),
  CONSTRAINT `employee_documents_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `employee_documents`
--

LOCK TABLES `employee_documents` WRITE;
/*!40000 ALTER TABLE `employee_documents` DISABLE KEYS */;
/*!40000 ALTER TABLE `employee_documents` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `employee_requests`
--

DROP TABLE IF EXISTS `employee_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `employee_requests` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `reference` varchar(30) NOT NULL,
  `employee_id` bigint(20) unsigned NOT NULL,
  `request_type_id` bigint(20) unsigned NOT NULL,
  `status` enum('pending','pending_manager','in_progress','completed','rejected','cancelled') NOT NULL DEFAULT 'pending',
  `details` text NOT NULL,
  `hr_notes` text DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `required_by` date DEFAULT NULL,
  `copies_needed` int(11) NOT NULL DEFAULT 1,
  `attachment_path` varchar(255) DEFAULT NULL COMMENT 'Path to supporting document uploaded by employee',
  `manager_approved_by` bigint(20) unsigned DEFAULT NULL,
  `manager_approved_at` timestamp NULL DEFAULT NULL,
  `manager_notes` text DEFAULT NULL COMMENT 'Notes added by manager when approving',
  `assigned_to` bigint(20) unsigned DEFAULT NULL,
  `completed_by` bigint(20) unsigned DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `rejected_by` bigint(20) unsigned DEFAULT NULL,
  `rejected_at` timestamp NULL DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `is_overdue` tinyint(1) NOT NULL DEFAULT 0,
  `completion_file` varchar(255) DEFAULT NULL,
  `completion_notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `employee_requests_reference_unique` (`reference`),
  KEY `employee_requests_employee_id_foreign` (`employee_id`),
  KEY `employee_requests_request_type_id_foreign` (`request_type_id`),
  KEY `employee_requests_manager_approved_by_foreign` (`manager_approved_by`),
  KEY `employee_requests_assigned_to_foreign` (`assigned_to`),
  KEY `employee_requests_completed_by_foreign` (`completed_by`),
  KEY `employee_requests_rejected_by_foreign` (`rejected_by`),
  CONSTRAINT `employee_requests_assigned_to_foreign` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`),
  CONSTRAINT `employee_requests_completed_by_foreign` FOREIGN KEY (`completed_by`) REFERENCES `users` (`id`),
  CONSTRAINT `employee_requests_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `employee_requests_manager_approved_by_foreign` FOREIGN KEY (`manager_approved_by`) REFERENCES `users` (`id`),
  CONSTRAINT `employee_requests_rejected_by_foreign` FOREIGN KEY (`rejected_by`) REFERENCES `users` (`id`),
  CONSTRAINT `employee_requests_request_type_id_foreign` FOREIGN KEY (`request_type_id`) REFERENCES `request_types` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `employee_requests`
--

LOCK TABLES `employee_requests` WRITE;
/*!40000 ALTER TABLE `employee_requests` DISABLE KEYS */;
INSERT INTO `employee_requests` VALUES (1,'REQ-2026-00001',106,21,'completed','dddddddddddddddddddddd','f',NULL,'2026-04-13',1,NULL,NULL,NULL,NULL,284,284,'2026-04-12 08:11:47',NULL,NULL,'2026-04-15',0,NULL,'ffffffff','2026-04-12 08:11:02','2026-04-12 08:11:47'),(2,'REQ-2026-00002',106,18,'in_progress','sssffff',NULL,NULL,'2026-04-13',1,NULL,284,'2026-04-12 09:04:55',NULL,284,NULL,NULL,NULL,NULL,'2026-04-17',0,NULL,NULL,'2026-04-12 09:04:51','2026-04-12 09:05:16'),(3,'REQ-2026-00003',106,2,'in_progress','Auto-generated from annual leave request #10. Annual leave: 2026-04-14 00:00:00 – 2026-04-16 00:00:00 (3.0 days). Exit re-entry visa required before departure.',NULL,NULL,NULL,1,NULL,NULL,NULL,NULL,284,NULL,NULL,NULL,NULL,'2026-04-20',0,NULL,NULL,'2026-04-13 09:56:20','2026-04-13 09:57:01');
/*!40000 ALTER TABLE `employee_requests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `employees`
--

DROP TABLE IF EXISTS `employees`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `employees` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `department_id` bigint(20) unsigned DEFAULT NULL,
  `designation_id` bigint(20) unsigned DEFAULT NULL,
  `manager_id` bigint(20) unsigned DEFAULT NULL,
  `employee_code` varchar(20) NOT NULL,
  `prefix` varchar(10) DEFAULT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `arabic_name` varchar(200) DEFAULT NULL,
  `email` varchar(191) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `work_phone` varchar(20) DEFAULT NULL,
  `extension` varchar(10) DEFAULT NULL,
  `dob` date DEFAULT NULL,
  `gender` enum('male','female','other') DEFAULT NULL,
  `marital_status` enum('single','married','divorced','widowed') DEFAULT NULL,
  `hire_date` date NOT NULL,
  `confirmation_date` date DEFAULT NULL,
  `probation_period` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `years_of_experience` smallint(5) unsigned DEFAULT NULL,
  `termination_date` date DEFAULT NULL,
  `employment_type` enum('full_time','part_time','contract','intern') NOT NULL DEFAULT 'full_time',
  `mode_of_employment` varchar(50) DEFAULT NULL,
  `role` varchar(50) DEFAULT NULL,
  `status` enum('active','inactive','terminated','on_leave','probation') DEFAULT 'active',
  `salary` decimal(12,2) NOT NULL DEFAULT 0.00,
  `housing_allowance` decimal(12,2) DEFAULT NULL COMMENT 'Monthly housing allowance (SAR). If null, 25% of basic is used.',
  `transport_allowance` decimal(12,2) DEFAULT NULL COMMENT 'Monthly transport allowance (SAR). If null, SAR 400 default is used.',
  `other_allowances` decimal(12,2) DEFAULT 0.00 COMMENT 'Other fixed monthly allowances (SAR).',
  `mobile_allowance` decimal(12,2) DEFAULT 0.00 COMMENT 'Monthly mobile/phone allowance (SAR).',
  `food_allowance` decimal(12,2) DEFAULT 0.00 COMMENT 'Monthly food/meal allowance (SAR).',
  `avatar` varchar(255) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `country` varchar(100) DEFAULT NULL,
  `nationality` varchar(100) DEFAULT NULL,
  `national_id` varchar(50) DEFAULT NULL,
  `bank_name` varchar(100) DEFAULT NULL,
  `bank_account` varchar(50) DEFAULT NULL,
  `emergency_contact_name` varchar(100) DEFAULT NULL,
  `emergency_contact_phone` varchar(20) DEFAULT NULL,
  `emergency_contact_relation` varchar(50) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `employees_user_id_unique` (`user_id`),
  UNIQUE KEY `employees_employee_code_unique` (`employee_code`),
  UNIQUE KEY `employees_email_unique` (`email`),
  KEY `employees_department_id_foreign` (`department_id`),
  KEY `employees_designation_id_foreign` (`designation_id`),
  KEY `employees_manager_id_foreign` (`manager_id`),
  CONSTRAINT `employees_department_id_foreign` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `employees_designation_id_foreign` FOREIGN KEY (`designation_id`) REFERENCES `designations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `employees_manager_id_foreign` FOREIGN KEY (`manager_id`) REFERENCES `employees` (`id`) ON DELETE SET NULL,
  CONSTRAINT `employees_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=108 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `employees`
--

LOCK TABLES `employees` WRITE;
/*!40000 ALTER TABLE `employees` DISABLE KEYS */;
INSERT INTO `employees` VALUES (106,284,2,3,NULL,'EMP0001',NULL,'System','Admin',NULL,'admin@hrms.com',NULL,NULL,NULL,NULL,NULL,NULL,'2026-04-12',NULL,0,NULL,NULL,'full_time',NULL,NULL,'active',5000.00,NULL,NULL,0.00,0.00,0.00,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-04-12 07:14:15','2026-04-12 07:14:15',NULL),(107,285,3,NULL,NULL,'EMP0002',NULL,'jithin','varkey',NULL,'jithinvarkey@gmail.com','+966920004778',NULL,NULL,NULL,NULL,NULL,'2026-04-13',NULL,90,NULL,NULL,'full_time',NULL,NULL,'probation',10000.00,NULL,NULL,0.00,0.00,0.00,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-04-13 10:56:11','2026-04-13 10:56:11',NULL);
/*!40000 ALTER TABLE `employees` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `failed_jobs`
--

DROP TABLE IF EXISTS `failed_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `failed_jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) NOT NULL,
  `connection` text NOT NULL,
  `queue` text NOT NULL,
  `payload` longtext NOT NULL,
  `exception` longtext NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `failed_jobs`
--

LOCK TABLES `failed_jobs` WRITE;
/*!40000 ALTER TABLE `failed_jobs` DISABLE KEYS */;
/*!40000 ALTER TABLE `failed_jobs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `holidays`
--

DROP TABLE IF EXISTS `holidays`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `holidays` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `date` date NOT NULL,
  `is_recurring` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `holidays`
--

LOCK TABLES `holidays` WRITE;
/*!40000 ALTER TABLE `holidays` DISABLE KEYS */;
/*!40000 ALTER TABLE `holidays` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `interviews`
--

DROP TABLE IF EXISTS `interviews`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `interviews` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `application_id` bigint(20) unsigned NOT NULL,
  `round` varchar(50) NOT NULL,
  `scheduled_at` datetime NOT NULL,
  `duration_minutes` int(11) NOT NULL DEFAULT 60,
  `format` enum('in_person','video','phone') NOT NULL DEFAULT 'video',
  `location_or_link` varchar(255) DEFAULT NULL,
  `status` enum('scheduled','completed','cancelled','no_show') NOT NULL DEFAULT 'scheduled',
  `feedback` text DEFAULT NULL,
  `result` enum('pass','fail','pending') NOT NULL DEFAULT 'pending',
  `interviewers` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`interviewers`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `interviews_application_id_foreign` (`application_id`),
  CONSTRAINT `interviews_application_id_foreign` FOREIGN KEY (`application_id`) REFERENCES `job_applications` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `interviews`
--

LOCK TABLES `interviews` WRITE;
/*!40000 ALTER TABLE `interviews` DISABLE KEYS */;
/*!40000 ALTER TABLE `interviews` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `job_applications`
--

DROP TABLE IF EXISTS `job_applications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `job_applications` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `job_posting_id` bigint(20) unsigned DEFAULT NULL,
  `is_cv_bank` tinyint(1) NOT NULL DEFAULT 0,
  `position_applied` varchar(150) DEFAULT NULL,
  `nationality` varchar(100) DEFAULT NULL,
  `experience_years` int(11) DEFAULT NULL,
  `skills` text DEFAULT NULL,
  `source` varchar(80) DEFAULT NULL,
  `rating` varchar(10) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `applicant_name` varchar(150) NOT NULL,
  `applicant_email` varchar(191) NOT NULL,
  `applicant_phone` varchar(20) DEFAULT NULL,
  `cv_path` varchar(255) DEFAULT NULL,
  `cover_letter_path` varchar(255) DEFAULT NULL,
  `cover_letter_text` text DEFAULT NULL,
  `stage` enum('applied','screening','interview','offer','hired','rejected') NOT NULL DEFAULT 'applied',
  `hr_notes` text DEFAULT NULL,
  `expected_salary` decimal(12,2) DEFAULT NULL,
  `available_from` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `job_applications_job_posting_id_foreign` (`job_posting_id`),
  CONSTRAINT `job_applications_job_posting_id_foreign` FOREIGN KEY (`job_posting_id`) REFERENCES `job_postings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `job_applications`
--

LOCK TABLES `job_applications` WRITE;
/*!40000 ALTER TABLE `job_applications` DISABLE KEYS */;
INSERT INTO `job_applications` VALUES (3,6,0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'jithin varkey','jithinvarkey@gmail.com','+966920004778',NULL,NULL,'ddd','hired',NULL,10000.00,'2026-04-14','2026-04-13 10:21:54','2026-04-13 10:56:12');
/*!40000 ALTER TABLE `job_applications` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `job_postings`
--

DROP TABLE IF EXISTS `job_postings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `job_postings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(150) NOT NULL,
  `department_id` bigint(20) unsigned DEFAULT NULL,
  `designation_id` bigint(20) unsigned DEFAULT NULL,
  `employment_type` enum('full_time','part_time','contract','intern') NOT NULL,
  `status` enum('draft','open','closed','on_hold') NOT NULL DEFAULT 'draft',
  `vacancies` int(11) NOT NULL DEFAULT 1,
  `description` text NOT NULL,
  `requirements` text DEFAULT NULL,
  `benefits` text DEFAULT NULL,
  `salary_min` decimal(12,2) DEFAULT NULL,
  `salary_max` decimal(12,2) DEFAULT NULL,
  `location` varchar(100) DEFAULT NULL,
  `closing_date` date DEFAULT NULL,
  `created_by` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `job_postings_department_id_foreign` (`department_id`),
  KEY `job_postings_designation_id_foreign` (`designation_id`),
  KEY `job_postings_created_by_foreign` (`created_by`),
  CONSTRAINT `job_postings_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `job_postings_department_id_foreign` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `job_postings_designation_id_foreign` FOREIGN KEY (`designation_id`) REFERENCES `designations` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `job_postings`
--

LOCK TABLES `job_postings` WRITE;
/*!40000 ALTER TABLE `job_postings` DISABLE KEYS */;
INSERT INTO `job_postings` VALUES (6,'IT developer',3,NULL,'full_time','closed',1,'dgggggggggggggggggggggg','dddddddddddddddddddddddddd','ddddddddddddddddddd',10000.00,10000.00,NULL,NULL,284,'2026-04-13 10:10:36','2026-04-13 10:56:12',NULL);
/*!40000 ALTER TABLE `job_postings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `jobs`
--

DROP TABLE IF EXISTS `jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `manpower_request_id` bigint(20) unsigned DEFAULT NULL,
  `source` varchar(255) DEFAULT 'direct',
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `department_id` bigint(20) unsigned DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `status` enum('open','paused','closed','filled') NOT NULL DEFAULT 'open',
  `employment_type` enum('full_time','part_time','contract','internship','freelance') NOT NULL DEFAULT 'full_time',
  `vacancies` int(10) unsigned NOT NULL DEFAULT 1,
  `location` varchar(255) DEFAULT NULL,
  `experience_years` int(10) unsigned DEFAULT NULL,
  `salary_min` bigint(20) unsigned DEFAULT NULL,
  `salary_max` bigint(20) unsigned DEFAULT NULL,
  `deadline` date DEFAULT NULL,
  `description` text DEFAULT NULL,
  `requirements` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_created_by_foreign` (`created_by`),
  KEY `jobs_department_id_foreign` (`department_id`),
  CONSTRAINT `jobs_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `jobs_department_id_foreign` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `jobs`
--

LOCK TABLES `jobs` WRITE;
/*!40000 ALTER TABLE `jobs` DISABLE KEYS */;
/*!40000 ALTER TABLE `jobs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `kpis`
--

DROP TABLE IF EXISTS `kpis`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `kpis` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `department_id` bigint(20) unsigned DEFAULT NULL,
  `employee_id` bigint(20) unsigned DEFAULT NULL,
  `category` enum('quantitative','qualitative','behavioral','learning') NOT NULL,
  `target_value` decimal(10,2) DEFAULT NULL,
  `unit` varchar(50) DEFAULT NULL,
  `weight` int(11) NOT NULL DEFAULT 10,
  `year` int(11) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `kpis_department_id_foreign` (`department_id`),
  KEY `kpis_employee_id_foreign` (`employee_id`),
  CONSTRAINT `kpis_department_id_foreign` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `kpis_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `kpis`
--

LOCK TABLES `kpis` WRITE;
/*!40000 ALTER TABLE `kpis` DISABLE KEYS */;
/*!40000 ALTER TABLE `kpis` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `leave_allocations`
--

DROP TABLE IF EXISTS `leave_allocations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `leave_allocations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `employee_id` bigint(20) unsigned NOT NULL,
  `leave_type_id` bigint(20) unsigned NOT NULL,
  `year` int(11) NOT NULL,
  `allocated_days` decimal(5,1) NOT NULL DEFAULT 0.0,
  `used_days` decimal(5,1) NOT NULL DEFAULT 0.0,
  `pending_days` decimal(5,1) NOT NULL DEFAULT 0.0,
  `remaining_days` decimal(5,1) NOT NULL DEFAULT 0.0,
  `carried_forward_days` decimal(5,1) NOT NULL DEFAULT 0.0 COMMENT 'Days carried forward from previous year',
  `used_hours` decimal(7,2) NOT NULL DEFAULT 0.00 COMMENT 'Total hours consumed this month (for hourly leave)',
  `pending_hours` decimal(7,2) NOT NULL DEFAULT 0.00,
  `accrual_year_start` date DEFAULT NULL COMMENT 'Anniversary date that started the current accrual year',
  `last_accrual_date` date DEFAULT NULL COMMENT 'Date this record was last updated by the accrual command',
  `annual_entitlement` tinyint(3) unsigned NOT NULL DEFAULT 22 COMMENT '22 days (<5 yrs service) or 30 days (>=5 yrs service)',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `leave_allocations_employee_id_leave_type_id_year_unique` (`employee_id`,`leave_type_id`,`year`),
  KEY `leave_allocations_leave_type_id_foreign` (`leave_type_id`),
  CONSTRAINT `leave_allocations_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `leave_allocations_leave_type_id_foreign` FOREIGN KEY (`leave_type_id`) REFERENCES `leave_types` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=26 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `leave_allocations`
--

LOCK TABLES `leave_allocations` WRITE;
/*!40000 ALTER TABLE `leave_allocations` DISABLE KEYS */;
INSERT INTO `leave_allocations` VALUES (19,107,37,2026,22.0,0.0,0.0,22.0,0.0,0.00,0.00,NULL,NULL,22,'2026-04-13 10:56:12','2026-04-13 10:56:12'),(20,107,38,2026,10.0,0.0,0.0,10.0,0.0,0.00,0.00,NULL,NULL,22,'2026-04-13 10:56:12','2026-04-13 10:56:12'),(21,107,39,2026,0.0,0.0,0.0,0.0,0.0,0.00,0.00,NULL,NULL,22,'2026-04-13 10:56:12','2026-04-13 10:56:12'),(22,107,40,2026,90.0,0.0,0.0,90.0,0.0,0.00,0.00,NULL,NULL,22,'2026-04-13 10:56:12','2026-04-13 10:56:12'),(23,107,41,2026,5.0,0.0,0.0,5.0,0.0,0.00,0.00,NULL,NULL,22,'2026-04-13 10:56:12','2026-04-13 10:56:12'),(24,107,42,2026,30.0,0.0,0.0,30.0,0.0,0.00,0.00,NULL,NULL,22,'2026-04-13 10:56:12','2026-04-13 10:56:12'),(25,107,43,2026,3.0,0.0,0.0,3.0,0.0,0.00,0.00,NULL,NULL,22,'2026-04-13 10:56:12','2026-04-13 10:56:12');
/*!40000 ALTER TABLE `leave_allocations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `leave_requests`
--

DROP TABLE IF EXISTS `leave_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `leave_requests` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `employee_id` bigint(20) unsigned NOT NULL,
  `leave_type_id` bigint(20) unsigned NOT NULL,
  `start_date` date NOT NULL,
  `start_time` time DEFAULT NULL COMMENT 'For hourly leave (e.g. Business Excuse)',
  `end_time` time DEFAULT NULL COMMENT 'For hourly leave',
  `end_date` date NOT NULL,
  `total_days` decimal(5,1) NOT NULL,
  `is_half_day` tinyint(1) NOT NULL DEFAULT 0,
  `half_day_period` enum('morning','afternoon') DEFAULT NULL,
  `requires_exit_reentry` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Employee needs exit re-entry visa',
  `requires_ticket` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Employee needs air ticket',
  `destination_country` varchar(100) DEFAULT NULL COMMENT 'Travel destination for annual leave',
  `total_hours` decimal(5,2) DEFAULT NULL COMMENT 'Duration in hours for hourly leave types',
  `status` enum('pending','manager_approved','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
  `reason` text NOT NULL,
  `rejection_reason` text DEFAULT NULL,
  `rejected_stage` varchar(20) DEFAULT NULL COMMENT 'manager or hr',
  `approved_by` bigint(20) unsigned DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `manager_approved_by` bigint(20) unsigned DEFAULT NULL,
  `manager_approved_at` timestamp NULL DEFAULT NULL,
  `manager_notes` text DEFAULT NULL,
  `hr_notes` text DEFAULT NULL,
  `document_path` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `leave_requests_employee_id_foreign` (`employee_id`),
  KEY `leave_requests_leave_type_id_foreign` (`leave_type_id`),
  KEY `leave_requests_approved_by_foreign` (`approved_by`),
  KEY `leave_requests_manager_approved_by_foreign` (`manager_approved_by`),
  CONSTRAINT `leave_requests_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`),
  CONSTRAINT `leave_requests_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `leave_requests_leave_type_id_foreign` FOREIGN KEY (`leave_type_id`) REFERENCES `leave_types` (`id`),
  CONSTRAINT `leave_requests_manager_approved_by_foreign` FOREIGN KEY (`manager_approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `leave_requests`
--

LOCK TABLES `leave_requests` WRITE;
/*!40000 ALTER TABLE `leave_requests` DISABLE KEYS */;
INSERT INTO `leave_requests` VALUES (9,106,37,'2026-04-13',NULL,NULL,'2026-04-16',4.0,0,NULL,0,0,NULL,NULL,'pending','ccddddggggggggggggg',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-04-12 09:17:41','2026-04-12 09:17:41'),(10,106,37,'2026-04-14',NULL,NULL,'2026-04-16',3.0,0,NULL,1,0,NULL,NULL,'pending','ffffffffffffffffffffffffffff',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-04-13 09:56:12','2026-04-13 09:56:12');
/*!40000 ALTER TABLE `leave_requests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `leave_type_department_visibility`
--

DROP TABLE IF EXISTS `leave_type_department_visibility`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `leave_type_department_visibility` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `leave_type_id` bigint(20) unsigned NOT NULL,
  `department_id` bigint(20) unsigned NOT NULL,
  `is_visible` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `leave_type_department_visible_unique` (`leave_type_id`,`department_id`),
  KEY `leave_type_department_visibility_department_id_foreign` (`department_id`),
  CONSTRAINT `leave_type_department_visibility_department_id_foreign` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `leave_type_department_visibility_leave_type_id_foreign` FOREIGN KEY (`leave_type_id`) REFERENCES `leave_types` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `leave_type_department_visibility`
--

LOCK TABLES `leave_type_department_visibility` WRITE;
/*!40000 ALTER TABLE `leave_type_department_visibility` DISABLE KEYS */;
INSERT INTO `leave_type_department_visibility` VALUES (1,39,8,0,'2026-06-18 10:00:30','2026-06-18 10:00:30'),(2,39,4,0,'2026-06-18 10:00:30','2026-06-18 10:00:30'),(3,39,2,0,'2026-06-18 10:00:30','2026-06-18 10:00:30'),(4,39,3,0,'2026-06-18 10:00:30','2026-06-18 10:00:30'),(5,39,7,0,'2026-06-18 10:00:30','2026-06-18 10:00:30'),(6,39,5,1,'2026-06-18 10:00:30','2026-06-18 10:00:30'),(7,39,6,1,'2026-06-18 10:00:30','2026-06-18 10:00:30');
/*!40000 ALTER TABLE `leave_type_department_visibility` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `leave_types`
--

DROP TABLE IF EXISTS `leave_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `leave_types` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `code` varchar(20) NOT NULL,
  `days_allowed` int(11) NOT NULL DEFAULT 0,
  `is_paid` tinyint(1) NOT NULL DEFAULT 1,
  `carry_forward` tinyint(1) NOT NULL DEFAULT 0,
  `max_carry_forward` int(11) NOT NULL DEFAULT 0,
  `requires_document` tinyint(1) NOT NULL DEFAULT 0,
  `is_hourly` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'If true, leave is measured in hours not days',
  `is_annual` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Annual leave — shows exit re-entry & ticket options',
  `monthly_hours_limit` decimal(5,2) DEFAULT NULL COMMENT 'Monthly cap in hours. NULL = unlimited (e.g. Sales team override)',
  `exempt_department_codes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Departments exempt from the monthly hours limit' CHECK (json_valid(`exempt_department_codes`)),
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `skip_manager_approval` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'If true, requests go straight to HR without manager approval (e.g. sick leave)',
  `description` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `leave_types_code_unique` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=45 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `leave_types`
--

LOCK TABLES `leave_types` WRITE;
/*!40000 ALTER TABLE `leave_types` DISABLE KEYS */;
INSERT INTO `leave_types` VALUES (37,'Annual Leave','AL',22,1,1,5,0,0,1,NULL,NULL,1,0,NULL,'2026-04-12 07:14:15','2026-04-12 07:14:15'),(38,'Sick Leave','SL',10,1,0,0,0,0,0,NULL,NULL,1,0,NULL,'2026-04-12 07:14:15','2026-04-12 07:14:15'),(39,'Business Excuse','BE',0,1,0,0,0,1,0,12.00,'\"[\\\"SM\\\"]\"',1,0,'Hourly excuse for business purposes. Sales team: unlimited. Others: 12h/month max.','2026-04-12 07:14:15','2026-04-12 07:14:15'),(40,'Maternity Leave','ML',90,1,0,0,0,0,0,NULL,NULL,1,0,NULL,'2026-04-12 07:14:15','2026-04-12 07:14:15'),(41,'Paternity Leave','PL',5,1,0,0,0,0,0,NULL,NULL,1,0,NULL,'2026-04-12 07:14:15','2026-04-12 07:14:15'),(42,'Unpaid Leave','UL',30,0,0,0,0,0,0,NULL,NULL,1,0,NULL,'2026-04-12 07:14:15','2026-04-12 07:14:15'),(43,'Emergency Leave','EML',3,1,0,0,0,0,0,NULL,NULL,1,0,NULL,'2026-04-12 07:14:15','2026-04-12 07:14:15'),(44,'Personal Excuse','PE',0,1,0,0,0,1,0,12.00,NULL,1,0,'Hourly personal excuse with department-wise monthly limits. Default cap: 12h/month per employee.','2026-06-18 10:00:30','2026-06-18 10:00:30');
/*!40000 ALTER TABLE `leave_types` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `loan_installments`
--

DROP TABLE IF EXISTS `loan_installments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `loan_installments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `loan_id` bigint(20) unsigned NOT NULL,
  `installment_no` int(11) NOT NULL,
  `due_date` date NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `paid_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `status` enum('pending','paid','skipped','overdue') NOT NULL DEFAULT 'pending',
  `paid_date` date DEFAULT NULL,
  `processed_by` bigint(20) unsigned DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `loan_installments_loan_id_foreign` (`loan_id`),
  KEY `loan_installments_processed_by_foreign` (`processed_by`),
  CONSTRAINT `loan_installments_loan_id_foreign` FOREIGN KEY (`loan_id`) REFERENCES `loans` (`id`) ON DELETE CASCADE,
  CONSTRAINT `loan_installments_processed_by_foreign` FOREIGN KEY (`processed_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `loan_installments`
--

LOCK TABLES `loan_installments` WRITE;
/*!40000 ALTER TABLE `loan_installments` DISABLE KEYS */;
/*!40000 ALTER TABLE `loan_installments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `loan_types`
--

DROP TABLE IF EXISTS `loan_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `loan_types` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `code` varchar(20) NOT NULL,
  `max_amount` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '0 = no limit',
  `max_installments` int(11) NOT NULL DEFAULT 12,
  `interest_rate` decimal(5,2) NOT NULL DEFAULT 0.00 COMMENT 'Annual % — 0 for interest-free',
  `requires_guarantor` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `description` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `loan_types_code_unique` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `loan_types`
--

LOCK TABLES `loan_types` WRITE;
/*!40000 ALTER TABLE `loan_types` DISABLE KEYS */;
INSERT INTO `loan_types` VALUES (7,'Personal Loan','PL',50000.00,12,0.00,0,1,'General purpose personal loan, interest-free.','2026-04-12 07:14:15','2026-04-12 07:14:15'),(8,'Housing Loan','HL',200000.00,12,0.00,0,1,'For housing expenses and rent deposits.','2026-04-12 07:14:15','2026-04-12 07:14:15'),(9,'Emergency Loan','EL',20000.00,6,0.00,0,1,'Fast-track emergency loan, max 6 months.','2026-04-12 07:14:15','2026-04-12 07:14:15'),(10,'Education Loan','EDL',30000.00,12,0.00,0,1,'For employee or dependent education costs.','2026-04-12 07:14:15','2026-04-12 07:14:15'),(11,'Vehicle Loan','VL',80000.00,12,3.50,0,1,'Vehicle purchase or major repair.','2026-04-12 07:14:15','2026-04-12 07:14:15');
/*!40000 ALTER TABLE `loan_types` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `loans`
--

DROP TABLE IF EXISTS `loans`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `loans` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `reference` varchar(30) NOT NULL COMMENT 'e.g. LOAN-2025-00042',
  `employee_id` bigint(20) unsigned NOT NULL,
  `loan_type_id` bigint(20) unsigned NOT NULL,
  `requested_amount` decimal(12,2) NOT NULL,
  `approved_amount` decimal(12,2) DEFAULT NULL,
  `installments` int(11) NOT NULL COMMENT 'Number of monthly installments',
  `monthly_installment` decimal(10,2) DEFAULT NULL,
  `purpose` text NOT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('pending_manager','pending_hr','pending_finance','approved','disbursed','completed','rejected','cancelled') NOT NULL DEFAULT 'pending_manager',
  `manager_approved_by` bigint(20) unsigned DEFAULT NULL,
  `manager_approved_at` timestamp NULL DEFAULT NULL,
  `hr_approved_by` bigint(20) unsigned DEFAULT NULL,
  `hr_approved_at` timestamp NULL DEFAULT NULL,
  `finance_approved_by` bigint(20) unsigned DEFAULT NULL,
  `finance_approved_at` timestamp NULL DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `rejected_by` bigint(20) unsigned DEFAULT NULL,
  `rejected_at` timestamp NULL DEFAULT NULL,
  `rejected_stage` enum('manager','hr','finance') DEFAULT NULL,
  `disbursed_date` date DEFAULT NULL,
  `first_installment_date` date DEFAULT NULL,
  `total_paid` decimal(12,2) NOT NULL DEFAULT 0.00,
  `balance_remaining` decimal(12,2) NOT NULL DEFAULT 0.00,
  `installments_paid` int(11) NOT NULL DEFAULT 0,
  `installments_skipped` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `loans_reference_unique` (`reference`),
  KEY `loans_employee_id_foreign` (`employee_id`),
  KEY `loans_loan_type_id_foreign` (`loan_type_id`),
  KEY `loans_manager_approved_by_foreign` (`manager_approved_by`),
  KEY `loans_hr_approved_by_foreign` (`hr_approved_by`),
  KEY `loans_finance_approved_by_foreign` (`finance_approved_by`),
  KEY `loans_rejected_by_foreign` (`rejected_by`),
  CONSTRAINT `loans_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `loans_finance_approved_by_foreign` FOREIGN KEY (`finance_approved_by`) REFERENCES `users` (`id`),
  CONSTRAINT `loans_hr_approved_by_foreign` FOREIGN KEY (`hr_approved_by`) REFERENCES `users` (`id`),
  CONSTRAINT `loans_loan_type_id_foreign` FOREIGN KEY (`loan_type_id`) REFERENCES `loan_types` (`id`),
  CONSTRAINT `loans_manager_approved_by_foreign` FOREIGN KEY (`manager_approved_by`) REFERENCES `users` (`id`),
  CONSTRAINT `loans_rejected_by_foreign` FOREIGN KEY (`rejected_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `loans`
--

LOCK TABLES `loans` WRITE;
/*!40000 ALTER TABLE `loans` DISABLE KEYS */;
/*!40000 ALTER TABLE `loans` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `manpower_requests`
--

DROP TABLE IF EXISTS `manpower_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `manpower_requests` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `reference` varchar(255) DEFAULT NULL,
  `requested_by` bigint(20) unsigned NOT NULL,
  `department_id` bigint(20) unsigned NOT NULL,
  `position_title` varchar(255) NOT NULL,
  `headcount` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `approved_headcount` tinyint(3) unsigned DEFAULT NULL,
  `employment_type` enum('full_time','part_time','contract','internship','freelance') NOT NULL DEFAULT 'full_time',
  `urgency` enum('low','medium','high','critical') NOT NULL DEFAULT 'medium',
  `reason` text NOT NULL,
  `expected_start_date` date DEFAULT NULL,
  `salary_min` bigint(20) unsigned DEFAULT NULL,
  `salary_max` bigint(20) unsigned DEFAULT NULL,
  `job_description` text DEFAULT NULL,
  `requirements` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `hr_notes` text DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `status` enum('draft','pending_hr','approved','rejected','hired') NOT NULL DEFAULT 'draft',
  `approved_by` bigint(20) unsigned DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `job_posting_created` tinyint(1) NOT NULL DEFAULT 0,
  `job_posting_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `manpower_requests_reference_unique` (`reference`),
  KEY `manpower_requests_requested_by_foreign` (`requested_by`),
  KEY `manpower_requests_department_id_foreign` (`department_id`),
  KEY `manpower_requests_approved_by_foreign` (`approved_by`),
  CONSTRAINT `manpower_requests_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `manpower_requests_department_id_foreign` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `manpower_requests_requested_by_foreign` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `manpower_requests`
--

LOCK TABLES `manpower_requests` WRITE;
/*!40000 ALTER TABLE `manpower_requests` DISABLE KEYS */;
/*!40000 ALTER TABLE `manpower_requests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `migrations`
--

DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `migrations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) NOT NULL,
  `batch` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=52 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `migrations`
--

LOCK TABLES `migrations` WRITE;
/*!40000 ALTER TABLE `migrations` DISABLE KEYS */;
INSERT INTO `migrations` VALUES (1,'2014_10_12_000000_create_users_table',1),(2,'2014_10_12_100000_create_password_reset_tokens_table',1),(3,'2019_08_19_000000_create_failed_jobs_table',1),(4,'2019_12_14_000001_create_personal_access_tokens_table',1),(5,'2023_12_31_000001_create_activity_log_table',1),(6,'2024_01_01_000001_create_departments_table',1),(7,'2024_01_01_000002_create_designations_table',1),(8,'2024_01_01_000003_create_employees_table',1),(9,'2024_01_01_000004_create_contracts_table',1),(10,'2024_01_01_000004_create_payroll_tables',1),(11,'2024_01_01_000005_create_contract_renewals_table',1),(12,'2024_01_01_000005_create_leave_attendance_tables',1),(13,'2024_01_01_000006_create_recruitment_tables',1),(14,'2024_01_01_000007_create_performance_tables',1),(15,'2024_01_01_000010_create_manpower_requests_table',1),(16,'2024_01_01_000011_add_manpower_fields_to_jobs_table',1),(17,'2024_01_02_000001_add_extra_fields_to_employees_table',1),(18,'2024_01_02_000002_create_employee_documents_table',1),(19,'2024_01_02_000003_add_accrual_fields_to_leave_allocations',1),(20,'2024_01_02_000004_add_salary_components_to_payslips',1),(21,'2024_01_02_000005_add_business_excuse_fields',1),(22,'2024_01_02_000006_create_department_excuse_limits_table',1),(23,'2024_01_03_000001_add_carry_forward_to_leave_allocations',1),(24,'2024_01_03_000001_create_loan_tables',1),(25,'2024_01_03_000002_add_salary_components_to_employees',1),(26,'2024_01_04_000001_create_attendance_devices_table',1),(27,'2024_01_04_000001_create_separation_tables',1),(28,'2024_01_05_000001_create_request_management_tables',1),(29,'2024_01_05_000002_add_attachment_path_to_requests',1),(30,'2024_01_06_000001_create_contracts_table',1),(31,'2024_01_06_000002_create_contract_renewal_requests_table',1),(32,'2024_01_06_000003_seed_payroll_settings',1),(33,'2024_01_07_000001_add_two_level_approval_to_leave',1),(34,'2024_01_20_000001_create_performance_cycles_table',1),(35,'2024_01_20_000002_create_performance_reviews_table',1),(36,'2024_01_20_000003_create_performance_goals_table',1),(37,'2024_01_20_000004_create_performance_kpis_table',1),(38,'2024_01_20_000005_create_performance_feedback_table',1),(39,'2026_03_09_094854_create_permission_tables',1),(40,'2026_03_11_110530_create_attendance_logs_table',1),(41,'2026_04_13_000001_add_half_day_to_leave_requests',2),(42,'2026_04_13_000002_add_annual_fields_to_leave_tables',3),(43,'2026_04_13_000002_add_cv_bank_fields_to_job_applications',4),(44,'2026_06_08_000001_add_document_columns_to_contract_renewal_requests_table',5),(45,'2026_06_08_1201223_add_renewal_requested_to_employee_contracts_table',5),(46,'2026_06_16_000000_create_communications_tables',5),(47,'2026_06_16_000001_add_personal_excuse_leave_type',5),(48,'2026_06_16_000002_create_leave_type_department_visibility_table',5),(49,'2026_06_16_000004_enhance_communications_modules',5),(50,'2026_06_16_000005_add_department_visibility_to_policies',5),(51,'2026_06_18_000001_create_system_settings_table',5);
/*!40000 ALTER TABLE `migrations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `model_has_permissions`
--

DROP TABLE IF EXISTS `model_has_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `model_has_permissions` (
  `permission_id` bigint(20) unsigned NOT NULL,
  `model_type` varchar(255) NOT NULL,
  `model_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`permission_id`,`model_id`,`model_type`),
  KEY `model_has_permissions_model_id_model_type_index` (`model_id`,`model_type`),
  CONSTRAINT `model_has_permissions_permission_id_foreign` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `model_has_permissions`
--

LOCK TABLES `model_has_permissions` WRITE;
/*!40000 ALTER TABLE `model_has_permissions` DISABLE KEYS */;
/*!40000 ALTER TABLE `model_has_permissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `model_has_roles`
--

DROP TABLE IF EXISTS `model_has_roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `model_has_roles` (
  `role_id` bigint(20) unsigned NOT NULL,
  `model_type` varchar(255) NOT NULL,
  `model_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`role_id`,`model_id`,`model_type`),
  KEY `model_has_roles_model_id_model_type_index` (`model_id`,`model_type`),
  CONSTRAINT `model_has_roles_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `model_has_roles`
--

LOCK TABLES `model_has_roles` WRITE;
/*!40000 ALTER TABLE `model_has_roles` DISABLE KEYS */;
INSERT INTO `model_has_roles` VALUES (351,'App\\Models\\User',284),(356,'App\\Models\\User',285);
/*!40000 ALTER TABLE `model_has_roles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `offboarding_items`
--

DROP TABLE IF EXISTS `offboarding_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `offboarding_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `separation_id` bigint(20) unsigned NOT NULL,
  `template_id` bigint(20) unsigned DEFAULT NULL,
  `title` varchar(150) NOT NULL,
  `category` varchar(60) NOT NULL DEFAULT 'general',
  `is_required` tinyint(1) NOT NULL DEFAULT 1,
  `status` enum('pending','completed','skipped','na') NOT NULL DEFAULT 'pending',
  `completed_by` bigint(20) unsigned DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `offboarding_items_separation_id_foreign` (`separation_id`),
  KEY `offboarding_items_template_id_foreign` (`template_id`),
  KEY `offboarding_items_completed_by_foreign` (`completed_by`),
  CONSTRAINT `offboarding_items_completed_by_foreign` FOREIGN KEY (`completed_by`) REFERENCES `users` (`id`),
  CONSTRAINT `offboarding_items_separation_id_foreign` FOREIGN KEY (`separation_id`) REFERENCES `separations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `offboarding_items_template_id_foreign` FOREIGN KEY (`template_id`) REFERENCES `offboarding_templates` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `offboarding_items`
--

LOCK TABLES `offboarding_items` WRITE;
/*!40000 ALTER TABLE `offboarding_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `offboarding_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `offboarding_templates`
--

DROP TABLE IF EXISTS `offboarding_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `offboarding_templates` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(150) NOT NULL,
  `category` varchar(60) NOT NULL DEFAULT 'general',
  `description` text DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_required` tinyint(1) NOT NULL DEFAULT 1,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `offboarding_templates`
--

LOCK TABLES `offboarding_templates` WRITE;
/*!40000 ALTER TABLE `offboarding_templates` DISABLE KEYS */;
INSERT INTO `offboarding_templates` VALUES (1,'Return laptop / workstation','it',NULL,1,1,1,'2026-04-12 07:14:15','2026-04-12 07:14:15'),(2,'Return mobile phone / SIM card','it',NULL,2,1,1,'2026-04-12 07:14:15','2026-04-12 07:14:15'),(3,'Revoke system / application access','it',NULL,3,1,1,'2026-04-12 07:14:15','2026-04-12 07:14:15'),(4,'Disable email account','it',NULL,4,1,1,'2026-04-12 07:14:15','2026-04-12 07:14:15'),(5,'Transfer data / project files','it',NULL,5,1,1,'2026-04-12 07:14:15','2026-04-12 07:14:15'),(6,'Complete exit interview','hr',NULL,10,1,1,'2026-04-12 07:14:15','2026-04-12 07:14:15'),(7,'Return ID / access card','hr',NULL,11,1,1,'2026-04-12 07:14:15','2026-04-12 07:14:15'),(8,'Return employee handbook','hr',NULL,12,0,1,'2026-04-12 07:14:15','2026-04-12 07:14:15'),(9,'Sign NDAs / non-compete docs','hr',NULL,13,1,1,'2026-04-12 07:14:15','2026-04-12 07:14:15'),(10,'Update HR records / GOSI','hr',NULL,14,1,1,'2026-04-12 07:14:15','2026-04-12 07:14:15'),(11,'Clear outstanding loans','finance',NULL,20,1,1,'2026-04-12 07:14:15','2026-04-12 07:14:15'),(12,'Return petty cash / advances','finance',NULL,21,1,1,'2026-04-12 07:14:15','2026-04-12 07:14:15'),(13,'Process final settlement','finance',NULL,22,1,1,'2026-04-12 07:14:15','2026-04-12 07:14:15'),(14,'Return company credit card','finance',NULL,23,0,1,'2026-04-12 07:14:15','2026-04-12 07:14:15'),(16,'Return office keys','admin',NULL,31,1,1,'2026-04-12 07:14:15','2026-04-12 07:14:15'),(18,'Knowledge transfer to successor','admin',NULL,33,1,1,'2026-04-12 07:14:15','2026-04-12 07:14:15');
/*!40000 ALTER TABLE `offboarding_templates` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `onboarding_tasks`
--

DROP TABLE IF EXISTS `onboarding_tasks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `onboarding_tasks` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `employee_id` bigint(20) unsigned NOT NULL,
  `title` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `category` enum('it_setup','hr_documents','training','introduction','probation') NOT NULL DEFAULT 'hr_documents',
  `status` enum('pending','in_progress','completed','skipped') NOT NULL DEFAULT 'pending',
  `due_date` date DEFAULT NULL,
  `completed_date` date DEFAULT NULL,
  `assigned_to` bigint(20) unsigned DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `onboarding_tasks_employee_id_foreign` (`employee_id`),
  KEY `onboarding_tasks_assigned_to_foreign` (`assigned_to`),
  CONSTRAINT `onboarding_tasks_assigned_to_foreign` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`),
  CONSTRAINT `onboarding_tasks_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=71 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `onboarding_tasks`
--

LOCK TABLES `onboarding_tasks` WRITE;
/*!40000 ALTER TABLE `onboarding_tasks` DISABLE KEYS */;
INSERT INTO `onboarding_tasks` VALUES (61,107,'Submit copy of National ID / Iqama',NULL,'hr_documents','pending','2026-04-14',NULL,NULL,0,'2026-04-13 10:56:12','2026-04-13 10:56:12'),(62,107,'Submit educational certificates',NULL,'hr_documents','pending','2026-04-16',NULL,NULL,1,'2026-04-13 10:56:12','2026-04-13 10:56:12'),(63,107,'Submit bank account details for payroll',NULL,'hr_documents','pending','2026-04-16',NULL,NULL,2,'2026-04-13 10:56:12','2026-04-13 10:56:12'),(64,107,'Sign employment contract',NULL,'hr_documents','pending','2026-04-14',NULL,NULL,3,'2026-04-13 10:56:12','2026-04-13 10:56:12'),(65,107,'Complete HR policy acknowledgement form',NULL,'hr_documents','pending','2026-04-20',NULL,NULL,4,'2026-04-13 10:56:12','2026-04-13 10:56:12'),(66,107,'IT: Set up workstation & email',NULL,'it_setup','pending','2026-04-14',NULL,NULL,5,'2026-04-13 10:56:12','2026-04-13 10:56:12'),(67,107,'IT: Configure VPN & system access',NULL,'it_setup','pending','2026-04-15',NULL,NULL,6,'2026-04-13 10:56:12','2026-04-13 10:56:12'),(68,107,'Complete system orientation / training',NULL,'training','pending','2026-04-27',NULL,NULL,7,'2026-04-13 10:56:12','2026-04-13 10:56:12'),(69,107,'Meet department manager & team introduction',NULL,'introduction','pending','2026-04-14',NULL,NULL,8,'2026-04-13 10:56:12','2026-04-13 10:56:12'),(70,107,'Complete probation review at 90 days',NULL,'probation','pending','2026-07-12',NULL,NULL,9,'2026-04-13 10:56:12','2026-04-13 10:56:12');
/*!40000 ALTER TABLE `onboarding_tasks` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `password_reset_tokens`
--

DROP TABLE IF EXISTS `password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `password_reset_tokens`
--

LOCK TABLES `password_reset_tokens` WRITE;
/*!40000 ALTER TABLE `password_reset_tokens` DISABLE KEYS */;
/*!40000 ALTER TABLE `password_reset_tokens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payroll_components`
--

DROP TABLE IF EXISTS `payroll_components`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payroll_components` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `code` varchar(20) NOT NULL,
  `type` enum('earning','deduction') NOT NULL,
  `calculation` enum('fixed','percentage') NOT NULL,
  `value` decimal(10,2) NOT NULL DEFAULT 0.00,
  `is_taxable` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `description` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `payroll_components_code_unique` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payroll_components`
--

LOCK TABLES `payroll_components` WRITE;
/*!40000 ALTER TABLE `payroll_components` DISABLE KEYS */;
INSERT INTO `payroll_components` VALUES (1,'Performance Bonus','PB','earning','percentage',0.00,0,0,'Discretionary performance bonus (% of basic)','2026-04-12 07:14:15','2026-04-12 07:14:15'),(2,'Mobile Allowance','MOB','earning','fixed',0.00,0,0,'Monthly mobile allowance (SAR)','2026-04-12 07:14:15','2026-04-12 07:14:15'),(3,'Loan Deduction','LOAN','deduction','fixed',0.00,0,0,'Monthly loan repayment deduction (SAR)','2026-04-12 07:14:15','2026-04-12 07:14:15'),(4,'Penalty Deduction','PEN','deduction','fixed',0.00,0,0,'Disciplinary / penalty deduction (SAR)','2026-04-12 07:14:15','2026-04-12 07:14:15');
/*!40000 ALTER TABLE `payroll_components` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payroll_settings`
--

DROP TABLE IF EXISTS `payroll_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payroll_settings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(100) NOT NULL,
  `value` varchar(255) DEFAULT NULL,
  `type` varchar(30) NOT NULL DEFAULT 'string',
  `label` varchar(200) DEFAULT NULL,
  `group` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `payroll_settings_key_unique` (`key`),
  KEY `payroll_settings_group_index` (`group`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payroll_settings`
--

LOCK TABLES `payroll_settings` WRITE;
/*!40000 ALTER TABLE `payroll_settings` DISABLE KEYS */;
INSERT INTO `payroll_settings` VALUES (1,'daily_rate_basis','monthly','string','Daily Rate Calculation Basis','deductions','monthly = salary ÷ working days in period | fixed = salary ÷ 26 | annual = salary × 12 ÷ 260','2026-04-12 07:08:59','2026-04-12 07:08:59'),(2,'working_days_per_month','26','integer','Working Days Per Month (Fixed Basis)','deductions','Used when daily_rate_basis = \"fixed\". Saudi standard is 26.','2026-04-12 07:08:59','2026-04-12 07:08:59'),(3,'deduct_unpaid_leave','1','boolean','Deduct Unpaid Leave from Salary','leave','When ON, approved leaves of types marked \"Unpaid\" are deducted from basic salary at the daily rate.','2026-04-12 07:08:59','2026-04-12 07:08:59'),(4,'deduct_absences','1','boolean','Deduct Unrecorded Absences','leave','When ON, days marked Absent in attendance with no approved leave request are deducted from basic salary.','2026-04-12 07:08:59','2026-04-12 07:08:59'),(5,'deduct_allowances_on_leave','0','boolean','Deduct Allowances on Unpaid Leave','leave','When ON, housing and transport allowances are also pro-rated for unpaid leave days.','2026-04-12 07:08:59','2026-04-12 07:08:59'),(6,'gosi_apply_saudi_only','1','boolean','Apply GOSI to Saudi Nationals Only','gosi','When ON, GOSI deductions only apply to Saudi national employees.','2026-04-12 07:08:59','2026-04-12 07:08:59'),(7,'gosi_employee_rate','0.09','decimal','GOSI Employee Contribution Rate','gosi','Employee-side GOSI rate (default 9% = 0.09).','2026-04-12 07:08:59','2026-04-12 07:08:59'),(8,'gosi_employer_rate','0.1175','decimal','GOSI Employer Contribution Rate','gosi','Employer-side GOSI rate (default 11.75% = 0.1175).','2026-04-12 07:08:59','2026-04-12 07:08:59'),(9,'overtime_rate','1.5','decimal','Overtime Rate Multiplier','overtime','Daily rate multiplier for overtime (e.g. 1.5 = 150% of daily rate).','2026-04-12 07:08:59','2026-04-12 07:08:59');
/*!40000 ALTER TABLE `payroll_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payrolls`
--

DROP TABLE IF EXISTS `payrolls`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payrolls` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `cycle_name` varchar(100) NOT NULL,
  `month` varchar(7) NOT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `status` enum('draft','pending_approval','approved','rejected','paid') NOT NULL DEFAULT 'draft',
  `total_gross` decimal(14,2) NOT NULL DEFAULT 0.00,
  `total_deductions` decimal(14,2) NOT NULL DEFAULT 0.00,
  `total_net` decimal(14,2) NOT NULL DEFAULT 0.00,
  `created_by` bigint(20) unsigned NOT NULL,
  `approved_by` bigint(20) unsigned DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `payrolls_created_by_foreign` (`created_by`),
  KEY `payrolls_approved_by_foreign` (`approved_by`),
  CONSTRAINT `payrolls_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`),
  CONSTRAINT `payrolls_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payrolls`
--

LOCK TABLES `payrolls` WRITE;
/*!40000 ALTER TABLE `payrolls` DISABLE KEYS */;
/*!40000 ALTER TABLE `payrolls` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payslips`
--

DROP TABLE IF EXISTS `payslips`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payslips` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `payroll_id` bigint(20) unsigned NOT NULL,
  `employee_id` bigint(20) unsigned NOT NULL,
  `basic_salary` decimal(12,2) NOT NULL DEFAULT 0.00,
  `housing_allowance` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '25% of basic salary',
  `transport_allowance` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Fixed monthly transport allowance',
  `other_allowances` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Any other allowances / bonuses',
  `total_earnings` decimal(12,2) NOT NULL DEFAULT 0.00,
  `gosi_employee` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'GOSI employee share: 9% of basic (Saudi nationals only)',
  `gosi_employer` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'GOSI employer share: 11.75% of basic (cost, not deducted from employee)',
  `other_deductions` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Loans, penalties, etc.',
  `is_saudi` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Whether GOSI applies',
  `total_deductions` decimal(12,2) NOT NULL DEFAULT 0.00,
  `gross_salary` decimal(12,2) NOT NULL DEFAULT 0.00,
  `net_salary` decimal(12,2) NOT NULL DEFAULT 0.00,
  `working_days` int(11) NOT NULL DEFAULT 0,
  `absent_days` int(11) NOT NULL DEFAULT 0,
  `leave_days` int(11) NOT NULL DEFAULT 0,
  `pdf_path` varchar(255) DEFAULT NULL,
  `email_sent` tinyint(1) NOT NULL DEFAULT 0,
  `email_sent_at` timestamp NULL DEFAULT NULL,
  `components` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`components`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `payslips_payroll_id_foreign` (`payroll_id`),
  KEY `payslips_employee_id_foreign` (`employee_id`),
  CONSTRAINT `payslips_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `payslips_payroll_id_foreign` FOREIGN KEY (`payroll_id`) REFERENCES `payrolls` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payslips`
--

LOCK TABLES `payslips` WRITE;
/*!40000 ALTER TABLE `payslips` DISABLE KEYS */;
/*!40000 ALTER TABLE `payslips` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `performance_cycles`
--

DROP TABLE IF EXISTS `performance_cycles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `performance_cycles` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `type` enum('annual','semi_annual','quarterly') NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `self_assessment_deadline` date DEFAULT NULL,
  `manager_review_deadline` date DEFAULT NULL,
  `status` enum('draft','active','completed','archived') NOT NULL DEFAULT 'draft',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `performance_cycles`
--

LOCK TABLES `performance_cycles` WRITE;
/*!40000 ALTER TABLE `performance_cycles` DISABLE KEYS */;
/*!40000 ALTER TABLE `performance_cycles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `performance_feedback`
--

DROP TABLE IF EXISTS `performance_feedback`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `performance_feedback` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `subject_employee_id` bigint(20) unsigned NOT NULL,
  `reviewer_id` bigint(20) unsigned NOT NULL,
  `review_id` bigint(20) unsigned DEFAULT NULL,
  `relationship` enum('self','manager','peer','report','client') NOT NULL DEFAULT 'peer',
  `is_anonymous` tinyint(1) NOT NULL DEFAULT 0,
  `communication` tinyint(3) unsigned DEFAULT NULL,
  `teamwork` tinyint(3) unsigned DEFAULT NULL,
  `technical` tinyint(3) unsigned DEFAULT NULL,
  `leadership` tinyint(3) unsigned DEFAULT NULL,
  `initiative` tinyint(3) unsigned DEFAULT NULL,
  `strengths` text DEFAULT NULL,
  `improvements` text DEFAULT NULL,
  `overall_comment` text DEFAULT NULL,
  `submitted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `performance_feedback_subject_employee_id_foreign` (`subject_employee_id`),
  KEY `performance_feedback_reviewer_id_foreign` (`reviewer_id`),
  KEY `performance_feedback_review_id_foreign` (`review_id`),
  CONSTRAINT `performance_feedback_review_id_foreign` FOREIGN KEY (`review_id`) REFERENCES `performance_reviews` (`id`) ON DELETE SET NULL,
  CONSTRAINT `performance_feedback_reviewer_id_foreign` FOREIGN KEY (`reviewer_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `performance_feedback_subject_employee_id_foreign` FOREIGN KEY (`subject_employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `performance_feedback`
--

LOCK TABLES `performance_feedback` WRITE;
/*!40000 ALTER TABLE `performance_feedback` DISABLE KEYS */;
/*!40000 ALTER TABLE `performance_feedback` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `performance_goals`
--

DROP TABLE IF EXISTS `performance_goals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `performance_goals` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `employee_id` bigint(20) unsigned NOT NULL,
  `review_id` bigint(20) unsigned DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `category` enum('professional','learning','leadership','project','personal','okr') NOT NULL DEFAULT 'professional',
  `priority` enum('low','medium','high','critical') NOT NULL DEFAULT 'medium',
  `status` enum('not_started','in_progress','achieved','on_hold','cancelled') NOT NULL DEFAULT 'not_started',
  `target_value` decimal(10,2) DEFAULT NULL,
  `current_value` decimal(10,2) NOT NULL DEFAULT 0.00,
  `unit` varchar(255) DEFAULT NULL,
  `start_date` date DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `achieved_at` date DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `performance_goals_employee_id_foreign` (`employee_id`),
  KEY `performance_goals_review_id_foreign` (`review_id`),
  KEY `performance_goals_created_by_foreign` (`created_by`),
  CONSTRAINT `performance_goals_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `performance_goals_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `performance_goals_review_id_foreign` FOREIGN KEY (`review_id`) REFERENCES `performance_reviews` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `performance_goals`
--

LOCK TABLES `performance_goals` WRITE;
/*!40000 ALTER TABLE `performance_goals` DISABLE KEYS */;
/*!40000 ALTER TABLE `performance_goals` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `performance_kpis`
--

DROP TABLE IF EXISTS `performance_kpis`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `performance_kpis` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `employee_id` bigint(20) unsigned NOT NULL,
  `review_id` bigint(20) unsigned DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `target` decimal(12,2) DEFAULT NULL,
  `actual` decimal(12,2) DEFAULT NULL,
  `unit` varchar(255) DEFAULT NULL,
  `period` varchar(255) DEFAULT NULL,
  `frequency` enum('daily','weekly','monthly','quarterly','annual') NOT NULL DEFAULT 'monthly',
  `weight` decimal(5,2) NOT NULL DEFAULT 1.00,
  `status` enum('on_track','at_risk','missed','achieved') NOT NULL DEFAULT 'on_track',
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `performance_kpis_employee_id_foreign` (`employee_id`),
  KEY `performance_kpis_review_id_foreign` (`review_id`),
  KEY `performance_kpis_created_by_foreign` (`created_by`),
  CONSTRAINT `performance_kpis_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `performance_kpis_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `performance_kpis_review_id_foreign` FOREIGN KEY (`review_id`) REFERENCES `performance_reviews` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `performance_kpis`
--

LOCK TABLES `performance_kpis` WRITE;
/*!40000 ALTER TABLE `performance_kpis` DISABLE KEYS */;
/*!40000 ALTER TABLE `performance_kpis` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `performance_reviews`
--

DROP TABLE IF EXISTS `performance_reviews`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `performance_reviews` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `cycle_id` bigint(20) unsigned NOT NULL,
  `employee_id` bigint(20) unsigned NOT NULL,
  `reviewer_id` bigint(20) unsigned DEFAULT NULL,
  `status` enum('pending','self_submitted','manager_reviewed','hr_calibrated','finalized') NOT NULL DEFAULT 'pending',
  `self_rating` decimal(3,1) DEFAULT NULL,
  `self_comments` text DEFAULT NULL,
  `self_kpi_scores` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`self_kpi_scores`)),
  `manager_rating` decimal(3,1) DEFAULT NULL,
  `manager_comments` text DEFAULT NULL,
  `manager_kpi_scores` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`manager_kpi_scores`)),
  `final_rating` decimal(3,1) DEFAULT NULL,
  `performance_band` enum('excellent','good','average','below_average','poor') DEFAULT NULL,
  `development_plan` text DEFAULT NULL,
  `hr_notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `performance_reviews_cycle_id_employee_id_unique` (`cycle_id`,`employee_id`),
  KEY `performance_reviews_employee_id_foreign` (`employee_id`),
  KEY `performance_reviews_reviewer_id_foreign` (`reviewer_id`),
  CONSTRAINT `performance_reviews_cycle_id_foreign` FOREIGN KEY (`cycle_id`) REFERENCES `performance_cycles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `performance_reviews_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `performance_reviews_reviewer_id_foreign` FOREIGN KEY (`reviewer_id`) REFERENCES `employees` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `performance_reviews`
--

LOCK TABLES `performance_reviews` WRITE;
/*!40000 ALTER TABLE `performance_reviews` DISABLE KEYS */;
/*!40000 ALTER TABLE `performance_reviews` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `permissions`
--

DROP TABLE IF EXISTS `permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `permissions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `guard_name` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `permissions_name_guard_name_unique` (`name`,`guard_name`)
) ENGINE=InnoDB AUTO_INCREMENT=2589 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `permissions`
--

LOCK TABLES `permissions` WRITE;
/*!40000 ALTER TABLE `permissions` DISABLE KEYS */;
INSERT INTO `permissions` VALUES (2539,'dashboard.view','web','2026-04-12 07:14:14','2026-04-12 07:14:14'),(2540,'employees.view','web','2026-04-12 07:14:14','2026-04-12 07:14:14'),(2541,'employees.create','web','2026-04-12 07:14:14','2026-04-12 07:14:14'),(2542,'employees.edit','web','2026-04-12 07:14:14','2026-04-12 07:14:14'),(2543,'employees.delete','web','2026-04-12 07:14:15','2026-04-12 07:14:15'),(2544,'employees.view_salary','web','2026-04-12 07:14:15','2026-04-12 07:14:15'),(2545,'employees.view_documents','web','2026-04-12 07:14:15','2026-04-12 07:14:15'),(2546,'payroll.view','web','2026-04-12 07:14:15','2026-04-12 07:14:15'),(2547,'payroll.run','web','2026-04-12 07:14:15','2026-04-12 07:14:15'),(2548,'payroll.approve','web','2026-04-12 07:14:15','2026-04-12 07:14:15'),(2549,'payroll.export','web','2026-04-12 07:14:15','2026-04-12 07:14:15'),(2550,'payroll.view_own','web','2026-04-12 07:14:15','2026-04-12 07:14:15'),(2551,'leave.view_all','web','2026-04-12 07:14:15','2026-04-12 07:14:15'),(2552,'leave.approve','web','2026-04-12 07:14:15','2026-04-12 07:14:15'),(2553,'leave.manage_types','web','2026-04-12 07:14:15','2026-04-12 07:14:15'),(2554,'leave.manage_holidays','web','2026-04-12 07:14:15','2026-04-12 07:14:15'),(2555,'leave.view_own','web','2026-04-12 07:14:15','2026-04-12 07:14:15'),(2556,'leave.request','web','2026-04-12 07:14:15','2026-04-12 07:14:15'),(2557,'loans.view_all','web','2026-04-12 07:14:15','2026-04-12 07:14:15'),(2558,'loans.approve_manager','web','2026-04-12 07:14:15','2026-04-12 07:14:15'),(2559,'loans.approve_hr','web','2026-04-12 07:14:15','2026-04-12 07:14:15'),(2560,'loans.approve_finance','web','2026-04-12 07:14:15','2026-04-12 07:14:15'),(2561,'loans.disburse','web','2026-04-12 07:14:15','2026-04-12 07:14:15'),(2562,'loans.manage_types','web','2026-04-12 07:14:15','2026-04-12 07:14:15'),(2563,'loans.view_own','web','2026-04-12 07:14:15','2026-04-12 07:14:15'),(2564,'loans.request','web','2026-04-12 07:14:15','2026-04-12 07:14:15'),(2565,'separations.view_all','web','2026-04-12 07:14:15','2026-04-12 07:14:15'),(2566,'separations.create','web','2026-04-12 07:14:15','2026-04-12 07:14:15'),(2567,'separations.approve_manager','web','2026-04-12 07:14:15','2026-04-12 07:14:15'),(2568,'separations.approve_hr','web','2026-04-12 07:14:15','2026-04-12 07:14:15'),(2569,'separations.manage_offboarding','web','2026-04-12 07:14:15','2026-04-12 07:14:15'),(2570,'requests.view_all','web','2026-04-12 07:14:15','2026-04-12 07:14:15'),(2571,'requests.process','web','2026-04-12 07:14:15','2026-04-12 07:14:15'),(2572,'requests.approve_manager','web','2026-04-12 07:14:15','2026-04-12 07:14:15'),(2573,'requests.manage_types','web','2026-04-12 07:14:15','2026-04-12 07:14:15'),(2574,'requests.view_own','web','2026-04-12 07:14:15','2026-04-12 07:14:15'),(2575,'requests.submit','web','2026-04-12 07:14:15','2026-04-12 07:14:15'),(2576,'recruitment.view','web','2026-04-12 07:14:15','2026-04-12 07:14:15'),(2577,'recruitment.manage','web','2026-04-12 07:14:15','2026-04-12 07:14:15'),(2578,'performance.view','web','2026-04-12 07:14:15','2026-04-12 07:14:15'),(2579,'performance.manage','web','2026-04-12 07:14:15','2026-04-12 07:14:15'),(2580,'attendance.view_all','web','2026-04-12 07:14:15','2026-04-12 07:14:15'),(2581,'attendance.view_own','web','2026-04-12 07:14:15','2026-04-12 07:14:15'),(2582,'attendance.checkin','web','2026-04-12 07:14:15','2026-04-12 07:14:15'),(2583,'attendance.manual_entry','web','2026-04-12 07:14:15','2026-04-12 07:14:15'),(2584,'attendance.manage','web','2026-04-12 07:14:15','2026-04-12 07:14:15'),(2585,'orgchart.view','web','2026-04-12 07:14:15','2026-04-12 07:14:15'),(2586,'admin.manage_users','web','2026-04-12 07:14:15','2026-04-12 07:14:15'),(2587,'admin.manage_roles','web','2026-04-12 07:14:15','2026-04-12 07:14:15'),(2588,'admin.view_logs','web','2026-04-12 07:14:15','2026-04-12 07:14:15');
/*!40000 ALTER TABLE `permissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `personal_access_tokens`
--

DROP TABLE IF EXISTS `personal_access_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `personal_access_tokens` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tokenable_type` varchar(255) NOT NULL,
  `tokenable_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `token` varchar(64) NOT NULL,
  `abilities` text DEFAULT NULL,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `personal_access_tokens`
--

LOCK TABLES `personal_access_tokens` WRITE;
/*!40000 ALTER TABLE `personal_access_tokens` DISABLE KEYS */;
INSERT INTO `personal_access_tokens` VALUES (2,'App\\Models\\User',284,'hrms-token','9d3ebde25fda92abe44c2ce98c7f5b1a623e9579d97b0d63841b8cbd351318b1','[\"*\"]',NULL,NULL,'2026-04-12 07:17:31','2026-04-12 07:17:31'),(3,'App\\Models\\User',284,'hrms-token','aca1c836b560a899dcacf11207959da8368c78a6c1601e1e54a12db236eb45a1','[\"*\"]',NULL,NULL,'2026-04-12 07:31:07','2026-04-12 07:31:07'),(4,'App\\Models\\User',284,'hrms-token','f7f5baebaf5be76c09b0d7791c3e002445aab8504c66881807408104231b21a0','[\"*\"]',NULL,NULL,'2026-04-12 08:36:29','2026-04-12 08:36:29'),(6,'App\\Models\\User',284,'hrms-token','66f904be689d7d5b80630eee93ca549cdb40112a385b4449f525013b69f70337','[\"*\"]',NULL,NULL,'2026-04-13 03:51:22','2026-04-13 03:51:22'),(7,'App\\Models\\User',284,'hrms-token','5b258362094abe1e71b1cb34ed195b76f7e69c04a14906580dae6ed9c040f4d1','[\"*\"]',NULL,NULL,'2026-04-13 04:53:20','2026-04-13 04:53:20'),(8,'App\\Models\\User',284,'hrms-token','37b5158460bcb10ba396e660831d72816945222b876db5ca06767cf7d02044c5','[\"*\"]',NULL,NULL,'2026-06-18 10:00:54','2026-06-18 10:00:54'),(9,'App\\Models\\User',284,'hrms-token','01f657960ffcdd7388cd81091f24799ae8c2466557b328a7a3f4bd8fc5432527','[\"*\"]','2026-06-18 10:06:17',NULL,'2026-06-18 10:01:16','2026-06-18 10:06:17');
/*!40000 ALTER TABLE `personal_access_tokens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `policies`
--

DROP TABLE IF EXISTS `policies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `policies` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `category_id` bigint(20) unsigned DEFAULT NULL,
  `audience_type` varchar(20) NOT NULL DEFAULT 'all',
  `target_department_ids` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`target_department_ids`)),
  `title` varchar(200) NOT NULL,
  `content` longtext DEFAULT NULL,
  `version` varchar(20) NOT NULL DEFAULT '1.0',
  `effective_date` date DEFAULT NULL,
  `review_date` date DEFAULT NULL,
  `requires_acknowledgement` tinyint(1) NOT NULL DEFAULT 1,
  `mandatory` tinyint(1) NOT NULL DEFAULT 0,
  `is_published` tinyint(1) NOT NULL DEFAULT 1,
  `status` varchar(20) NOT NULL DEFAULT 'approved',
  `attachment_path` varchar(255) DEFAULT NULL,
  `attachment_name` varchar(255) DEFAULT NULL,
  `attachment_mime` varchar(255) DEFAULT NULL,
  `attachment_size` bigint(20) unsigned DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `approved_by` bigint(20) unsigned DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `policies_category_id_foreign` (`category_id`),
  KEY `policies_created_by_foreign` (`created_by`),
  KEY `policies_is_published_effective_date_index` (`is_published`,`effective_date`),
  KEY `policies_approved_by_foreign` (`approved_by`),
  CONSTRAINT `policies_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `policies_category_id_foreign` FOREIGN KEY (`category_id`) REFERENCES `policy_categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `policies_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `policies`
--

LOCK TABLES `policies` WRITE;
/*!40000 ALTER TABLE `policies` DISABLE KEYS */;
/*!40000 ALTER TABLE `policies` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `policy_acknowledgements`
--

DROP TABLE IF EXISTS `policy_acknowledgements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `policy_acknowledgements` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `policy_id` bigint(20) unsigned NOT NULL,
  `employee_id` bigint(20) unsigned NOT NULL,
  `policy_version` varchar(20) NOT NULL DEFAULT '1.0',
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `acknowledged_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `policy_acknowledgements_policy_id_employee_id_unique` (`policy_id`,`employee_id`),
  UNIQUE KEY `policy_ack_unique_per_version` (`policy_id`,`employee_id`,`policy_version`),
  KEY `policy_acknowledgements_employee_id_foreign` (`employee_id`),
  CONSTRAINT `policy_acknowledgements_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `policy_acknowledgements_policy_id_foreign` FOREIGN KEY (`policy_id`) REFERENCES `policies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `policy_acknowledgements`
--

LOCK TABLES `policy_acknowledgements` WRITE;
/*!40000 ALTER TABLE `policy_acknowledgements` DISABLE KEYS */;
/*!40000 ALTER TABLE `policy_acknowledgements` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `policy_categories`
--

DROP TABLE IF EXISTS `policy_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `policy_categories` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `slug` varchar(120) NOT NULL,
  `icon` varchar(50) DEFAULT NULL,
  `sort_order` int(10) unsigned NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `policy_categories_slug_unique` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `policy_categories`
--

LOCK TABLES `policy_categories` WRITE;
/*!40000 ALTER TABLE `policy_categories` DISABLE KEYS */;
/*!40000 ALTER TABLE `policy_categories` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `request_comments`
--

DROP TABLE IF EXISTS `request_comments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `request_comments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `request_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `comment` text NOT NULL,
  `is_internal` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `request_comments_request_id_foreign` (`request_id`),
  KEY `request_comments_user_id_foreign` (`user_id`),
  CONSTRAINT `request_comments_request_id_foreign` FOREIGN KEY (`request_id`) REFERENCES `employee_requests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `request_comments_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `request_comments`
--

LOCK TABLES `request_comments` WRITE;
/*!40000 ALTER TABLE `request_comments` DISABLE KEYS */;
INSERT INTO `request_comments` VALUES (1,1,284,'Request completed. ffffffff',0,'2026-04-12 08:11:42','2026-04-12 08:11:42'),(2,1,284,'Request completed. ffffffff',0,'2026-04-12 08:11:47','2026-04-12 08:11:47'),(3,2,284,'Request assigned to System Admin and is now in progress.',1,'2026-04-12 09:05:16','2026-04-12 09:05:16'),(4,3,284,'Request assigned to self and is now in progress.',1,'2026-04-13 09:57:01','2026-04-13 09:57:01');
/*!40000 ALTER TABLE `request_comments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `request_types`
--

DROP TABLE IF EXISTS `request_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `request_types` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `code` varchar(30) NOT NULL,
  `category` varchar(60) NOT NULL DEFAULT 'general',
  `description` text DEFAULT NULL,
  `instructions` text DEFAULT NULL,
  `sla_days` int(11) NOT NULL DEFAULT 3,
  `requires_attachment` tinyint(1) NOT NULL DEFAULT 0,
  `requires_manager_approval` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `icon` varchar(50) NOT NULL DEFAULT 'description',
  `color` varchar(20) NOT NULL DEFAULT '#6366f1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `request_types_code_unique` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=24 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `request_types`
--

LOCK TABLES `request_types` WRITE;
/*!40000 ALTER TABLE `request_types` DISABLE KEYS */;
INSERT INTO `request_types` VALUES (1,'Exit Re-entry Visa (Single)','VISA_EXIT_S','visa',NULL,'Please provide your passport copy, ID copy, and intended travel dates.',5,0,0,1,1,'flight_takeoff','#3b82f6','2026-04-12 07:14:15','2026-04-12 07:14:15'),(2,'Exit Re-entry Visa (Multiple)','VISA_EXIT_M','visa',NULL,'Provide passport copy, ID copy, duration needed, and travel purpose.',7,0,0,1,2,'flight_takeoff','#3b82f6','2026-04-12 07:14:15','2026-04-12 07:14:15'),(3,'Visit Visa for Family','VISA_FAMILY','visa',NULL,'Provide family member details (name, passport, relationship) and visit duration.',7,0,0,1,3,'family_restroom','#6366f1','2026-04-12 07:14:15','2026-04-12 07:14:15'),(4,'Business Visa Support Letter','VISA_BIZ','visa',NULL,'Specify destination country, business purpose, and travel dates.',3,0,0,1,4,'business_center','#0ea5e9','2026-04-12 07:14:15','2026-04-12 07:14:15'),(5,'Air Ticket Request','TRAVEL_TICKET','travel',NULL,'Provide travel dates, destination, preferred airline if any, and reason for travel.',3,0,0,1,10,'airplane_ticket','#f59e0b','2026-04-12 07:14:15','2026-04-12 07:14:15'),(6,'Air Ticket Allowance Letter','TRAVEL_LETTER','travel',NULL,'Specify destination and travel dates for the allowance letter.',2,0,0,1,11,'mail','#f59e0b','2026-04-12 07:14:15','2026-04-12 07:14:15'),(7,'Salary Certificate','DOC_SALARY','documents',NULL,'Specify if required for bank, embassy, or other purpose. Mention language (Arabic/English).',2,0,0,1,20,'payments','#10b981','2026-04-12 07:14:15','2026-04-12 07:14:15'),(8,'Employment Certificate','DOC_EMPLOY','documents',NULL,'Mention the purpose (bank, embassy, other) and required language.',2,0,0,1,21,'badge','#10b981','2026-04-12 07:14:15','2026-04-12 07:14:15'),(9,'Experience Letter','DOC_EXP','documents',NULL,'Provide the addressee details if directed to a specific party.',3,0,0,1,22,'workspace_premium','#10b981','2026-04-12 07:14:15','2026-04-12 07:14:15'),(10,'NOC Letter','DOC_NOC','documents',NULL,'State the purpose of the NOC and to whom it is addressed.',3,0,1,1,23,'verified','#10b981','2026-04-12 07:14:15','2026-04-12 07:14:15'),(11,'Bank Letter','DOC_BANK','documents',NULL,'Mention your bank name, account details, and purpose of the letter.',2,0,0,1,24,'account_balance','#10b981','2026-04-12 07:14:15','2026-04-12 07:14:15'),(12,'Salary Transfer Letter','DOC_SALARY_TR','documents',NULL,'Provide new bank name and account number for the transfer.',2,0,0,1,25,'swap_horiz','#10b981','2026-04-12 07:14:15','2026-04-12 07:14:15'),(13,'GOSI Certificate','DOC_GOSI','documents',NULL,'Specify required for personal use or third party.',3,0,0,1,26,'health_and_safety','#10b981','2026-04-12 07:14:15','2026-04-12 07:14:15'),(14,'Advance Salary Request','HR_ADVANCE','hr',NULL,'State the advance amount needed and reason.',5,0,1,1,30,'monetization_on','#8b5cf6','2026-04-12 07:14:15','2026-04-12 07:14:15'),(15,'Change of Information','HR_INFO','hr',NULL,'Describe the information that needs to be updated and attach supporting documents.',3,0,0,1,31,'manage_accounts','#8b5cf6','2026-04-12 07:14:15','2026-04-12 07:14:15'),(16,'Work From Home Request','HR_WFH','hr',NULL,'Specify dates and reason for WFH request.',2,0,1,1,32,'home_work','#8b5cf6','2026-04-12 07:14:15','2026-04-12 07:14:15'),(17,'Training Request','HR_TRAIN','hr',NULL,'Provide training name, provider, dates, and how it benefits your role.',5,0,1,1,33,'school','#8b5cf6','2026-04-12 07:14:15','2026-04-12 07:14:15'),(18,'IT Equipment Request','IT_EQUIP','it',NULL,'Specify equipment type, model if preferred, and business justification.',5,0,1,1,40,'computer','#ef4444','2026-04-12 07:14:15','2026-04-12 07:14:15'),(19,'Software Access Request','IT_ACCESS','it',NULL,'Specify the system/software name and the access level required.',3,0,1,1,41,'lock_open','#ef4444','2026-04-12 07:14:15','2026-04-12 07:14:15'),(20,'Email / Account Setup','IT_EMAIL','it',NULL,'Provide details of the account or email needed.',2,0,0,1,42,'email','#ef4444','2026-04-12 07:14:15','2026-04-12 07:14:15'),(21,'Parking Pass Request','ADMIN_PARK','admin',NULL,'Provide vehicle plate number, make, and model.',3,0,0,1,50,'local_parking','#ec4899','2026-04-12 07:14:15','2026-04-12 07:14:15'),(22,'Business Card Request','ADMIN_CARD','admin',NULL,'Confirm your name, designation, phone, and email to appear on the card.',5,0,0,1,51,'contact_page','#ec4899','2026-04-12 07:14:15','2026-04-12 07:14:15'),(23,'Office Supply Request','ADMIN_SUPPLY','admin',NULL,'List the items needed with quantities.',2,0,0,1,52,'inventory_2','#ec4899','2026-04-12 07:14:15','2026-04-12 07:14:15');
/*!40000 ALTER TABLE `request_types` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `role_has_permissions`
--

DROP TABLE IF EXISTS `role_has_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `role_has_permissions` (
  `permission_id` bigint(20) unsigned NOT NULL,
  `role_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`permission_id`,`role_id`),
  KEY `role_has_permissions_role_id_foreign` (`role_id`),
  CONSTRAINT `role_has_permissions_permission_id_foreign` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `role_has_permissions_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `role_has_permissions`
--

LOCK TABLES `role_has_permissions` WRITE;
/*!40000 ALTER TABLE `role_has_permissions` DISABLE KEYS */;
INSERT INTO `role_has_permissions` VALUES (2539,351),(2539,352),(2539,353),(2539,354),(2539,355),(2539,356),(2540,351),(2540,352),(2540,353),(2540,354),(2540,355),(2541,351),(2541,352),(2541,353),(2542,351),(2542,352),(2542,353),(2543,351),(2543,352),(2544,351),(2544,352),(2544,354),(2545,351),(2545,352),(2545,353),(2546,351),(2546,352),(2546,353),(2546,354),(2547,351),(2547,352),(2548,351),(2548,352),(2548,354),(2549,351),(2549,352),(2549,354),(2550,351),(2550,356),(2551,351),(2551,352),(2551,353),(2551,355),(2552,351),(2552,352),(2552,353),(2552,355),(2553,351),(2553,352),(2554,351),(2554,352),(2555,351),(2555,354),(2555,356),(2556,351),(2556,354),(2556,356),(2557,351),(2557,352),(2557,353),(2557,354),(2557,355),(2558,351),(2558,355),(2559,351),(2559,352),(2560,351),(2560,354),(2561,351),(2561,354),(2562,351),(2562,352),(2563,351),(2563,356),(2564,351),(2564,356),(2565,351),(2565,352),(2565,353),(2565,355),(2566,351),(2566,352),(2566,353),(2567,351),(2567,355),(2568,351),(2568,352),(2568,354),(2569,351),(2569,352),(2570,351),(2570,352),(2570,353),(2570,355),(2571,351),(2571,352),(2571,353),(2572,351),(2572,352),(2572,355),(2573,351),(2573,352),(2574,351),(2574,354),(2574,356),(2575,351),(2575,354),(2575,356),(2576,351),(2576,352),(2576,353),(2577,351),(2577,352),(2578,351),(2578,352),(2578,353),(2578,355),(2579,351),(2579,352),(2579,355),(2580,351),(2580,352),(2580,353),(2580,355),(2581,351),(2581,354),(2581,356),(2582,351),(2582,352),(2582,353),(2582,354),(2582,355),(2582,356),(2583,351),(2583,352),(2583,353),(2584,351),(2584,352),(2585,351),(2585,352),(2585,353),(2585,354),(2585,355),(2585,356),(2586,351),(2586,352),(2587,351),(2587,352),(2588,351);
/*!40000 ALTER TABLE `role_has_permissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `roles`
--

DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `roles` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `guard_name` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `roles_name_guard_name_unique` (`name`,`guard_name`)
) ENGINE=InnoDB AUTO_INCREMENT=357 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `roles`
--

LOCK TABLES `roles` WRITE;
/*!40000 ALTER TABLE `roles` DISABLE KEYS */;
INSERT INTO `roles` VALUES (351,'super_admin','web','2026-04-12 07:14:14','2026-04-12 07:14:14'),(352,'hr_manager','web','2026-04-12 07:14:14','2026-04-12 07:14:14'),(353,'hr_staff','web','2026-04-12 07:14:14','2026-04-12 07:14:14'),(354,'finance_manager','web','2026-04-12 07:14:14','2026-04-12 07:14:14'),(355,'department_manager','web','2026-04-12 07:14:14','2026-04-12 07:14:14'),(356,'employee','web','2026-04-12 07:14:14','2026-04-12 07:14:14');
/*!40000 ALTER TABLE `roles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `separations`
--

DROP TABLE IF EXISTS `separations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `separations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `reference` varchar(30) NOT NULL,
  `employee_id` bigint(20) unsigned NOT NULL,
  `type` enum('resignation','termination','end_of_contract','retirement','abandonment','mutual_agreement') NOT NULL,
  `status` enum('draft','submitted','pending_manager','pending_hr','approved','offboarding','completed','cancelled','rejected') NOT NULL DEFAULT 'draft',
  `request_date` date NOT NULL,
  `last_working_day` date NOT NULL,
  `notice_period_start` date DEFAULT NULL,
  `notice_period_days` int(11) NOT NULL DEFAULT 30,
  `notice_waived` tinyint(1) NOT NULL DEFAULT 0,
  `notice_waived_reason` text DEFAULT NULL,
  `reason` text NOT NULL,
  `reason_category` enum('personal','better_opportunity','relocation','health','misconduct','performance','restructuring','contract_end','other') DEFAULT NULL,
  `hr_notes` text DEFAULT NULL,
  `initiated_by` bigint(20) unsigned NOT NULL,
  `manager_approved_by` bigint(20) unsigned DEFAULT NULL,
  `manager_approved_at` timestamp NULL DEFAULT NULL,
  `hr_approved_by` bigint(20) unsigned DEFAULT NULL,
  `hr_approved_at` timestamp NULL DEFAULT NULL,
  `rejected_by` bigint(20) unsigned DEFAULT NULL,
  `rejected_at` timestamp NULL DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `exit_interview_required` tinyint(1) NOT NULL DEFAULT 1,
  `exit_interview_done` tinyint(1) NOT NULL DEFAULT 0,
  `exit_interview_date` date DEFAULT NULL,
  `exit_interview_notes` text DEFAULT NULL,
  `gratuity_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `leave_encashment` decimal(12,2) NOT NULL DEFAULT 0.00,
  `other_deductions` decimal(12,2) NOT NULL DEFAULT 0.00,
  `other_additions` decimal(12,2) NOT NULL DEFAULT 0.00,
  `final_settlement_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `settlement_paid` tinyint(1) NOT NULL DEFAULT 0,
  `settlement_paid_date` date DEFAULT NULL,
  `settlement_notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `separations_reference_unique` (`reference`),
  KEY `separations_employee_id_foreign` (`employee_id`),
  KEY `separations_initiated_by_foreign` (`initiated_by`),
  KEY `separations_manager_approved_by_foreign` (`manager_approved_by`),
  KEY `separations_hr_approved_by_foreign` (`hr_approved_by`),
  KEY `separations_rejected_by_foreign` (`rejected_by`),
  CONSTRAINT `separations_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `separations_hr_approved_by_foreign` FOREIGN KEY (`hr_approved_by`) REFERENCES `users` (`id`),
  CONSTRAINT `separations_initiated_by_foreign` FOREIGN KEY (`initiated_by`) REFERENCES `users` (`id`),
  CONSTRAINT `separations_manager_approved_by_foreign` FOREIGN KEY (`manager_approved_by`) REFERENCES `users` (`id`),
  CONSTRAINT `separations_rejected_by_foreign` FOREIGN KEY (`rejected_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `separations`
--

LOCK TABLES `separations` WRITE;
/*!40000 ALTER TABLE `separations` DISABLE KEYS */;
/*!40000 ALTER TABLE `separations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `system_settings`
--

DROP TABLE IF EXISTS `system_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `system_settings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(100) NOT NULL,
  `value` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `system_settings_key_unique` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `system_settings`
--

LOCK TABLES `system_settings` WRITE;
/*!40000 ALTER TABLE `system_settings` DISABLE KEYS */;
/*!40000 ALTER TABLE `system_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `remember_token` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=286 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (284,'System Admin','admin@hrms.com',NULL,'$2y$12$/YHlWGwJZsLlHg9nE.XCxuDopE/dLhjQdDMNxJEzlsEMeqvwOKfpm',NULL,'2026-04-12 07:14:15','2026-04-12 07:16:55'),(285,'jithin varkey','jithinvarkey@gmail.com',NULL,'$2y$12$p/vibqakNxqE1vqjmSwP1udNajmOckk6E0wycKlSUrIJsJJUtUttu',NULL,'2026-04-13 10:56:11','2026-04-13 10:56:11');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping routines for database 'hrsystem'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-06-18 13:06:21
