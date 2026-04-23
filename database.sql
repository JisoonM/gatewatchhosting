-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: 127.0.0.1    Database: pcu_rfid2
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
-- Current Database: `pcu_rfid2`
--

CREATE DATABASE /*!32312 IF NOT EXISTS*/ `pcu_rfid2` /*!40100 DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci */;

USE `pcu_rfid2`;

--
-- Table structure for table `audit_log`
--

DROP TABLE IF EXISTS `audit_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `audit_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(100) NOT NULL COMMENT 'register_card, unregister_card, clear_violation, etc.',
  `table_name` varchar(50) NOT NULL,
  `record_id` int(11) DEFAULT NULL,
  `old_values` text DEFAULT NULL COMMENT 'JSON of old values',
  `new_values` text DEFAULT NULL COMMENT 'JSON of new values',
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_action` (`action`),
  KEY `idx_table_name` (`table_name`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `audit_log_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `audit_log`
--

LOCK TABLES `audit_log` WRITE;
/*!40000 ALTER TABLE `audit_log` DISABLE KEYS */;
/*!40000 ALTER TABLE `audit_log` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `audit_logs`
--

DROP TABLE IF EXISTS `audit_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `audit_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `admin_id` int(11) NOT NULL,
  `admin_name` varchar(255) NOT NULL,
  `action_type` varchar(100) NOT NULL,
  `target_type` varchar(50) NOT NULL,
  `target_id` int(11) DEFAULT NULL,
  `target_name` varchar(255) DEFAULT NULL,
  `description` text NOT NULL,
  `details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`details`)),
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_admin_id` (`admin_id`),
  KEY `idx_action_type` (`action_type`),
  KEY `idx_target_type` (`target_type`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_al_admin_created` (`admin_id`,`created_at`),
  CONSTRAINT `fk_audit_logs_admin` FOREIGN KEY (`admin_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=42 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `audit_logs`
--

LOCK TABLES `audit_logs` WRITE;
/*!40000 ALTER TABLE `audit_logs` DISABLE KEYS */;
INSERT INTO `audit_logs` VALUES (1,3,'System Administrator','APPROVE_STUDENT','student',4,'Jason Ramos','Approved student account for Jason Ramos (ID: TEMP-1770390474)','{\"student_id\":\"TEMP-1770390474\",\"email\":\"mrk.ramos118@gmail.com\",\"previous_status\":\"Pending\",\"new_status\":\"Active\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36','2026-02-06 15:14:55'),(2,3,'System Administrator','APPROVE_STUDENT','student',5,'Joshua Morales','Approved student account for Joshua Morales (ID: TEMP-1770538459)','{\"student_id\":\"TEMP-1770538459\",\"email\":\"morales.josh133@gmail.com\",\"previous_status\":\"Pending\",\"new_status\":\"Active\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36','2026-02-08 08:15:28'),(3,3,'System Administrator','MARK_LOST','rfid_card',1,'halimaw mag pa baby','Marked RFID card 0014973874 as lost for halimaw mag pa baby','{\"rfid_uid\":\"0014973874\",\"card_id\":1,\"student_id\":2,\"email_sent\":true}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36','2026-02-21 05:42:11'),(4,3,'System Administrator','MARK_FOUND','rfid_card',1,'halimaw mag pa baby','Re-enabled RFID card 0014973874 for halimaw mag pa baby ()','{\"rfid_uid\":\"0014973874\",\"card_id\":1,\"student_id\":\"\",\"email_sent\":true}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36','2026-02-21 05:51:59'),(5,3,'System Administrator','UPDATE_STUDENT','student',2,'Mark Jason Briones Ramos','Updated student info for Mark Jason Briones Ramos (202232903)','{\"changes\":{\"name\":{\"from\":\"halimaw mag pa baby\",\"to\":\"Mark Jason Briones Ramos\"}},\"student_id\":\"202232903\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36','2026-02-21 05:53:50'),(6,3,'System Administrator','UPDATE_STUDENT','student',2,'Mark Jason Briones Ramoss','Updated student info for Mark Jason Briones Ramoss (202232903)','{\"changes\":{\"name\":{\"from\":\"Mark Jason Briones Ramos\",\"to\":\"Mark Jason Briones Ramoss\"}},\"student_id\":\"202232903\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36','2026-03-17 13:21:08'),(7,3,'System Administrator','ADD_VIOLATION','student',2,'Mark Jason Briones Ramoss','Added minor violation: No Physical ID (Strike #3) for Mark Jason Briones Ramoss','{\"violation_id\":3,\"category_id\":1,\"category_name\":\"No Physical ID\",\"category_type\":\"minor\",\"offense_number\":3,\"school_year\":\"2025-2026\",\"semester\":\"1st\",\"description\":\"\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36','2026-03-19 06:00:54'),(8,3,'System Administrator','ASSIGN_REPARATION','student',2,'Mark Jason Briones Ramoss','Assigned reparation task for minor violation: No Physical ID (Strike #3) — Task: Written Apology','{\"violation_id\":3,\"category_name\":\"No Physical ID\",\"category_type\":\"minor\",\"offense_number\":3,\"reparation_type\":\"written_apology\",\"reparation_notes\":\"\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36','2026-03-19 06:01:24'),(9,3,'System Administrator','RESOLVE_ALL_VIOLATIONS','student',2,'Mark Jason Briones Ramoss','Resolved all active violations for Mark Jason Briones Ramoss','{\"previous_violation_count\":3,\"previous_active_count\":3,\"violations_resolved\":3,\"rfid_violations_resolved\":3,\"reparation_type\":\"batch_resolution\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36','2026-03-19 06:02:30'),(10,3,'System Administrator','ASSIGN_REPARATION','student',2,'Mark Jason Briones Ramoss','Assigned reparation task for minor violation: No Physical ID (Strike #6) — Task: Written Apology','{\"violation_id\":6,\"category_name\":\"No Physical ID\",\"category_type\":\"minor\",\"offense_number\":6,\"reparation_type\":\"written_apology\",\"reparation_notes\":\"\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36','2026-03-19 06:09:28'),(11,3,'System Administrator','RESOLVE_VIOLATION','student',2,'Mark Jason Briones Ramoss','Resolved minor violation: No Physical ID (Strike #6) for Mark Jason Briones Ramoss','{\"violation_id\":6,\"category_name\":\"No Physical ID\",\"category_type\":\"minor\",\"offense_number\":6,\"reparation_type\":\"written_apology\",\"reparation_notes\":\"\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36','2026-03-19 06:10:46'),(12,3,'System Administrator','RESOLVE_ALL_VIOLATIONS','student',2,'Mark Jason Briones Ramoss','Resolved all active violations for Mark Jason Briones Ramoss','{\"previous_violation_count\":5,\"previous_active_count\":2,\"violations_resolved\":2,\"rfid_violations_resolved\":2,\"reparation_type\":\"batch_resolution\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36','2026-03-19 06:11:00'),(13,3,'System Administrator','EXPORT_AUDIT_LOG','audit_log',NULL,'Audit Log Export','Exported audit log to Excel (12 records)','{\"filename\":\"AuditLog_20260319_081306.xlsx\",\"records_exported\":12,\"filters_applied\":\"No filters applied \\u2014 showing all records\",\"exported_at\":\"March 19, 2026 8:13 AM\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36','2026-03-19 07:13:06'),(14,3,'System Administrator','EXPORT_AUDIT_LOG','audit_log',NULL,'Audit Log Export','Exported audit log to Excel (13 records)','{\"filename\":\"AuditLog_20260319_081644.xlsx\",\"records_exported\":13,\"filters_applied\":\"No filters applied \\u2014 showing all records\",\"exported_at\":\"March 19, 2026 8:16 AM\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36','2026-03-19 07:16:44'),(15,3,'System Administrator','EXPORT_AUDIT_LOG','audit_log',NULL,'Audit Log Export','Exported audit log to Excel (12 records)','{\"filename\":\"AuditLog_20260319_082317.xlsx\",\"records_exported\":12,\"filters_applied\":\"No filters applied \\u2014 showing all records\",\"exported_at\":\"March 19, 2026 8:23 AM\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36','2026-03-19 07:23:17'),(16,3,'System Administrator','ASSIGN_REPARATION','student',2,'Mark Jason Briones Ramoss','Assigned reparation task for minor violation: No Physical ID (Strike #7) — Task: Written Apology','{\"violation_id\":7,\"category_name\":\"No Physical ID\",\"category_type\":\"minor\",\"offense_number\":7,\"reparation_type\":\"written_apology\",\"reparation_notes\":\"\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36','2026-03-19 07:30:05'),(17,3,'System Administrator','RESOLVE_VIOLATION','student',2,'Mark Jason Briones Ramoss','Resolved minor violation: No Physical ID (Strike #7) for Mark Jason Briones Ramoss','{\"violation_id\":7,\"category_name\":\"No Physical ID\",\"category_type\":\"minor\",\"offense_number\":7,\"reparation_type\":\"written_apology\",\"reparation_notes\":\"\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36','2026-03-19 07:30:26'),(18,3,'System Administrator','RESOLVE_VIOLATION','student',2,'Mark Jason Briones Ramoss','Resolved minor violation: No Physical ID (Strike #8) for Mark Jason Briones Ramoss','{\"violation_id\":8,\"category_name\":\"No Physical ID\",\"category_type\":\"minor\",\"offense_number\":8,\"reparation_type\":\"written_apology\",\"reparation_notes\":\"\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36','2026-03-19 07:53:20'),(19,3,'System Administrator','RESOLVE_VIOLATION','student',2,'Mark Jason Briones Ramoss','Resolved minor violation: No Physical ID (Strike #9) for Mark Jason Briones Ramoss','{\"violation_id\":9,\"category_name\":\"No Physical ID\",\"category_type\":\"minor\",\"offense_number\":9,\"reparation_type\":\"written_apology\",\"reparation_notes\":\"\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36','2026-03-19 07:53:31'),(20,3,'System Administrator','RESOLVE_ALL_VIOLATIONS','student',2,'Mark Jason Briones Ramoss','Resolved all active violations for Mark Jason Briones Ramoss','{\"previous_violation_count\":12,\"previous_active_count\":3,\"violations_resolved\":3,\"rfid_violations_resolved\":3,\"reparation_type\":\"batch_resolution\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36','2026-03-19 07:54:20'),(21,3,'System Administrator','ASSIGN_REPARATION','student',2,'Mark Jason Briones Ramoss','Assigned reparation task for minor violation: No Physical ID (Strike #13) — Task: Written Apology','{\"violation_id\":13,\"category_name\":\"No Physical ID\",\"category_type\":\"minor\",\"offense_number\":13,\"reparation_type\":\"written_apology\",\"reparation_notes\":\"\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36','2026-03-19 08:10:06'),(22,3,'System Administrator','RESOLVE_VIOLATION','student',2,'Mark Jason Briones Ramoss','Resolved minor violation: No Physical ID (Strike #13) for Mark Jason Briones Ramoss','{\"violation_id\":13,\"category_name\":\"No Physical ID\",\"category_type\":\"minor\",\"offense_number\":13,\"reparation_type\":\"written_apology\",\"reparation_notes\":\"\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36','2026-03-19 08:11:21'),(23,3,'System Administrator','ASSIGN_REPARATION','student',2,'Mark Jason Briones Ramoss','Assigned reparation task for minor violation: No Physical ID (Strike #14) — Task: Written Apology','{\"violation_id\":14,\"category_name\":\"No Physical ID\",\"category_type\":\"minor\",\"offense_number\":14,\"reparation_type\":\"written_apology\",\"reparation_notes\":\"\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36','2026-03-19 08:46:13'),(24,3,'System Administrator','RESOLVE_VIOLATION','student',2,'Mark Jason Briones Ramoss','Resolved minor violation: No Physical ID (Strike #14) for Mark Jason Briones Ramoss','{\"violation_id\":14,\"category_name\":\"No Physical ID\",\"category_type\":\"minor\",\"offense_number\":14,\"reparation_type\":\"written_apology\",\"reparation_notes\":\"\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36','2026-03-19 08:48:26'),(25,3,'System Administrator','ASSIGN_REPARATION','student',2,'Mark Jason Briones Ramoss','Assigned reparation task for minor violation: No Physical ID (Strike #15) — Task: Written Apology','{\"violation_id\":15,\"category_name\":\"No Physical ID\",\"category_type\":\"minor\",\"offense_number\":15,\"reparation_type\":\"written_apology\",\"reparation_notes\":\"\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36','2026-03-19 09:06:27'),(26,3,'System Administrator','RESOLVE_VIOLATION','student',2,'Mark Jason Briones Ramoss','Resolved minor violation: No Physical ID (Strike #15) for Mark Jason Briones Ramoss','{\"violation_id\":15,\"category_name\":\"No Physical ID\",\"category_type\":\"minor\",\"offense_number\":15,\"reparation_type\":\"written_apology\",\"reparation_notes\":\"\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36','2026-03-19 09:10:48'),(27,3,'System Administrator','RESOLVE_VIOLATION','student',2,'Mark Jason Briones Ramoss','Resolved minor violation: No Physical ID (Strike #16) for Mark Jason Briones Ramoss','{\"violation_id\":16,\"category_name\":\"No Physical ID\",\"category_type\":\"minor\",\"offense_number\":16,\"reparation_type\":\"written_apology\",\"reparation_notes\":\"\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36','2026-03-19 11:58:03'),(28,3,'System Administrator','RESOLVE_VIOLATION','student',2,'Mark Jason Briones Ramoss','Resolved minor violation: No Physical ID (Strike #17) for Mark Jason Briones Ramoss','{\"violation_id\":17,\"category_name\":\"No Physical ID\",\"category_type\":\"minor\",\"offense_number\":17,\"reparation_type\":\"written_apology\",\"reparation_notes\":\"\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36','2026-03-19 11:58:13'),(29,3,'System Administrator','RESOLVE_VIOLATION','student',2,'Mark Jason Briones Ramoss','Resolved minor violation: No Physical ID (Strike #18) for Mark Jason Briones Ramoss','{\"violation_id\":18,\"category_name\":\"No Physical ID\",\"category_type\":\"minor\",\"offense_number\":18,\"reparation_type\":\"written_apology\",\"reparation_notes\":\"\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36','2026-03-19 12:02:01'),(30,3,'System Administrator','RESOLVE_ALL_VIOLATIONS','student',2,'Mark Jason Briones Ramoss','Resolved all active violations for Mark Jason Briones Ramoss','{\"previous_violation_count\":46,\"previous_active_count\":3,\"violations_resolved\":3,\"rfid_violations_resolved\":3,\"reparation_type\":\"batch_resolution\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36','2026-03-19 15:26:40'),(31,3,'System Administrator','ASSIGN_REPARATION','student',2,'Mark Jason Briones Ramoss','Assigned reparation task for minor violation: No Physical ID (Strike #22) — Task: Written Apology','{\"violation_id\":22,\"category_name\":\"No Physical ID\",\"category_type\":\"minor\",\"offense_number\":22,\"reparation_type\":\"written_apology\",\"reparation_notes\":\"\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36','2026-03-20 09:50:02'),(32,3,'System Administrator','RESOLVE_ALL_VIOLATIONS','student',2,'Mark Jason Briones Ramoss','Resolved all active violations for Mark Jason Briones Ramoss','{\"previous_violation_count\":53,\"previous_active_count\":2,\"violations_resolved\":2,\"rfid_violations_resolved\":2,\"reparation_type\":\"batch_resolution\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36','2026-03-23 01:09:04'),(33,3,'System Administrator','RESOLVE_VIOLATION','student',2,'Mark Jason Briones Ramoss','Resolved minor violation: No Physical ID (Strike #24) for Mark Jason Briones Ramoss','{\"violation_id\":24,\"category_name\":\"No Physical ID\",\"category_type\":\"minor\",\"offense_number\":24,\"reparation_type\":\"written_apology\",\"reparation_notes\":\"\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36','2026-03-25 15:01:20'),(34,3,'System Administrator','RESOLVE_ALL_VIOLATIONS','student',2,'Mark Jason Briones Ramoss','Resolved all active violations for Mark Jason Briones Ramoss','{\"previous_violation_count\":71,\"previous_active_count\":3,\"violations_resolved\":2,\"rfid_violations_resolved\":0,\"reparation_type\":\"batch_resolution\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36','2026-04-06 01:50:14'),(35,3,'System Administrator','RESOLVE_ALL_VIOLATIONS','student',2,'Mark Jason Briones Ramoss','Resolved all active violations for Mark Jason Briones Ramoss','{\"previous_violation_count\":74,\"previous_active_count\":3,\"violations_resolved\":2,\"remaining_active_after_resolve\":0,\"reparation_type\":\"batch_resolution\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36','2026-04-06 02:28:04'),(36,3,'System Administrator','RESOLVE_ALL_VIOLATIONS','student',2,'Mark Jason Briones Ramoss','Resolved all active violations for Mark Jason Briones Ramoss','{\"previous_violation_count\":78,\"previous_active_count\":3,\"violations_resolved\":2,\"remaining_active_after_resolve\":0,\"reparation_type\":\"batch_resolution\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36','2026-04-06 02:29:01'),(37,3,'System Administrator','RESOLVE_VIOLATION','student',2,'Mark Jason Briones Ramoss','Resolved minor violation: Violation of School ID policy (Strike #3) for Mark Jason Briones Ramoss','{\"violation_id\":31,\"category_name\":\"Violation of School ID policy\",\"category_type\":\"minor\",\"offense_number\":3,\"reparation_type\":\"written_apology\",\"reparation_notes\":\"\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36','2026-04-06 02:29:50'),(38,3,'System Administrator','RESOLVE_VIOLATION','student',2,'Mark Jason Briones Ramoss','Resolved minor violation: Violation of School ID policy (Strike #3) for Mark Jason Briones Ramoss','{\"violation_id\":32,\"category_name\":\"Violation of School ID policy\",\"category_type\":\"minor\",\"offense_number\":3,\"reparation_type\":\"written_apology\",\"reparation_notes\":\"\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36','2026-04-06 02:30:03'),(39,3,'System Administrator','RESOLVE_VIOLATION','student',2,'Mark Jason Briones Ramoss','Resolved minor violation: Violation of School ID policy (Strike #1) for Mark Jason Briones Ramoss','{\"violation_id\":35,\"category_name\":\"Violation of School ID policy\",\"category_type\":\"minor\",\"offense_number\":1,\"reparation_type\":\"written_apology\",\"reparation_notes\":\"\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36','2026-04-06 06:09:07'),(40,3,'System Administrator','RESOLVE_VIOLATION','student',2,'Mark Jason Briones Ramoss','Resolved minor violation: Violation of School ID policy (Strike #1) for Mark Jason Briones Ramoss','{\"violation_id\":38,\"category_name\":\"Violation of School ID policy\",\"category_type\":\"minor\",\"offense_number\":1,\"reparation_type\":\"written_apology\",\"reparation_notes\":\"\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36','2026-04-06 06:09:15'),(41,3,'System Administrator','EXPORT_AUDIT_LOG','audit_log',NULL,'Audit Log Export','Exported audit log to Excel (37 records)','{\"filename\":\"AuditLog_20260406_144431.xlsx\",\"records_exported\":37,\"filters_applied\":\"No filters applied \\u2014 showing all records\",\"exported_at\":\"April 6, 2026 2:44 PM\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36','2026-04-06 12:44:31');
/*!40000 ALTER TABLE `audit_logs` ENABLE KEYS */;
UNLOCK TABLES;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER trg_audit_logs_block_update
BEFORE UPDATE ON audit_logs
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is immutable: updates are not allowed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER trg_audit_logs_block_delete
BEFORE DELETE ON audit_logs
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is immutable: deletes are not allowed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

--
-- Table structure for table `auth_audit_log`
--

DROP TABLE IF EXISTS `auth_audit_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `auth_audit_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `provider_id` int(11) NOT NULL,
  `email` varchar(255) NOT NULL,
  `action` enum('login_success','login_failed','logout','signup','link_account') NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `provider_id` (`provider_id`),
  KEY `idx_user_action` (`user_id`,`action`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_ip_address` (`ip_address`),
  KEY `idx_email` (`email`),
  CONSTRAINT `auth_audit_log_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `auth_audit_log_ibfk_2` FOREIGN KEY (`provider_id`) REFERENCES `auth_providers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `auth_audit_log`
--

LOCK TABLES `auth_audit_log` WRITE;
/*!40000 ALTER TABLE `auth_audit_log` DISABLE KEYS */;
/*!40000 ALTER TABLE `auth_audit_log` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `auth_providers`
--

DROP TABLE IF EXISTS `auth_providers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `auth_providers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `provider_name` varchar(50) NOT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `is_primary` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `provider_name` (`provider_name`),
  KEY `idx_enabled` (`is_enabled`),
  KEY `idx_primary` (`is_primary`),
  CONSTRAINT `chk_auth_providers_flags` CHECK (`is_enabled` in (0,1) and `is_primary` in (0,1))
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `auth_providers`
--

LOCK TABLES `auth_providers` WRITE;
/*!40000 ALTER TABLE `auth_providers` DISABLE KEYS */;
INSERT INTO `auth_providers` VALUES (1,'google',1,1,'2026-02-18 02:57:05','2026-02-18 02:57:05'),(2,'manual',0,0,'2026-02-18 02:57:05','2026-02-18 02:57:05');
/*!40000 ALTER TABLE `auth_providers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `face_descriptor_version_counter`
--

DROP TABLE IF EXISTS `face_descriptor_version_counter`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `face_descriptor_version_counter` (
  `id` int(11) NOT NULL DEFAULT 1,
  `current_version` bigint(20) unsigned NOT NULL DEFAULT 0,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  CONSTRAINT `single_row` CHECK (`id` = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `face_descriptor_version_counter`
--

LOCK TABLES `face_descriptor_version_counter` WRITE;
/*!40000 ALTER TABLE `face_descriptor_version_counter` DISABLE KEYS */;
INSERT INTO `face_descriptor_version_counter` VALUES (1,10,'2026-03-19 11:56:30');
/*!40000 ALTER TABLE `face_descriptor_version_counter` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `face_descriptors`
--

DROP TABLE IF EXISTS `face_descriptors`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `face_descriptors` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `descriptor_data` text NOT NULL,
  `descriptor_iv` varchar(48) NOT NULL,
  `descriptor_tag` varchar(48) NOT NULL,
  `label` varchar(100) DEFAULT NULL,
  `quality_score` float DEFAULT NULL,
  `registered_by` int(11) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `version` bigint(20) unsigned NOT NULL DEFAULT 0 COMMENT 'Monotonic version for incremental sync',
  `descriptor_dimension` smallint(5) unsigned NOT NULL DEFAULT 128 COMMENT 'Dimensionality of the face embedding vector',
  `quality_checks` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Detailed quality metrics: sharpness, brightness, centering, face_size, angle' CHECK (json_valid(`quality_checks`)),
  `enrollment_wave` int(10) unsigned NOT NULL DEFAULT 1 COMMENT 'Re-enrollment campaign number',
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_registered_by` (`registered_by`),
  KEY `idx_is_active` (`is_active`),
  KEY `idx_version` (`version`),
  CONSTRAINT `face_descriptors_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `face_descriptors_ibfk_2` FOREIGN KEY (`registered_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chk_face_descriptors_quality` CHECK (`quality_score` is null or `quality_score` >= 0 and `quality_score` <= 1),
  CONSTRAINT `chk_face_descriptors_active` CHECK (`is_active` in (0,1))
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `face_descriptors`
--

LOCK TABLES `face_descriptors` WRITE;
/*!40000 ALTER TABLE `face_descriptors` DISABLE KEYS */;
INSERT INTO `face_descriptors` VALUES (1,2,'vaNxtXnFL7rtpMTQ0WMwqSE+IfQO8dWV1pwjEqdvDk4dqNwGKscRyc42yDmBmxiFh7hF3uaB2w98msXXzDz4Q9ZQNpZvO+/5YZfp5Y5HM3Xx/XqIneGJ3ueElu2m/LsVwYkDOZzpWaumCvuHf2jRRJvUZ8wFSvnEdy8uVF2//HW36MwHvMXywLYHzj1SYrDeyjJnUDZWJP0lzGGmw8Ee2fjShVnPH/OrsMkqv1HHBGmpXaDCnPee6iqh0WPUH2U7NJU2G/J+XSNNUptzsHtqaf5pXmJa4WhKVTgMIiG+rj8xvV2/evBp1NAbLFiMDxb1WJ84jtjp27BWw63qYSmbIWBAYc/rOG8/VxrvzBi5TRnZwIYC4NuK24zaSa9tdq8gsQl4+NF7V2jARvw8E2GK5aFynmi8TUK7qdK0T5iAfQ6qj5e1sEC55Laygc41BWdBnIQGftCBX2pn9DcnQSoxmnvFQciV4d615LyuOOclDKrqGR1k3p6E8+RuMJ5yNMGd8LR/R/n8vr2mWmyHVts/RGjkaTcl6bGOy+xEAgd7ClVGDz8+iaeROA9ZhtjpBzbxJ2rbPy7Jkhez2aKzG0AO09iTfeZScOIkGj7QfpoR1WxNGPR6c29e2UOG6yBtwa+lNYgODAHLKDmTaYCtdcJT9IM2XuARLLScMd/Cb4NpluFAyrh+bH+FkOVQjkzXyLTy1xBpXUXqeN3drGMude0Ubve/IWg78aHHJzl/EjkAtbIYFhzSEoPRn8OWXIOOyPuNEsPYpw/RZf1HCz8wg+4o5ue+Y4teaV9xMaR33BToYzWlo326/SrNEh9YzZkITFTraoC38V8Ks/pFTU7Q+D4eKqgeOXCLT0jtQ6f2pPMevHb5ZqymF4Ogi+aAvay4W6kSwNJ3FcCuSNDvpaSYB8Uw+rrYizQ/454qjjm1gyyk2WOIWrNKb73kGkXAgOypHOdrGO5Mg5BuQKF4w5XoibXz6rIuI5mJbdZ8GEye+UsXGiS3ZOeAwX5Is7cmFQyfwoVQ1785zB/ACGj5Rpd2DNSvIwRf0T+T5W9yqFs3tInFchPzOZOY/nOWfv4lkE+7TumpTSiIJls7uKxYIdmFpD2Qm1I4ccDUC0tBKCe2BIchmfxN6vUaoD0XNulPpa1xOfJtWPhjMKWVQ5Wu8YF4wMJAjLn7NIWkkzUXZzwKibm1+xTSK3IgKYhRdWnccnLGQYae5MujSGdW66qj0YLkLNQjHMruPxYNL0AeT0j3z3W3vjtrJFI2Df8HqVir+UODHqbbUcXt5LgpE4N/9FDmr4+s9Yfg1WHuNtLqQ/7ey394J2A9qRwAQeMMwWxvSiVdyYYh388vm6ifaAYUcLD1W80726IGTaGn20xOYUnPks6YYC6a0V2IjbY3NqNTLH++1XibMGKsp7wO1wFCwD4N0UhjEOPi7QIaoDO+D2kUdXNWEhfrEyBOe5BZPgaTXEgkUdLnyNFUjwQENdsiNAf5rCwXnSFl93Vl+yjYNzy4/YQTp1/mC71dD1uXMnsqmYwXDK1/SZYW1GVOPo+2fs50h2tl3coMtnMAaaLCRsY6mIp2SrtOCLJDOQApoleLa+qku/YwmwagaZYOUQ073ilqkq7M1600R6mVo1Df/prlQTBm7nrkjkkTPgUkzvnUZVTlEq2M9EXekfUaQlSDp0pb25uuH9onNW+lsOg2jzphrVmdabhVhzf2jyD3nruNJlTvavUQEqyjahQI7cHYWRD4OYXrK5/xJhL77hPV7S2vSycG6t8E+0YtbjQZDPYBAYqxpQjA7+q3NEKJ/PPiIMvDm/KXw6t/Qc4e0+UNgTE1bUFqWZzmuoXUMp/Q6gpQ9If8/RlMiEGMt7LPMTg8H3sLIPtO7/LO0m56RPZ5qYvyyZm3gj2HuL2rAYzlHmFOKzAk4XF3R3o9bIoB5HXvabifiPvus1uyJGNLmBjw7YjUf8DDva+t8z4B6RdCbCDi6sSmsEzdrIChF5Pyi9TTm1OVOYYS5aGRDTVpB4Zl0YKPRtJ9SKzHVaI2zhHR2bzxo9LnHhEi+0s5OPkRBc5/y0gqszbAoxqtftmiSO/BfCeulHCwb+yZGpGGHBrmJoF7nl22B7A7J6n1bvxHQEo+dz2q/rstIaxseRhpDs9EspXJKTT7CJOxU+OM98OcGQb0/AlhjLvBp6uTC607UoH+B/22Yfh5Zs/8ynH4G6r6El0ThVmJ0d/HpGzCLTF3RwxhV7ncgc0qG7Rlx4GkrhjWO5+GbxfOu7p5gDz1e3KkzOcyFCf5e0mOgkxWelPbJk6d/ECUrP8/JEC+fvDzWGCmPDIesmAqtcK0EDeUixtEA1VciQUgR6yyUY5w/JEDREl7hgnXKOgQMP850iDB2jF2MxVCJcTV66RmCIYlnRfc0obkWIc55YJ9z9pTktmIZtr4SCec26QLONaD3RTjtlgcH0GCV+B4GbbVoGkcSeQvgw1BIQ0F1AwS1vmi4lE+at7Xx2cS0UnMaa92fubEZrjRQ1rlee1eGlrZw7Tg/ajDKQ4BpMqEWWWuinulhhZPEJiKGNjD+CLIRTalR3o1o4S7CwNQ6VCqbUvcv/PMfpZXH5nA2YjFsA+TXIfxaNV+MgWmaVlJ28S+iULuTuLWHqc3yc3pXVmkh2BeShDl3gZv2GSW4lXOJXNdzh3GvUxNVGZFF4mKqBej1q0l9eFv0tmHdVs1D2/zAiccunhMQI7/1wnqk/hl5A2IrT/LNlBUUPfsXuc2HszU2lz+XjpAgA4hjP8Q4y5vwXH2nIxBaXpi3YEQtgQr+MQ4A585dQtyhJ5uspFeMvsMIO/iyI7e3JZDSeQxZB0Yz7fgBbSbTe+YteqkYJDYax0k+h+0Tiw3kgmqd+PlEZpTs66lSRwHnH12X0sB0UJpR/KIFFTl7GOdPBEbr8OrS5b4ix0wXHSo4OnWQzhUn5D9Pwq6FvtdtHujGET9mpy4pO3Gn9FBA+JizRqgPUKQ5oRHtuE/c4tYDbq/Zt3sF6fuY2HFXCG3Jp6PNLlRXawbMoQC5IA2h1FliB/9dC66Bpep7wsZGoBneRagSWsqT+oavOY7ClzJ7E0wLKTIoruRD16Gp/JE2sGsB0vLtVRWYnfh3gN1/6SmDwMb0DqQJgJEN2sKI3zBsc8H67LAdrXfpuFZsnmG3qs65DyLhjd/Zc1RnC8KPzeXxSAThN3s3OMOorTeuAge1DSeAwM+aMHEB9ORjKdXTYhz9Tr5007BAbpA/bxiqSaNB3XSw6Gq5r3CvDBSMI4YDP+hwr0nzgP+5yqqr7uXUfV1Ml+r9EVOFNcjAIFaqf5IXd9bLgDx/uGSznHveQTg9PFEoUDxpWDtQNPJsqNUoH3p7hj31Y4aS/V2zdTqYXWAW/H6C2ZTXmh7MlkVvUbYDK8rAs/hVL3xKOuWtpYhkvjA1+ByksTcl/5Fzz0FMnwHw1+bK4A5viNM03HAOtg+koIp48b6/F4CfSA1SoPWqkYU6KMYyemG2hRhPKSzIjU=','10ernbZfb79+TYsz','K0Y580sziSGTIAxKyzfeaw==','front',0.999718,3,0,'2026-02-07 00:21:29','2026-03-19 11:51:11',6,128,NULL,1),(2,2,'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','NJQIflh321IYaA7x','MKzYq1oY1spB8Zd1Wgn9rw==','left',0.972595,3,0,'2026-02-07 00:21:39','2026-03-19 11:51:11',6,128,NULL,1),(3,2,'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','5ITmS0tV1oC0TnQC','VcFlVTvuCYBFgrtEqnmMcA==','right',0.979081,3,0,'2026-02-07 00:21:46','2026-03-19 11:51:11',6,128,NULL,1),(4,2,'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','nwxf4PLMQsbWA3S6','QvTOt3FxIDfdXd2s++KF1A==','down',0.999163,3,0,'2026-02-07 00:21:56','2026-03-19 11:51:11',6,128,NULL,1),(5,5,'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','G7kGWDC6ruQKHqF3','D5Jhe81sajLBdesSWVKXAg==','front',0.997056,3,0,'2026-02-08 08:17:25','2026-03-19 11:51:15',7,128,NULL,1),(6,2,'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','zu6XvWWbbaW5TE0M','6dzFF1SaMYHJFCngaUkzgQ==','front',0.999529,3,1,'2026-03-19 11:56:19','2026-03-19 11:56:19',8,128,NULL,1),(7,2,'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','0GJTlfRMRGM5VBRL','jCE2vAmCKsK4fmYkAotJMg==','left',0.921606,3,1,'2026-03-19 11:56:26','2026-03-19 11:56:26',9,128,NULL,1),(8,2,'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','m9KRFoEKaJTj192f','evV/qjdgs6G+rxP6YZIwQw==','right',0.945133,3,1,'2026-03-19 11:56:30','2026-03-19 11:56:30',10,128,NULL,1);
/*!40000 ALTER TABLE `face_descriptors` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `face_entry_logs`
--

DROP TABLE IF EXISTS `face_entry_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `face_entry_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `confidence_score` float NOT NULL,
  `match_threshold` float NOT NULL,
  `gate_location` varchar(100) DEFAULT NULL,
  `security_guard_id` int(11) DEFAULT NULL,
  `entry_type` enum('face_match','face_violation','face_denied') NOT NULL DEFAULT 'face_match',
  `snapshot_path` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `query_descriptor_hash` varchar(64) DEFAULT NULL COMMENT 'SHA-256 hash of the query descriptor used for matching',
  `server_verified` tinyint(1) DEFAULT NULL COMMENT 'Whether the match was verified server-side (NULL=legacy, 1=pass, 0=fail)',
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_security_guard_id` (`security_guard_id`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_entry_type` (`entry_type`),
  KEY `idx_fel_user_created` (`user_id`,`created_at`),
  CONSTRAINT `face_entry_logs_ibfk_2` FOREIGN KEY (`security_guard_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_face_entry_logs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `chk_face_entry_scores` CHECK (`confidence_score` >= 0 and `confidence_score` <= 1 and `match_threshold` >= 0 and `match_threshold` <= 1)
) ENGINE=InnoDB AUTO_INCREMENT=78 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `face_entry_logs`
--

LOCK TABLES `face_entry_logs` WRITE;
/*!40000 ALTER TABLE `face_entry_logs` DISABLE KEYS */;
INSERT INTO `face_entry_logs` VALUES (1,2,0.6926,0.6,NULL,NULL,'face_violation',NULL,'2026-02-07 00:23:34',NULL,NULL),(2,2,0.7855,0.6,NULL,NULL,'face_violation',NULL,'2026-02-07 00:23:39',NULL,NULL),(3,2,0.7137,0.6,NULL,NULL,'face_violation',NULL,'2026-02-07 00:29:05',NULL,NULL),(4,2,0.712,0.6,NULL,NULL,'face_denied',NULL,'2026-02-07 00:29:10',NULL,NULL),(5,2,0.7186,0.6,NULL,NULL,'face_denied',NULL,'2026-02-07 00:29:15',NULL,NULL),(6,2,0.7145,0.6,NULL,NULL,'face_violation',NULL,'2026-02-07 00:37:05',NULL,NULL),(7,2,0.446,0.6,NULL,NULL,'face_violation',NULL,'2026-02-07 00:37:24',NULL,NULL),(8,2,0.6657,0.6,NULL,NULL,'face_violation',NULL,'2026-02-07 01:07:25',NULL,NULL),(9,2,0.6714,0.6,NULL,NULL,'face_violation',NULL,'2026-02-07 01:07:41',NULL,NULL),(10,2,0.7506,0.6,NULL,NULL,'face_violation',NULL,'2026-02-07 13:29:09',NULL,NULL),(11,2,0.6248,0.6,NULL,NULL,'face_denied',NULL,'2026-02-07 13:29:33',NULL,NULL),(12,2,0.6905,0.6,NULL,NULL,'face_violation',NULL,'2026-02-07 13:29:56',NULL,NULL),(13,2,0.6286,0.6,NULL,NULL,'face_violation',NULL,'2026-02-08 08:10:52',NULL,NULL),(14,5,0.6576,0.6,NULL,NULL,'face_violation',NULL,'2026-02-08 08:19:34',NULL,NULL),(15,2,0.5947,0.6,NULL,NULL,'face_violation',NULL,'2026-02-20 15:31:31',NULL,NULL),(16,2,0.7213,0.6,NULL,NULL,'face_denied',NULL,'2026-02-20 15:31:41',NULL,NULL),(17,2,0.6825,0.6,NULL,NULL,'face_denied',NULL,'2026-02-20 15:31:47',NULL,NULL),(18,2,0.6787,0.6,NULL,NULL,'face_denied',NULL,'2026-02-20 15:31:52',NULL,NULL),(19,2,0.5234,0.6,NULL,NULL,'face_denied',NULL,'2026-02-20 15:31:58',NULL,NULL),(20,2,0.6639,0.6,NULL,NULL,'face_denied',NULL,'2026-02-20 15:39:16',NULL,NULL),(21,2,0.5949,0.6,NULL,NULL,'face_denied',NULL,'2026-02-20 15:39:21',NULL,NULL),(22,2,0.6555,0.6,NULL,NULL,'face_denied',NULL,'2026-02-20 15:39:26',NULL,NULL),(23,2,0.6826,0.6,NULL,NULL,'face_denied',NULL,'2026-02-20 15:51:28',NULL,NULL),(24,2,0.6743,0.6,NULL,NULL,'face_denied',NULL,'2026-02-20 15:51:54',NULL,NULL),(25,2,0.7155,0.6,NULL,NULL,'face_denied',NULL,'2026-02-20 15:52:13',NULL,NULL),(26,2,0.6808,0.6,NULL,NULL,'face_denied',NULL,'2026-02-20 15:52:18',NULL,NULL),(27,2,0.6581,0.6,NULL,NULL,'face_denied',NULL,'2026-02-20 15:52:24',NULL,NULL),(28,2,0.6985,0.6,NULL,NULL,'face_denied',NULL,'2026-02-20 15:53:39',NULL,NULL),(29,2,0.6941,0.6,NULL,NULL,'face_denied',NULL,'2026-02-20 15:53:50',NULL,NULL),(30,2,0.6232,0.6,NULL,NULL,'face_denied',NULL,'2026-02-20 15:57:12',NULL,NULL),(31,2,0.6243,0.6,NULL,NULL,'face_denied',NULL,'2026-02-20 15:57:20',NULL,NULL),(32,2,0.6312,0.6,NULL,NULL,'face_denied',NULL,'2026-02-20 15:57:31',NULL,NULL),(33,2,0.6677,0.6,NULL,NULL,'face_denied',NULL,'2026-02-20 15:57:39',NULL,NULL),(34,2,0.7414,0.6,NULL,NULL,'face_denied',NULL,'2026-02-20 16:00:37',NULL,NULL),(35,2,0.6797,0.6,NULL,NULL,'face_denied',NULL,'2026-02-20 16:00:44',NULL,NULL),(36,2,0.5987,0.6,NULL,NULL,'face_denied',NULL,'2026-02-20 16:00:49',NULL,NULL),(37,2,0.6284,0.6,NULL,NULL,'face_denied',NULL,'2026-02-20 16:00:54',NULL,NULL),(38,2,0.6344,0.6,NULL,NULL,'face_denied',NULL,'2026-02-20 16:01:04',NULL,NULL),(39,2,0.6286,0.6,NULL,NULL,'face_denied',NULL,'2026-02-20 16:01:12',NULL,NULL),(40,2,0.6428,0.6,NULL,NULL,'face_denied',NULL,'2026-02-20 16:09:14',NULL,NULL),(41,2,0.5503,0.6,NULL,NULL,'face_denied',NULL,'2026-02-20 16:09:25',NULL,NULL),(42,2,0.519,0.6,NULL,NULL,'face_violation',NULL,'2026-02-21 05:57:32',NULL,NULL),(43,2,0.6587,0.6,NULL,NULL,'face_denied',NULL,'2026-02-21 05:57:38',NULL,NULL),(44,2,0.6462,0.6,NULL,NULL,'face_denied',NULL,'2026-02-21 05:57:44',NULL,NULL),(45,2,1,0.6,NULL,NULL,'',NULL,'2026-03-19 11:57:07','70838b75b649a485936c6c4f0412ec471c905f83630566c6fc660d68b5774843',NULL),(46,2,1,0.6,NULL,NULL,'',NULL,'2026-03-19 11:57:12','949bb3d7adaef5d1e923edab0d6495f8fa4a078b3ab3779fb576a65c5ea7d6d8',NULL),(47,2,1,0.6,NULL,NULL,'face_violation',NULL,'2026-03-19 11:57:18','83c6809efd595bbfcd6950a196131260c1a8e870934469c7d0eaaf18cf7f23c6',NULL),(48,2,1,0.6,NULL,NULL,'',NULL,'2026-03-19 11:57:23','cc345414303c850a9e781f5ca4b3d03acda8662e7496a9083105426bb73008e7',NULL),(49,2,1,0.6,NULL,NULL,'',NULL,'2026-03-19 11:57:39','0ffcb07995ce83c0913c58f8f3f5fb58aed6ebb274f1e9cfad805d6edf657a24',NULL),(50,2,1,0.6,NULL,NULL,'face_violation',NULL,'2026-03-19 11:57:45','b51d4876315d826a19436616a82361cdc298b9543a2608e9b5605f99a2541974',NULL),(51,2,1,0.6,NULL,NULL,'',NULL,'2026-03-19 11:58:27','96ccad4ac208be4a949f4d8d9a79d37d359381e5cde1a95ed06ad393066184de',NULL),(52,2,1,0.6,NULL,NULL,'',NULL,'2026-03-19 11:58:33','102c3a0e76036bfc82156eabae96b602017c8cf35cb112ee14344d345f9a129c',NULL),(53,2,1,0.6,NULL,NULL,'face_violation',NULL,'2026-03-19 11:58:39','2cc7106aff5286ebc2fa2d00c1f68aa6ff06731edf11f03cfc65ac22bbc53471',NULL),(54,2,1,0.6,NULL,NULL,'',NULL,'2026-03-19 11:59:02','62e71246074e997ff15c44bd190febdc580e27847566b7181864ef860683f8be',NULL),(55,2,1,0.6,NULL,NULL,'',NULL,'2026-03-19 13:59:25','59cd2f8f5b1efdad5cc36c2da3c1be0726af22a5aff153fa043729370b89c649',NULL),(56,2,1,0.6,NULL,NULL,'',NULL,'2026-03-19 13:59:49','a92ab01cd1135e35bf11d2cdf2777815393fecdbda56dab4e6d375dbd02f1cad',NULL),(57,2,1,0.6,NULL,NULL,'face_violation',NULL,'2026-03-19 14:04:08','13dafdf030187606fa87274a61c2cc302742ad98a1313b38a68a8d32d7694bb5',NULL),(58,2,1,0.45,NULL,NULL,'',NULL,'2026-03-19 14:14:02','dd403515023ac790ed136aea1d0095d0b8fccbe0077a0facecd21791b2dd2490',NULL),(59,2,0.7001,0.45,NULL,NULL,'',NULL,'2026-03-19 14:24:19','1d41ee69b6b41832307fa4636f02a4b8cc142c8f477a04380296c7a2aa7d0bbe',NULL),(60,2,0.679,0.45,NULL,NULL,'face_violation',NULL,'2026-03-19 14:31:02','a6feeba452b241eb6f5f65416f8ad272c7fc9ca14fa9f527c3ce56c02a75efd8',NULL),(61,2,0.7382,0.45,NULL,NULL,'',NULL,'2026-03-19 14:31:08','536c4b36a823a1e8093c62d5b1357695199b70ec9dc6d2689b293bc734ca2221',NULL),(62,2,0.7627,0.45,NULL,NULL,'',NULL,'2026-03-19 14:31:20','1ccb4ef9130874c830e274f5f5f1c8b835135656a909e61cc29e8c6067297112',NULL),(63,2,0.7479,0.45,NULL,NULL,'face_violation',NULL,'2026-03-19 14:32:20','b5ee7bb9e9a93266e0a0ff1752ffea1f4d33fa35951045a66fcbe97f9bdcc944',NULL),(64,2,0.7647,0.45,NULL,NULL,'',NULL,'2026-03-19 14:32:29','caec25fbb910958ffa552f9e96516930f42fbb4638ab7a705cfc42d838cf8bbf',NULL),(65,2,0.705,0.45,NULL,NULL,'',NULL,'2026-03-19 14:32:48','4b85b63eb005c1625ff248bed5033f82a56ef96ccba1ade8cac6dd261094be0d',NULL),(66,2,0.6182,0.45,NULL,NULL,'',NULL,'2026-03-19 14:33:45','00b495fee995152d41384b8846193a6e97e2cc955bd4d0e3509066efbad36ffa',NULL),(67,2,0.6888,0.45,NULL,NULL,'',NULL,'2026-03-19 14:36:24','fdc5b83bcd6367d776665323cc9a04560bc7e76de0ce317f8165576ecde29fdf',NULL),(68,2,0.7736,0.45,NULL,NULL,'',NULL,'2026-03-19 14:36:29','2ee22f1f4fbd4239006720c802aee33aedde9d99fc1e8c14e8edf07610a1c194',NULL),(69,2,0.6386,0.45,NULL,NULL,'',NULL,'2026-03-19 14:37:10','29d4206855356b90e349e868ef4c4e61da55d20ced10241abef96a9e0c636f8c',NULL),(70,2,0.6585,0.45,NULL,NULL,'',NULL,'2026-03-19 14:37:23','c2aef7b05783c479f070395e64c424bf274e00b186cbdc18497fe5751077b735',NULL),(71,2,0.7447,0.45,NULL,NULL,'',NULL,'2026-03-20 09:49:12','14c713d65e31fbc8eb4369c5a12dcabbadacd36a19deb16c5a8d99ae0e1664da',NULL),(72,2,0.6403,0.45,NULL,NULL,'',NULL,'2026-03-20 09:49:21','7517862d1b3d9173d93f7509fd4172929d1827325e0725726341a9212250a5e4',NULL),(73,2,0.7023,0.45,NULL,NULL,'face_violation',NULL,'2026-03-20 09:49:26','a1d46beb985cfb3cc1c719a67ecba4d34f9f55e7ccfb1dd4649c3d4a61612d8c',NULL),(74,2,0.6638,0.45,NULL,NULL,'',NULL,'2026-03-23 05:00:47','5773ffdac4f4a67243ad632501508036743953c7dc89fe7710a9fed5ae9a7c60',NULL),(75,2,0.6661,0.45,NULL,NULL,'',NULL,'2026-03-23 05:16:25','939826531c67594a651b6fff24613486ca3788701b31b2da8b7bafc704d49422',NULL),(76,2,0.6725,0.45,NULL,NULL,'face_violation',NULL,'2026-03-23 05:25:15','065c35454e1055041e186f6e6e469331a08bfc3f7eccce7f8290eb4119d87207',NULL),(77,2,0.6798,0.45,NULL,NULL,'',NULL,'2026-03-23 05:25:52','7f82b106b0eafcac63480b981189b8b4bd454fe87b7308d32ea5a1baa13e866b',NULL);
/*!40000 ALTER TABLE `face_entry_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `face_registration_log`
--

DROP TABLE IF EXISTS `face_registration_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `face_registration_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `action` enum('registered','deactivated','reactivated','deleted') NOT NULL,
  `descriptor_count` int(11) DEFAULT 0,
  `performed_by` int(11) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_performed_by` (`performed_by`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `face_registration_log_ibfk_2` FOREIGN KEY (`performed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_face_reg_log_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `chk_face_registration_descriptor_count` CHECK (`descriptor_count` >= 0)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `face_registration_log`
--

LOCK TABLES `face_registration_log` WRITE;
/*!40000 ALTER TABLE `face_registration_log` DISABLE KEYS */;
INSERT INTO `face_registration_log` VALUES (1,2,'registered',1,3,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36','2026-02-07 00:21:29'),(2,2,'registered',1,3,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36','2026-02-07 00:21:39'),(3,2,'registered',1,3,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36','2026-02-07 00:21:46'),(4,2,'registered',1,3,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36','2026-02-07 00:21:56'),(5,5,'registered',1,3,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36','2026-02-08 08:17:25'),(6,2,'deactivated',4,3,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36','2026-03-19 11:51:11'),(7,5,'deactivated',1,3,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36','2026-03-19 11:51:15'),(8,2,'registered',1,3,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36','2026-03-19 11:56:19'),(9,2,'registered',1,3,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36','2026-03-19 11:56:27'),(10,2,'registered',1,3,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36','2026-03-19 11:56:30');
/*!40000 ALTER TABLE `face_registration_log` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `guardians`
--

DROP TABLE IF EXISTS `guardians`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `guardians` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `phone_number` varchar(20) DEFAULT NULL,
  `relationship` enum('Mother','Father','Guardian','Other') NOT NULL DEFAULT 'Guardian',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  KEY `idx_email` (`email`),
  KEY `idx_name` (`last_name`,`first_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `guardians`
--

LOCK TABLES `guardians` WRITE;
/*!40000 ALTER TABLE `guardians` DISABLE KEYS */;
/*!40000 ALTER TABLE `guardians` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ip_rate_limits`
--

DROP TABLE IF EXISTS `ip_rate_limits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ip_rate_limits` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `identifier` varchar(180) NOT NULL,
  `attempts` int(10) unsigned NOT NULL DEFAULT 1,
  `first_attempt` int(10) unsigned NOT NULL,
  `blocked_until` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_identifier` (`identifier`)
) ENGINE=InnoDB AUTO_INCREMENT=62 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ip_rate_limits`
--

LOCK TABLES `ip_rate_limits` WRITE;
/*!40000 ALTER TABLE `ip_rate_limits` DISABLE KEYS */;
INSERT INTO `ip_rate_limits` VALUES (3,'face_fetch|::1',1,1775455676,0),(16,'face_delete|::1',4,1773920839,0),(17,'face_register|::1',3,1773921379,0),(18,'face_verify|::1',2,1774243514,0),(19,'face_entry|::1',2,1774243515,0),(21,'face_sync|::1',2,1774243429,0),(25,'ai_chat_student|::1',2,1773999960,0),(33,'qr_challenge_issue|::1',6,1774243499,0),(35,'qr_scan_verify|::1',4,1774243504,0),(49,'security_record_violation|::1',3,1775455720,0),(53,'smoke_rate_alert|unknown',3,1775452411,1775452531),(57,'face_delete|127.0.0.1',1,1775453431,0);
/*!40000 ALTER TABLE `ip_rate_limits` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `notification_logs`
--

DROP TABLE IF EXISTS `notification_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `notification_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `guardian_id` int(11) NOT NULL,
  `notification_type` enum('entry','exit','violation','daily_summary') NOT NULL,
  `sent_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('sent','failed','queued') NOT NULL DEFAULT 'sent',
  `error_message` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_student_type_sent` (`student_id`,`notification_type`,`sent_at`),
  KEY `idx_guardian_sent` (`guardian_id`,`sent_at`),
  KEY `idx_sent_at` (`sent_at`),
  CONSTRAINT `fk_notification_logs_student` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`),
  CONSTRAINT `notification_logs_ibfk_2` FOREIGN KEY (`guardian_id`) REFERENCES `guardians` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `notification_logs`
--

LOCK TABLES `notification_logs` WRITE;
/*!40000 ALTER TABLE `notification_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `notification_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `notification_queue`
--

DROP TABLE IF EXISTS `notification_queue`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `notification_queue` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `guardian_id` int(11) NOT NULL,
  `notification_type` enum('entry','exit','violation','daily_summary') NOT NULL,
  `scheduled_for` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `sent_at` timestamp NULL DEFAULT NULL,
  `status` enum('pending','sent','failed') NOT NULL DEFAULT 'pending',
  `retry_count` int(11) NOT NULL DEFAULT 0,
  `data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`data`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_status_scheduled` (`status`,`scheduled_for`),
  KEY `idx_student` (`student_id`),
  KEY `idx_guardian` (`guardian_id`),
  KEY `idx_nq_status_scheduled` (`status`,`scheduled_for`,`retry_count`),
  CONSTRAINT `notification_queue_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `notification_queue_ibfk_2` FOREIGN KEY (`guardian_id`) REFERENCES `guardians` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chk_notification_queue_retry_non_negative` CHECK (`retry_count` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `notification_queue`
--

LOCK TABLES `notification_queue` WRITE;
/*!40000 ALTER TABLE `notification_queue` DISABLE KEYS */;
/*!40000 ALTER TABLE `notification_queue` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `notification_settings`
--

DROP TABLE IF EXISTS `notification_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `notification_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `guardian_id` int(11) NOT NULL,
  `entry_notification` tinyint(1) NOT NULL DEFAULT 1,
  `exit_notification` tinyint(1) NOT NULL DEFAULT 0,
  `violation_notification` tinyint(1) NOT NULL DEFAULT 1,
  `daily_summary` tinyint(1) NOT NULL DEFAULT 0,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `guardian_id` (`guardian_id`),
  KEY `idx_entry_enabled` (`entry_notification`),
  CONSTRAINT `notification_settings_ibfk_1` FOREIGN KEY (`guardian_id`) REFERENCES `guardians` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chk_notification_settings_flags` CHECK (`entry_notification` in (0,1) and `exit_notification` in (0,1) and `violation_notification` in (0,1) and `daily_summary` in (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `notification_settings`
--

LOCK TABLES `notification_settings` WRITE;
/*!40000 ALTER TABLE `notification_settings` DISABLE KEYS */;
/*!40000 ALTER TABLE `notification_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `password_resets`
--

DROP TABLE IF EXISTS `password_resets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `password_resets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `token` varchar(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_token` (`token`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_expires_at` (`expires_at`),
  KEY `idx_used` (`used`),
  CONSTRAINT `password_resets_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chk_password_resets_used` CHECK (`used` in (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `password_resets`
--

LOCK TABLES `password_resets` WRITE;
/*!40000 ALTER TABLE `password_resets` DISABLE KEYS */;
/*!40000 ALTER TABLE `password_resets` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `permission_audit_log`
--

DROP TABLE IF EXISTS `permission_audit_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `permission_audit_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `actor_role_key` varchar(64) NOT NULL,
  `actor_user_id` int(11) DEFAULT NULL,
  `permission_key` varchar(100) NOT NULL,
  `decision` enum('allow','deny') NOT NULL,
  `decision_source` varchar(50) NOT NULL COMMENT 'legacy|rbac|fallback|error|tier_not_enforced',
  `rbac_mode` varchar(20) NOT NULL COMMENT 'legacy|dual|enforce',
  `is_enforced` tinyint(1) NOT NULL DEFAULT 0,
  `request_method` varchar(10) DEFAULT NULL,
  `request_uri` varchar(255) DEFAULT NULL,
  `ip_address` varchar(64) DEFAULT NULL,
  `details_json` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_permission_audit_created` (`created_at`),
  KEY `idx_permission_audit_actor` (`actor_role_key`,`actor_user_id`,`created_at`),
  KEY `idx_permission_audit_permission` (`permission_key`,`created_at`),
  KEY `idx_permission_audit_decision` (`decision`,`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=315 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `permission_audit_log`
--

LOCK TABLES `permission_audit_log` WRITE;
/*!40000 ALTER TABLE `permission_audit_log` DISABLE KEYS */;
INSERT INTO `permission_audit_log` VALUES (1,'admin',3,'student.delete','allow','rbac','enforce',1,'POST','/rbac-smoke/admin/student.delete','unknown','{\"rbac_decision\":true,\"enforce_tier\":2,\"permission_tier\":1}','2026-04-06 05:08:08'),(2,'admin',3,'rfid.register','allow','rbac','enforce',1,'POST','/rbac-smoke/admin/rfid.register','unknown','{\"rbac_decision\":true,\"enforce_tier\":2,\"permission_tier\":1}','2026-04-06 05:08:08'),(3,'admin',3,'audit.read','allow','rbac','enforce',1,'POST','/rbac-smoke/admin/audit.read','unknown','{\"rbac_decision\":true,\"enforce_tier\":2,\"permission_tier\":2}','2026-04-06 05:08:08'),(4,'admin',3,'audit.export','allow','rbac','enforce',1,'POST','/rbac-smoke/admin/audit.export','unknown','{\"rbac_decision\":true,\"enforce_tier\":2,\"permission_tier\":1}','2026-04-06 05:08:08'),(5,'admin',3,'gate.scan.rfid','deny','rbac','enforce',1,'POST','/rbac-smoke/admin/gate.scan.rfid','unknown','{\"rbac_decision\":false,\"enforce_tier\":2,\"permission_tier\":2}','2026-04-06 05:08:08'),(6,'admin',3,'qr.scan','deny','rbac','enforce',1,'POST','/rbac-smoke/admin/qr.scan','unknown','{\"rbac_decision\":false,\"enforce_tier\":2,\"permission_tier\":2}','2026-04-06 05:08:08'),(7,'security',NULL,'gate.scan.rfid','allow','rbac','enforce',1,'POST','/rbac-smoke/security/gate.scan.rfid','unknown','{\"rbac_decision\":true,\"enforce_tier\":2,\"permission_tier\":2}','2026-04-06 05:08:08'),(8,'security',NULL,'qr.scan','allow','rbac','enforce',1,'POST','/rbac-smoke/security/qr.scan','unknown','{\"rbac_decision\":true,\"enforce_tier\":2,\"permission_tier\":2}','2026-04-06 05:08:08'),(9,'security',NULL,'face.verify','allow','rbac','enforce',1,'POST','/rbac-smoke/security/face.verify','unknown','{\"rbac_decision\":true,\"enforce_tier\":2,\"permission_tier\":2}','2026-04-06 05:08:08'),(10,'security',NULL,'violation.record','allow','rbac','enforce',1,'POST','/rbac-smoke/security/violation.record','unknown','{\"rbac_decision\":true,\"enforce_tier\":2,\"permission_tier\":1}','2026-04-06 05:08:08'),(11,'security',NULL,'student.delete','deny','rbac','enforce',1,'POST','/rbac-smoke/security/student.delete','unknown','{\"rbac_decision\":false,\"enforce_tier\":2,\"permission_tier\":1}','2026-04-06 05:08:08'),(12,'security',NULL,'audit.export','deny','rbac','enforce',1,'POST','/rbac-smoke/security/audit.export','unknown','{\"rbac_decision\":false,\"enforce_tier\":2,\"permission_tier\":1}','2026-04-06 05:08:08'),(13,'student',202232903,'student.delete','deny','rbac','enforce',1,'POST','/rbac-smoke/student/student.delete','unknown','{\"rbac_decision\":false,\"enforce_tier\":2,\"permission_tier\":1}','2026-04-06 05:08:08'),(14,'student',202232903,'audit.read','deny','rbac','enforce',1,'POST','/rbac-smoke/student/audit.read','unknown','{\"rbac_decision\":false,\"enforce_tier\":2,\"permission_tier\":2}','2026-04-06 05:08:08'),(15,'student',202232903,'violation.record','deny','rbac','enforce',1,'POST','/rbac-smoke/student/violation.record','unknown','{\"rbac_decision\":false,\"enforce_tier\":2,\"permission_tier\":1}','2026-04-06 05:08:08'),(16,'student',202232903,'gate.scan.rfid','deny','rbac','enforce',1,'POST','/rbac-smoke/student/gate.scan.rfid','unknown','{\"rbac_decision\":false,\"enforce_tier\":2,\"permission_tier\":2}','2026-04-06 05:08:08'),(17,'superadmin',1,'admin.create','allow','rbac','enforce',1,'POST','/rbac-smoke/superadmin/admin.create','unknown','{\"rbac_decision\":true,\"enforce_tier\":2,\"permission_tier\":1}','2026-04-06 05:08:08'),(18,'superadmin',1,'admin.update','allow','rbac','enforce',1,'POST','/rbac-smoke/superadmin/admin.update','unknown','{\"rbac_decision\":true,\"enforce_tier\":2,\"permission_tier\":1}','2026-04-06 05:08:08'),(19,'superadmin',1,'admin.delete','allow','rbac','enforce',1,'POST','/rbac-smoke/superadmin/admin.delete','unknown','{\"rbac_decision\":true,\"enforce_tier\":2,\"permission_tier\":1}','2026-04-06 05:08:08'),(20,'superadmin',1,'gate.scan.rfid','allow','rbac','enforce',1,'POST','/rbac-smoke/superadmin/gate.scan.rfid','unknown','{\"rbac_decision\":true,\"enforce_tier\":2,\"permission_tier\":2}','2026-04-06 05:08:08'),(21,'admin',3,'student.delete','allow','rbac','enforce',1,'POST','/rbac-smoke/admin/student.delete','unknown','{\"rbac_decision\":true,\"enforce_tier\":2,\"permission_tier\":1}','2026-04-06 05:12:58'),(22,'admin',3,'rfid.register','allow','rbac','enforce',1,'POST','/rbac-smoke/admin/rfid.register','unknown','{\"rbac_decision\":true,\"enforce_tier\":2,\"permission_tier\":1}','2026-04-06 05:12:58'),(23,'admin',3,'audit.read','allow','rbac','enforce',1,'POST','/rbac-smoke/admin/audit.read','unknown','{\"rbac_decision\":true,\"enforce_tier\":2,\"permission_tier\":2}','2026-04-06 05:12:58'),(24,'admin',3,'audit.export','allow','rbac','enforce',1,'POST','/rbac-smoke/admin/audit.export','unknown','{\"rbac_decision\":true,\"enforce_tier\":2,\"permission_tier\":1}','2026-04-06 05:12:58'),(25,'admin',3,'gate.scan.rfid','deny','rbac','enforce',1,'POST','/rbac-smoke/admin/gate.scan.rfid','unknown','{\"rbac_decision\":false,\"enforce_tier\":2,\"permission_tier\":2}','2026-04-06 05:12:58'),(26,'admin',3,'qr.scan','deny','rbac','enforce',1,'POST','/rbac-smoke/admin/qr.scan','unknown','{\"rbac_decision\":false,\"enforce_tier\":2,\"permission_tier\":2}','2026-04-06 05:12:58'),(27,'security',NULL,'gate.scan.rfid','allow','rbac','enforce',1,'POST','/rbac-smoke/security/gate.scan.rfid','unknown','{\"rbac_decision\":true,\"enforce_tier\":2,\"permission_tier\":2}','2026-04-06 05:12:58'),(28,'security',NULL,'qr.scan','allow','rbac','enforce',1,'POST','/rbac-smoke/security/qr.scan','unknown','{\"rbac_decision\":true,\"enforce_tier\":2,\"permission_tier\":2}','2026-04-06 05:12:58'),(29,'security',NULL,'face.verify','allow','rbac','enforce',1,'POST','/rbac-smoke/security/face.verify','unknown','{\"rbac_decision\":true,\"enforce_tier\":2,\"permission_tier\":2}','2026-04-06 05:12:58'),(30,'security',NULL,'violation.record','allow','rbac','enforce',1,'POST','/rbac-smoke/security/violation.record','unknown','{\"rbac_decision\":true,\"enforce_tier\":2,\"permission_tier\":1}','2026-04-06 05:12:58'),(31,'security',NULL,'student.delete','deny','rbac','enforce',1,'POST','/rbac-smoke/security/student.delete','unknown','{\"rbac_decision\":false,\"enforce_tier\":2,\"permission_tier\":1}','2026-04-06 05:12:58'),(32,'security',NULL,'audit.export','deny','rbac','enforce',1,'POST','/rbac-smoke/security/audit.export','unknown','{\"rbac_decision\":false,\"enforce_tier\":2,\"permission_tier\":1}','2026-04-06 05:12:58'),(33,'student',202232903,'student.delete','deny','rbac','enforce',1,'POST','/rbac-smoke/student/student.delete','unknown','{\"rbac_decision\":false,\"enforce_tier\":2,\"permission_tier\":1}','2026-04-06 05:12:58'),(34,'student',202232903,'audit.read','deny','rbac','enforce',1,'POST','/rbac-smoke/student/audit.read','unknown','{\"rbac_decision\":false,\"enforce_tier\":2,\"permission_tier\":2}','2026-04-06 05:12:58'),(35,'student',202232903,'violation.record','deny','rbac','enforce',1,'POST','/rbac-smoke/student/violation.record','unknown','{\"rbac_decision\":false,\"enforce_tier\":2,\"permission_tier\":1}','2026-04-06 05:12:58'),(36,'student',202232903,'gate.scan.rfid','deny','rbac','enforce',1,'POST','/rbac-smoke/student/gate.scan.rfid','unknown','{\"rbac_decision\":false,\"enforce_tier\":2,\"permission_tier\":2}','2026-04-06 05:12:58'),(37,'superadmin',1,'admin.create','allow','rbac','enforce',1,'POST','/rbac-smoke/superadmin/admin.create','unknown','{\"rbac_decision\":true,\"enforce_tier\":2,\"permission_tier\":1}','2026-04-06 05:12:58'),(38,'superadmin',1,'admin.update','allow','rbac','enforce',1,'POST','/rbac-smoke/superadmin/admin.update','unknown','{\"rbac_decision\":true,\"enforce_tier\":2,\"permission_tier\":1}','2026-04-06 05:12:58'),(39,'superadmin',1,'admin.delete','allow','rbac','enforce',1,'POST','/rbac-smoke/superadmin/admin.delete','unknown','{\"rbac_decision\":true,\"enforce_tier\":2,\"permission_tier\":1}','2026-04-06 05:12:58'),(40,'superadmin',1,'gate.scan.rfid','allow','rbac','enforce',1,'POST','/rbac-smoke/superadmin/gate.scan.rfid','unknown','{\"rbac_decision\":true,\"enforce_tier\":2,\"permission_tier\":2}','2026-04-06 05:12:58'),(41,'admin',3,'audit.read','allow','rbac','enforce',1,'GET','/pcurfid2/admin/analytics_data.php?period=today','::1','{\"rbac_decision\":true,\"enforce_tier\":2,\"permission_tier\":2}','2026-04-06 05:25:16'),(42,'admin',3,'audit.read','allow','rbac','enforce',1,'GET','/pcurfid2/admin/analytics_data.php?period=week','::1','{\"rbac_decision\":true,\"enforce_tier\":2,\"permission_tier\":2}','2026-04-06 05:25:23'),(43,'admin',3,'audit.read','allow','rbac','enforce',1,'GET','/pcurfid2/admin/analytics_data.php?period=today','::1','{\"rbac_decision\":true,\"enforce_tier\":2,\"permission_tier\":2}','2026-04-06 05:25:25'),(44,'admin',3,'audit.read','allow','rbac','enforce',1,'GET','/pcurfid2/admin/analytics_data.php?period=year','::1','{\"rbac_decision\":true,\"enforce_tier\":2,\"permission_tier\":2}','2026-04-06 05:25:34'),(45,'admin',3,'audit.read','allow','rbac','enforce',1,'GET','/pcurfid2/admin/analytics_data.php?period=today','::1','{\"rbac_decision\":true,\"enforce_tier\":2,\"permission_tier\":2}','2026-04-06 05:25:40'),(46,'admin',3,'audit.read','allow','rbac','enforce',1,'GET','/pcurfid2/admin/filter_audit_logs.php?action_type=&date_from=&date_to=','::1','{\"rbac_decision\":true,\"enforce_tier\":2,\"permission_tier\":2}','2026-04-06 05:25:42'),(47,'admin',3,'audit.read','allow','rbac','enforce',1,'GET','/pcurfid2/admin/filter_audit_logs.php?action_type=&date_from=&date_to=','::1','{\"rbac_decision\":true,\"enforce_tier\":2,\"permission_tier\":2}','2026-04-06 05:25:47'),(48,'admin',3,'audit.read','allow','rbac','enforce',1,'GET','/pcurfid2/admin/filter_audit_logs.php?action_type=&date_from=&date_to=','::1','{\"rbac_decision\":true,\"enforce_tier\":2,\"permission_tier\":2}','2026-04-06 05:25:53'),(49,'security',NULL,'violation.record','allow','rbac','enforce',1,'GET','/pcurfid2/security/get_violation_categories.php','::1','{\"rbac_decision\":true,\"enforce_tier\":2,\"permission_tier\":1}','2026-04-06 05:26:10'),(50,'security',NULL,'face.verify','allow','rbac','enforce',1,'GET','/pcurfid2/api/get_face_descriptors.php','::1','{\"rbac_decision\":true,\"enforce_tier\":2,\"permission_tier\":2}','2026-04-06 05:26:13'),(51,'admin',3,'student.delete','allow','rbac','enforce',1,'POST','/rbac-smoke/admin/student.delete','unknown','{\"rbac_decision\":true,\"enforce_tier\":2,\"permission_tier\":1}','2026-04-06 05:26:36'),(52,'admin',3,'rfid.register','allow','rbac','enforce',1,'POST','/rbac-smoke/admin/rfid.register','unknown','{\"rbac_decision\":true,\"enforce_tier\":2,\"permission_tier\":1}','2026-04-06 05:26:36'),(53,'admin',3,'audit.read','allow','rbac','enforce',1,'POST','/rbac-smoke/admin/audit.read','unknown','{\"rbac_decision\":true,\"enforce_tier\":2,\"permission_tier\":2}','2026-04-06 05:26:36'),(54,'admin',3,'audit.export','allow','rbac','enforce',1,'POST','/rbac-smoke/admin/audit.export','unknown','{\"rbac_decision\":true,\"enforce_tier\":2,\"permission_tier\":1}','2026-04-06 05:26:36'),(55,'admin',3,'gate.scan.rfid','deny','rbac','enforce',1,'POST','/rbac-smoke/admin/gate.scan.rfid','unknown','{\"rbac_decision\":false,\"enforce_tier\":2,\"permission_tier\":2}','2026-04-06 05:26:36'),(56,'admin',3,'qr.scan','deny','rbac','enforce',1,'POST','/rbac-smoke/admin/qr.scan','unknown','{\"rbac_decision\":false,\"enforce_tier\":2,\"permission_tier\":2}','2026-04-06 05:26:36'),(57,'security',NULL,'gate.scan.rfid','allow','rbac','enforce',1,'POST','/rbac-smoke/security/gate.scan.rfid','unknown','{\"rbac_decision\":true,\"enforce_tier\":2,\"permission_tier\":2}','2026-04-06 05:26:36'),(58,'security',NULL,'qr.scan','allow','rbac','enforce',1,'POST','/rbac-smoke/security/qr.scan','unknown','{\"rbac_decision\":true,\"enforce_tier\":2,\"permission_tier\":2}','2026-04-06 05:26:36'),(59,'security',NULL,'face.verify','allow','rbac','enforce',1,'POST','/rbac-smoke/security/face.verify','unknown','{\"rbac_decision\":true,\"enforce_tier\":2,\"permission_tier\":2}','2026-04-06 05:26:36'),(60,'security',NULL,'violation.record','allow','rbac','enforce',1,'POST','/rbac-smoke/security/violation.record','unknown','{\"rbac_decision\":true,\"enforce_tier\":2,\"permission_tier\":1}','2026-04-06 05:26:36'),(61,'security',NULL,'student.delete','deny','rbac','enforce',1,'POST','/rbac-smoke/security/student.delete','unknown','{\"rbac_decision\":false,\"enforce_tier\":2,\"permission_tier\":1}','2026-04-06 05:26:36'),(62,'security',NULL,'audit.export','deny','rbac','enforce',1,'POST','/rbac-smoke/security/audit.export','unknown','{\"rbac_decision\":false,\"enforce_tier\":2,\"permission_tier\":1}','2026-04-06 05:26:36'),(63,'student',202232903,'student.delete','deny','rbac','enforce',1,'POST','/rbac-smoke/student/student.delete','unknown','{\"rbac_decision\":false,\"enforce_tier\":2,\"permission_tier\":1}','2026-04-06 05:26:36'),(64,'student',202232903,'audit.read','deny','rbac','enforce',1,'POST','/rbac-smoke/student/audit.read','unknown','{\"rbac_decision\":false,\"enforce_tier\":2,\"permission_tier\":2}','2026-04-06 05:26:36'),(65,'student',202232903,'violation.record','deny','rbac','enforce',1,'POST','/rbac-smoke/student/violation.record','unknown','{\"rbac_decision\":false,\"enforce_tier\":2,\"permission_tier\":1}','2026-04-06 05:26:36'),(66,'student',202232903,'gate.scan.rfid','deny','rbac','enforce',1,'POST','/rbac-smoke/student/gate.scan.rfid','unknown','{\"rbac_decision\":false,\"enforce_tier\":2,\"permission_tier\":2}','2026-04-06 05:26:36'),(67,'superadmin',1,'admin.create','allow','rbac','enforce',1,'POST','/rbac-smoke/superadmin/admin.create','unknown','{\"rbac_decision\":true,\"enforce_tier\":2,\"permission_tier\":1}','2026-04-06 05:26:36'),(68,'superadmin',1,'admin.update','allow','rbac','enforce',1,'POST','/rbac-smoke/superadmin/admin.update','unknown','{\"rbac_decision\":true,\"enforce_tier\":2,\"permission_tier\":1}','2026-04-06 05:26:36'),(69,'superadmin',1,'admin.delete','allow','rbac','enforce',1,'POST','/rbac-smoke/superadmin/admin.delete','unknown','{\"rbac_decision\":true,\"enforce_tier\":2,\"permission_tier\":1}','2026-04-06 05:26:36'),(70,'superadmin',1,'gate.scan.rfid','allow','rbac','enforce',1,'POST','/rbac-smoke/superadmin/gate.scan.rfid','unknown','{\"rbac_decision\":true,\"enforce_tier\":2,\"permission_tier\":2}','2026-04-06 05:26:36'),(71,'security',NULL,'violation.record','allow','rbac','enforce',1,'GET','/pcurfid2/security/get_violation_categories.php','::1','{\"rbac_decision\":true,\"enforce_tier\":2,\"permission_tier\":1}','2026-04-06 05:27:08'),(72,'admin',3,'student.delete','allow','rbac','enforce',1,'POST','/rbac-smoke/admin/student.delete','unknown','{\"rbac_decision\":true,\"enforce_tier\":2,\"permission_tier\":1}','2026-04-06 05:27:20'),(73,'admin',3,'rfid.register','allow','rbac','enforce',1,'POST','/rbac-smoke/admin/rfid.register','unknown','{\"rbac_decision\":true,\"enforce_tier\":2,\"permission_tier\":1}','2026-04-06 05:27:20'),(74,'admin',3,'audit.read','allow','rbac','enforce',1,'POST','/rbac-smoke/admin/audit.read','unknown','{\"rbac_decision\":true,\"enforce_tier\":2,\"permission_tier\":2}','2026-04-06 05:27:20'),(75,'admin',3,'audit.export','allow','rbac','enforce',1,'POST','/rbac-smoke/admin/audit.export','unknown','{\"rbac_decision\":true,\"enforce_tier\":2,\"permission_tier\":1}','2026-04-06 05:27:20'),(76,'admin',3,'gate.scan.rfid','deny','rbac','enforce',1,'POST','/rbac-smoke/admin/gate.scan.rfid','unknown','{\"rbac_decision\":false,\"enforce_tier\":2,\"permission_tier\":2}','2026-04-06 05:27:20'),(77,'admin',3,'qr.scan','deny','rbac','enforce',1,'POST','/rbac-smoke/admin/qr.scan','unknown','{\"rbac_decision\":false,\"enforce_tier\":2,\"permission_tier\":2}','2026-04-06 05:27:20'),(78,'security',NULL,'gate.scan.rfid','allow','rbac','enforce',1,'POST','/rbac-smoke/security/gate.scan.rfid','unknown','{\"rbac_decision\":true,\"enforce_tier\":2,\"permission_tier\":2}','2026-04-06 05:27:20'),(79,'security',NULL,'qr.scan','allow','rbac','enforce',1,'POST','/rbac-smoke/security/qr.scan','unknown','{\"rbac_decision\":true,\"enforce_tier\":2,\"permission_tier\":2}','2026-04-06 05:27:20'),(80,'security',NULL,'face.verify','allow','rbac','enforce',1,'POST','/rbac-smoke/security/face.verify','unknown','{\"rbac_decision\":true,\"enforce_tier\":2,\"permission_tier\":2}','2026-04-06 05:27:20'),(81,'security',NULL,'violation.record','allow','rbac','enforce',1,'POST','/rbac-smoke/security/violation.record','unknown','{\"rbac_decision\":true,\"enforce_tier\":2,\"permission_tier\":1}','2026-04-06 05:27:20'),(82,'security',NULL,'student.delete','deny','rbac','enforce',1,'POST','/rbac-smoke/security/student.delete','unknown','{\"rbac_decision\":false,\"enforce_tier\":2,\"permission_tier\":1}','2026-04-06 05:27:20'),(83,'security',NULL,'audit.export','deny','rbac','enforce',1,'POST','/rbac-smoke/security/audit.export','unknown','{\"rbac_decision\":false,\"enforce_tier\":2,\"permission_tier\":1}','2026-04-06 05:27:20'),(84,'student',202232903,'student.delete','deny','rbac','enforce',1,'POST','/rbac-smoke/student/student.delete','unknown','{\"rbac_decision\":false,\"enforce_tier\":2,\"permission_tier\":1}','2026-04-06 05:27:20'),(85,'student',202232903,'audit.read','deny','rbac','enforce',1,'POST','/rbac-smoke/student/audit.read','unknown','{\"rbac_decision\":false,\"enforce_tier\":2,\"permission_tier\":2}','2026-04-06 05:27:20'),(86,'student',202232903,'violation.record','deny','rbac','enforce',1,'POST','/rbac-smoke/student/violation.record','unknown','{\"rbac_decision\":false,\"enforce_tier\":2,\"permission_tier\":1}','2026-04-06 05:27:20'),(87,'student',202232903,'gate.scan.rfid','deny','rbac','enforce',1,'POST','/rbac-smoke/student/gate.scan.rfid','unknown','{\"rbac_decision\":false,\"enforce_tier\":2,\"permission_tier\":2}','2026-04-06 05:27:20'),(88,'superadmin',1,'admin.create','allow','rbac','enforce',1,'POST','/rbac-smoke/superadmin/admin.create','unknown','{\"rbac_decision\":true,\"enforce_tier\":2,\"permission_tier\":1}','2026-04-06 05:27:20'),(89,'superadmin',1,'admin.update','allow','rbac','enforce',1,'POST','/rbac-smoke/superadmin/admin.update','unknown','{\"rbac_decision\":true,\"enforce_tier\":2,\"permission_tier\":1}','2026-04-06 05:27:20'),(90,'superadmin',1,'admin.delete','allow','rbac','enforce',1,'POST','/rbac-smoke/superadmin/admin.delete','unknown','{\"rbac_decision\":true,\"enforce_tier\":2,\"permission_tier\":1}','2026-04-06 05:27:20'),(91,'superadmin',1,'gate.scan.rfid','allow','rbac','enforce',1,'POST','/rbac-smoke/superadmin/gate.scan.rfid','unknown','{\"rbac_decision\":true,\"enforce_tier\":2,\"permission_tier\":2}','2026-04-06 05:27:20'),(92,'admin',999,'violation.clear','allow','rbac','enforce',1,'POST','/admin/manage_violations.php','127.0.0.1','{\"rbac_decision\":true,\"enforce_tier\":2,\"permission_tier\":1}','2026-04-06 05:29:45'),(93,'admin',999,'rfid.mark_lost','allow','rbac','enforce',1,'POST','/admin/mark_lost_rfid.php','127.0.0.1','{\"rbac_decision\":true,\"enforce_tier\":2,\"permission_tier\":1}','2026-04-06 05:29:45'),(94,'admin',999,'violation.clear','allow','rbac','enforce',1,'POST','/admin/manage_violations.php','127.0.0.1','{\"rbac_decision\":true,\"enforce_tier\":2,\"permission_tier\":1}','2026-04-06 05:29:53'),(95,'admin',999,'rfid.mark_lost','allow','rbac','enforce',1,'POST','/admin/mark_lost_rfid.php','127.0.0.1','{\"rbac_decision\":true,\"enforce_tier\":2,\"permission_tier\":1}','2026-04-06 05:29:53'),(96,'admin',999,'violation.clear','allow','rbac','enforce',1,'POST','/admin/manage_violations.php','127.0.0.1','{\"rbac_decision\":true,\"enforce_tier\":2,\"permission_tier\":1}','2026-04-06 05:30:05'),(97,'admin',999,'rfid.mark_lost','allow','rbac','enforce',1,'POST','/admin/mark_lost_rfid.php','127.0.0.1','{\"rbac_decision\":true,\"enforce_tier\":2,\"permission_tier\":1}','2026-04-06 05:30:12'),(98,'admin',999,'face.delete','allow','rbac','enforce',1,'POST','/api/delete_face.php','127.0.0.1','{\"rbac_decision\":true,\"enforce_tier\":2,\"permission_tier\":1}','2026-04-06 05:30:31'),(99,'admin',3,'student.delete','allow','rbac','enforce',1,'POST','/rbac-smoke/admin/student.delete','unknown','{\"rbac_decision\":true,\"enforce_tier\":2,\"permission_tier\":1}','2026-04-06 05:30:39'),(100,'admin',3,'rfid.register','allow','rbac','enforce',1,'POST','/rbac-smoke/admin/rfid.register','unknown','{\"rbac_decision\":true,\"enforce_tier\":2,\"permission_tier\":1}','2026-04-06 05:30:39'),(101,'admin',3,'audit.read','allow','rbac','enforce',1,'POST','/rbac-smoke/admin/audit.read','unknown','{\"rbac_decision\":true,\"enforce_tier\":2,\"permission_tier\":2}','2026-04-06 05:30:39'),(102,'admin',3,'audit.export','allow','rbac','enforce',1,'POST','/rbac-smoke/admin/audit.export','unknown','{\"rbac_decision\":true,\"enforce_tier\":2,\"permission_tier\":1}','2026-04-06 05:30:39'),(103,'admin',3,'gate.scan.rfid','deny','rbac','enforce',1,'POST','/rbac-smoke/admin/gate.scan.rfid','unknown','{\"rbac_decision\":false,\"enforce_tier\":2,\"permission_tier\":2}','2026-04-06 05:30:39'),(104,'admin',3,'qr.scan','deny','rbac','enforce',1,'POST','/rbac-smoke/admin/qr.scan','unknown','{\"rbac_decision\":false,\"enforce_tier\":2,\"permission_tier\":2}','2026-04-06 05:30:39'),(105,'security',NULL,'gate.scan.rfid','allow','rbac','enforce',1,'POST','/rbac-smoke/security/gate.scan.rfid','unknown','{\"rbac_decision\":true,\"enforce_tier\":2,\"permission_tier\":2}','2026-04-06 05:30:39'),(106,'security',NULL,'qr.scan','allow','rbac','enforce',1,'POST','/rbac-smoke/security/qr.scan','unknown','{\"rbac_decision\":true,\"enforce_tier\":2,\"permission_tier\":2}','2026-04-06 05:30:39'),(107,'security',NULL,'face.verify','allow','rbac','enforce',1,'POST','/rbac-smoke/security/face.verify','unknown','{\"rbac_decision\":true,\"enforce_tier\":2,\"permission_tier\":2}','2026-04-06 05:30:39'),(108,'security',NULL,'violation.record','allow','rbac','enforce',1,'POST','/rbac-smoke/security/violation.record','unknown','{\"rbac_decision\":true,\"enforce_tier\":2,\"permission_tier\":1}','2026-04-06 05:30:39'),(109,'security',NULL,'student.delete','deny','rbac','enforce',1,'POST','/rbac-smoke/security/student.delete','unknown','{\"rbac_decision\":false,\"enforce_tier\":2,\"permission_tier\":1}','2026-04-06 05:30:39'),(110,'security',NULL,'audit.export','deny','rbac','enforce',1,'POST','/rbac-smoke/security/audit.export','unknown','{\"rbac_decision\":false,\"enforce_tier\":2,\"permission_tier\":1}','2026-04-06 05:30:39'),(111,'student',202232903,'student.delete','deny','rbac','enforce',1,'POST','/rbac-smoke/student/student.delete','unknown','{\"rbac_decision\":false,\"enforce_tier\":2,\"permission_tier\":1}','2026-04-06 05:30:39'),(112,'student',202232903,'audit.read','deny','rbac','enforce',1,'POST','/rbac-smoke/student/audit.read','unknown','{\"rbac_decision\":false,\"enforce_tier\":2,\"permission_tier\":2}','2026-04-06 05:30:39'),(113,'student',202232903,'violation.record','deny','rbac','enforce',1,'POST','/rbac-smoke/student/violation.record','unknown','{\"rbac_decision\":false,\"enforce_tier\":2,\"permission_tier\":1}','2026-04-06 05:30:39'),(114,'student',202232903,'gate.scan.rfid','deny','rbac','enforce',1,'POST','/rbac-smoke/student/gate.scan.rfid','unknown','{\"rbac_decision\":false,\"enforce_tier\":2,\"permission_tier\":2}','2026-04-06 05:30:39'),(115,'superadmin',1,'admin.create','allow','rbac','enforce',1,'POST','/rbac-smoke/superadmin/admin.create','unknown','{\"rbac_decision\":true,\"enforce_tier\":2,\"permission_tier\":1}','2026-04-06 05:30:39'),(116,'superadmin',1,'admin.update','allow','rbac','enforce',1,'POST','/rbac-smoke/superadmin/admin.update','unknown','{\"rbac_decision\":true,\"enforce_tier\":2,\"permission_tier\":1}','2026-04-06 05:30:39'),(117,'superadmin',1,'admin.delete','allow','rbac','enforce',1,'POST','/rbac-smoke/superadmin/admin.delete','unknown','{\"rbac_decision\":true,\"enforce_tier\":2,\"permission_tier\":1}','2026-04-06 05:30:39'),(118,'superadmin',1,'gate.scan.rfid','allow','rbac','enforce',1,'POST','/rbac-smoke/superadmin/gate.scan.rfid','unknown','{\"rbac_decision\":true,\"enforce_tier\":2,\"permission_tier\":2}','2026-04-06 05:30:39'),(119,'admin',3,'student.delete','allow','rbac','enforce',1,'POST','/rbac-smoke/admin/student.delete','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 05:30:46'),(120,'admin',3,'rfid.register','allow','rbac','enforce',1,'POST','/rbac-smoke/admin/rfid.register','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 05:30:46'),(121,'admin',3,'audit.read','allow','rbac','enforce',1,'POST','/rbac-smoke/admin/audit.read','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 05:30:46'),(122,'admin',3,'audit.export','allow','rbac','enforce',1,'POST','/rbac-smoke/admin/audit.export','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 05:30:46'),(123,'admin',3,'gate.scan.rfid','deny','rbac','enforce',1,'POST','/rbac-smoke/admin/gate.scan.rfid','unknown','{\"rbac_decision\":false,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 05:30:46'),(124,'admin',3,'qr.scan','deny','rbac','enforce',1,'POST','/rbac-smoke/admin/qr.scan','unknown','{\"rbac_decision\":false,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 05:30:46'),(125,'security',NULL,'gate.scan.rfid','allow','rbac','enforce',1,'POST','/rbac-smoke/security/gate.scan.rfid','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 05:30:46'),(126,'security',NULL,'qr.scan','allow','rbac','enforce',1,'POST','/rbac-smoke/security/qr.scan','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 05:30:46'),(127,'security',NULL,'face.verify','allow','rbac','enforce',1,'POST','/rbac-smoke/security/face.verify','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 05:30:46'),(128,'security',NULL,'violation.record','allow','rbac','enforce',1,'POST','/rbac-smoke/security/violation.record','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 05:30:46'),(129,'security',NULL,'student.delete','deny','rbac','enforce',1,'POST','/rbac-smoke/security/student.delete','unknown','{\"rbac_decision\":false,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 05:30:46'),(130,'security',NULL,'audit.export','deny','rbac','enforce',1,'POST','/rbac-smoke/security/audit.export','unknown','{\"rbac_decision\":false,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 05:30:46'),(131,'student',202232903,'student.delete','deny','rbac','enforce',1,'POST','/rbac-smoke/student/student.delete','unknown','{\"rbac_decision\":false,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 05:30:46'),(132,'student',202232903,'audit.read','deny','rbac','enforce',1,'POST','/rbac-smoke/student/audit.read','unknown','{\"rbac_decision\":false,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 05:30:46'),(133,'student',202232903,'violation.record','deny','rbac','enforce',1,'POST','/rbac-smoke/student/violation.record','unknown','{\"rbac_decision\":false,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 05:30:46'),(134,'student',202232903,'gate.scan.rfid','deny','rbac','enforce',1,'POST','/rbac-smoke/student/gate.scan.rfid','unknown','{\"rbac_decision\":false,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 05:30:46'),(135,'superadmin',1,'admin.create','allow','rbac','enforce',1,'POST','/rbac-smoke/superadmin/admin.create','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 05:30:46'),(136,'superadmin',1,'admin.update','allow','rbac','enforce',1,'POST','/rbac-smoke/superadmin/admin.update','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 05:30:46'),(137,'superadmin',1,'admin.delete','allow','rbac','enforce',1,'POST','/rbac-smoke/superadmin/admin.delete','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 05:30:46'),(138,'superadmin',1,'gate.scan.rfid','allow','rbac','enforce',1,'POST','/rbac-smoke/superadmin/gate.scan.rfid','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 05:30:46'),(139,'admin',3,'student.delete','allow','rbac','enforce',1,'POST','/rbac-smoke/admin/student.delete','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 05:41:28'),(140,'admin',3,'rfid.register','allow','rbac','enforce',1,'POST','/rbac-smoke/admin/rfid.register','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 05:41:28'),(141,'admin',3,'audit.read','allow','rbac','enforce',1,'POST','/rbac-smoke/admin/audit.read','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 05:41:28'),(142,'admin',3,'audit.export','allow','rbac','enforce',1,'POST','/rbac-smoke/admin/audit.export','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 05:41:28'),(143,'admin',3,'gate.scan.rfid','deny','rbac','enforce',1,'POST','/rbac-smoke/admin/gate.scan.rfid','unknown','{\"rbac_decision\":false,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 05:41:28'),(144,'admin',3,'qr.scan','deny','rbac','enforce',1,'POST','/rbac-smoke/admin/qr.scan','unknown','{\"rbac_decision\":false,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 05:41:28'),(145,'security',NULL,'gate.scan.rfid','allow','rbac','enforce',1,'POST','/rbac-smoke/security/gate.scan.rfid','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 05:41:28'),(146,'security',NULL,'qr.scan','allow','rbac','enforce',1,'POST','/rbac-smoke/security/qr.scan','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 05:41:28'),(147,'security',NULL,'face.verify','allow','rbac','enforce',1,'POST','/rbac-smoke/security/face.verify','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 05:41:28'),(148,'security',NULL,'violation.record','allow','rbac','enforce',1,'POST','/rbac-smoke/security/violation.record','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 05:41:28'),(149,'security',NULL,'student.delete','deny','rbac','enforce',1,'POST','/rbac-smoke/security/student.delete','unknown','{\"rbac_decision\":false,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 05:41:28'),(150,'security',NULL,'audit.export','deny','rbac','enforce',1,'POST','/rbac-smoke/security/audit.export','unknown','{\"rbac_decision\":false,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 05:41:28'),(151,'student',202232903,'student.delete','deny','rbac','enforce',1,'POST','/rbac-smoke/student/student.delete','unknown','{\"rbac_decision\":false,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 05:41:28'),(152,'student',202232903,'audit.read','deny','rbac','enforce',1,'POST','/rbac-smoke/student/audit.read','unknown','{\"rbac_decision\":false,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 05:41:28'),(153,'student',202232903,'violation.record','deny','rbac','enforce',1,'POST','/rbac-smoke/student/violation.record','unknown','{\"rbac_decision\":false,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 05:41:28'),(154,'student',202232903,'gate.scan.rfid','deny','rbac','enforce',1,'POST','/rbac-smoke/student/gate.scan.rfid','unknown','{\"rbac_decision\":false,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 05:41:28'),(155,'superadmin',1,'admin.create','allow','rbac','enforce',1,'POST','/rbac-smoke/superadmin/admin.create','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 05:41:28'),(156,'superadmin',1,'admin.update','allow','rbac','enforce',1,'POST','/rbac-smoke/superadmin/admin.update','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 05:41:28'),(157,'superadmin',1,'admin.delete','allow','rbac','enforce',1,'POST','/rbac-smoke/superadmin/admin.delete','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 05:41:28'),(158,'superadmin',1,'gate.scan.rfid','allow','rbac','enforce',1,'POST','/rbac-smoke/superadmin/gate.scan.rfid','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 05:41:28'),(159,'admin',3,'student.delete','allow','rbac','enforce',1,'POST','/rbac-smoke/admin/student.delete','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 05:52:24'),(160,'admin',3,'rfid.register','allow','rbac','enforce',1,'POST','/rbac-smoke/admin/rfid.register','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 05:52:24'),(161,'admin',3,'audit.read','allow','rbac','enforce',1,'POST','/rbac-smoke/admin/audit.read','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 05:52:24'),(162,'admin',3,'audit.export','allow','rbac','enforce',1,'POST','/rbac-smoke/admin/audit.export','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 05:52:24'),(163,'admin',3,'gate.scan.rfid','deny','rbac','enforce',1,'POST','/rbac-smoke/admin/gate.scan.rfid','unknown','{\"rbac_decision\":false,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 05:52:24'),(164,'admin',3,'qr.scan','deny','rbac','enforce',1,'POST','/rbac-smoke/admin/qr.scan','unknown','{\"rbac_decision\":false,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 05:52:24'),(165,'security',NULL,'gate.scan.rfid','allow','rbac','enforce',1,'POST','/rbac-smoke/security/gate.scan.rfid','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 05:52:24'),(166,'security',NULL,'qr.scan','allow','rbac','enforce',1,'POST','/rbac-smoke/security/qr.scan','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 05:52:24'),(167,'security',NULL,'face.verify','allow','rbac','enforce',1,'POST','/rbac-smoke/security/face.verify','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 05:52:24'),(168,'security',NULL,'violation.record','allow','rbac','enforce',1,'POST','/rbac-smoke/security/violation.record','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 05:52:24'),(169,'security',NULL,'student.delete','deny','rbac','enforce',1,'POST','/rbac-smoke/security/student.delete','unknown','{\"rbac_decision\":false,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 05:52:24'),(170,'security',NULL,'audit.export','deny','rbac','enforce',1,'POST','/rbac-smoke/security/audit.export','unknown','{\"rbac_decision\":false,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 05:52:24'),(171,'student',202232903,'student.delete','deny','rbac','enforce',1,'POST','/rbac-smoke/student/student.delete','unknown','{\"rbac_decision\":false,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 05:52:24'),(172,'student',202232903,'audit.read','deny','rbac','enforce',1,'POST','/rbac-smoke/student/audit.read','unknown','{\"rbac_decision\":false,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 05:52:24'),(173,'student',202232903,'violation.record','deny','rbac','enforce',1,'POST','/rbac-smoke/student/violation.record','unknown','{\"rbac_decision\":false,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 05:52:24'),(174,'student',202232903,'gate.scan.rfid','deny','rbac','enforce',1,'POST','/rbac-smoke/student/gate.scan.rfid','unknown','{\"rbac_decision\":false,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 05:52:24'),(175,'superadmin',1,'admin.create','allow','rbac','enforce',1,'POST','/rbac-smoke/superadmin/admin.create','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 05:52:24'),(176,'superadmin',1,'admin.update','allow','rbac','enforce',1,'POST','/rbac-smoke/superadmin/admin.update','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 05:52:24'),(177,'superadmin',1,'admin.delete','allow','rbac','enforce',1,'POST','/rbac-smoke/superadmin/admin.delete','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 05:52:24'),(178,'superadmin',1,'gate.scan.rfid','allow','rbac','enforce',1,'POST','/rbac-smoke/superadmin/gate.scan.rfid','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 05:52:24'),(179,'admin',3,'student.delete','allow','rbac','enforce',1,'POST','/rbac-smoke/admin/student.delete','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 05:54:13'),(180,'admin',3,'rfid.register','allow','rbac','enforce',1,'POST','/rbac-smoke/admin/rfid.register','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 05:54:13'),(181,'admin',3,'audit.read','allow','rbac','enforce',1,'POST','/rbac-smoke/admin/audit.read','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 05:54:13'),(182,'admin',3,'audit.export','allow','rbac','enforce',1,'POST','/rbac-smoke/admin/audit.export','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 05:54:13'),(183,'admin',3,'gate.scan.rfid','deny','rbac','enforce',1,'POST','/rbac-smoke/admin/gate.scan.rfid','unknown','{\"rbac_decision\":false,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 05:54:13'),(184,'admin',3,'qr.scan','deny','rbac','enforce',1,'POST','/rbac-smoke/admin/qr.scan','unknown','{\"rbac_decision\":false,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 05:54:13'),(185,'security',NULL,'gate.scan.rfid','allow','rbac','enforce',1,'POST','/rbac-smoke/security/gate.scan.rfid','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 05:54:13'),(186,'security',NULL,'qr.scan','allow','rbac','enforce',1,'POST','/rbac-smoke/security/qr.scan','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 05:54:13'),(187,'security',NULL,'face.verify','allow','rbac','enforce',1,'POST','/rbac-smoke/security/face.verify','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 05:54:13'),(188,'security',NULL,'violation.record','allow','rbac','enforce',1,'POST','/rbac-smoke/security/violation.record','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 05:54:13'),(189,'security',NULL,'student.delete','deny','rbac','enforce',1,'POST','/rbac-smoke/security/student.delete','unknown','{\"rbac_decision\":false,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 05:54:13'),(190,'security',NULL,'audit.export','deny','rbac','enforce',1,'POST','/rbac-smoke/security/audit.export','unknown','{\"rbac_decision\":false,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 05:54:13'),(191,'student',202232903,'student.delete','deny','rbac','enforce',1,'POST','/rbac-smoke/student/student.delete','unknown','{\"rbac_decision\":false,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 05:54:13'),(192,'student',202232903,'audit.read','deny','rbac','enforce',1,'POST','/rbac-smoke/student/audit.read','unknown','{\"rbac_decision\":false,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 05:54:13'),(193,'student',202232903,'violation.record','deny','rbac','enforce',1,'POST','/rbac-smoke/student/violation.record','unknown','{\"rbac_decision\":false,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 05:54:13'),(194,'student',202232903,'gate.scan.rfid','deny','rbac','enforce',1,'POST','/rbac-smoke/student/gate.scan.rfid','unknown','{\"rbac_decision\":false,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 05:54:13'),(195,'superadmin',1,'admin.create','allow','rbac','enforce',1,'POST','/rbac-smoke/superadmin/admin.create','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 05:54:13'),(196,'superadmin',1,'admin.update','allow','rbac','enforce',1,'POST','/rbac-smoke/superadmin/admin.update','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 05:54:13'),(197,'superadmin',1,'admin.delete','allow','rbac','enforce',1,'POST','/rbac-smoke/superadmin/admin.delete','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 05:54:13'),(198,'superadmin',1,'gate.scan.rfid','allow','rbac','enforce',1,'POST','/rbac-smoke/superadmin/gate.scan.rfid','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 05:54:13'),(199,'admin',3,'student.delete','allow','rbac','enforce',1,'POST','/rbac-smoke/admin/student.delete','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 05:56:15'),(200,'admin',3,'rfid.register','allow','rbac','enforce',1,'POST','/rbac-smoke/admin/rfid.register','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 05:56:15'),(201,'admin',3,'audit.read','allow','rbac','enforce',1,'POST','/rbac-smoke/admin/audit.read','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 05:56:15'),(202,'admin',3,'audit.export','allow','rbac','enforce',1,'POST','/rbac-smoke/admin/audit.export','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 05:56:15'),(203,'admin',3,'gate.scan.rfid','deny','rbac','enforce',1,'POST','/rbac-smoke/admin/gate.scan.rfid','unknown','{\"rbac_decision\":false,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 05:56:15'),(204,'admin',3,'qr.scan','deny','rbac','enforce',1,'POST','/rbac-smoke/admin/qr.scan','unknown','{\"rbac_decision\":false,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 05:56:15'),(205,'security',NULL,'gate.scan.rfid','allow','rbac','enforce',1,'POST','/rbac-smoke/security/gate.scan.rfid','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 05:56:15'),(206,'security',NULL,'qr.scan','allow','rbac','enforce',1,'POST','/rbac-smoke/security/qr.scan','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 05:56:15'),(207,'security',NULL,'face.verify','allow','rbac','enforce',1,'POST','/rbac-smoke/security/face.verify','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 05:56:15'),(208,'security',NULL,'violation.record','allow','rbac','enforce',1,'POST','/rbac-smoke/security/violation.record','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 05:56:15'),(209,'security',NULL,'student.delete','deny','rbac','enforce',1,'POST','/rbac-smoke/security/student.delete','unknown','{\"rbac_decision\":false,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 05:56:15'),(210,'security',NULL,'audit.export','deny','rbac','enforce',1,'POST','/rbac-smoke/security/audit.export','unknown','{\"rbac_decision\":false,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 05:56:15'),(211,'student',202232903,'student.delete','deny','rbac','enforce',1,'POST','/rbac-smoke/student/student.delete','unknown','{\"rbac_decision\":false,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 05:56:15'),(212,'student',202232903,'audit.read','deny','rbac','enforce',1,'POST','/rbac-smoke/student/audit.read','unknown','{\"rbac_decision\":false,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 05:56:15'),(213,'student',202232903,'violation.record','deny','rbac','enforce',1,'POST','/rbac-smoke/student/violation.record','unknown','{\"rbac_decision\":false,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 05:56:15'),(214,'student',202232903,'gate.scan.rfid','deny','rbac','enforce',1,'POST','/rbac-smoke/student/gate.scan.rfid','unknown','{\"rbac_decision\":false,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 05:56:15'),(215,'superadmin',1,'admin.create','allow','rbac','enforce',1,'POST','/rbac-smoke/superadmin/admin.create','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 05:56:15'),(216,'superadmin',1,'admin.update','allow','rbac','enforce',1,'POST','/rbac-smoke/superadmin/admin.update','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 05:56:15'),(217,'superadmin',1,'admin.delete','allow','rbac','enforce',1,'POST','/rbac-smoke/superadmin/admin.delete','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 05:56:15'),(218,'superadmin',1,'gate.scan.rfid','allow','rbac','enforce',1,'POST','/rbac-smoke/superadmin/gate.scan.rfid','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 05:56:15'),(219,'admin',3,'student.delete','allow','rbac','enforce',1,'POST','/rbac-smoke/admin/student.delete','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 05:56:36'),(220,'admin',3,'rfid.register','allow','rbac','enforce',1,'POST','/rbac-smoke/admin/rfid.register','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 05:56:36'),(221,'admin',3,'audit.read','allow','rbac','enforce',1,'POST','/rbac-smoke/admin/audit.read','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 05:56:36'),(222,'admin',3,'audit.export','allow','rbac','enforce',1,'POST','/rbac-smoke/admin/audit.export','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 05:56:36'),(223,'admin',3,'gate.scan.rfid','deny','rbac','enforce',1,'POST','/rbac-smoke/admin/gate.scan.rfid','unknown','{\"rbac_decision\":false,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 05:56:36'),(224,'admin',3,'qr.scan','deny','rbac','enforce',1,'POST','/rbac-smoke/admin/qr.scan','unknown','{\"rbac_decision\":false,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 05:56:36'),(225,'security',NULL,'gate.scan.rfid','allow','rbac','enforce',1,'POST','/rbac-smoke/security/gate.scan.rfid','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 05:56:36'),(226,'security',NULL,'qr.scan','allow','rbac','enforce',1,'POST','/rbac-smoke/security/qr.scan','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 05:56:36'),(227,'security',NULL,'face.verify','allow','rbac','enforce',1,'POST','/rbac-smoke/security/face.verify','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 05:56:36'),(228,'security',NULL,'violation.record','allow','rbac','enforce',1,'POST','/rbac-smoke/security/violation.record','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 05:56:36'),(229,'security',NULL,'student.delete','deny','rbac','enforce',1,'POST','/rbac-smoke/security/student.delete','unknown','{\"rbac_decision\":false,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 05:56:36'),(230,'security',NULL,'audit.export','deny','rbac','enforce',1,'POST','/rbac-smoke/security/audit.export','unknown','{\"rbac_decision\":false,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 05:56:36'),(231,'student',202232903,'student.delete','deny','rbac','enforce',1,'POST','/rbac-smoke/student/student.delete','unknown','{\"rbac_decision\":false,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 05:56:36'),(232,'student',202232903,'audit.read','deny','rbac','enforce',1,'POST','/rbac-smoke/student/audit.read','unknown','{\"rbac_decision\":false,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 05:56:36'),(233,'student',202232903,'violation.record','deny','rbac','enforce',1,'POST','/rbac-smoke/student/violation.record','unknown','{\"rbac_decision\":false,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 05:56:36'),(234,'student',202232903,'gate.scan.rfid','deny','rbac','enforce',1,'POST','/rbac-smoke/student/gate.scan.rfid','unknown','{\"rbac_decision\":false,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 05:56:36'),(235,'superadmin',1,'admin.create','allow','rbac','enforce',1,'POST','/rbac-smoke/superadmin/admin.create','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 05:56:36'),(236,'superadmin',1,'admin.update','allow','rbac','enforce',1,'POST','/rbac-smoke/superadmin/admin.update','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 05:56:36'),(237,'superadmin',1,'admin.delete','allow','rbac','enforce',1,'POST','/rbac-smoke/superadmin/admin.delete','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 05:56:36'),(238,'superadmin',1,'gate.scan.rfid','allow','rbac','enforce',1,'POST','/rbac-smoke/superadmin/gate.scan.rfid','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 05:56:36'),(239,'security',NULL,'violation.record','allow','rbac','enforce',1,'GET','/pcurfid2/security/get_violation_categories.php','::1','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 06:00:14'),(240,'security',NULL,'face.verify','allow','rbac','enforce',1,'GET','/pcurfid2/api/get_face_descriptors.php','::1','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 06:00:16'),(241,'security',NULL,'violation.record','allow','rbac','enforce',1,'GET','/pcurfid2/security/get_violation_categories.php','::1','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 06:00:23'),(242,'security',NULL,'face.verify','allow','rbac','enforce',1,'GET','/pcurfid2/api/get_face_descriptors.php','::1','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 06:00:26'),(243,'security',NULL,'gate.scan.rfid','allow','rbac','enforce',1,'POST','/pcurfid2/security/gate_scan.php','::1','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 06:00:40'),(244,'security',NULL,'gate.scan.rfid','allow','rbac','enforce',1,'POST','/pcurfid2/security/gate_scan.php','::1','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 06:01:06'),(245,'admin',3,'student.delete','allow','rbac','enforce',1,'POST','/rbac-smoke/admin/student.delete','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 06:03:05'),(246,'admin',3,'rfid.register','allow','rbac','enforce',1,'POST','/rbac-smoke/admin/rfid.register','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 06:03:05'),(247,'admin',3,'audit.read','allow','rbac','enforce',1,'POST','/rbac-smoke/admin/audit.read','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 06:03:05'),(248,'admin',3,'audit.export','allow','rbac','enforce',1,'POST','/rbac-smoke/admin/audit.export','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 06:03:05'),(249,'admin',3,'gate.scan.rfid','deny','rbac','enforce',1,'POST','/rbac-smoke/admin/gate.scan.rfid','unknown','{\"rbac_decision\":false,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 06:03:05'),(250,'admin',3,'qr.scan','deny','rbac','enforce',1,'POST','/rbac-smoke/admin/qr.scan','unknown','{\"rbac_decision\":false,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 06:03:05'),(251,'security',NULL,'gate.scan.rfid','allow','rbac','enforce',1,'POST','/rbac-smoke/security/gate.scan.rfid','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 06:03:05'),(252,'security',NULL,'qr.scan','allow','rbac','enforce',1,'POST','/rbac-smoke/security/qr.scan','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 06:03:05'),(253,'security',NULL,'face.verify','allow','rbac','enforce',1,'POST','/rbac-smoke/security/face.verify','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 06:03:05'),(254,'security',NULL,'violation.record','allow','rbac','enforce',1,'POST','/rbac-smoke/security/violation.record','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 06:03:05'),(255,'security',NULL,'student.delete','deny','rbac','enforce',1,'POST','/rbac-smoke/security/student.delete','unknown','{\"rbac_decision\":false,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 06:03:05'),(256,'security',NULL,'audit.export','deny','rbac','enforce',1,'POST','/rbac-smoke/security/audit.export','unknown','{\"rbac_decision\":false,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 06:03:05'),(257,'student',202232903,'student.delete','deny','rbac','enforce',1,'POST','/rbac-smoke/student/student.delete','unknown','{\"rbac_decision\":false,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 06:03:05'),(258,'student',202232903,'audit.read','deny','rbac','enforce',1,'POST','/rbac-smoke/student/audit.read','unknown','{\"rbac_decision\":false,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 06:03:05'),(259,'student',202232903,'violation.record','deny','rbac','enforce',1,'POST','/rbac-smoke/student/violation.record','unknown','{\"rbac_decision\":false,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 06:03:05'),(260,'student',202232903,'gate.scan.rfid','deny','rbac','enforce',1,'POST','/rbac-smoke/student/gate.scan.rfid','unknown','{\"rbac_decision\":false,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 06:03:05'),(261,'superadmin',1,'admin.create','allow','rbac','enforce',1,'POST','/rbac-smoke/superadmin/admin.create','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 06:03:05'),(262,'superadmin',1,'admin.update','allow','rbac','enforce',1,'POST','/rbac-smoke/superadmin/admin.update','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 06:03:05'),(263,'superadmin',1,'admin.delete','allow','rbac','enforce',1,'POST','/rbac-smoke/superadmin/admin.delete','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 06:03:05'),(264,'superadmin',1,'gate.scan.rfid','allow','rbac','enforce',1,'POST','/rbac-smoke/superadmin/gate.scan.rfid','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 06:03:05'),(265,'security',NULL,'violation.record','allow','rbac','enforce',1,'GET','/pcurfid2/security/get_violation_categories.php','::1','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 06:03:17'),(266,'security',NULL,'face.verify','allow','rbac','enforce',1,'GET','/pcurfid2/api/get_face_descriptors.php','::1','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 06:03:19'),(267,'security',NULL,'gate.scan.rfid','allow','rbac','enforce',1,'POST','/pcurfid2/security/gate_scan.php','::1','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 06:03:20'),(268,'admin',3,'student.delete','allow','rbac','enforce',1,'POST','/rbac-smoke/admin/student.delete','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 06:07:39'),(269,'admin',3,'rfid.register','allow','rbac','enforce',1,'POST','/rbac-smoke/admin/rfid.register','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 06:07:39'),(270,'admin',3,'audit.read','allow','rbac','enforce',1,'POST','/rbac-smoke/admin/audit.read','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 06:07:39'),(271,'admin',3,'audit.export','allow','rbac','enforce',1,'POST','/rbac-smoke/admin/audit.export','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 06:07:39'),(272,'admin',3,'gate.scan.rfid','deny','rbac','enforce',1,'POST','/rbac-smoke/admin/gate.scan.rfid','unknown','{\"rbac_decision\":false,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 06:07:39'),(273,'admin',3,'qr.scan','deny','rbac','enforce',1,'POST','/rbac-smoke/admin/qr.scan','unknown','{\"rbac_decision\":false,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 06:07:39'),(274,'security',NULL,'gate.scan.rfid','allow','rbac','enforce',1,'POST','/rbac-smoke/security/gate.scan.rfid','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 06:07:39'),(275,'security',NULL,'qr.scan','allow','rbac','enforce',1,'POST','/rbac-smoke/security/qr.scan','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 06:07:39'),(276,'security',NULL,'face.verify','allow','rbac','enforce',1,'POST','/rbac-smoke/security/face.verify','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 06:07:39'),(277,'security',NULL,'violation.record','allow','rbac','enforce',1,'POST','/rbac-smoke/security/violation.record','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 06:07:39'),(278,'security',NULL,'student.delete','deny','rbac','enforce',1,'POST','/rbac-smoke/security/student.delete','unknown','{\"rbac_decision\":false,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 06:07:39'),(279,'security',NULL,'audit.export','deny','rbac','enforce',1,'POST','/rbac-smoke/security/audit.export','unknown','{\"rbac_decision\":false,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 06:07:39'),(280,'student',202232903,'student.delete','deny','rbac','enforce',1,'POST','/rbac-smoke/student/student.delete','unknown','{\"rbac_decision\":false,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 06:07:39'),(281,'student',202232903,'audit.read','deny','rbac','enforce',1,'POST','/rbac-smoke/student/audit.read','unknown','{\"rbac_decision\":false,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 06:07:39'),(282,'student',202232903,'violation.record','deny','rbac','enforce',1,'POST','/rbac-smoke/student/violation.record','unknown','{\"rbac_decision\":false,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 06:07:39'),(283,'student',202232903,'gate.scan.rfid','deny','rbac','enforce',1,'POST','/rbac-smoke/student/gate.scan.rfid','unknown','{\"rbac_decision\":false,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 06:07:39'),(284,'superadmin',1,'admin.create','allow','rbac','enforce',1,'POST','/rbac-smoke/superadmin/admin.create','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 06:07:39'),(285,'superadmin',1,'admin.update','allow','rbac','enforce',1,'POST','/rbac-smoke/superadmin/admin.update','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 06:07:39'),(286,'superadmin',1,'admin.delete','allow','rbac','enforce',1,'POST','/rbac-smoke/superadmin/admin.delete','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 06:07:39'),(287,'superadmin',1,'gate.scan.rfid','allow','rbac','enforce',1,'POST','/rbac-smoke/superadmin/gate.scan.rfid','unknown','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 06:07:39'),(288,'security',NULL,'violation.record','allow','rbac','enforce',1,'GET','/pcurfid2/security/get_violation_categories.php','::1','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 06:07:53'),(289,'security',NULL,'gate.scan.rfid','allow','rbac','enforce',1,'POST','/pcurfid2/security/gate_scan.php','::1','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 06:07:55'),(290,'security',NULL,'face.verify','allow','rbac','enforce',1,'GET','/pcurfid2/api/get_face_descriptors.php','::1','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 06:07:56'),(291,'security',NULL,'violation.record','allow','rbac','enforce',1,'POST','/pcurfid2/security/record_violation.php','::1','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 06:08:40'),(292,'security',NULL,'gate.scan.rfid','allow','rbac','enforce',1,'POST','/pcurfid2/security/gate_scan.php','::1','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 06:08:45'),(293,'security',NULL,'violation.record','allow','rbac','enforce',1,'POST','/pcurfid2/security/record_violation.php','::1','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 06:08:47'),(294,'security',NULL,'gate.scan.rfid','allow','rbac','enforce',1,'POST','/pcurfid2/security/gate_scan.php','::1','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 06:08:49'),(295,'security',NULL,'violation.record','allow','rbac','enforce',1,'POST','/pcurfid2/security/record_violation.php','::1','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 06:08:53'),(296,'admin',3,'audit.read','allow','rbac','enforce',1,'GET','/pcurfid2/admin/manage_violations.php?action=history&user_id=2','::1','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 06:09:01'),(297,'admin',3,'violation.clear','allow','rbac','enforce',1,'POST','/pcurfid2/admin/manage_violations.php','::1','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 06:09:07'),(298,'admin',3,'audit.read','allow','rbac','enforce',1,'GET','/pcurfid2/admin/manage_violations.php?action=history&user_id=2','::1','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 06:09:12'),(299,'admin',3,'violation.clear','allow','rbac','enforce',1,'POST','/pcurfid2/admin/manage_violations.php','::1','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 06:09:15'),(300,'admin',3,'audit.read','allow','rbac','enforce',1,'GET','/pcurfid2/admin/filter_audit_logs.php?action_type=&date_from=&date_to=','::1','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 12:44:17'),(301,'admin',3,'audit.read','allow','rbac','enforce',1,'GET','/pcurfid2/admin/filter_audit_logs.php?action_type=&date_from=&date_to=','::1','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 12:44:22'),(302,'admin',3,'audit.read','allow','rbac','enforce',1,'GET','/pcurfid2/admin/filter_audit_logs.php?action_type=&date_from=&date_to=','::1','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 12:44:27'),(303,'admin',3,'audit.export','allow','rbac','enforce',1,'GET','/pcurfid2/admin/export_audit_logs.php','::1','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":1}','2026-04-06 12:44:31'),(304,'admin',3,'audit.read','allow','rbac','enforce',1,'GET','/pcurfid2/admin/filter_audit_logs.php?action_type=&date_from=&date_to=','::1','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 12:44:32'),(305,'admin',3,'audit.read','allow','rbac','enforce',1,'GET','/pcurfid2/admin/filter_audit_logs.php?action_type=&date_from=&date_to=','::1','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 12:44:37'),(306,'admin',3,'audit.read','allow','rbac','enforce',1,'GET','/pcurfid2/admin/filter_audit_logs.php?action_type=&date_from=&date_to=','::1','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 12:44:42'),(307,'admin',3,'audit.read','allow','rbac','enforce',1,'GET','/pcurfid2/admin/filter_audit_logs.php?action_type=&date_from=&date_to=','::1','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 12:44:47'),(308,'admin',3,'audit.read','allow','rbac','enforce',1,'GET','/pcurfid2/admin/filter_audit_logs.php?action_type=&date_from=&date_to=','::1','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 12:44:52'),(309,'admin',3,'audit.read','allow','rbac','enforce',1,'GET','/pcurfid2/admin/filter_audit_logs.php?action_type=&date_from=&date_to=','::1','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 12:44:57'),(310,'admin',3,'audit.read','allow','rbac','enforce',1,'GET','/pcurfid2/admin/filter_audit_logs.php?action_type=&date_from=&date_to=','::1','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 12:45:02'),(311,'admin',3,'audit.read','allow','rbac','enforce',1,'GET','/pcurfid2/admin/filter_audit_logs.php?action_type=&date_from=&date_to=','::1','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 12:45:07'),(312,'admin',3,'audit.read','allow','rbac','enforce',1,'GET','/pcurfid2/admin/filter_audit_logs.php?action_type=&date_from=&date_to=','::1','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 12:45:12'),(313,'admin',3,'audit.read','allow','rbac','enforce',1,'GET','/pcurfid2/admin/filter_audit_logs.php?action_type=&date_from=&date_to=','::1','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 12:45:15'),(314,'admin',3,'audit.read','allow','rbac','enforce',1,'GET','/pcurfid2/admin/filter_audit_logs.php?action_type=&date_from=&date_to=','::1','{\"rbac_decision\":true,\"enforce_tier\":3,\"permission_tier\":2}','2026-04-06 12:45:17');
/*!40000 ALTER TABLE `permission_audit_log` ENABLE KEYS */;
UNLOCK TABLES;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER trg_permission_audit_log_block_update
BEFORE UPDATE ON permission_audit_log
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'permission_audit_log is immutable: updates are not allowed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER trg_permission_audit_log_block_delete
BEFORE DELETE ON permission_audit_log
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'permission_audit_log is immutable: deletes are not allowed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

--
-- Table structure for table `permissions`
--

DROP TABLE IF EXISTS `permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `permissions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `permission_key` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `enforce_tier` tinyint(3) unsigned NOT NULL DEFAULT 3 COMMENT '1=critical,2=high,3=medium',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_permission_key` (`permission_key`),
  KEY `idx_permissions_tier_active` (`enforce_tier`,`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=46 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `permissions`
--

LOCK TABLES `permissions` WRITE;
/*!40000 ALTER TABLE `permissions` DISABLE KEYS */;
INSERT INTO `permissions` VALUES (1,'student.profile.view','View own student profile',3,1,'2026-04-06 04:48:46','2026-04-06 04:48:46'),(2,'student.profile.update','Update own student profile',3,1,'2026-04-06 04:48:46','2026-04-06 04:48:46'),(3,'student.violations.read_own','View own violations',3,1,'2026-04-06 04:48:46','2026-04-06 04:48:46'),(4,'student.digital_id.view','View own digital ID',3,1,'2026-04-06 04:48:46','2026-04-06 04:48:46'),(5,'student.verify','Approve or deny pending student account',1,1,'2026-04-06 04:48:46','2026-04-06 04:48:46'),(6,'student.update','Update student records',1,1,'2026-04-06 04:48:46','2026-04-06 04:48:46'),(7,'student.delete','Delete student account',1,1,'2026-04-06 04:48:46','2026-04-06 04:48:46'),(8,'rfid.register','Register RFID to student',1,1,'2026-04-06 04:48:46','2026-04-06 04:48:46'),(9,'rfid.unregister','Unregister RFID from student',1,1,'2026-04-06 04:48:46','2026-04-06 04:48:46'),(10,'rfid.mark_lost','Mark RFID as lost or found',1,1,'2026-04-06 04:48:46','2026-04-06 04:48:46'),(11,'face.register','Register face descriptors',1,1,'2026-04-06 04:48:46','2026-04-06 04:48:46'),(12,'face.delete','Delete face descriptors',1,1,'2026-04-06 04:48:46','2026-04-06 04:48:46'),(13,'face.verify','Verify face matching at gate',2,1,'2026-04-06 04:48:46','2026-04-06 04:48:46'),(14,'violation.record','Record student violation',1,1,'2026-04-06 04:48:46','2026-04-06 04:48:46'),(15,'violation.clear','Resolve or clear student violations',1,1,'2026-04-06 04:48:46','2026-04-06 04:48:46'),(16,'audit.read','Read audit logs',2,1,'2026-04-06 04:48:46','2026-04-06 04:48:46'),(17,'audit.export','Export audit logs',1,1,'2026-04-06 04:48:46','2026-04-06 04:48:46'),(18,'admin.create','Create admin account',1,1,'2026-04-06 04:48:46','2026-04-06 04:48:46'),(19,'admin.update','Update admin account',1,1,'2026-04-06 04:48:46','2026-04-06 04:48:46'),(20,'admin.delete','Delete admin account',1,1,'2026-04-06 04:48:46','2026-04-06 04:48:46'),(21,'qr.scan','Scan and validate QR code',2,1,'2026-04-06 04:48:46','2026-04-06 04:48:46'),(22,'gate.scan.rfid','Scan and validate RFID at gate',2,1,'2026-04-06 04:48:46','2026-04-06 04:48:46');
/*!40000 ALTER TABLE `permissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `qr_entry_logs`
--

DROP TABLE IF EXISTS `qr_entry_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `qr_entry_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `student_id` varchar(50) NOT NULL,
  `entry_type` enum('QR_CODE','RFID') DEFAULT 'QR_CODE',
  `scanned_at` datetime NOT NULL,
  `security_guard` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_student_id` (`student_id`),
  KEY `idx_scanned_at` (`scanned_at`),
  KEY `idx_qel_user_scanned` (`user_id`,`scanned_at`),
  CONSTRAINT `qr_entry_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `qr_entry_logs`
--

LOCK TABLES `qr_entry_logs` WRITE;
/*!40000 ALTER TABLE `qr_entry_logs` DISABLE KEYS */;
INSERT INTO `qr_entry_logs` VALUES (1,2,'202232903','QR_CODE','2026-03-23 08:38:09','E2E Test Guard','2026-03-23 00:38:09'),(2,2,'202232903','QR_CODE','2026-03-23 08:47:37','jeysidelima04@gmail.com','2026-03-23 00:47:37'),(3,2,'202232903','QR_CODE','2026-03-23 08:48:09','jeysidelima04@gmail.com','2026-03-23 00:48:09'),(4,2,'202232903','QR_CODE','2026-03-23 08:48:37','jeysidelima04@gmail.com','2026-03-23 00:48:37'),(5,2,'202232903','QR_CODE','2026-03-23 09:07:58','jeysidelima04@gmail.com','2026-03-23 01:07:58'),(6,2,'202232903','QR_CODE','2026-03-23 09:08:02','jeysidelima04@gmail.com','2026-03-23 01:08:02'),(7,2,'202232903','QR_CODE','2026-03-23 09:08:07','jeysidelima04@gmail.com','2026-03-23 01:08:07'),(8,2,'202232903','QR_CODE','2026-03-23 09:08:15','jeysidelima04@gmail.com','2026-03-23 01:08:15'),(9,2,'202232903','','2026-03-23 13:16:25','jeysidelima04@gmail.com','2026-03-23 05:16:25'),(10,2,'202232903','','2026-03-23 13:25:15','jeysidelima04@gmail.com','2026-03-23 05:25:15'),(11,2,'202232903','','2026-03-23 13:25:52','jeysidelima04@gmail.com','2026-03-23 05:25:52');
/*!40000 ALTER TABLE `qr_entry_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `qr_face_pending`
--

DROP TABLE IF EXISTS `qr_face_pending`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `qr_face_pending` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `guard_session_hash` char(64) NOT NULL,
  `guard_username` varchar(100) DEFAULT NULL,
  `challenge_id` varchar(64) NOT NULL,
  `token_hash` char(64) NOT NULL,
  `user_id` int(11) NOT NULL,
  `student_id` varchar(50) NOT NULL,
  `status` enum('pending','verified','rejected','expired') NOT NULL DEFAULT 'pending',
  `reject_reason` varchar(80) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `expires_at` datetime NOT NULL,
  `resolved_at` datetime DEFAULT NULL,
  `resolved_by_user_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_qr_pending_guard_status` (`guard_session_hash`,`status`),
  KEY `idx_qr_pending_expires` (`status`,`expires_at`),
  KEY `idx_qr_pending_token` (`token_hash`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `qr_face_pending`
--

LOCK TABLES `qr_face_pending` WRITE;
/*!40000 ALTER TABLE `qr_face_pending` DISABLE KEYS */;
INSERT INTO `qr_face_pending` VALUES (1,'da27a207c0d2c9dfd1fa062e82e4a5056ec4dc223ef65ca05604da9e7d3b0ba1','jeysidelima04@gmail.com','a25b6274d5cc134b3c6610b3f51d3ba8','3057305ebe63f675eea07b3128704b86ceb55c0c2038fb6ecf6d03ca43ed1967',2,'202232903','expired',NULL,'2026-03-23 05:00:23','2026-03-23 06:00:43','2026-03-23 13:00:43',NULL),(2,'da27a207c0d2c9dfd1fa062e82e4a5056ec4dc223ef65ca05604da9e7d3b0ba1','jeysidelima04@gmail.com','e7bf57ea0ad904e43a12d0f2684d5f2e','25318dd071780244354aff6ab7d3ff9f34514fe94724e26777cd0863cae361a6',2,'202232903','expired',NULL,'2026-03-23 05:03:16','2026-03-23 06:03:36','2026-03-23 13:03:37',NULL),(3,'791656d672da85bb1aca2a89909cc1648c274fea485d75741a2810d44bd8bae2','jeysidelima04@gmail.com','7785727dba6a4d4b6ae08551c4bb8cc6','f496d8004c7ad2352cf3e8f673742a81ffbe2bb5a47f80bdca6a0757cc70c03d',2,'202232903','verified',NULL,'2026-03-23 05:16:13','2026-03-23 06:16:33','2026-03-23 13:16:25',2),(4,'791656d672da85bb1aca2a89909cc1648c274fea485d75741a2810d44bd8bae2','jeysidelima04@gmail.com','89aa213228714bf148fca9d3688bc775','45ac5088dbed7319dbadcd8085ab865fcbad6e039f7af30452029779ebee388d',2,'202232903','expired',NULL,'2026-03-23 05:17:08','2026-03-23 06:17:28','2026-03-23 13:17:28',NULL),(5,'791656d672da85bb1aca2a89909cc1648c274fea485d75741a2810d44bd8bae2','jeysidelima04@gmail.com','94f1b12d2256f3eacbb7226e58c78cf4','c5c511c4840d82a102c21d0d769760d10be7f664abf6ce8425d07e899436272e',2,'202232903','expired',NULL,'2026-03-23 05:18:09','2026-03-23 06:18:29','2026-03-23 13:18:29',NULL),(6,'791656d672da85bb1aca2a89909cc1648c274fea485d75741a2810d44bd8bae2','jeysidelima04@gmail.com','2362545fefe8329e22e66a3ac8a03644','4b8fdb42bdccdf8241cc2091eb1350ceeda2d6efe19015b60a74de0ce79cabd6',2,'202232903','expired',NULL,'2026-03-23 05:18:46','2026-03-23 06:19:06','2026-03-23 13:19:07',NULL),(7,'671ceeb7547925ef22303301d4b15b76b04fe178fa18d346afd127a724b443e0','jeysidelima04@gmail.com','811ce6719443f08ac945caea4c1bd940','551b32a9ccb8d2ac687529a8f4ec9443ccdf7bd882166e68abf30ddbf7019897',2,'202232903','verified',NULL,'2026-03-23 05:25:07','2026-03-23 06:25:27','2026-03-23 13:25:15',2),(8,'671ceeb7547925ef22303301d4b15b76b04fe178fa18d346afd127a724b443e0','jeysidelima04@gmail.com','d2e350d0dcee2487a69bee2480539972','8fcae1ba2b6c4c1e8c76da42d767a81f27d6a3e69ca91ad67b8e3d89367de1ea',2,'202232903','verified',NULL,'2026-03-23 05:25:48','2026-03-23 06:26:08','2026-03-23 13:25:52',2);
/*!40000 ALTER TABLE `qr_face_pending` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `qr_scan_challenges`
--

DROP TABLE IF EXISTS `qr_scan_challenges`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `qr_scan_challenges` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `challenge_id` varchar(64) NOT NULL,
  `guard_session_hash` char(64) NOT NULL,
  `guard_username` varchar(100) DEFAULT NULL,
  `status` enum('active','consumed','expired') NOT NULL DEFAULT 'active',
  `expires_at` datetime NOT NULL,
  `consumed_at` datetime DEFAULT NULL,
  `consumed_by_user_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `challenge_id` (`challenge_id`),
  KEY `idx_qr_challenge_status_expires` (`status`,`expires_at`),
  KEY `idx_qr_challenge_guard_session` (`guard_session_hash`,`status`)
) ENGINE=InnoDB AUTO_INCREMENT=46 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `qr_scan_challenges`
--

LOCK TABLES `qr_scan_challenges` WRITE;
/*!40000 ALTER TABLE `qr_scan_challenges` DISABLE KEYS */;
INSERT INTO `qr_scan_challenges` VALUES (1,'e85b04857d08b79512248b764c9006cf','ced07f516cdb64456491fd9686cbb051dc2014804b4882b1b2c6cd2d59f28276','jeysidelima04@gmail.com','expired','2026-03-23 04:49:44',NULL,NULL,'2026-03-23 03:49:29'),(2,'85398a8b52c81f209bef9024c730e3a8','ced07f516cdb64456491fd9686cbb051dc2014804b4882b1b2c6cd2d59f28276','jeysidelima04@gmail.com','expired','2026-03-23 04:50:13',NULL,NULL,'2026-03-23 03:49:58'),(3,'119da4b0cfcd002bb16d1e99ac3fdb92','ced07f516cdb64456491fd9686cbb051dc2014804b4882b1b2c6cd2d59f28276','jeysidelima04@gmail.com','expired','2026-03-23 04:50:13',NULL,NULL,'2026-03-23 03:49:58'),(4,'d552df3ec66c652555f7d8975c800957','ced07f516cdb64456491fd9686cbb051dc2014804b4882b1b2c6cd2d59f28276','jeysidelima04@gmail.com','expired','2026-03-23 04:50:16',NULL,NULL,'2026-03-23 03:50:01'),(5,'340be5327ec80839b765ec38142812be','ced07f516cdb64456491fd9686cbb051dc2014804b4882b1b2c6cd2d59f28276','jeysidelima04@gmail.com','expired','2026-03-23 04:50:21',NULL,NULL,'2026-03-23 03:50:06'),(6,'206297c969ab44faee6e93d163e305e9','ced07f516cdb64456491fd9686cbb051dc2014804b4882b1b2c6cd2d59f28276','jeysidelima04@gmail.com','expired','2026-03-23 04:50:23',NULL,NULL,'2026-03-23 03:50:08'),(7,'f997b602021be25cd585532614104892','ced07f516cdb64456491fd9686cbb051dc2014804b4882b1b2c6cd2d59f28276','jeysidelima04@gmail.com','expired','2026-03-23 04:50:25',NULL,NULL,'2026-03-23 03:50:10'),(8,'7d17fc8a0290a160f9579cd23a7fd184','ced07f516cdb64456491fd9686cbb051dc2014804b4882b1b2c6cd2d59f28276','jeysidelima04@gmail.com','expired','2026-03-23 04:50:32',NULL,NULL,'2026-03-23 03:50:17'),(9,'6070fbe1da8ccdced853e93319adde77','ced07f516cdb64456491fd9686cbb051dc2014804b4882b1b2c6cd2d59f28276','jeysidelima04@gmail.com','expired','2026-03-23 04:50:36',NULL,NULL,'2026-03-23 03:50:21'),(10,'306aab0302c5b21086c48c94ae27f0ae','ced07f516cdb64456491fd9686cbb051dc2014804b4882b1b2c6cd2d59f28276','jeysidelima04@gmail.com','expired','2026-03-23 04:50:50',NULL,NULL,'2026-03-23 03:50:35'),(11,'395d575e8bad39f05bd27c95d31a9912','ced07f516cdb64456491fd9686cbb051dc2014804b4882b1b2c6cd2d59f28276','jeysidelima04@gmail.com','expired','2026-03-23 04:50:51',NULL,NULL,'2026-03-23 03:50:36'),(12,'306e86c921673ba1b82b17f323aa868d','ced07f516cdb64456491fd9686cbb051dc2014804b4882b1b2c6cd2d59f28276','jeysidelima04@gmail.com','expired','2026-03-23 04:50:54',NULL,NULL,'2026-03-23 03:50:39'),(13,'3fa4a178d5674c46547410169e0f70b5','ced07f516cdb64456491fd9686cbb051dc2014804b4882b1b2c6cd2d59f28276','jeysidelima04@gmail.com','expired','2026-03-23 04:50:57',NULL,NULL,'2026-03-23 03:50:42'),(14,'6bf409703b39301d1c1cfae19dcab355','ced07f516cdb64456491fd9686cbb051dc2014804b4882b1b2c6cd2d59f28276','jeysidelima04@gmail.com','expired','2026-03-23 04:50:59',NULL,NULL,'2026-03-23 03:50:44'),(15,'44f17c351350b41122b494f901baeb58','ced07f516cdb64456491fd9686cbb051dc2014804b4882b1b2c6cd2d59f28276','jeysidelima04@gmail.com','expired','2026-03-23 04:51:36',NULL,NULL,'2026-03-23 03:51:21'),(16,'83b4e3958a68160e80236fdcc507f8bc','ced07f516cdb64456491fd9686cbb051dc2014804b4882b1b2c6cd2d59f28276','jeysidelima04@gmail.com','expired','2026-03-23 04:51:42',NULL,NULL,'2026-03-23 03:51:27'),(17,'b3aa0cd01415b5746229d2d70d38346a','ced07f516cdb64456491fd9686cbb051dc2014804b4882b1b2c6cd2d59f28276','jeysidelima04@gmail.com','expired','2026-03-23 04:51:50',NULL,NULL,'2026-03-23 03:51:35'),(18,'503cd7ffee2616209622c37c6fed586a','ced07f516cdb64456491fd9686cbb051dc2014804b4882b1b2c6cd2d59f28276','jeysidelima04@gmail.com','expired','2026-03-23 04:51:54',NULL,NULL,'2026-03-23 03:51:39'),(19,'a3c563a5cc184b373571c69cd5c04c84','ced07f516cdb64456491fd9686cbb051dc2014804b4882b1b2c6cd2d59f28276','jeysidelima04@gmail.com','expired','2026-03-23 04:51:58',NULL,NULL,'2026-03-23 03:51:43'),(20,'36a4e4cfd20c9aafea5322020c70864e','ced07f516cdb64456491fd9686cbb051dc2014804b4882b1b2c6cd2d59f28276','jeysidelima04@gmail.com','expired','2026-03-23 04:52:02',NULL,NULL,'2026-03-23 03:51:47'),(21,'a30f9943f715fd02b43e90cd1fa35890','aff04d8a5ea667000e7ed75565c7358ed89397d2e197783fc5869ff8eee36c35','jeysidelima04@gmail.com','expired','2026-03-23 05:46:22',NULL,NULL,'2026-03-23 04:46:07'),(22,'1f6bcdaa2ecd7f7db2f6b08d634f3957','aff04d8a5ea667000e7ed75565c7358ed89397d2e197783fc5869ff8eee36c35','jeysidelima04@gmail.com','expired','2026-03-23 05:46:32',NULL,NULL,'2026-03-23 04:46:17'),(23,'84e9366cee3a6e70d7985dd8dceeb134','aff04d8a5ea667000e7ed75565c7358ed89397d2e197783fc5869ff8eee36c35','jeysidelima04@gmail.com','expired','2026-03-23 05:46:34',NULL,NULL,'2026-03-23 04:46:19'),(24,'daadebb5193ea4df804b68f063137eea','aff04d8a5ea667000e7ed75565c7358ed89397d2e197783fc5869ff8eee36c35','jeysidelima04@gmail.com','expired','2026-03-23 05:46:37',NULL,NULL,'2026-03-23 04:46:22'),(25,'39a64a11491aa96b3ade7b3e159f7619','da27a207c0d2c9dfd1fa062e82e4a5056ec4dc223ef65ca05604da9e7d3b0ba1','jeysidelima04@gmail.com','expired','2026-03-23 06:00:29',NULL,NULL,'2026-03-23 05:00:14'),(26,'dc74c6554abf3a52f2c2e30365330f4c','da27a207c0d2c9dfd1fa062e82e4a5056ec4dc223ef65ca05604da9e7d3b0ba1','jeysidelima04@gmail.com','expired','2026-03-23 06:00:34',NULL,NULL,'2026-03-23 05:00:19'),(27,'a25b6274d5cc134b3c6610b3f51d3ba8','da27a207c0d2c9dfd1fa062e82e4a5056ec4dc223ef65ca05604da9e7d3b0ba1','jeysidelima04@gmail.com','consumed','2026-03-23 06:00:37','2026-03-23 13:00:23',2,'2026-03-23 05:00:22'),(28,'11df454d2077774cf82d8b44fd659e97','da27a207c0d2c9dfd1fa062e82e4a5056ec4dc223ef65ca05604da9e7d3b0ba1','jeysidelima04@gmail.com','expired','2026-03-23 06:03:22',NULL,NULL,'2026-03-23 05:03:07'),(29,'e7bf57ea0ad904e43a12d0f2684d5f2e','da27a207c0d2c9dfd1fa062e82e4a5056ec4dc223ef65ca05604da9e7d3b0ba1','jeysidelima04@gmail.com','consumed','2026-03-23 06:03:27','2026-03-23 13:03:16',2,'2026-03-23 05:03:12'),(30,'a1186819c0266d7f505616f0b4c0a8e1','791656d672da85bb1aca2a89909cc1648c274fea485d75741a2810d44bd8bae2','jeysidelima04@gmail.com','expired','2026-03-23 06:16:12',NULL,NULL,'2026-03-23 05:15:57'),(31,'c7f46159895f87feb9111c8c8e28c19a','791656d672da85bb1aca2a89909cc1648c274fea485d75741a2810d44bd8bae2','jeysidelima04@gmail.com','expired','2026-03-23 06:16:22',NULL,NULL,'2026-03-23 05:16:07'),(32,'7785727dba6a4d4b6ae08551c4bb8cc6','791656d672da85bb1aca2a89909cc1648c274fea485d75741a2810d44bd8bae2','jeysidelima04@gmail.com','consumed','2026-03-23 06:16:25','2026-03-23 13:16:13',2,'2026-03-23 05:16:10'),(33,'d987f372440ce0d92d1520b12daf3fe3','791656d672da85bb1aca2a89909cc1648c274fea485d75741a2810d44bd8bae2','jeysidelima04@gmail.com','expired','2026-03-23 06:16:40',NULL,NULL,'2026-03-23 05:16:25'),(34,'d18b0a31e931e55ab0bb54681e178395','791656d672da85bb1aca2a89909cc1648c274fea485d75741a2810d44bd8bae2','jeysidelima04@gmail.com','expired','2026-03-23 06:17:00',NULL,NULL,'2026-03-23 05:16:45'),(35,'25ddd0d4bd2b766bf3219a9e05f85cf4','791656d672da85bb1aca2a89909cc1648c274fea485d75741a2810d44bd8bae2','jeysidelima04@gmail.com','expired','2026-03-23 06:17:11',NULL,NULL,'2026-03-23 05:16:56'),(36,'89aa213228714bf148fca9d3688bc775','791656d672da85bb1aca2a89909cc1648c274fea485d75741a2810d44bd8bae2','jeysidelima04@gmail.com','consumed','2026-03-23 06:17:16','2026-03-23 13:17:08',2,'2026-03-23 05:17:01'),(37,'2250d0c19399f7faa5c5a9a30c977684','791656d672da85bb1aca2a89909cc1648c274fea485d75741a2810d44bd8bae2','jeysidelima04@gmail.com','expired','2026-03-23 06:18:17',NULL,NULL,'2026-03-23 05:18:02'),(38,'94f1b12d2256f3eacbb7226e58c78cf4','791656d672da85bb1aca2a89909cc1648c274fea485d75741a2810d44bd8bae2','jeysidelima04@gmail.com','consumed','2026-03-23 06:18:21','2026-03-23 13:18:09',2,'2026-03-23 05:18:06'),(39,'2362545fefe8329e22e66a3ac8a03644','791656d672da85bb1aca2a89909cc1648c274fea485d75741a2810d44bd8bae2','jeysidelima04@gmail.com','consumed','2026-03-23 06:18:53','2026-03-23 13:18:46',2,'2026-03-23 05:18:38'),(40,'3f78006122d89ccefbee8d730ea0b54e','671ceeb7547925ef22303301d4b15b76b04fe178fa18d346afd127a724b443e0','jeysidelima04@gmail.com','expired','2026-03-23 06:25:14',NULL,NULL,'2026-03-23 05:24:59'),(41,'811ce6719443f08ac945caea4c1bd940','671ceeb7547925ef22303301d4b15b76b04fe178fa18d346afd127a724b443e0','jeysidelima04@gmail.com','consumed','2026-03-23 06:25:19','2026-03-23 13:25:07',2,'2026-03-23 05:25:04'),(42,'46dc4ae786990a10291a76e30f502b00','671ceeb7547925ef22303301d4b15b76b04fe178fa18d346afd127a724b443e0','jeysidelima04@gmail.com','expired','2026-03-23 06:25:30',NULL,NULL,'2026-03-23 05:25:15'),(43,'d6d5a3533e0147650dca4b6c4809fd3b','671ceeb7547925ef22303301d4b15b76b04fe178fa18d346afd127a724b443e0','jeysidelima04@gmail.com','expired','2026-03-23 06:25:57',NULL,NULL,'2026-03-23 05:25:42'),(44,'d2e350d0dcee2487a69bee2480539972','671ceeb7547925ef22303301d4b15b76b04fe178fa18d346afd127a724b443e0','jeysidelima04@gmail.com','consumed','2026-03-23 06:26:00','2026-03-23 13:25:48',2,'2026-03-23 05:25:45'),(45,'7801a9c62ac6a174d7e1d8ca5e598f53','671ceeb7547925ef22303301d4b15b76b04fe178fa18d346afd127a724b443e0','jeysidelima04@gmail.com','expired','2026-03-23 06:26:07',NULL,NULL,'2026-03-23 05:25:52');
/*!40000 ALTER TABLE `qr_scan_challenges` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `qr_security_events`
--

DROP TABLE IF EXISTS `qr_security_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `qr_security_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `event_type` varchar(64) NOT NULL,
  `guard_session_hash` char(64) DEFAULT NULL,
  `guard_username` varchar(100) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `student_id` varchar(50) DEFAULT NULL,
  `challenge_id` varchar(64) DEFAULT NULL,
  `token_hash` char(64) DEFAULT NULL,
  `details_json` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_qr_events_type_time` (`event_type`,`created_at`),
  KEY `idx_qr_events_guard` (`guard_session_hash`,`created_at`),
  KEY `idx_qr_events_user` (`user_id`,`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=100 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `qr_security_events`
--

LOCK TABLES `qr_security_events` WRITE;
/*!40000 ALTER TABLE `qr_security_events` DISABLE KEYS */;
INSERT INTO `qr_security_events` VALUES (1,'challenge_created','ced07f516cdb64456491fd9686cbb051dc2014804b4882b1b2c6cd2d59f28276','jeysidelima04@gmail.com',NULL,NULL,'e85b04857d08b79512248b764c9006cf',NULL,'{\"expires_at\":\"2026-03-23 04:49:44\",\"ttl_seconds\":15}','2026-03-23 03:49:29'),(2,'challenge_created','ced07f516cdb64456491fd9686cbb051dc2014804b4882b1b2c6cd2d59f28276','jeysidelima04@gmail.com',NULL,NULL,'85398a8b52c81f209bef9024c730e3a8',NULL,'{\"expires_at\":\"2026-03-23 04:50:13\",\"ttl_seconds\":15}','2026-03-23 03:49:58'),(3,'qr_challenge_required','ced07f516cdb64456491fd9686cbb051dc2014804b4882b1b2c6cd2d59f28276','jeysidelima04@gmail.com',NULL,NULL,'85398a8b52c81f209bef9024c730e3a8','917ff9d8295ba859df28efbbec7ad7eb177701246abb98d13d5cde545e6cea95',NULL,'2026-03-23 03:49:58'),(4,'challenge_created','ced07f516cdb64456491fd9686cbb051dc2014804b4882b1b2c6cd2d59f28276','jeysidelima04@gmail.com',NULL,NULL,'119da4b0cfcd002bb16d1e99ac3fdb92',NULL,'{\"expires_at\":\"2026-03-23 04:50:13\",\"ttl_seconds\":15}','2026-03-23 03:49:58'),(5,'qr_challenge_required','ced07f516cdb64456491fd9686cbb051dc2014804b4882b1b2c6cd2d59f28276','jeysidelima04@gmail.com',NULL,NULL,'119da4b0cfcd002bb16d1e99ac3fdb92','917ff9d8295ba859df28efbbec7ad7eb177701246abb98d13d5cde545e6cea95',NULL,'2026-03-23 03:50:01'),(6,'challenge_created','ced07f516cdb64456491fd9686cbb051dc2014804b4882b1b2c6cd2d59f28276','jeysidelima04@gmail.com',NULL,NULL,'d552df3ec66c652555f7d8975c800957',NULL,'{\"expires_at\":\"2026-03-23 04:50:16\",\"ttl_seconds\":15}','2026-03-23 03:50:01'),(7,'qr_challenge_required','ced07f516cdb64456491fd9686cbb051dc2014804b4882b1b2c6cd2d59f28276','jeysidelima04@gmail.com',NULL,NULL,'d552df3ec66c652555f7d8975c800957','917ff9d8295ba859df28efbbec7ad7eb177701246abb98d13d5cde545e6cea95',NULL,'2026-03-23 03:50:06'),(8,'challenge_created','ced07f516cdb64456491fd9686cbb051dc2014804b4882b1b2c6cd2d59f28276','jeysidelima04@gmail.com',NULL,NULL,'340be5327ec80839b765ec38142812be',NULL,'{\"expires_at\":\"2026-03-23 04:50:21\",\"ttl_seconds\":15}','2026-03-23 03:50:06'),(9,'qr_challenge_required','ced07f516cdb64456491fd9686cbb051dc2014804b4882b1b2c6cd2d59f28276','jeysidelima04@gmail.com',NULL,NULL,'340be5327ec80839b765ec38142812be','9182de224efbee92ffc7f8851980b2d221ab7555c120956629ea40fae0b2aa80',NULL,'2026-03-23 03:50:08'),(10,'challenge_created','ced07f516cdb64456491fd9686cbb051dc2014804b4882b1b2c6cd2d59f28276','jeysidelima04@gmail.com',NULL,NULL,'206297c969ab44faee6e93d163e305e9',NULL,'{\"expires_at\":\"2026-03-23 04:50:23\",\"ttl_seconds\":15}','2026-03-23 03:50:08'),(11,'qr_challenge_required','ced07f516cdb64456491fd9686cbb051dc2014804b4882b1b2c6cd2d59f28276','jeysidelima04@gmail.com',NULL,NULL,'206297c969ab44faee6e93d163e305e9','4c9d5f66a9dd651a5fbdae60694183627c1318c87e2fa352c35455576cf078e6',NULL,'2026-03-23 03:50:10'),(12,'challenge_created','ced07f516cdb64456491fd9686cbb051dc2014804b4882b1b2c6cd2d59f28276','jeysidelima04@gmail.com',NULL,NULL,'f997b602021be25cd585532614104892',NULL,'{\"expires_at\":\"2026-03-23 04:50:25\",\"ttl_seconds\":15}','2026-03-23 03:50:10'),(13,'qr_challenge_required','ced07f516cdb64456491fd9686cbb051dc2014804b4882b1b2c6cd2d59f28276','jeysidelima04@gmail.com',NULL,NULL,'f997b602021be25cd585532614104892','c533bcb62ac8f4c0f6db65fdf5e7a34214b2ce06409c98b6100fa0e6b43c6bf2',NULL,'2026-03-23 03:50:17'),(14,'challenge_created','ced07f516cdb64456491fd9686cbb051dc2014804b4882b1b2c6cd2d59f28276','jeysidelima04@gmail.com',NULL,NULL,'7d17fc8a0290a160f9579cd23a7fd184',NULL,'{\"expires_at\":\"2026-03-23 04:50:32\",\"ttl_seconds\":15}','2026-03-23 03:50:17'),(15,'qr_challenge_required','ced07f516cdb64456491fd9686cbb051dc2014804b4882b1b2c6cd2d59f28276','jeysidelima04@gmail.com',NULL,NULL,'7d17fc8a0290a160f9579cd23a7fd184','c533bcb62ac8f4c0f6db65fdf5e7a34214b2ce06409c98b6100fa0e6b43c6bf2',NULL,'2026-03-23 03:50:21'),(16,'challenge_created','ced07f516cdb64456491fd9686cbb051dc2014804b4882b1b2c6cd2d59f28276','jeysidelima04@gmail.com',NULL,NULL,'6070fbe1da8ccdced853e93319adde77',NULL,'{\"expires_at\":\"2026-03-23 04:50:36\",\"ttl_seconds\":15}','2026-03-23 03:50:21'),(17,'challenge_created','ced07f516cdb64456491fd9686cbb051dc2014804b4882b1b2c6cd2d59f28276','jeysidelima04@gmail.com',NULL,NULL,'306aab0302c5b21086c48c94ae27f0ae',NULL,'{\"expires_at\":\"2026-03-23 04:50:50\",\"ttl_seconds\":15}','2026-03-23 03:50:35'),(18,'qr_challenge_required','ced07f516cdb64456491fd9686cbb051dc2014804b4882b1b2c6cd2d59f28276','jeysidelima04@gmail.com',NULL,NULL,'306aab0302c5b21086c48c94ae27f0ae','c533bcb62ac8f4c0f6db65fdf5e7a34214b2ce06409c98b6100fa0e6b43c6bf2',NULL,'2026-03-23 03:50:36'),(19,'challenge_created','ced07f516cdb64456491fd9686cbb051dc2014804b4882b1b2c6cd2d59f28276','jeysidelima04@gmail.com',NULL,NULL,'395d575e8bad39f05bd27c95d31a9912',NULL,'{\"expires_at\":\"2026-03-23 04:50:51\",\"ttl_seconds\":15}','2026-03-23 03:50:36'),(20,'qr_challenge_required','ced07f516cdb64456491fd9686cbb051dc2014804b4882b1b2c6cd2d59f28276','jeysidelima04@gmail.com',NULL,NULL,'395d575e8bad39f05bd27c95d31a9912','c533bcb62ac8f4c0f6db65fdf5e7a34214b2ce06409c98b6100fa0e6b43c6bf2',NULL,'2026-03-23 03:50:39'),(21,'challenge_created','ced07f516cdb64456491fd9686cbb051dc2014804b4882b1b2c6cd2d59f28276','jeysidelima04@gmail.com',NULL,NULL,'306e86c921673ba1b82b17f323aa868d',NULL,'{\"expires_at\":\"2026-03-23 04:50:54\",\"ttl_seconds\":15}','2026-03-23 03:50:39'),(22,'qr_challenge_required','ced07f516cdb64456491fd9686cbb051dc2014804b4882b1b2c6cd2d59f28276','jeysidelima04@gmail.com',NULL,NULL,'306e86c921673ba1b82b17f323aa868d','c533bcb62ac8f4c0f6db65fdf5e7a34214b2ce06409c98b6100fa0e6b43c6bf2',NULL,'2026-03-23 03:50:42'),(23,'challenge_created','ced07f516cdb64456491fd9686cbb051dc2014804b4882b1b2c6cd2d59f28276','jeysidelima04@gmail.com',NULL,NULL,'3fa4a178d5674c46547410169e0f70b5',NULL,'{\"expires_at\":\"2026-03-23 04:50:57\",\"ttl_seconds\":15}','2026-03-23 03:50:42'),(24,'qr_challenge_required','ced07f516cdb64456491fd9686cbb051dc2014804b4882b1b2c6cd2d59f28276','jeysidelima04@gmail.com',NULL,NULL,'3fa4a178d5674c46547410169e0f70b5','86157b74b1b107033fe97fe7fc5eba9d3adb55d6490dc2f4df89f74c61216bf6',NULL,'2026-03-23 03:50:44'),(25,'challenge_created','ced07f516cdb64456491fd9686cbb051dc2014804b4882b1b2c6cd2d59f28276','jeysidelima04@gmail.com',NULL,NULL,'6bf409703b39301d1c1cfae19dcab355',NULL,'{\"expires_at\":\"2026-03-23 04:50:59\",\"ttl_seconds\":15}','2026-03-23 03:50:44'),(26,'challenge_created','ced07f516cdb64456491fd9686cbb051dc2014804b4882b1b2c6cd2d59f28276','jeysidelima04@gmail.com',NULL,NULL,'44f17c351350b41122b494f901baeb58',NULL,'{\"expires_at\":\"2026-03-23 04:51:36\",\"ttl_seconds\":15}','2026-03-23 03:51:21'),(27,'qr_challenge_required','ced07f516cdb64456491fd9686cbb051dc2014804b4882b1b2c6cd2d59f28276','jeysidelima04@gmail.com',NULL,NULL,'44f17c351350b41122b494f901baeb58','86157b74b1b107033fe97fe7fc5eba9d3adb55d6490dc2f4df89f74c61216bf6',NULL,'2026-03-23 03:51:27'),(28,'challenge_created','ced07f516cdb64456491fd9686cbb051dc2014804b4882b1b2c6cd2d59f28276','jeysidelima04@gmail.com',NULL,NULL,'83b4e3958a68160e80236fdcc507f8bc',NULL,'{\"expires_at\":\"2026-03-23 04:51:42\",\"ttl_seconds\":15}','2026-03-23 03:51:27'),(29,'qr_challenge_required','ced07f516cdb64456491fd9686cbb051dc2014804b4882b1b2c6cd2d59f28276','jeysidelima04@gmail.com',NULL,NULL,'83b4e3958a68160e80236fdcc507f8bc','86157b74b1b107033fe97fe7fc5eba9d3adb55d6490dc2f4df89f74c61216bf6',NULL,'2026-03-23 03:51:35'),(30,'challenge_created','ced07f516cdb64456491fd9686cbb051dc2014804b4882b1b2c6cd2d59f28276','jeysidelima04@gmail.com',NULL,NULL,'b3aa0cd01415b5746229d2d70d38346a',NULL,'{\"expires_at\":\"2026-03-23 04:51:50\",\"ttl_seconds\":15}','2026-03-23 03:51:35'),(31,'qr_challenge_required','ced07f516cdb64456491fd9686cbb051dc2014804b4882b1b2c6cd2d59f28276','jeysidelima04@gmail.com',NULL,NULL,'b3aa0cd01415b5746229d2d70d38346a','86157b74b1b107033fe97fe7fc5eba9d3adb55d6490dc2f4df89f74c61216bf6',NULL,'2026-03-23 03:51:39'),(32,'challenge_created','ced07f516cdb64456491fd9686cbb051dc2014804b4882b1b2c6cd2d59f28276','jeysidelima04@gmail.com',NULL,NULL,'503cd7ffee2616209622c37c6fed586a',NULL,'{\"expires_at\":\"2026-03-23 04:51:54\",\"ttl_seconds\":15}','2026-03-23 03:51:39'),(33,'qr_challenge_required','ced07f516cdb64456491fd9686cbb051dc2014804b4882b1b2c6cd2d59f28276','jeysidelima04@gmail.com',NULL,NULL,'503cd7ffee2616209622c37c6fed586a','86157b74b1b107033fe97fe7fc5eba9d3adb55d6490dc2f4df89f74c61216bf6',NULL,'2026-03-23 03:51:43'),(34,'challenge_created','ced07f516cdb64456491fd9686cbb051dc2014804b4882b1b2c6cd2d59f28276','jeysidelima04@gmail.com',NULL,NULL,'a3c563a5cc184b373571c69cd5c04c84',NULL,'{\"expires_at\":\"2026-03-23 04:51:58\",\"ttl_seconds\":15}','2026-03-23 03:51:43'),(35,'qr_challenge_required','ced07f516cdb64456491fd9686cbb051dc2014804b4882b1b2c6cd2d59f28276','jeysidelima04@gmail.com',NULL,NULL,'a3c563a5cc184b373571c69cd5c04c84','86157b74b1b107033fe97fe7fc5eba9d3adb55d6490dc2f4df89f74c61216bf6',NULL,'2026-03-23 03:51:46'),(36,'challenge_created','ced07f516cdb64456491fd9686cbb051dc2014804b4882b1b2c6cd2d59f28276','jeysidelima04@gmail.com',NULL,NULL,'36a4e4cfd20c9aafea5322020c70864e',NULL,'{\"expires_at\":\"2026-03-23 04:52:02\",\"ttl_seconds\":15}','2026-03-23 03:51:47'),(37,'challenge_created','aff04d8a5ea667000e7ed75565c7358ed89397d2e197783fc5869ff8eee36c35','jeysidelima04@gmail.com',NULL,NULL,'a30f9943f715fd02b43e90cd1fa35890',NULL,'{\"expires_at\":\"2026-03-23 05:46:22\",\"ttl_seconds\":15}','2026-03-23 04:46:07'),(38,'challenge_created','aff04d8a5ea667000e7ed75565c7358ed89397d2e197783fc5869ff8eee36c35','jeysidelima04@gmail.com',NULL,NULL,'1f6bcdaa2ecd7f7db2f6b08d634f3957',NULL,'{\"expires_at\":\"2026-03-23 05:46:32\",\"ttl_seconds\":15}','2026-03-23 04:46:17'),(39,'qr_challenge_required','aff04d8a5ea667000e7ed75565c7358ed89397d2e197783fc5869ff8eee36c35','jeysidelima04@gmail.com',NULL,NULL,'1f6bcdaa2ecd7f7db2f6b08d634f3957','f51328bf9de6909dddc23a6f6632ad2de507c91d18c5be2f6a718071a0e86671',NULL,'2026-03-23 04:46:19'),(40,'challenge_created','aff04d8a5ea667000e7ed75565c7358ed89397d2e197783fc5869ff8eee36c35','jeysidelima04@gmail.com',NULL,NULL,'84e9366cee3a6e70d7985dd8dceeb134',NULL,'{\"expires_at\":\"2026-03-23 05:46:34\",\"ttl_seconds\":15}','2026-03-23 04:46:19'),(41,'qr_challenge_required','aff04d8a5ea667000e7ed75565c7358ed89397d2e197783fc5869ff8eee36c35','jeysidelima04@gmail.com',NULL,NULL,'84e9366cee3a6e70d7985dd8dceeb134','f51328bf9de6909dddc23a6f6632ad2de507c91d18c5be2f6a718071a0e86671',NULL,'2026-03-23 04:46:22'),(42,'challenge_created','aff04d8a5ea667000e7ed75565c7358ed89397d2e197783fc5869ff8eee36c35','jeysidelima04@gmail.com',NULL,NULL,'daadebb5193ea4df804b68f063137eea',NULL,'{\"expires_at\":\"2026-03-23 05:46:37\",\"ttl_seconds\":15}','2026-03-23 04:46:22'),(43,'challenge_created','da27a207c0d2c9dfd1fa062e82e4a5056ec4dc223ef65ca05604da9e7d3b0ba1','jeysidelima04@gmail.com',NULL,NULL,'39a64a11491aa96b3ade7b3e159f7619',NULL,'{\"expires_at\":\"2026-03-23 06:00:29\",\"ttl_seconds\":15}','2026-03-23 05:00:14'),(44,'qr_challenge_required','da27a207c0d2c9dfd1fa062e82e4a5056ec4dc223ef65ca05604da9e7d3b0ba1','jeysidelima04@gmail.com',NULL,NULL,'39a64a11491aa96b3ade7b3e159f7619','92e1f3e19b8ccaff398b5d42d720af0c6a567ee558d98b4891e2bd1041a15db0',NULL,'2026-03-23 05:00:19'),(45,'challenge_created','da27a207c0d2c9dfd1fa062e82e4a5056ec4dc223ef65ca05604da9e7d3b0ba1','jeysidelima04@gmail.com',NULL,NULL,'dc74c6554abf3a52f2c2e30365330f4c',NULL,'{\"expires_at\":\"2026-03-23 06:00:34\",\"ttl_seconds\":15}','2026-03-23 05:00:19'),(46,'qr_challenge_required','da27a207c0d2c9dfd1fa062e82e4a5056ec4dc223ef65ca05604da9e7d3b0ba1','jeysidelima04@gmail.com',NULL,NULL,'dc74c6554abf3a52f2c2e30365330f4c','92e1f3e19b8ccaff398b5d42d720af0c6a567ee558d98b4891e2bd1041a15db0',NULL,'2026-03-23 05:00:22'),(47,'challenge_created','da27a207c0d2c9dfd1fa062e82e4a5056ec4dc223ef65ca05604da9e7d3b0ba1','jeysidelima04@gmail.com',NULL,NULL,'a25b6274d5cc134b3c6610b3f51d3ba8',NULL,'{\"expires_at\":\"2026-03-23 06:00:37\",\"ttl_seconds\":15}','2026-03-23 05:00:22'),(48,'challenge_consumed','da27a207c0d2c9dfd1fa062e82e4a5056ec4dc223ef65ca05604da9e7d3b0ba1','jeysidelima04@gmail.com',2,'202232903','a25b6274d5cc134b3c6610b3f51d3ba8','3057305ebe63f675eea07b3128704b86ceb55c0c2038fb6ecf6d03ca43ed1967',NULL,'2026-03-23 05:00:23'),(49,'qr_pending_face','da27a207c0d2c9dfd1fa062e82e4a5056ec4dc223ef65ca05604da9e7d3b0ba1','jeysidelima04@gmail.com',2,'202232903','a25b6274d5cc134b3c6610b3f51d3ba8','3057305ebe63f675eea07b3128704b86ceb55c0c2038fb6ecf6d03ca43ed1967','{\"expires_at\":\"2026-03-23 06:00:43\"}','2026-03-23 05:00:23'),(50,'challenge_created','da27a207c0d2c9dfd1fa062e82e4a5056ec4dc223ef65ca05604da9e7d3b0ba1','jeysidelima04@gmail.com',NULL,NULL,'11df454d2077774cf82d8b44fd659e97',NULL,'{\"expires_at\":\"2026-03-23 06:03:22\",\"ttl_seconds\":15}','2026-03-23 05:03:07'),(51,'qr_challenge_required','da27a207c0d2c9dfd1fa062e82e4a5056ec4dc223ef65ca05604da9e7d3b0ba1','jeysidelima04@gmail.com',NULL,NULL,'11df454d2077774cf82d8b44fd659e97','277c939179235d26bea23521d1fb0b2c3a0143b7c667f0e2776ef9319dfa4555',NULL,'2026-03-23 05:03:12'),(52,'challenge_created','da27a207c0d2c9dfd1fa062e82e4a5056ec4dc223ef65ca05604da9e7d3b0ba1','jeysidelima04@gmail.com',NULL,NULL,'e7bf57ea0ad904e43a12d0f2684d5f2e',NULL,'{\"expires_at\":\"2026-03-23 06:03:27\",\"ttl_seconds\":15}','2026-03-23 05:03:12'),(53,'challenge_consumed','da27a207c0d2c9dfd1fa062e82e4a5056ec4dc223ef65ca05604da9e7d3b0ba1','jeysidelima04@gmail.com',2,'202232903','e7bf57ea0ad904e43a12d0f2684d5f2e','25318dd071780244354aff6ab7d3ff9f34514fe94724e26777cd0863cae361a6',NULL,'2026-03-23 05:03:16'),(54,'qr_pending_face','da27a207c0d2c9dfd1fa062e82e4a5056ec4dc223ef65ca05604da9e7d3b0ba1','jeysidelima04@gmail.com',2,'202232903','e7bf57ea0ad904e43a12d0f2684d5f2e','25318dd071780244354aff6ab7d3ff9f34514fe94724e26777cd0863cae361a6','{\"expires_at\":\"2026-03-23 06:03:36\"}','2026-03-23 05:03:16'),(55,'challenge_created','791656d672da85bb1aca2a89909cc1648c274fea485d75741a2810d44bd8bae2','jeysidelima04@gmail.com',NULL,NULL,'a1186819c0266d7f505616f0b4c0a8e1',NULL,'{\"expires_at\":\"2026-03-23 06:16:12\",\"ttl_seconds\":15}','2026-03-23 05:15:57'),(56,'challenge_created','791656d672da85bb1aca2a89909cc1648c274fea485d75741a2810d44bd8bae2','jeysidelima04@gmail.com',NULL,NULL,'c7f46159895f87feb9111c8c8e28c19a',NULL,'{\"expires_at\":\"2026-03-23 06:16:22\",\"ttl_seconds\":15}','2026-03-23 05:16:07'),(57,'qr_challenge_required','791656d672da85bb1aca2a89909cc1648c274fea485d75741a2810d44bd8bae2','jeysidelima04@gmail.com',NULL,NULL,'c7f46159895f87feb9111c8c8e28c19a','79b56b3909d8df9e4fceb01ffb4714f8623460e83820f4806bfe457221d80167',NULL,'2026-03-23 05:16:10'),(58,'challenge_created','791656d672da85bb1aca2a89909cc1648c274fea485d75741a2810d44bd8bae2','jeysidelima04@gmail.com',NULL,NULL,'7785727dba6a4d4b6ae08551c4bb8cc6',NULL,'{\"expires_at\":\"2026-03-23 06:16:25\",\"ttl_seconds\":15}','2026-03-23 05:16:10'),(59,'challenge_consumed','791656d672da85bb1aca2a89909cc1648c274fea485d75741a2810d44bd8bae2','jeysidelima04@gmail.com',2,'202232903','7785727dba6a4d4b6ae08551c4bb8cc6','f496d8004c7ad2352cf3e8f673742a81ffbe2bb5a47f80bdca6a0757cc70c03d',NULL,'2026-03-23 05:16:13'),(60,'qr_pending_face','791656d672da85bb1aca2a89909cc1648c274fea485d75741a2810d44bd8bae2','jeysidelima04@gmail.com',2,'202232903','7785727dba6a4d4b6ae08551c4bb8cc6','f496d8004c7ad2352cf3e8f673742a81ffbe2bb5a47f80bdca6a0757cc70c03d','{\"expires_at\":\"2026-03-23 06:16:33\"}','2026-03-23 05:16:13'),(61,'qr_face_match_success','791656d672da85bb1aca2a89909cc1648c274fea485d75741a2810d44bd8bae2','jeysidelima04@gmail.com',2,'202232903','7785727dba6a4d4b6ae08551c4bb8cc6','f496d8004c7ad2352cf3e8f673742a81ffbe2bb5a47f80bdca6a0757cc70c03d','{\"distance\":0.301,\"server_confidence\":0.699}','2026-03-23 05:16:25'),(62,'qr_face_verified','791656d672da85bb1aca2a89909cc1648c274fea485d75741a2810d44bd8bae2','jeysidelima04@gmail.com',2,'202232903','7785727dba6a4d4b6ae08551c4bb8cc6','f496d8004c7ad2352cf3e8f673742a81ffbe2bb5a47f80bdca6a0757cc70c03d','{\"confidence_score\":0.6661}','2026-03-23 05:16:25'),(63,'challenge_created','791656d672da85bb1aca2a89909cc1648c274fea485d75741a2810d44bd8bae2','jeysidelima04@gmail.com',NULL,NULL,'d987f372440ce0d92d1520b12daf3fe3',NULL,'{\"expires_at\":\"2026-03-23 06:16:40\",\"ttl_seconds\":15}','2026-03-23 05:16:25'),(64,'challenge_created','791656d672da85bb1aca2a89909cc1648c274fea485d75741a2810d44bd8bae2','jeysidelima04@gmail.com',NULL,NULL,'d18b0a31e931e55ab0bb54681e178395',NULL,'{\"expires_at\":\"2026-03-23 06:17:00\",\"ttl_seconds\":15}','2026-03-23 05:16:45'),(65,'suspected_proxy_attempt','791656d672da85bb1aca2a89909cc1648c274fea485d75741a2810d44bd8bae2','jeysidelima04@gmail.com',NULL,NULL,'d18b0a31e931e55ab0bb54681e178395','42f5e2b36b86a21921b6604c83f441b1c4cb7385ba5d5f8b11cc01b67e2cc483','{\"reason\":\"challenge_mismatch\",\"token_challenge_id\":\"d987f372440ce0d92d1520b12daf3fe3\",\"request_challenge_id\":\"d18b0a31e931e55ab0bb54681e178395\"}','2026-03-23 05:16:50'),(66,'suspected_proxy_attempt','791656d672da85bb1aca2a89909cc1648c274fea485d75741a2810d44bd8bae2','jeysidelima04@gmail.com',NULL,NULL,'d18b0a31e931e55ab0bb54681e178395','42f5e2b36b86a21921b6604c83f441b1c4cb7385ba5d5f8b11cc01b67e2cc483','{\"reason\":\"challenge_mismatch\",\"token_challenge_id\":\"d987f372440ce0d92d1520b12daf3fe3\",\"request_challenge_id\":\"d18b0a31e931e55ab0bb54681e178395\"}','2026-03-23 05:16:53'),(67,'challenge_created','791656d672da85bb1aca2a89909cc1648c274fea485d75741a2810d44bd8bae2','jeysidelima04@gmail.com',NULL,NULL,'25ddd0d4bd2b766bf3219a9e05f85cf4',NULL,'{\"expires_at\":\"2026-03-23 06:17:11\",\"ttl_seconds\":15}','2026-03-23 05:16:56'),(68,'suspected_proxy_attempt','791656d672da85bb1aca2a89909cc1648c274fea485d75741a2810d44bd8bae2','jeysidelima04@gmail.com',NULL,NULL,'25ddd0d4bd2b766bf3219a9e05f85cf4','041cda83b15b4322fa9e7a495239b99831c5cc836a7073413eb122b646dd8e11','{\"reason\":\"challenge_mismatch\",\"token_challenge_id\":\"d18b0a31e931e55ab0bb54681e178395\",\"request_challenge_id\":\"25ddd0d4bd2b766bf3219a9e05f85cf4\"}','2026-03-23 05:16:56'),(69,'suspected_proxy_attempt','791656d672da85bb1aca2a89909cc1648c274fea485d75741a2810d44bd8bae2','jeysidelima04@gmail.com',NULL,NULL,'25ddd0d4bd2b766bf3219a9e05f85cf4','041cda83b15b4322fa9e7a495239b99831c5cc836a7073413eb122b646dd8e11','{\"reason\":\"challenge_mismatch\",\"token_challenge_id\":\"d18b0a31e931e55ab0bb54681e178395\",\"request_challenge_id\":\"25ddd0d4bd2b766bf3219a9e05f85cf4\"}','2026-03-23 05:16:59'),(70,'challenge_created','791656d672da85bb1aca2a89909cc1648c274fea485d75741a2810d44bd8bae2','jeysidelima04@gmail.com',NULL,NULL,'89aa213228714bf148fca9d3688bc775',NULL,'{\"expires_at\":\"2026-03-23 06:17:16\",\"ttl_seconds\":15}','2026-03-23 05:17:01'),(71,'suspected_proxy_attempt','791656d672da85bb1aca2a89909cc1648c274fea485d75741a2810d44bd8bae2','jeysidelima04@gmail.com',NULL,NULL,'89aa213228714bf148fca9d3688bc775','041cda83b15b4322fa9e7a495239b99831c5cc836a7073413eb122b646dd8e11','{\"reason\":\"challenge_mismatch\",\"token_challenge_id\":\"d18b0a31e931e55ab0bb54681e178395\",\"request_challenge_id\":\"89aa213228714bf148fca9d3688bc775\"}','2026-03-23 05:17:03'),(72,'suspected_proxy_attempt','791656d672da85bb1aca2a89909cc1648c274fea485d75741a2810d44bd8bae2','jeysidelima04@gmail.com',NULL,NULL,'89aa213228714bf148fca9d3688bc775','041cda83b15b4322fa9e7a495239b99831c5cc836a7073413eb122b646dd8e11','{\"reason\":\"challenge_mismatch\",\"token_challenge_id\":\"d18b0a31e931e55ab0bb54681e178395\",\"request_challenge_id\":\"89aa213228714bf148fca9d3688bc775\"}','2026-03-23 05:17:06'),(73,'challenge_consumed','791656d672da85bb1aca2a89909cc1648c274fea485d75741a2810d44bd8bae2','jeysidelima04@gmail.com',2,'202232903','89aa213228714bf148fca9d3688bc775','45ac5088dbed7319dbadcd8085ab865fcbad6e039f7af30452029779ebee388d',NULL,'2026-03-23 05:17:08'),(74,'qr_pending_face','791656d672da85bb1aca2a89909cc1648c274fea485d75741a2810d44bd8bae2','jeysidelima04@gmail.com',2,'202232903','89aa213228714bf148fca9d3688bc775','45ac5088dbed7319dbadcd8085ab865fcbad6e039f7af30452029779ebee388d','{\"expires_at\":\"2026-03-23 06:17:28\"}','2026-03-23 05:17:08'),(75,'challenge_created','791656d672da85bb1aca2a89909cc1648c274fea485d75741a2810d44bd8bae2','jeysidelima04@gmail.com',NULL,NULL,'2250d0c19399f7faa5c5a9a30c977684',NULL,'{\"expires_at\":\"2026-03-23 06:18:17\",\"ttl_seconds\":15}','2026-03-23 05:18:02'),(76,'qr_challenge_required','791656d672da85bb1aca2a89909cc1648c274fea485d75741a2810d44bd8bae2','jeysidelima04@gmail.com',NULL,NULL,'2250d0c19399f7faa5c5a9a30c977684','44127e5b9d49f444f497f0225780bd325b4cd8f96a1eff0fe5e655ec06c8350f',NULL,'2026-03-23 05:18:06'),(77,'challenge_created','791656d672da85bb1aca2a89909cc1648c274fea485d75741a2810d44bd8bae2','jeysidelima04@gmail.com',NULL,NULL,'94f1b12d2256f3eacbb7226e58c78cf4',NULL,'{\"expires_at\":\"2026-03-23 06:18:21\",\"ttl_seconds\":15}','2026-03-23 05:18:06'),(78,'challenge_consumed','791656d672da85bb1aca2a89909cc1648c274fea485d75741a2810d44bd8bae2','jeysidelima04@gmail.com',2,'202232903','94f1b12d2256f3eacbb7226e58c78cf4','c5c511c4840d82a102c21d0d769760d10be7f664abf6ce8425d07e899436272e',NULL,'2026-03-23 05:18:09'),(79,'qr_pending_face','791656d672da85bb1aca2a89909cc1648c274fea485d75741a2810d44bd8bae2','jeysidelima04@gmail.com',2,'202232903','94f1b12d2256f3eacbb7226e58c78cf4','c5c511c4840d82a102c21d0d769760d10be7f664abf6ce8425d07e899436272e','{\"expires_at\":\"2026-03-23 06:18:29\"}','2026-03-23 05:18:09'),(80,'challenge_created','791656d672da85bb1aca2a89909cc1648c274fea485d75741a2810d44bd8bae2','jeysidelima04@gmail.com',NULL,NULL,'2362545fefe8329e22e66a3ac8a03644',NULL,'{\"expires_at\":\"2026-03-23 06:18:53\",\"ttl_seconds\":15}','2026-03-23 05:18:38'),(81,'suspected_proxy_attempt','791656d672da85bb1aca2a89909cc1648c274fea485d75741a2810d44bd8bae2','jeysidelima04@gmail.com',NULL,NULL,'2362545fefe8329e22e66a3ac8a03644','c5c511c4840d82a102c21d0d769760d10be7f664abf6ce8425d07e899436272e','{\"reason\":\"challenge_mismatch\",\"token_challenge_id\":\"94f1b12d2256f3eacbb7226e58c78cf4\",\"request_challenge_id\":\"2362545fefe8329e22e66a3ac8a03644\"}','2026-03-23 05:18:43'),(82,'challenge_consumed','791656d672da85bb1aca2a89909cc1648c274fea485d75741a2810d44bd8bae2','jeysidelima04@gmail.com',2,'202232903','2362545fefe8329e22e66a3ac8a03644','4b8fdb42bdccdf8241cc2091eb1350ceeda2d6efe19015b60a74de0ce79cabd6',NULL,'2026-03-23 05:18:46'),(83,'qr_pending_face','791656d672da85bb1aca2a89909cc1648c274fea485d75741a2810d44bd8bae2','jeysidelima04@gmail.com',2,'202232903','2362545fefe8329e22e66a3ac8a03644','4b8fdb42bdccdf8241cc2091eb1350ceeda2d6efe19015b60a74de0ce79cabd6','{\"expires_at\":\"2026-03-23 06:19:06\"}','2026-03-23 05:18:46'),(84,'challenge_created','671ceeb7547925ef22303301d4b15b76b04fe178fa18d346afd127a724b443e0','jeysidelima04@gmail.com',NULL,NULL,'3f78006122d89ccefbee8d730ea0b54e',NULL,'{\"expires_at\":\"2026-03-23 06:25:14\",\"ttl_seconds\":15}','2026-03-23 05:24:59'),(85,'qr_challenge_required','671ceeb7547925ef22303301d4b15b76b04fe178fa18d346afd127a724b443e0','jeysidelima04@gmail.com',NULL,NULL,'3f78006122d89ccefbee8d730ea0b54e','a576f889b2b7f92a983de4061fb2a2911e89fdfa7e568d0b63def7bf37807835',NULL,'2026-03-23 05:25:04'),(86,'challenge_created','671ceeb7547925ef22303301d4b15b76b04fe178fa18d346afd127a724b443e0','jeysidelima04@gmail.com',NULL,NULL,'811ce6719443f08ac945caea4c1bd940',NULL,'{\"expires_at\":\"2026-03-23 06:25:19\",\"ttl_seconds\":15}','2026-03-23 05:25:04'),(87,'challenge_consumed','671ceeb7547925ef22303301d4b15b76b04fe178fa18d346afd127a724b443e0','jeysidelima04@gmail.com',2,'202232903','811ce6719443f08ac945caea4c1bd940','551b32a9ccb8d2ac687529a8f4ec9443ccdf7bd882166e68abf30ddbf7019897',NULL,'2026-03-23 05:25:07'),(88,'qr_pending_face','671ceeb7547925ef22303301d4b15b76b04fe178fa18d346afd127a724b443e0','jeysidelima04@gmail.com',2,'202232903','811ce6719443f08ac945caea4c1bd940','551b32a9ccb8d2ac687529a8f4ec9443ccdf7bd882166e68abf30ddbf7019897','{\"expires_at\":\"2026-03-23 06:25:27\"}','2026-03-23 05:25:07'),(89,'qr_face_match_success','671ceeb7547925ef22303301d4b15b76b04fe178fa18d346afd127a724b443e0','jeysidelima04@gmail.com',2,'202232903','811ce6719443f08ac945caea4c1bd940','551b32a9ccb8d2ac687529a8f4ec9443ccdf7bd882166e68abf30ddbf7019897','{\"distance\":0.3371,\"server_confidence\":0.6629}','2026-03-23 05:25:14'),(90,'qr_face_verified','671ceeb7547925ef22303301d4b15b76b04fe178fa18d346afd127a724b443e0','jeysidelima04@gmail.com',2,'202232903','811ce6719443f08ac945caea4c1bd940','551b32a9ccb8d2ac687529a8f4ec9443ccdf7bd882166e68abf30ddbf7019897','{\"confidence_score\":0.6725}','2026-03-23 05:25:15'),(91,'challenge_created','671ceeb7547925ef22303301d4b15b76b04fe178fa18d346afd127a724b443e0','jeysidelima04@gmail.com',NULL,NULL,'46dc4ae786990a10291a76e30f502b00',NULL,'{\"expires_at\":\"2026-03-23 06:25:30\",\"ttl_seconds\":15}','2026-03-23 05:25:15'),(92,'challenge_created','671ceeb7547925ef22303301d4b15b76b04fe178fa18d346afd127a724b443e0','jeysidelima04@gmail.com',NULL,NULL,'d6d5a3533e0147650dca4b6c4809fd3b',NULL,'{\"expires_at\":\"2026-03-23 06:25:57\",\"ttl_seconds\":15}','2026-03-23 05:25:42'),(93,'qr_challenge_required','671ceeb7547925ef22303301d4b15b76b04fe178fa18d346afd127a724b443e0','jeysidelima04@gmail.com',NULL,NULL,'d6d5a3533e0147650dca4b6c4809fd3b','df15e9b95643b1df831b2c1ebda4e513a379f273f3bc3be4d76fbd2bd9874fe5',NULL,'2026-03-23 05:25:45'),(94,'challenge_created','671ceeb7547925ef22303301d4b15b76b04fe178fa18d346afd127a724b443e0','jeysidelima04@gmail.com',NULL,NULL,'d2e350d0dcee2487a69bee2480539972',NULL,'{\"expires_at\":\"2026-03-23 06:26:00\",\"ttl_seconds\":15}','2026-03-23 05:25:45'),(95,'challenge_consumed','671ceeb7547925ef22303301d4b15b76b04fe178fa18d346afd127a724b443e0','jeysidelima04@gmail.com',2,'202232903','d2e350d0dcee2487a69bee2480539972','8fcae1ba2b6c4c1e8c76da42d767a81f27d6a3e69ca91ad67b8e3d89367de1ea',NULL,'2026-03-23 05:25:48'),(96,'qr_pending_face','671ceeb7547925ef22303301d4b15b76b04fe178fa18d346afd127a724b443e0','jeysidelima04@gmail.com',2,'202232903','d2e350d0dcee2487a69bee2480539972','8fcae1ba2b6c4c1e8c76da42d767a81f27d6a3e69ca91ad67b8e3d89367de1ea','{\"expires_at\":\"2026-03-23 06:26:08\"}','2026-03-23 05:25:48'),(97,'qr_face_match_success','671ceeb7547925ef22303301d4b15b76b04fe178fa18d346afd127a724b443e0','jeysidelima04@gmail.com',2,'202232903','d2e350d0dcee2487a69bee2480539972','8fcae1ba2b6c4c1e8c76da42d767a81f27d6a3e69ca91ad67b8e3d89367de1ea','{\"distance\":0.3383,\"server_confidence\":0.6617}','2026-03-23 05:25:52'),(98,'qr_face_verified','671ceeb7547925ef22303301d4b15b76b04fe178fa18d346afd127a724b443e0','jeysidelima04@gmail.com',2,'202232903','d2e350d0dcee2487a69bee2480539972','8fcae1ba2b6c4c1e8c76da42d767a81f27d6a3e69ca91ad67b8e3d89367de1ea','{\"confidence_score\":0.6798}','2026-03-23 05:25:52'),(99,'challenge_created','671ceeb7547925ef22303301d4b15b76b04fe178fa18d346afd127a724b443e0','jeysidelima04@gmail.com',NULL,NULL,'7801a9c62ac6a174d7e1d8ca5e598f53',NULL,'{\"expires_at\":\"2026-03-23 06:26:07\",\"ttl_seconds\":15}','2026-03-23 05:25:52');
/*!40000 ALTER TABLE `qr_security_events` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `rfid_cards`
--

DROP TABLE IF EXISTS `rfid_cards`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `rfid_cards` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `rfid_uid` varchar(50) NOT NULL,
  `registered_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `unregistered_at` timestamp NULL DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `registered_by` int(11) DEFAULT NULL COMMENT 'Admin who registered the card',
  `unregistered_by` int(11) DEFAULT NULL COMMENT 'Admin who unregistered the card',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `is_lost` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Whether RFID is marked as lost',
  `lost_at` datetime DEFAULT NULL COMMENT 'When RFID was marked as lost',
  `lost_reason` varchar(255) DEFAULT NULL COMMENT 'Reason for marking as lost',
  `lost_reported_by` int(11) DEFAULT NULL COMMENT 'Admin who marked it as lost',
  `found_at` datetime DEFAULT NULL COMMENT 'When RFID was found/unmarked',
  `found_by` int(11) DEFAULT NULL COMMENT 'Admin who unmarked it',
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_active_rfid_per_user` (`user_id`,`is_active`),
  UNIQUE KEY `uk_rfid_cards_uid` (`rfid_uid`),
  KEY `registered_by` (`registered_by`),
  KEY `unregistered_by` (`unregistered_by`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_is_active` (`is_active`),
  KEY `idx_is_lost` (`is_lost`),
  KEY `fk_rfid_lost_reported_by` (`lost_reported_by`),
  KEY `fk_rfid_found_by` (`found_by`),
  CONSTRAINT `fk_rfid_found_by` FOREIGN KEY (`found_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_rfid_lost_reported_by` FOREIGN KEY (`lost_reported_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `rfid_cards_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `rfid_cards_ibfk_2` FOREIGN KEY (`registered_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `rfid_cards_ibfk_3` FOREIGN KEY (`unregistered_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chk_rfid_cards_flags` CHECK (`is_active` in (0,1) and `is_lost` in (0,1))
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `rfid_cards`
--

LOCK TABLES `rfid_cards` WRITE;
/*!40000 ALTER TABLE `rfid_cards` DISABLE KEYS */;
INSERT INTO `rfid_cards` VALUES (1,2,'0014973874','2026-02-21 04:50:18',NULL,1,NULL,NULL,NULL,'2026-02-21 04:56:35','2026-02-21 05:51:56',0,'2026-02-21 13:42:08','RFID card marked as lost by admin - Student notified',3,'2026-02-21 13:51:56',3);
/*!40000 ALTER TABLE `rfid_cards` ENABLE KEYS */;
UNLOCK TABLES;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER `after_rfid_insert` AFTER INSERT ON `rfid_cards` FOR EACH ROW BEGIN
    IF NEW.is_active = 1 THEN
        UPDATE users 
        SET rfid_uid = NEW.rfid_uid, 
            rfid_registered_at = NEW.registered_at
        WHERE id = NEW.user_id;
    END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER `after_rfid_update` AFTER UPDATE ON `rfid_cards` FOR EACH ROW BEGIN
    IF NEW.is_active = 0 AND OLD.is_active = 1 THEN
        UPDATE users 
        SET rfid_uid = NULL, 
            rfid_registered_at = NULL
        WHERE id = NEW.user_id;
    END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

--
-- Table structure for table `rfid_status_history`
--

DROP TABLE IF EXISTS `rfid_status_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `rfid_status_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `rfid_card_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `status_change` varchar(50) NOT NULL,
  `changed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `changed_by` int(11) DEFAULT NULL,
  `reason` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_rfid_card` (`rfid_card_id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_changed_by` (`changed_by`),
  KEY `idx_rsh_card_date` (`rfid_card_id`,`changed_at`),
  CONSTRAINT `fk_rfid_history_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `rfid_status_history_ibfk_1` FOREIGN KEY (`rfid_card_id`) REFERENCES `rfid_cards` (`id`) ON DELETE CASCADE,
  CONSTRAINT `rfid_status_history_ibfk_3` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chk_rfid_status_history_status` CHECK (`status_change` in ('LOST','FOUND','REGISTERED','UNREGISTERED'))
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `rfid_status_history`
--

LOCK TABLES `rfid_status_history` WRITE;
/*!40000 ALTER TABLE `rfid_status_history` DISABLE KEYS */;
INSERT INTO `rfid_status_history` VALUES (1,1,2,'LOST','2026-02-21 04:56:55',3,'RFID card marked as lost by admin - Student notified',NULL,'::1'),(2,1,2,'FOUND','2026-02-21 05:11:34',3,'RFID card marked as lost by admin - Student notified','Previously lost','::1'),(3,1,2,'LOST','2026-02-21 05:42:08',3,'RFID card marked as lost by admin - Student notified',NULL,'::1'),(4,1,2,'FOUND','2026-02-21 05:51:56',3,'RFID card marked as lost by admin - Student notified','Previously lost','::1');
/*!40000 ALTER TABLE `rfid_status_history` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `role_permissions`
--

DROP TABLE IF EXISTS `role_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `role_permissions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `role_id` int(10) unsigned NOT NULL,
  `permission_id` int(10) unsigned NOT NULL,
  `is_allowed` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_role_permission` (`role_id`,`permission_id`),
  KEY `idx_role_permissions_permission` (`permission_id`),
  CONSTRAINT `fk_role_permissions_permission` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_role_permissions_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=69 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `role_permissions`
--

LOCK TABLES `role_permissions` WRITE;
/*!40000 ALTER TABLE `role_permissions` DISABLE KEYS */;
INSERT INTO `role_permissions` VALUES (1,1,4,1,'2026-04-06 04:48:46','2026-04-06 04:48:46'),(2,1,2,1,'2026-04-06 04:48:46','2026-04-06 04:48:46'),(3,1,1,1,'2026-04-06 04:48:46','2026-04-06 04:48:46'),(4,1,3,1,'2026-04-06 04:48:46','2026-04-06 04:48:46'),(8,2,17,1,'2026-04-06 04:48:46','2026-04-06 04:48:46'),(9,2,16,1,'2026-04-06 04:48:46','2026-04-06 04:48:46'),(10,2,12,1,'2026-04-06 04:48:46','2026-04-06 04:48:46'),(11,2,11,1,'2026-04-06 04:48:46','2026-04-06 04:48:46'),(12,2,10,1,'2026-04-06 04:48:46','2026-04-06 04:48:46'),(13,2,8,1,'2026-04-06 04:48:46','2026-04-06 04:48:46'),(14,2,9,1,'2026-04-06 04:48:46','2026-04-06 04:48:46'),(15,2,7,1,'2026-04-06 04:48:46','2026-04-06 04:48:46'),(16,2,6,1,'2026-04-06 04:48:46','2026-04-06 04:48:46'),(17,2,5,1,'2026-04-06 04:48:46','2026-04-06 04:48:46'),(18,2,15,1,'2026-04-06 04:48:46','2026-04-06 04:48:46'),(23,3,13,1,'2026-04-06 04:48:46','2026-04-06 04:48:46'),(24,3,22,1,'2026-04-06 04:48:46','2026-04-06 04:48:46'),(25,3,21,1,'2026-04-06 04:48:46','2026-04-06 04:48:46'),(26,3,14,1,'2026-04-06 04:48:46','2026-04-06 04:48:46'),(30,4,5,1,'2026-04-06 04:48:46','2026-04-06 04:48:46'),(31,4,6,1,'2026-04-06 04:48:46','2026-04-06 04:48:46'),(32,4,7,1,'2026-04-06 04:48:46','2026-04-06 04:48:46'),(33,4,8,1,'2026-04-06 04:48:46','2026-04-06 04:48:46'),(34,4,9,1,'2026-04-06 04:48:46','2026-04-06 04:48:46'),(35,4,10,1,'2026-04-06 04:48:46','2026-04-06 04:48:46'),(36,4,11,1,'2026-04-06 04:48:46','2026-04-06 04:48:46'),(37,4,12,1,'2026-04-06 04:48:46','2026-04-06 04:48:46'),(38,4,14,1,'2026-04-06 04:48:46','2026-04-06 04:48:46'),(39,4,15,1,'2026-04-06 04:48:46','2026-04-06 04:48:46'),(40,4,17,1,'2026-04-06 04:48:46','2026-04-06 04:48:46'),(41,4,18,1,'2026-04-06 04:48:46','2026-04-06 04:48:46'),(42,4,19,1,'2026-04-06 04:48:46','2026-04-06 04:48:46'),(43,4,20,1,'2026-04-06 04:48:46','2026-04-06 04:48:46'),(44,4,13,1,'2026-04-06 04:48:46','2026-04-06 04:48:46'),(45,4,16,1,'2026-04-06 04:48:46','2026-04-06 04:48:46'),(46,4,21,1,'2026-04-06 04:48:46','2026-04-06 04:48:46'),(47,4,22,1,'2026-04-06 04:48:46','2026-04-06 04:48:46'),(48,4,1,1,'2026-04-06 04:48:46','2026-04-06 04:48:46'),(49,4,2,1,'2026-04-06 04:48:46','2026-04-06 04:48:46'),(50,4,3,1,'2026-04-06 04:48:46','2026-04-06 04:48:46'),(51,4,4,1,'2026-04-06 04:48:46','2026-04-06 04:48:46');
/*!40000 ALTER TABLE `role_permissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `roles`
--

DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `roles` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `role_key` varchar(64) NOT NULL,
  `display_name` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_role_key` (`role_key`),
  KEY `idx_roles_active` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `roles`
--

LOCK TABLES `roles` WRITE;
/*!40000 ALTER TABLE `roles` DISABLE KEYS */;
INSERT INTO `roles` VALUES (1,'student','Student','Student self-service role',1,'2026-04-06 04:48:46','2026-04-06 04:48:46'),(2,'admin','Admin','School administrator role',1,'2026-04-06 04:48:46','2026-04-06 04:48:46'),(3,'security','Security','Security guard role',1,'2026-04-06 04:48:46','2026-04-06 04:48:46'),(4,'superadmin','Super Admin','System super administrator role',1,'2026-04-06 04:48:46','2026-04-06 04:48:46');
/*!40000 ALTER TABLE `roles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `schema_migrations`
--

DROP TABLE IF EXISTS `schema_migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `schema_migrations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `migration_name` varchar(255) NOT NULL,
  `applied_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `checksum` char(64) DEFAULT NULL COMMENT 'SHA-256 of migration file for drift detection',
  `execution_time_ms` int(10) unsigned DEFAULT NULL COMMENT 'How long the migration took',
  `applied_by` varchar(100) DEFAULT NULL COMMENT 'User or script that applied the migration',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_migration_name` (`migration_name`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `schema_migrations`
--

LOCK TABLES `schema_migrations` WRITE;
/*!40000 ALTER TABLE `schema_migrations` DISABLE KEYS */;
INSERT INTO `schema_migrations` VALUES (1,'000_schema_migrations','2026-03-25 14:50:47',NULL,NULL,'initial_setup'),(2,'001_add_missing_columns','2026-03-25 14:50:47',NULL,NULL,'root@localhost'),(3,'002_add_runtime_tables','2026-03-25 14:50:47',NULL,NULL,'root@localhost'),(4,'003_add_missing_fks_indexes','2026-03-25 14:50:48',NULL,NULL,'root@localhost'),(5,'004_fix_audit_cascade','2026-03-25 14:50:48',NULL,NULL,'root@localhost'),(6,'005_add_soft_delete','2026-03-25 14:50:48',NULL,NULL,'root@localhost'),(7,'006_counter_sync_triggers','2026-03-25 14:50:48',NULL,NULL,'root@localhost'),(8,'007_improve_views','2026-03-25 14:50:49',NULL,NULL,'root@localhost'),(9,'008_add_rbac_foundation','2026-04-06 04:49:48',NULL,NULL,'root@localhost'),(10,'009_hardening_immutable_audit_and_alerts','2026-04-06 05:12:49',NULL,NULL,'root@localhost'),(11,'010_replace_violation_categories_original_policy','2026-04-06 06:07:25',NULL,NULL,'root@localhost');
/*!40000 ALTER TABLE `schema_migrations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `security_alert_log`
--

DROP TABLE IF EXISTS `security_alert_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `security_alert_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `alert_type` varchar(50) NOT NULL,
  `action_key` varchar(120) NOT NULL,
  `identifier` varchar(191) NOT NULL,
  `ip_address` varchar(64) DEFAULT NULL,
  `attempts` int(10) unsigned NOT NULL DEFAULT 0,
  `threshold` int(10) unsigned NOT NULL DEFAULT 0,
  `blocked_until` datetime DEFAULT NULL,
  `severity` enum('info','warning','critical') NOT NULL DEFAULT 'warning',
  `context_json` longtext DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_security_alert_created` (`created_at`),
  KEY `idx_security_alert_type` (`alert_type`,`severity`,`created_at`),
  KEY `idx_security_alert_action` (`action_key`,`created_at`),
  KEY `idx_security_alert_identifier` (`identifier`,`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `security_alert_log`
--

LOCK TABLES `security_alert_log` WRITE;
/*!40000 ALTER TABLE `security_alert_log` DISABLE KEYS */;
INSERT INTO `security_alert_log` VALUES (1,'rate_limit','smoke_rate_alert','smoke_rate_alert|unknown','unknown',3,2,'2026-04-06 07:15:31','critical','{\"source\":\"db_threshold_exceeded\"}','2026-04-06 05:13:31'),(2,'rate_limit','smoke_rate_alert','smoke_rate_alert|unknown','unknown',3,2,'2026-04-06 07:15:31','warning','{\"source\":\"db_blocked_window\"}','2026-04-06 05:13:31');
/*!40000 ALTER TABLE `security_alert_log` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `security_scan_tokens`
--

DROP TABLE IF EXISTS `security_scan_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `security_scan_tokens` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `token_hash` char(64) NOT NULL,
  `guard_session_hash` char(64) NOT NULL,
  `guard_id` int(11) DEFAULT NULL,
  `guard_username` varchar(120) DEFAULT NULL,
  `user_id` int(11) NOT NULL,
  `scan_source` varchar(16) NOT NULL,
  `issued_at` datetime NOT NULL DEFAULT current_timestamp(),
  `expires_at` datetime NOT NULL,
  `consumed_at` datetime DEFAULT NULL,
  `consumed_by_guard_id` int(11) DEFAULT NULL,
  `consumed_ip` varchar(45) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token_hash` (`token_hash`),
  KEY `idx_sst_guard_session` (`guard_session_hash`),
  KEY `idx_sst_user` (`user_id`),
  KEY `idx_sst_expires` (`expires_at`),
  KEY `idx_sst_source` (`scan_source`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `security_scan_tokens`
--

LOCK TABLES `security_scan_tokens` WRITE;
/*!40000 ALTER TABLE `security_scan_tokens` DISABLE KEYS */;
INSERT INTO `security_scan_tokens` VALUES (1,'de5d41417d6fbff4fa811a8afe655fa0be31931e32ef3bd04ffeb4748fb7b79f','b675fe036aa1918e0ee0e8ba05b13008f0daab3888ffda1393982541ab4a61d6',NULL,'jeysidelima04@gmail.com',2,'rfid','2026-04-06 14:00:41','2026-04-06 08:02:41',NULL,NULL,NULL),(2,'e7fd9efc367476651a4cf713f10758ab79ee77154db54a741a3bd84d7d21085d','b675fe036aa1918e0ee0e8ba05b13008f0daab3888ffda1393982541ab4a61d6',NULL,'jeysidelima04@gmail.com',2,'rfid','2026-04-06 14:01:06','2026-04-06 08:03:06',NULL,NULL,NULL),(3,'cae9b34d63b18a04e6a17718e20ddd8326f4028e3e32464377a3df75e5076e2d','b675fe036aa1918e0ee0e8ba05b13008f0daab3888ffda1393982541ab4a61d6',NULL,'jeysidelima04@gmail.com',2,'rfid','2026-04-06 14:03:20','2026-04-06 08:05:20',NULL,NULL,NULL),(4,'d3ee71f9aea8c08b32e257279661890c74becbcdec1fa29616b15972d336ac4a','fa094d3c43a950b2c8f1f989923d2c0224a6908178ee3bdeaaab09b967137b04',NULL,'jeysidelima04@gmail.com',2,'rfid','2026-04-06 14:07:55','2026-04-06 08:09:55','2026-04-06 14:08:40',NULL,'::1'),(5,'ba993af47864be95c844c6a20b1972053887c7e7e8bd9a20eb42c8437c3c8992','fa094d3c43a950b2c8f1f989923d2c0224a6908178ee3bdeaaab09b967137b04',NULL,'jeysidelima04@gmail.com',2,'rfid','2026-04-06 14:08:45','2026-04-06 08:10:45','2026-04-06 14:08:47',NULL,'::1'),(6,'496b70f4413b8e0c6f93eca38e8e1e7daaa2067f30d3f62609c653ea2385ffee','fa094d3c43a950b2c8f1f989923d2c0224a6908178ee3bdeaaab09b967137b04',NULL,'jeysidelima04@gmail.com',2,'rfid','2026-04-06 14:08:49','2026-04-06 08:10:49','2026-04-06 14:08:53',NULL,'::1');
/*!40000 ALTER TABLE `security_scan_tokens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `student_guardians`
--

DROP TABLE IF EXISTS `student_guardians`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `student_guardians` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `guardian_id` int(11) NOT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT 1,
  `relationship_notes` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_student_guardian` (`student_id`,`guardian_id`),
  KEY `idx_student` (`student_id`),
  KEY `idx_guardian` (`guardian_id`),
  KEY `idx_primary` (`is_primary`),
  CONSTRAINT `student_guardians_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `student_guardians_ibfk_2` FOREIGN KEY (`guardian_id`) REFERENCES `guardians` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chk_student_guardians_primary` CHECK (`is_primary` in (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `student_guardians`
--

LOCK TABLES `student_guardians` WRITE;
/*!40000 ALTER TABLE `student_guardians` DISABLE KEYS */;
/*!40000 ALTER TABLE `student_guardians` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `student_profiles`
--

DROP TABLE IF EXISTS `student_profiles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `student_profiles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `profile_picture` varchar(255) DEFAULT NULL,
  `profile_picture_uploaded_at` datetime DEFAULT NULL,
  `profile_picture_size` int(11) DEFAULT NULL COMMENT 'File size in bytes',
  `profile_picture_mime_type` varchar(50) DEFAULT NULL,
  `bio` text DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `emergency_contact` varchar(100) DEFAULT NULL,
  `emergency_phone` varchar(20) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_profile` (`user_id`),
  KEY `idx_profile_picture` (`profile_picture`),
  CONSTRAINT `student_profiles_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `student_profiles`
--

LOCK TABLES `student_profiles` WRITE;
/*!40000 ALTER TABLE `student_profiles` DISABLE KEYS */;
/*!40000 ALTER TABLE `student_profiles` ENABLE KEYS */;
UNLOCK TABLES;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER `after_profile_update` AFTER UPDATE ON `student_profiles` FOR EACH ROW BEGIN
    IF NEW.profile_picture != OLD.profile_picture OR 
       NEW.profile_picture_uploaded_at != OLD.profile_picture_uploaded_at THEN
        UPDATE users 
        SET profile_picture = NEW.profile_picture,
            profile_picture_uploaded_at = NEW.profile_picture_uploaded_at
        WHERE id = NEW.user_id;
    END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

--
-- Table structure for table `student_violations`
--

DROP TABLE IF EXISTS `student_violations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `student_violations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `category_id` int(11) NOT NULL,
  `description` text DEFAULT NULL,
  `offense_number` int(11) NOT NULL DEFAULT 1,
  `status` enum('active','pending_reparation','apprehended') NOT NULL DEFAULT 'active',
  `reparation_type` varchar(100) DEFAULT NULL,
  `reparation_notes` text DEFAULT NULL,
  `reparation_completed_at` datetime DEFAULT NULL,
  `resolved_by` int(11) DEFAULT NULL,
  `recorded_by` int(11) DEFAULT NULL,
  `school_year` varchar(20) NOT NULL,
  `semester` enum('1st','2nd','summer') NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user_category_status` (`user_id`,`category_id`,`status`),
  KEY `idx_user_school_year` (`user_id`,`school_year`),
  KEY `idx_status` (`status`),
  KEY `idx_created_at` (`created_at`),
  KEY `fk_sv_resolved_by` (`resolved_by`),
  KEY `fk_sv_recorded_by` (`recorded_by`),
  KEY `fk_sv_category` (`category_id`),
  KEY `idx_sv_user_status_date` (`user_id`,`status`,`created_at`),
  CONSTRAINT `fk_sv_category` FOREIGN KEY (`category_id`) REFERENCES `violation_categories` (`id`),
  CONSTRAINT `fk_sv_recorded_by` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_sv_resolved_by` FOREIGN KEY (`resolved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `student_violations_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `student_violations_ibfk_2` FOREIGN KEY (`category_id`) REFERENCES `violation_categories` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=39 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `student_violations`
--

LOCK TABLES `student_violations` WRITE;
/*!40000 ALTER TABLE `student_violations` DISABLE KEYS */;
INSERT INTO `student_violations` VALUES (33,2,75,'Recorded by security via RFID Tap.',1,'apprehended',NULL,NULL,NULL,NULL,NULL,'2025-2026','summer','2026-04-06 02:48:20','2026-04-06 02:48:20'),(34,2,75,'Recorded by security via RFID Tap.',1,'apprehended',NULL,NULL,NULL,NULL,NULL,'2025-2026','summer','2026-04-06 02:48:28','2026-04-06 02:48:28'),(35,2,75,'Recorded by security via RFID Tap.',1,'apprehended','written_apology','','2026-04-06 14:09:07',3,NULL,'2025-2026','summer','2026-04-06 02:48:35','2026-04-06 06:09:07'),(36,2,122,'Recorded by security via RFID Tap.',1,'apprehended',NULL,NULL,NULL,NULL,NULL,'2025-2026','summer','2026-04-06 06:08:40','2026-04-06 06:08:40'),(37,2,122,'Recorded by security via RFID Tap.',1,'apprehended',NULL,NULL,NULL,NULL,NULL,'2025-2026','summer','2026-04-06 06:08:47','2026-04-06 06:08:47'),(38,2,122,'Recorded by security via RFID Tap.',1,'apprehended','written_apology','','2026-04-06 14:09:15',3,NULL,'2025-2026','summer','2026-04-06 06:08:53','2026-04-06 06:09:15');
/*!40000 ALTER TABLE `student_violations` ENABLE KEYS */;
UNLOCK TABLES;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = cp850 */ ;
/*!50003 SET character_set_results = cp850 */ ;
/*!50003 SET collation_connection  = cp850_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER after_student_violation_insert
AFTER INSERT ON student_violations
FOR EACH ROW
BEGIN
    UPDATE users
    SET active_violations_count = (
        SELECT COUNT(*)
        FROM student_violations
        WHERE user_id = NEW.user_id AND status = 'active'
    )
    WHERE id = NEW.user_id;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = cp850 */ ;
/*!50003 SET character_set_results = cp850 */ ;
/*!50003 SET collation_connection  = cp850_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER after_student_violation_update
AFTER UPDATE ON student_violations
FOR EACH ROW
BEGIN
    IF NEW.status != OLD.status THEN
        UPDATE users
        SET active_violations_count = (
            SELECT COUNT(*)
            FROM student_violations
            WHERE user_id = NEW.user_id AND status = 'active'
        )
        WHERE id = NEW.user_id;
    END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = cp850 */ ;
/*!50003 SET character_set_results = cp850 */ ;
/*!50003 SET collation_connection  = cp850_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER after_student_violation_delete
AFTER DELETE ON student_violations
FOR EACH ROW
BEGIN
    UPDATE users
    SET active_violations_count = (
        SELECT COUNT(*)
        FROM student_violations
        WHERE user_id = OLD.user_id AND status = 'active'
    )
    WHERE id = OLD.user_id;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

--
-- Table structure for table `system_settings`
--

DROP TABLE IF EXISTS `system_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `system_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(100) NOT NULL,
  `value` text NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `updated_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `setting_key` (`setting_key`),
  KEY `updated_by` (`updated_by`),
  KEY `idx_setting_key` (`setting_key`),
  CONSTRAINT `system_settings_ibfk_1` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `system_settings`
--

LOCK TABLES `system_settings` WRITE;
/*!40000 ALTER TABLE `system_settings` DISABLE KEYS */;
INSERT INTO `system_settings` VALUES (1,'guardian_notifications_enabled','1','Enable/disable guardian entry notifications globally','2026-02-18 02:56:56',NULL),(3,'rbac_mode','enforce','RBAC mode: legacy, dual, enforce','2026-04-06 04:51:39',NULL),(4,'rbac_enforce_tier','3','RBAC enforcement tier: 0=none,1=critical,2=high,3=medium','2026-04-06 05:30:46',NULL),(5,'rbac_log_decisions','1','Log RBAC authorization decisions','2026-04-06 04:49:48',NULL),(6,'rbac_fail_closed','1','If 1, deny when RBAC storage is unavailable during enforce mode','2026-04-06 05:41:28',NULL),(7,'csrf_rotate_on_critical','0','Rotate CSRF token after critical state-changing operations','2026-04-06 04:49:48',NULL),(8,'audit_immutable_enabled','1','Enable immutable audit chain protections','2026-04-06 05:12:49',NULL),(9,'session_isolation_on_privilege_change','1','Rotate/revoke sessions after privilege changes','2026-04-06 05:12:49',NULL),(10,'ratelimit_policy_mode','centralized','Rate-limit policy mode: legacy or centralized','2026-04-06 05:12:49',NULL);
/*!40000 ALTER TABLE `system_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `twofactor_codes`
--

DROP TABLE IF EXISTS `twofactor_codes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `twofactor_codes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `code` varchar(6) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_expires_at` (`expires_at`),
  CONSTRAINT `twofactor_codes_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `twofactor_codes`
--

LOCK TABLES `twofactor_codes` WRITE;
/*!40000 ALTER TABLE `twofactor_codes` DISABLE KEYS */;
/*!40000 ALTER TABLE `twofactor_codes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `used_qr_tokens`
--

DROP TABLE IF EXISTS `used_qr_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `used_qr_tokens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `token_hash` varchar(64) NOT NULL,
  `user_id` int(11) NOT NULL,
  `student_id` varchar(50) NOT NULL,
  `used_at` datetime NOT NULL,
  `security_guard` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `token_hash` (`token_hash`),
  KEY `idx_token_hash` (`token_hash`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_used_at` (`used_at`),
  CONSTRAINT `used_qr_tokens_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `used_qr_tokens`
--

LOCK TABLES `used_qr_tokens` WRITE;
/*!40000 ALTER TABLE `used_qr_tokens` DISABLE KEYS */;
INSERT INTO `used_qr_tokens` VALUES (1,'c94e6f5ac408bdc2054a06dbc0e3e5f2ec1c6c9ee9cbcf7211516e20bddac206',2,'202232903','2026-03-23 08:38:09','E2E Test Guard','2026-03-23 00:38:09'),(2,'7addb594b998487c534b1153415653ddff384f85150036619b4460ff9c6cdd60',2,'202232903','2026-03-23 08:47:37','jeysidelima04@gmail.com','2026-03-23 00:47:37'),(3,'9fc8dbbf1258e97c1a524b1d81b5ba8c1f8f87368c1181c072ef736fa02f47ed',2,'202232903','2026-03-23 08:48:09','jeysidelima04@gmail.com','2026-03-23 00:48:09'),(4,'2ce2a4c7bea92ec77b88ecb54fa81c341ca8861d85e7fd03e8455acc7193eb16',2,'202232903','2026-03-23 08:48:37','jeysidelima04@gmail.com','2026-03-23 00:48:37'),(5,'064fd8e78ea457e1b646ad53978f59cca40c3268ec25171d1b4cd57051a261dd',2,'202232903','2026-03-23 09:07:58','jeysidelima04@gmail.com','2026-03-23 01:07:58'),(6,'c582ad9331f0274cd07ff5c92a810884c6118fa2acfb18c6472b5757f458314e',2,'202232903','2026-03-23 09:08:01','jeysidelima04@gmail.com','2026-03-23 01:08:01'),(7,'16c71c32e5a8f42339eb41d46890c13e505cb20bda2e48135877f4655784cd08',2,'202232903','2026-03-23 09:08:07','jeysidelima04@gmail.com','2026-03-23 01:08:07'),(8,'d555f08eaf680a30bc17cafbff19637e3ed725b0b9812244c76cf0a6165df04a',2,'202232903','2026-03-23 09:08:15','jeysidelima04@gmail.com','2026-03-23 01:08:15'),(9,'f496d8004c7ad2352cf3e8f673742a81ffbe2bb5a47f80bdca6a0757cc70c03d',2,'202232903','2026-03-23 13:16:25','jeysidelima04@gmail.com','2026-03-23 05:16:25'),(10,'551b32a9ccb8d2ac687529a8f4ec9443ccdf7bd882166e68abf30ddbf7019897',2,'202232903','2026-03-23 13:25:15','jeysidelima04@gmail.com','2026-03-23 05:25:15'),(11,'8fcae1ba2b6c4c1e8c76da42d767a81f27d6a3e69ca91ad67b8e3d89367de1ea',2,'202232903','2026-03-23 13:25:52','jeysidelima04@gmail.com','2026-03-23 05:25:52');
/*!40000 ALTER TABLE `used_qr_tokens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `user_auth_methods`
--

DROP TABLE IF EXISTS `user_auth_methods`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_auth_methods` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `provider_id` int(11) NOT NULL,
  `provider_user_id` varchar(255) DEFAULT NULL,
  `is_primary_method` tinyint(1) NOT NULL DEFAULT 0,
  `first_used_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_used_at` timestamp NULL DEFAULT NULL,
  `use_count` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_provider` (`user_id`,`provider_id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_provider` (`provider_id`),
  KEY `idx_provider_user_id` (`provider_user_id`),
  CONSTRAINT `user_auth_methods_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `user_auth_methods_ibfk_2` FOREIGN KEY (`provider_id`) REFERENCES `auth_providers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chk_user_auth_methods_flags` CHECK (`is_primary_method` in (0,1) and `use_count` >= 0)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user_auth_methods`
--

LOCK TABLES `user_auth_methods` WRITE;
/*!40000 ALTER TABLE `user_auth_methods` DISABLE KEYS */;
INSERT INTO `user_auth_methods` VALUES (1,5,1,'108230023574228583644',1,'2026-02-08 08:14:19','2026-02-08 08:19:34',0),(2,2,1,'117931670655597175684',1,'2026-02-04 11:27:36','2026-02-08 08:10:52',0),(4,1,2,NULL,1,'2026-02-04 10:52:03',NULL,0),(5,3,2,NULL,1,'2026-02-04 11:44:42',NULL,0);
/*!40000 ALTER TABLE `user_auth_methods` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `user_permission_overrides`
--

DROP TABLE IF EXISTS `user_permission_overrides`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_permission_overrides` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `permission_id` int(10) unsigned NOT NULL,
  `is_allowed` tinyint(1) NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `assigned_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user_permission_overrides_user` (`user_id`),
  KEY `idx_user_permission_overrides_permission` (`permission_id`),
  KEY `idx_user_permission_overrides_expires` (`expires_at`),
  KEY `idx_user_permission_overrides_lookup` (`user_id`,`permission_id`,`expires_at`),
  KEY `fk_user_permission_overrides_assigned_by` (`assigned_by`),
  CONSTRAINT `fk_user_permission_overrides_assigned_by` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_user_permission_overrides_permission` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_user_permission_overrides_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user_permission_overrides`
--

LOCK TABLES `user_permission_overrides` WRITE;
/*!40000 ALTER TABLE `user_permission_overrides` DISABLE KEYS */;
/*!40000 ALTER TABLE `user_permission_overrides` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `user_roles`
--

DROP TABLE IF EXISTS `user_roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_roles` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `role_id` int(10) unsigned NOT NULL,
  `assigned_by` int(11) DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_roles_user` (`user_id`),
  KEY `idx_user_roles_role` (`role_id`),
  KEY `idx_user_roles_assigned_by` (`assigned_by`),
  CONSTRAINT `fk_user_roles_assigned_by` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_user_roles_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`),
  CONSTRAINT `fk_user_roles_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user_roles`
--

LOCK TABLES `user_roles` WRITE;
/*!40000 ALTER TABLE `user_roles` DISABLE KEYS */;
INSERT INTO `user_roles` VALUES (1,1,2,NULL,'Backfilled from users.role enum','2026-04-06 04:49:48','2026-04-06 04:49:48'),(2,3,2,NULL,'Backfilled from users.role enum','2026-04-06 04:49:48','2026-04-06 04:49:48'),(3,2,1,NULL,'Backfilled from users.role enum','2026-04-06 04:49:48','2026-04-06 04:49:48'),(4,5,1,NULL,'Backfilled from users.role enum','2026-04-06 04:49:48','2026-04-06 04:49:48');
/*!40000 ALTER TABLE `user_roles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` varchar(20) NOT NULL,
  `name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `course` varchar(255) DEFAULT NULL,
  `google_id` varchar(255) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('Admin','Student') NOT NULL DEFAULT 'Student',
  `status` enum('Pending','Active','Locked') NOT NULL DEFAULT 'Pending',
  `locked_until` datetime DEFAULT NULL,
  `failed_attempts` int(11) NOT NULL DEFAULT 0,
  `last_login` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `profile_picture` varchar(255) DEFAULT NULL,
  `profile_picture_uploaded_at` datetime DEFAULT NULL,
  `rfid_uid` varchar(50) DEFAULT NULL,
  `rfid_registered_at` timestamp NULL DEFAULT NULL,
  `violation_count` int(11) NOT NULL DEFAULT 0,
  `face_registered` tinyint(1) NOT NULL DEFAULT 0,
  `face_registered_at` timestamp NULL DEFAULT NULL,
  `active_violations_count` int(11) NOT NULL DEFAULT 0,
  `gate_mark_count` int(11) NOT NULL DEFAULT 0,
  `terms_accepted_at` datetime DEFAULT NULL,
  `terms_version` varchar(32) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `student_id` (`student_id`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `unique_rfid` (`rfid_uid`),
  UNIQUE KEY `google_id` (`google_id`),
  KEY `idx_student_id` (`student_id`),
  KEY `idx_email` (`email`),
  KEY `idx_role` (`role`),
  KEY `idx_status` (`status`),
  KEY `idx_profile_picture` (`profile_picture`),
  KEY `idx_users_rfid_lookup` (`rfid_uid`,`role`,`status`),
  KEY `idx_users_violations` (`role`,`violation_count`,`status`),
  KEY `idx_users_profile` (`id`,`email`,`profile_picture`),
  KEY `idx_users_face_lookup` (`face_registered`,`role`,`status`),
  KEY `idx_users_gate_mark` (`gate_mark_count`),
  KEY `idx_users_role_status` (`role`,`status`),
  KEY `idx_users_deleted_at` (`deleted_at`),
  KEY `idx_users_active_students` (`role`,`status`,`deleted_at`),
  CONSTRAINT `chk_users_non_negative` CHECK (`failed_attempts` >= 0 and `violation_count` >= 0),
  CONSTRAINT `chk_users_face_registered_bool` CHECK (`face_registered` in (0,1)),
  CONSTRAINT `chk_users_gate_mark_count` CHECK (`gate_mark_count` >= 0),
  CONSTRAINT `chk_users_active_violations` CHECK (`active_violations_count` >= 0)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'ADMIN001','System Admin','admin@pcu.edu.ph',NULL,NULL,'$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','Admin','Active',NULL,0,NULL,'2026-02-04 10:52:03','2026-02-04 10:52:03',NULL,NULL,NULL,NULL,0,0,NULL,0,0,NULL,NULL,NULL),(2,'202232903','Mark Jason Briones Ramoss','mrk.jason118@gmail.com','BS Information Technology','117931670655597175684','$2y$10$FNjaXw2zWHfTRMYeYOu1j.6SewRlL7KfwJG1xR6iDl8p8UXXWydg2','Student','Active',NULL,0,'2026-04-06 20:42:59','2026-02-04 11:27:36','2026-04-06 12:42:59','573a472de056478a7fd5e57d2c593268.jpg',NULL,'0014973874','2026-02-21 04:50:18',9,1,'2026-03-19 11:56:30',0,0,NULL,NULL,NULL),(3,'ADMIN-001','System Administrator','jeysidelima04@gmail.com',NULL,NULL,'$argon2id$v=19$m=65536,t=4,p=1$UVEucG03OUZENEVPNlhvSA$9l8SnovYbeEdFd1TovGNvhpiQMCP2PdRtmsx1d1Ttwk','Admin','Active',NULL,0,NULL,'2026-02-04 11:44:42','2026-03-17 13:20:52',NULL,NULL,NULL,NULL,0,0,NULL,0,0,NULL,NULL,NULL),(5,'TEMP-1770538459','Joshua Morales','morales.josh133@gmail.com',NULL,'108230023574228583644','$2y$10$7GlTtRmZWWKMw5.q49u7uutm01nLCmdYMyMndMFgdLaMfhrXFBklO','Student','Active',NULL,0,'2026-02-08 16:18:21','2026-02-08 08:14:19','2026-03-19 11:51:15',NULL,NULL,NULL,NULL,2,0,NULL,0,0,NULL,NULL,NULL);
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Temporary table structure for view `v_active_rfid_cards`
--

DROP TABLE IF EXISTS `v_active_rfid_cards`;
/*!50001 DROP VIEW IF EXISTS `v_active_rfid_cards`*/;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8;
/*!50001 CREATE VIEW `v_active_rfid_cards` AS SELECT
 1 AS `user_id`,
  1 AS `student_id`,
  1 AS `name`,
  1 AS `email`,
  1 AS `rfid_uid`,
  1 AS `registered_at`,
  1 AS `registered_by`,
  1 AS `violation_count` */;
SET character_set_client = @saved_cs_client;

--
-- Temporary table structure for view `v_students_complete`
--

DROP TABLE IF EXISTS `v_students_complete`;
/*!50001 DROP VIEW IF EXISTS `v_students_complete`*/;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8;
/*!50001 CREATE VIEW `v_students_complete` AS SELECT
 1 AS `id`,
  1 AS `student_id`,
  1 AS `name`,
  1 AS `email`,
  1 AS `role`,
  1 AS `status`,
  1 AS `created_at`,
  1 AS `last_login`,
  1 AS `profile_picture`,
  1 AS `profile_picture_uploaded_at`,
  1 AS `rfid_uid`,
  1 AS `rfid_registered_at`,
  1 AS `violation_count`,
  1 AS `bio`,
  1 AS `phone`,
  1 AS `emergency_contact`,
  1 AS `emergency_phone`,
  1 AS `total_violations`,
  1 AS `last_violation_date` */;
SET character_set_client = @saved_cs_client;

--
-- Table structure for table `violation_categories`
--

DROP TABLE IF EXISTS `violation_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `violation_categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `type` enum('minor','major','grave') NOT NULL,
  `description` text DEFAULT NULL,
  `default_sanction` varchar(255) DEFAULT NULL,
  `article_reference` varchar(100) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_type` (`type`),
  KEY `idx_is_active` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=166 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `violation_categories`
--

LOCK TABLES `violation_categories` WRITE;
/*!40000 ALTER TABLE `violation_categories` DISABLE KEYS */;
INSERT INTO `violation_categories` VALUES (1,'No Physical ID','minor','Student entered school premises without carrying their physical student ID card.','Verbal Warning / Written Apology','Article 12, Section 1.a',0,'2026-03-18 13:29:20'),(2,'Improper Wearing of ID/Uniform','minor','Failure to wear the prescribed school uniform or student ID properly while on campus.','Verbal Warning','Article 12, Section 1.b',0,'2026-03-18 13:29:20'),(3,'Littering','minor','Littering','1st: DIS 1, 2nd: DIS 2, 3rd: DIS 3','Article 12 (Minor)',0,'2026-03-18 13:29:20'),(4,'Loitering in Restricted Areas','minor','Being in off-limits or restricted areas of the campus without authorization.','Verbal Warning','Article 12, Section 1.d',0,'2026-03-18 13:29:20'),(5,'Creating Noise or Disturbance','minor','Causing unnecessary noise or disturbance that disrupts classes or school activities.','Written Apology','Article 12, Section 1.e',0,'2026-03-18 13:29:20'),(6,'Unauthorized Posting','minor','Posting or distributing materials on campus without proper authorization from the administration.','Confiscation / Written Apology','Article 12, Section 1.f',0,'2026-03-18 13:29:20'),(7,'Eating in Restricted Areas','minor','Eating or drinking in classrooms, laboratories, or other restricted areas.','Verbal Warning','Article 12, Section 1.g',0,'2026-03-18 13:29:20'),(8,'Minor Discourtesy','minor','Minor acts of discourtesy or impoliteness toward fellow students, faculty, or staff.','Written Apology','Article 12, Section 1.h',0,'2026-03-18 13:29:20'),(9,'Cutting Classes','major','Absence from scheduled classes without valid reason or prior approval from the instructor.','Conference with Parents / Suspension','Article 12, Section 2.a',0,'2026-03-18 13:29:20'),(10,'Disrespectful Behavior','major','Disrespectful or defiant behavior toward faculty members, administrators, or staff.','Suspension / Conference','Article 12, Section 2.b',0,'2026-03-18 13:29:20'),(11,'Smoking on Campus','major','Smoking or vaping within the school premises including buildings and grounds.','Suspension','Article 12, Section 2.c',0,'2026-03-18 13:29:20'),(12,'Gambling','major','Engaging in any form of gambling within the school premises.','Suspension / Community Service','Article 12, Section 2.d',0,'2026-03-18 13:29:20'),(13,'Unauthorized Use of Facilities','major','Using school facilities, equipment, or property without proper authorization.','Suspension / Restitution','Article 12, Section 2.e',0,'2026-03-18 13:29:20'),(14,'Falsification of Documents','major','Falsifying, altering, or misusing school documents, records, or identification.','Suspension / Possible Expulsion','Article 12, Section 2.f',0,'2026-03-18 13:29:20'),(15,'Bullying or Harassment','major','Engaging in bullying, intimidation, or harassment of any member of the school community.','Suspension / Counseling','Article 12, Section 2.g',0,'2026-03-18 13:29:20'),(16,'Cheating in Examinations','major','Cheating, using unauthorized materials, or copying during examinations or academic assessments.','Automatic Failure / Suspension','Article 12, Section 2.h',0,'2026-03-18 13:29:20'),(17,'Unauthorized Solicitation','major','Soliciting funds, selling merchandise, or conducting commercial activities without school approval.','Confiscation / Suspension','Article 12, Section 2.i',0,'2026-03-18 13:29:20'),(18,'Disruption of School Activities','major','Deliberately disrupting official school programs, activities, or ceremonies.','Suspension','Article 12, Section 2.j',0,'2026-03-18 13:29:20'),(19,'Physical Assault','grave','Inflicting physical harm or bodily injury on any member of the school community.','Expulsion','Article 12, Section 3.a',0,'2026-03-18 13:29:20'),(20,'Possession of Illegal Drugs','grave','Possession, use, sale, or distribution of illegal drugs or controlled substances on campus.','Expulsion / Legal Action','Article 12, Section 3.b',0,'2026-03-18 13:29:20'),(21,'Theft or Robbery','grave','Stealing or attempting to steal property belonging to the school, students, faculty, or staff.','Expulsion / Legal Action','Article 12, Section 3.c',0,'2026-03-18 13:29:20'),(22,'Possession of Deadly Weapons','grave','Bringing or possessing firearms, knives, or any deadly weapon within school premises.','Expulsion / Legal Action','Article 12, Section 3.d',0,'2026-03-18 13:29:20'),(23,'Sexual Harassment or Assault','grave','Any form of sexual harassment, sexual assault, or acts of lasciviousness against any person on campus.','Expulsion / Legal Action','Article 12, Section 3.e',0,'2026-03-18 13:29:20'),(24,'Vandalism','grave','Willful damage, destruction, or defacement of school property, facilities, or equipment.','Expulsion / Restitution','Article 12, Section 3.f',0,'2026-03-18 13:29:20'),(25,'Hazing','grave','Hazing','1st: DIS 3 or DIS 4, 2nd: DIS 4','Article 12 (Major)',0,'2026-03-18 13:29:20'),(26,'Arson','grave','Deliberately setting fire to, or attempting to burn, school property or facilities.','Expulsion / Legal Action','Article 12, Section 3.h',0,'2026-03-18 13:29:20'),(27,'Threatening or Intimidating Behavior','grave','Making threats of violence, intimidation, or coercion against any member of the school community.','Suspension / Expulsion','Article 12, Section 3.i',0,'2026-03-18 13:29:20'),(28,'Forgery or Fraud','grave','Committing forgery or fraud involving school documents, credentials, or academic records.','Expulsion','Article 12, Section 3.j',0,'2026-03-18 13:29:20'),(29,'Involvement in Illegal Activities','grave','Engaging in illegal activities within or outside the campus that bring disrepute to the institution.','Expulsion / Legal Action','Article 12, Section 3.k',0,'2026-03-18 13:29:20'),(30,'Gross Misconduct','grave','Any act of gross misconduct or moral turpitude that brings serious harm or dishonor to the university.','Expulsion','Article 12, Section 3.l',0,'2026-03-18 13:29:20'),(31,'No Physical ID','minor','Student entered school premises without carrying their physical student ID card.','Verbal Warning / Written Apology','Article 12, Section 1.a',0,'2026-03-25 14:50:47'),(32,'Improper Wearing of ID/Uniform','minor','Failure to wear the prescribed school uniform or student ID properly while on campus.','Verbal Warning','Article 12, Section 1.b',0,'2026-03-25 14:50:47'),(33,'Littering','minor','Throwing of trash or refuse in areas not designated as waste disposal areas.','Community Service','Article 12, Section 1.c',0,'2026-03-25 14:50:47'),(34,'Loitering in Restricted Areas','minor','Being in off-limits or restricted areas of the campus without authorization.','Verbal Warning','Article 12, Section 1.d',0,'2026-03-25 14:50:47'),(35,'Creating Noise or Disturbance','minor','Causing unnecessary noise or disturbance that disrupts classes or school activities.','Written Apology','Article 12, Section 1.e',0,'2026-03-25 14:50:47'),(36,'Unauthorized Posting','minor','Posting or distributing materials on campus without proper authorization from the administration.','Confiscation / Written Apology','Article 12, Section 1.f',0,'2026-03-25 14:50:47'),(37,'Eating in Restricted Areas','minor','Eating or drinking in classrooms, laboratories, or other restricted areas.','Verbal Warning','Article 12, Section 1.g',0,'2026-03-25 14:50:47'),(38,'Minor Discourtesy','minor','Minor acts of discourtesy or impoliteness toward fellow students, faculty, or staff.','Written Apology','Article 12, Section 1.h',0,'2026-03-25 14:50:47'),(39,'Cutting Classes','major','Absence from scheduled classes without valid reason or prior approval from the instructor.','Conference with Parents / Suspension','Article 12, Section 2.a',0,'2026-03-25 14:50:47'),(40,'Disrespectful Behavior','major','Disrespectful or defiant behavior toward faculty members, administrators, or staff.','Suspension / Conference','Article 12, Section 2.b',0,'2026-03-25 14:50:47'),(41,'Smoking on Campus','major','Smoking or vaping within the school premises including buildings and grounds.','Suspension','Article 12, Section 2.c',0,'2026-03-25 14:50:47'),(42,'Gambling','major','Engaging in any form of gambling within the school premises.','Suspension / Community Service','Article 12, Section 2.d',0,'2026-03-25 14:50:47'),(43,'Unauthorized Use of Facilities','major','Using school facilities, equipment, or property without proper authorization.','Suspension / Restitution','Article 12, Section 2.e',0,'2026-03-25 14:50:47'),(44,'Falsification of Documents','major','Falsifying, altering, or misusing school documents, records, or identification.','Suspension / Possible Expulsion','Article 12, Section 2.f',0,'2026-03-25 14:50:47'),(45,'Bullying or Harassment','major','Engaging in bullying, intimidation, or harassment of any member of the school community.','Suspension / Counseling','Article 12, Section 2.g',0,'2026-03-25 14:50:47'),(46,'Cheating in Examinations','major','Cheating, using unauthorized materials, or copying during examinations or academic assessments.','Automatic Failure / Suspension','Article 12, Section 2.h',0,'2026-03-25 14:50:47'),(47,'Unauthorized Solicitation','major','Soliciting funds, selling merchandise, or conducting commercial activities without school approval.','Confiscation / Suspension','Article 12, Section 2.i',0,'2026-03-25 14:50:47'),(48,'Disruption of School Activities','major','Deliberately disrupting official school programs, activities, or ceremonies.','Suspension','Article 12, Section 2.j',0,'2026-03-25 14:50:47'),(49,'Physical Assault','grave','Inflicting physical harm or bodily injury on any member of the school community.','Expulsion','Article 12, Section 3.a',0,'2026-03-25 14:50:47'),(50,'Possession of Illegal Drugs','grave','Possession, use, sale, or distribution of illegal drugs or controlled substances on campus.','Expulsion / Legal Action','Article 12, Section 3.b',0,'2026-03-25 14:50:47'),(51,'Theft or Robbery','grave','Stealing or attempting to steal property belonging to the school, students, faculty, or staff.','Expulsion / Legal Action','Article 12, Section 3.c',0,'2026-03-25 14:50:47'),(52,'Possession of Deadly Weapons','grave','Bringing or possessing firearms, knives, or any deadly weapon within school premises.','Expulsion / Legal Action','Article 12, Section 3.d',0,'2026-03-25 14:50:47'),(53,'Sexual Harassment or Assault','grave','Any form of sexual harassment, sexual assault, or acts of lasciviousness against any person on campus.','Expulsion / Legal Action','Article 12, Section 3.e',0,'2026-03-25 14:50:47'),(54,'Vandalism','grave','Willful damage, destruction, or defacement of school property, facilities, or equipment.','Expulsion / Restitution','Article 12, Section 3.f',0,'2026-03-25 14:50:47'),(55,'Hazing','grave','Planning, organizing, or participating in hazing activities in any form, as prohibited by R.A. 8049.','Expulsion / Legal Action','Article 12, Section 3.g',0,'2026-03-25 14:50:47'),(56,'Arson','grave','Deliberately setting fire to, or attempting to burn, school property or facilities.','Expulsion / Legal Action','Article 12, Section 3.h',0,'2026-03-25 14:50:47'),(57,'Threatening or Intimidating Behavior','grave','Making threats of violence, intimidation, or coercion against any member of the school community.','Suspension / Expulsion','Article 12, Section 3.i',0,'2026-03-25 14:50:47'),(58,'Forgery or Fraud','grave','Committing forgery or fraud involving school documents, credentials, or academic records.','Expulsion','Article 12, Section 3.j',0,'2026-03-25 14:50:47'),(59,'Involvement in Illegal Activities','grave','Engaging in illegal activities within or outside the campus that bring disrepute to the institution.','Expulsion / Legal Action','Article 12, Section 3.k',0,'2026-03-25 14:50:47'),(60,'Gross Misconduct','grave','Any act of gross misconduct or moral turpitude that brings serious harm or dishonor to the university.','Expulsion','Article 12, Section 3.l',0,'2026-03-25 14:50:47'),(61,'Uniform Violations','minor','Uniform-related violations under Article 12 minor offenses.','1st: DIS 1, 2nd: DIS 2, 3rd: DIS 3','Article 12 (Minor)',0,'2026-04-06 00:02:54'),(62,'PDA','minor','Public display of affection classified as a minor offense.','1st: DIS 1, 2nd: DIS 2, 3rd: DIS 3','Article 12 (Minor)',0,'2026-04-06 00:02:54'),(63,'Gadget Misuse','minor','Improper gadget use under school discipline policy.','1st: DIS 1, 2nd: DIS 2, 3rd: DIS 3','Article 12 (Minor)',0,'2026-04-06 00:02:54'),(64,'Cheating','major','Cheating offense under Article 12 moderate offenses.','1st: DIS 2, 2nd: DIS 3, 3rd: DIS 4','Article 12 (Moderate)',0,'2026-04-06 00:02:54'),(65,'Fighting','major','Fighting offense under Article 12 moderate offenses.','1st: DIS 2, 2nd: DIS 3, 3rd: DIS 4','Article 12 (Moderate)',0,'2026-04-06 00:02:54'),(66,'Alcohol Possession','major','Possession of alcohol under Article 12 moderate offenses.','1st: DIS 2, 2nd: DIS 3, 3rd: DIS 4','Article 12 (Moderate)',0,'2026-04-06 00:02:54'),(67,'Assault','grave','Assault offense under Article 12 major offenses.','1st: DIS 3 or DIS 4, 2nd: DIS 4','Article 12 (Major)',0,'2026-04-06 00:02:54'),(68,'Theft','grave','Theft','1st: DIS 3 or DIS 4, 2nd: DIS 4','Article 12 (Major)',0,'2026-04-06 00:02:54'),(69,'Drugs','grave','Drug-related offense under Article 12 major offenses.','1st: DIS 3 or DIS 4, 2nd: DIS 4','Article 12 (Major)',0,'2026-04-06 00:02:54'),(70,'Sexual Harassment','grave','Sexual harassment offense under Article 12 major offenses.','1st: DIS 3 or DIS 4, 2nd: DIS 4','Article 12 (Major)',0,'2026-04-06 00:02:54'),(71,'Violation of school\'s uniform, general appearance, and hair grooming policy.','minor','Violation of school\'s uniform, general appearance, and hair grooming policy.','1st: DIS 1, 2nd: DIS 2, 3rd: DIS 3','Article 12 (Minor)',0,'2026-04-06 00:17:04'),(72,'PDA or public display of affection','minor','PDA or public display of affection','1st: DIS 1, 2nd: DIS 2, 3rd: DIS 3','Article 12 (Minor)',0,'2026-04-06 00:17:04'),(73,'Inexcusable disturbance during classes and school functions','minor','Inexcusable disturbance during classes and school functions','1st: DIS 1, 2nd: DIS 2, 3rd: DIS 3','Article 12 (Minor)',0,'2026-04-06 00:17:04'),(74,'Unauthorized usage of gadgets during classes or spiritual activities.','minor','Unauthorized usage of gadgets during classes or spiritual activities.','1st: DIS 1, 2nd: DIS 2, 3rd: DIS 3','Article 12 (Minor)',0,'2026-04-06 00:17:04'),(75,'Violation of School ID policy','minor','Violation of School ID policy','1st: DIS 1, 2nd: DIS 2, 3rd: DIS 3','Article 12 (Minor)',0,'2026-04-06 00:17:04'),(76,'Playing of computer games, browsing of social media sites, or any unwarranted activities inside the computer laboratories','minor','Playing of computer games, browsing of social media sites, or any unwarranted activities inside the computer laboratories','1st: DIS 1, 2nd: DIS 2, 3rd: DIS 3','Article 12 (Minor)',0,'2026-04-06 00:17:04'),(77,'Refusal to comply with disciplinary procedure, imposed interventions, instruction, counseling notices, and other matters relative thereto.','major','Refusal to comply with disciplinary procedure, imposed interventions, instruction, counseling notices, and other matters relative thereto.','1st: DIS 2, 2nd: DIS 3, 3rd: DIS 4','Article 12 (Moderate)',0,'2026-04-06 00:17:04'),(78,'Entering school premises under the influence of alcohol.','major','Entering school premises under the influence of alcohol.','1st: DIS 2, 2nd: DIS 3, 3rd: DIS 4','Article 12 (Moderate)',0,'2026-04-06 00:17:04'),(79,'Bringing gambling items inside the campus.','major','Bringing gambling items inside the campus.','1st: DIS 2, 2nd: DIS 3, 3rd: DIS 4','Article 12 (Moderate)',0,'2026-04-06 00:17:04'),(80,'Vandalism or writing, removing, posting, or altering anything on a bulletin board, building wall, or any school property.','major','Vandalism or writing, removing, posting, or altering anything on a bulletin board, building wall, or any school property.','1st: DIS 2, 2nd: DIS 3, 3rd: DIS 4','Article 12 (Moderate)',0,'2026-04-06 00:17:04'),(81,'Disorderly or immoral conduct or expression.','major','Disorderly or immoral conduct or expression.','1st: DIS 2, 2nd: DIS 3, 3rd: DIS 4','Article 12 (Moderate)',0,'2026-04-06 00:17:04'),(82,'Gossiping or Intriguing against honor','major','Gossiping or Intriguing against honor','1st: DIS 2, 2nd: DIS 3, 3rd: DIS 4','Article 12 (Moderate)',0,'2026-04-06 00:17:04'),(83,'Possession of pornographic materials inside the school either in printed or digital form.','major','Possession of pornographic materials inside the school either in printed or digital form.','1st: DIS 2, 2nd: DIS 3, 3rd: DIS 4','Article 12 (Moderate)',0,'2026-04-06 00:17:04'),(84,'All forms of cheating inside the class (during quizzes, examinations, and other learning assessments).','major','All forms of cheating inside the class (during quizzes, examinations, and other learning assessments).','1st: DIS 2, 2nd: DIS 3, 3rd: DIS 4','Article 12 (Moderate)',0,'2026-04-06 00:17:04'),(85,'Physical attempt to cause physical harm to any person inside the school premises.','major','Physical attempt to cause physical harm to any person inside the school premises.','1st: DIS 2, 2nd: DIS 3, 3rd: DIS 4','Article 12 (Moderate)',0,'2026-04-06 00:17:04'),(86,'Possession of any smoking paraphernalia or alcohol inside the campus.','major','Possession of any smoking paraphernalia or alcohol inside the campus.','1st: DIS 2, 2nd: DIS 3, 3rd: DIS 4','Article 12 (Moderate)',0,'2026-04-06 00:17:04'),(87,'Unauthorized usage and disposition of any of the school\'s property and/or facility.','major','Unauthorized usage and disposition of any of the school\'s property and/or facility.','1st: DIS 2, 2nd: DIS 3, 3rd: DIS 4','Article 12 (Moderate)',0,'2026-04-06 00:17:04'),(88,'Repeated cutting classes and/or loitering during classes','major','Repeated cutting classes and/or loitering during classes','1st: DIS 2, 2nd: DIS 3, 3rd: DIS 4','Article 12 (Moderate)',0,'2026-04-06 00:17:04'),(89,'Inexcusable utilization of unauthorized access to or from school premises.','major','Inexcusable utilization of unauthorized access to or from school premises.','1st: DIS 2, 2nd: DIS 3, 3rd: DIS 4','Article 12 (Moderate)',0,'2026-04-06 00:17:04'),(90,'Non-disclosure of information that will compromise the health or safety of students.','major','Non-disclosure of information that will compromise the health or safety of students.','1st: DIS 2, 2nd: DIS 3, 3rd: DIS 4','Article 12 (Moderate)',0,'2026-04-06 00:17:04'),(91,'Sabotage or Unauthorized Interference with the University\'s Information and Communication Technology (ICT) Resources','major','Sabotage or Unauthorized Interference with the University\'s Information and Communication Technology (ICT) Resources','1st: DIS 2, 2nd: DIS 3, 3rd: DIS 4','Article 12 (Moderate)',0,'2026-04-06 00:17:04'),(92,'Involvement in fights and any form of violence inside the school premises or during school-related activities.','major','Involvement in fights and any form of violence inside the school premises or during school-related activities.','1st: DIS 2, 2nd: DIS 3, 3rd: DIS 4','Article 12 (Moderate)',0,'2026-04-06 00:17:04'),(93,'Violation of school\'s social media policy.','major','Violation of school\'s social media policy.','1st: DIS 2, 2nd: DIS 3, 3rd: DIS 4','Article 12 (Moderate)',0,'2026-04-06 00:17:04'),(94,'Direct physical assault upon any student, faculty, staff, or administrator resulting in physical injury.','grave','Direct physical assault upon any student, faculty, staff, or administrator resulting in physical injury.','1st: DIS 3 or DIS 4, 2nd: DIS 4','Article 12 (Major)',0,'2026-04-06 00:17:04'),(95,'Robbery','grave','Robbery','1st: DIS 3 or DIS 4, 2nd: DIS 4','Article 12 (Major)',0,'2026-04-06 00:17:04'),(96,'Organization-related violence','grave','Organization-related violence','1st: DIS 3 or DIS 4, 2nd: DIS 4','Article 12 (Major)',0,'2026-04-06 00:17:04'),(97,'Drug-related offense','grave','Drug-related offense','1st: DIS 3 or DIS 4, 2nd: DIS 4','Article 12 (Major)',0,'2026-04-06 00:17:04'),(98,'Possession of deadly weapons, combustible, or explosive materials at school or to any recognized activity held outside of the school','grave','Possession of deadly weapons, combustible, or explosive materials at school or to any recognized activity held outside of the school','1st: DIS 3 or DIS 4, 2nd: DIS 4','Article 12 (Major)',0,'2026-04-06 00:17:04'),(99,'Willful destruction of the school\'s property or facility','grave','Willful destruction of the school\'s property or facility','1st: DIS 3 or DIS 4, 2nd: DIS 4','Article 12 (Major)',0,'2026-04-06 00:17:04'),(100,'Any form of forgery and falsification of faculty, staff, and administrator\'s signature.','grave','Any form of forgery and falsification of faculty, staff, and administrator\'s signature.','1st: DIS 3 or DIS 4, 2nd: DIS 4','Article 12 (Major)',0,'2026-04-06 00:17:04'),(101,'Submission of fraudulent documents and/or falsification of documents','grave','Submission of fraudulent documents and/or falsification of documents','1st: DIS 3 or DIS 4, 2nd: DIS 4','Article 12 (Major)',0,'2026-04-06 00:17:04'),(102,'Unlawful mass action and barricade','grave','Unlawful mass action and barricade','1st: DIS 3 or DIS 4, 2nd: DIS 4','Article 12 (Major)',0,'2026-04-06 00:17:04'),(103,'Sexual harassment and any of its forms.','grave','Sexual harassment and any of its forms.','1st: DIS 3 or DIS 4, 2nd: DIS 4','Article 12 (Major)',0,'2026-04-06 00:17:04'),(104,'3.12. Submission of someone else\'s work in their own name (Complete plagiarism)','grave','3.12. Submission of someone else\'s work in their own name (Complete plagiarism)','1st: DIS 3 or DIS 4, 2nd: DIS 4','Article 12 (Major)',0,'2026-04-06 00:17:04'),(105,'Tampering of school records','grave','Tampering of school records','1st: DIS 3 or DIS 4, 2nd: DIS 4','Article 12 (Major)',0,'2026-04-06 00:17:04'),(106,'Any form of misrepresentation that may cause loss or damage to the school.','grave','Any form of misrepresentation that may cause loss or damage to the school.','1st: DIS 3 or DIS 4, 2nd: DIS 4','Article 12 (Major)',0,'2026-04-06 00:17:04'),(107,'Unjust vexation','grave','Unjust vexation','1st: DIS 3 or DIS 4, 2nd: DIS 4','Article 12 (Major)',0,'2026-04-06 00:17:04'),(108,'Slanderous/Oral defamatory statement or accusation against any student or employee of the University.','grave','Slanderous/Oral defamatory statement or accusation against any student or employee of the University.','1st: DIS 3 or DIS 4, 2nd: DIS 4','Article 12 (Major)',0,'2026-04-06 00:17:04'),(109,'Libelous statement or accusation by means of writings or similar means against any student or employee of the University (including cyber libel).','grave','Libelous statement or accusation by means of writings or similar means against any student or employee of the University (including cyber libel).','1st: DIS 3 or DIS 4, 2nd: DIS 4','Article 12 (Major)',0,'2026-04-06 00:17:04'),(110,'Bullying or other forms of bullying (cyber-bullying, etc.).','grave','Bullying or other forms of bullying (cyber-bullying, etc.).','1st: DIS 3 or DIS 4, 2nd: DIS 4','Article 12 (Major)',0,'2026-04-06 00:17:04'),(111,'Sexual intercourse while inside the school.','grave','Sexual intercourse while inside the school.','1st: DIS 3 or DIS 4, 2nd: DIS 4','Article 12 (Major)',0,'2026-04-06 00:17:04'),(112,'Unauthorized collection of money in any transaction either personal or pertaining to the University or any of its departments, and recognized student councils, clubs, and organizations','grave','Unauthorized collection of money in any transaction either personal or pertaining to the University or any of its departments, and recognized student councils, clubs, and organizations','1st: DIS 3 or DIS 4, 2nd: DIS 4','Article 12 (Major)',0,'2026-04-06 00:17:04'),(113,'Acts or publishing or circulating false and unfounded information that would malign the good name and reputation of the University, its officials, faculty, staff, and students','grave','Acts or publishing or circulating false and unfounded information that would malign the good name and reputation of the University, its officials, faculty, staff, and students','1st: DIS 3 or DIS 4, 2nd: DIS 4','Article 12 (Major)',0,'2026-04-06 00:17:04'),(114,'Preventing and/or threatening any student or school personnel from entering school premises to attend their classes and/or discharge their duties','grave','Preventing and/or threatening any student or school personnel from entering school premises to attend their classes and/or discharge their duties','1st: DIS 3 or DIS 4, 2nd: DIS 4','Article 12 (Major)',0,'2026-04-06 00:17:04'),(115,'Repeated willful violations of the school\'s rules and regulations including the commission of a fourth minor offense.','grave','Repeated willful violations of the school\'s rules and regulations including the commission of a fourth minor offense.','1st: DIS 3 or DIS 4, 2nd: DIS 4','Article 12 (Major)',0,'2026-04-06 00:17:04'),(116,'Creating and/or joining unauthorized or illegal student organizations.','grave','Creating and/or joining unauthorized or illegal student organizations.','1st: DIS 3 or DIS 4, 2nd: DIS 4','Article 12 (Major)',0,'2026-04-06 00:17:04'),(117,'Violation of school\'s uniform, general appearance, and hair grooming policy.','minor','Violation of school\'s uniform, general appearance, and hair grooming policy.',NULL,NULL,1,'2026-04-06 06:07:25'),(118,'Littering','minor','Littering',NULL,NULL,1,'2026-04-06 06:07:25'),(119,'PDA or public display of affection','minor','PDA or public display of affection',NULL,NULL,1,'2026-04-06 06:07:25'),(120,'Inexcusable disturbance during classes and school functions','minor','Inexcusable disturbance during classes and school functions',NULL,NULL,1,'2026-04-06 06:07:25'),(121,'Unauthorized usage of gadgets during classes or spiritual activities.','minor','Unauthorized usage of gadgets during classes or spiritual activities.',NULL,NULL,1,'2026-04-06 06:07:25'),(122,'Violation of School ID policy','minor','Violation of School ID policy',NULL,NULL,1,'2026-04-06 06:07:25'),(123,'Playing of computer games, browsing of social media sites, or any unwarranted activities inside the computer laboratories','minor','Playing of computer games, browsing of social media sites, or any unwarranted activities inside the computer laboratories',NULL,NULL,1,'2026-04-06 06:07:25'),(124,'Refusal to comply with disciplinary procedure, imposed interventions, instruction, counseling notices, and other matters relative thereto.','major','Refusal to comply with disciplinary procedure, imposed interventions, instruction, counseling notices, and other matters relative thereto.',NULL,NULL,1,'2026-04-06 06:07:25'),(125,'Entering school premises under the influence of alcohol.','major','Entering school premises under the influence of alcohol.',NULL,NULL,1,'2026-04-06 06:07:25'),(126,'Bringing gambling items inside the campus.','major','Bringing gambling items inside the campus.',NULL,NULL,1,'2026-04-06 06:07:25'),(127,'Vandalism or writing, removing, posting, or altering anything on a bulletin board, building wall, or any school property.','major','Vandalism or writing, removing, posting, or altering anything on a bulletin board, building wall, or any school property.',NULL,NULL,1,'2026-04-06 06:07:25'),(128,'Disorderly or immoral conduct or expression.','major','Disorderly or immoral conduct or expression.',NULL,NULL,1,'2026-04-06 06:07:25'),(129,'Gossiping or Intriguing against honor','major','Gossiping or Intriguing against honor',NULL,NULL,1,'2026-04-06 06:07:25'),(130,'Possession of pornographic materials inside the school either in printed or digital form.','major','Possession of pornographic materials inside the school either in printed or digital form.',NULL,NULL,1,'2026-04-06 06:07:25'),(131,'All forms of cheating inside the class (during quizzes, examinations, and other learning assessments).','major','All forms of cheating inside the class (during quizzes, examinations, and other learning assessments).',NULL,NULL,1,'2026-04-06 06:07:25'),(132,'Physical attempt to cause physical harm to any person inside the school premises.','major','Physical attempt to cause physical harm to any person inside the school premises.',NULL,NULL,1,'2026-04-06 06:07:25'),(133,'Possession of any smoking paraphernalia or alcohol inside the campus.','major','Possession of any smoking paraphernalia or alcohol inside the campus.',NULL,NULL,1,'2026-04-06 06:07:25'),(134,'Unauthorized usage and disposition of any of the school\'s property and/or facility.','major','Unauthorized usage and disposition of any of the school\'s property and/or facility.',NULL,NULL,1,'2026-04-06 06:07:25'),(135,'Repeated cutting classes and/or loitering during classes','major','Repeated cutting classes and/or loitering during classes',NULL,NULL,1,'2026-04-06 06:07:25'),(136,'Inexcusable utilization of unauthorized access to or from school premises.','major','Inexcusable utilization of unauthorized access to or from school premises.',NULL,NULL,1,'2026-04-06 06:07:25'),(137,'Non-disclosure of information that will compromise the health or safety of students.','major','Non-disclosure of information that will compromise the health or safety of students.',NULL,NULL,1,'2026-04-06 06:07:25'),(138,'Sabotage or Unauthorized Interference with the University\'s Information and Communication Technology (ICT) Resources','major','Sabotage or Unauthorized Interference with the University\'s Information and Communication Technology (ICT) Resources',NULL,NULL,1,'2026-04-06 06:07:25'),(139,'Involvement in fights and any form of violence inside the school premises or during school-related activities.','major','Involvement in fights and any form of violence inside the school premises or during school-related activities.',NULL,NULL,1,'2026-04-06 06:07:25'),(140,'Violation of school\'s social media policy.','major','Violation of school\'s social media policy.',NULL,NULL,1,'2026-04-06 06:07:25'),(141,'Direct physical assault upon any student, faculty, staff, or administrator resulting in physical injury.','grave','Direct physical assault upon any student, faculty, staff, or administrator resulting in physical injury.',NULL,NULL,1,'2026-04-06 06:07:25'),(142,'Theft','grave','Theft',NULL,NULL,1,'2026-04-06 06:07:25'),(143,'Robbery','grave','Robbery',NULL,NULL,1,'2026-04-06 06:07:25'),(144,'Hazing','grave','Hazing',NULL,NULL,1,'2026-04-06 06:07:25'),(145,'Organization-related violence','grave','Organization-related violence',NULL,NULL,1,'2026-04-06 06:07:25'),(146,'Drug-related offense','grave','Drug-related offense',NULL,NULL,1,'2026-04-06 06:07:25'),(147,'Possession of deadly weapons, combustible, or explosive materials at school or to any recognized activity held outside of the school','grave','Possession of deadly weapons, combustible, or explosive materials at school or to any recognized activity held outside of the school',NULL,NULL,1,'2026-04-06 06:07:25'),(148,'Willful destruction of the school\'s property or facility','grave','Willful destruction of the school\'s property or facility',NULL,NULL,1,'2026-04-06 06:07:25'),(149,'Any form of forgery and falsification of faculty, staff, and administrator\'s signature.','grave','Any form of forgery and falsification of faculty, staff, and administrator\'s signature.',NULL,NULL,1,'2026-04-06 06:07:25'),(150,'Submission of fraudulent documents and/or falsification of documents','grave','Submission of fraudulent documents and/or falsification of documents',NULL,NULL,1,'2026-04-06 06:07:25'),(151,'Unlawful mass action and barricade','grave','Unlawful mass action and barricade',NULL,NULL,1,'2026-04-06 06:07:25'),(152,'Sexual harassment and any of its forms.','grave','Sexual harassment and any of its forms.',NULL,NULL,1,'2026-04-06 06:07:25'),(153,'Submission of someone else\'s work in their own name (Complete plagiarism)','grave','Submission of someone else\'s work in their own name (Complete plagiarism)',NULL,NULL,1,'2026-04-06 06:07:25'),(154,'Tampering of school records','grave','Tampering of school records',NULL,NULL,1,'2026-04-06 06:07:25'),(155,'Any form of misrepresentation that may cause loss or damage to the school.','grave','Any form of misrepresentation that may cause loss or damage to the school.',NULL,NULL,1,'2026-04-06 06:07:25'),(156,'Unjust vexation','grave','Unjust vexation',NULL,NULL,1,'2026-04-06 06:07:25'),(157,'Slanderous/Oral defamatory statement or accusation against any student or employee of the University.','grave','Slanderous/Oral defamatory statement or accusation against any student or employee of the University.',NULL,NULL,1,'2026-04-06 06:07:25'),(158,'Libelous statement or accusation by means of writings or similar means against any student or employee of the University (including cyber libel).','grave','Libelous statement or accusation by means of writings or similar means against any student or employee of the University (including cyber libel).',NULL,NULL,1,'2026-04-06 06:07:25'),(159,'Bullying or other forms of bullying (cyber-bullying, etc.).','grave','Bullying or other forms of bullying (cyber-bullying, etc.).',NULL,NULL,1,'2026-04-06 06:07:25'),(160,'Sexual intercourse while inside the school.','grave','Sexual intercourse while inside the school.',NULL,NULL,1,'2026-04-06 06:07:25'),(161,'Unauthorized collection of money in any transaction either personal or pertaining to the University or any of its departments, and recognized student councils, clubs, and organizations','grave','Unauthorized collection of money in any transaction either personal or pertaining to the University or any of its departments, and recognized student councils, clubs, and organizations',NULL,NULL,1,'2026-04-06 06:07:25'),(162,'Acts or publishing or circulating false and unfounded information that would malign the good name and reputation of the University, its officials, faculty, staff, and students','grave','Acts or publishing or circulating false and unfounded information that would malign the good name and reputation of the University, its officials, faculty, staff, and students',NULL,NULL,1,'2026-04-06 06:07:25'),(163,'Preventing and/or threatening any student or school personnel from entering school premises to attend their classes and/or discharge their duties','grave','Preventing and/or threatening any student or school personnel from entering school premises to attend their classes and/or discharge their duties',NULL,NULL,1,'2026-04-06 06:07:25'),(164,'Repeated willful violations of the school\'s rules and regulations including the commission of a fourth minor offense.','grave','Repeated willful violations of the school\'s rules and regulations including the commission of a fourth minor offense.',NULL,NULL,1,'2026-04-06 06:07:25'),(165,'Creating and/or joining unauthorized or illegal student organizations.','grave','Creating and/or joining unauthorized or illegal student organizations.',NULL,NULL,1,'2026-04-06 06:07:25');
/*!40000 ALTER TABLE `violation_categories` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `violation_record_audit`
--

DROP TABLE IF EXISTS `violation_record_audit`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `violation_record_audit` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `violation_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `category_id` int(11) NOT NULL,
  `recorded_by` int(11) DEFAULT NULL,
  `scan_source` varchar(16) NOT NULL,
  `guard_session_hash` char(64) NOT NULL,
  `scan_token_id` bigint(20) unsigned NOT NULL,
  `scan_token_hash` char(64) NOT NULL,
  `notes_length` int(11) NOT NULL DEFAULT 0,
  `request_ip` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_vra_violation` (`violation_id`),
  KEY `idx_vra_user` (`user_id`),
  KEY `idx_vra_guard_session` (`guard_session_hash`),
  KEY `idx_vra_scan_token_id` (`scan_token_id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `violation_record_audit`
--

LOCK TABLES `violation_record_audit` WRITE;
/*!40000 ALTER TABLE `violation_record_audit` DISABLE KEYS */;
INSERT INTO `violation_record_audit` VALUES (1,36,2,122,NULL,'rfid','fa094d3c43a950b2c8f1f989923d2c0224a6908178ee3bdeaaab09b967137b04',4,'d3ee71f9aea8c08b32e257279661890c74becbcdec1fa29616b15972d336ac4a',0,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36','2026-04-06 14:08:40'),(2,37,2,122,NULL,'rfid','fa094d3c43a950b2c8f1f989923d2c0224a6908178ee3bdeaaab09b967137b04',5,'ba993af47864be95c844c6a20b1972053887c7e7e8bd9a20eb42c8437c3c8992',0,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36','2026-04-06 14:08:47'),(3,38,2,122,NULL,'rfid','fa094d3c43a950b2c8f1f989923d2c0224a6908178ee3bdeaaab09b967137b04',6,'496b70f4413b8e0c6f93eca38e8e1e7daaa2067f30d3f62609c653ea2385ffee',0,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36','2026-04-06 14:08:53');
/*!40000 ALTER TABLE `violation_record_audit` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `violations`
--

DROP TABLE IF EXISTS `violations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `violations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `rfid_uid` varchar(50) NOT NULL COMMENT 'RFID scanned at gate (may differ from registered)',
  `scanned_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `violation_type` enum('forgot_card','unauthorized_access','blocked_entry') NOT NULL DEFAULT 'forgot_card',
  `gate_location` varchar(100) DEFAULT NULL COMMENT 'Which security gate',
  `security_guard_id` int(11) DEFAULT NULL COMMENT 'Guard who logged the violation',
  `email_sent` tinyint(1) NOT NULL DEFAULT 0,
  `email_sent_at` timestamp NULL DEFAULT NULL,
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `security_guard_id` (`security_guard_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_scanned_at` (`scanned_at`),
  KEY `idx_violation_type` (`violation_type`),
  KEY `idx_rfid_uid` (`rfid_uid`),
  CONSTRAINT `violations_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `violations_ibfk_2` FOREIGN KEY (`security_guard_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chk_violations_email_sent` CHECK (`email_sent` in (0,1))
) ENGINE=InnoDB AUTO_INCREMENT=151 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `violations`
--

LOCK TABLES `violations` WRITE;
/*!40000 ALTER TABLE `violations` DISABLE KEYS */;
INSERT INTO `violations` VALUES (12,5,'FACE_RECOGNITION','2026-02-08 08:19:34','forgot_card',NULL,NULL,0,NULL,NULL),(142,2,'0014973874','2026-04-06 02:48:16','forgot_card',NULL,NULL,0,NULL,NULL),(143,2,'0014973874','2026-04-06 02:48:24','forgot_card',NULL,NULL,0,NULL,NULL),(144,2,'0014973874','2026-04-06 02:48:32','forgot_card',NULL,NULL,0,NULL,NULL),(145,2,'0014973874','2026-04-06 06:00:41','forgot_card',NULL,NULL,0,NULL,NULL),(146,2,'0014973874','2026-04-06 06:01:06','forgot_card',NULL,NULL,0,NULL,NULL),(147,2,'0014973874','2026-04-06 06:03:20','forgot_card',NULL,NULL,0,NULL,NULL),(148,2,'0014973874','2026-04-06 06:07:55','forgot_card',NULL,NULL,0,NULL,NULL),(149,2,'0014973874','2026-04-06 06:08:45','forgot_card',NULL,NULL,0,NULL,NULL),(150,2,'0014973874','2026-04-06 06:08:49','forgot_card',NULL,NULL,0,NULL,NULL);
/*!40000 ALTER TABLE `violations` ENABLE KEYS */;
UNLOCK TABLES;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER `after_violation_insert` AFTER INSERT ON `violations` FOR EACH ROW BEGIN
    UPDATE users 
    SET violation_count = (
        SELECT COUNT(*) 
        FROM violations 
        WHERE user_id = NEW.user_id
    )
    WHERE id = NEW.user_id;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

--
-- Dumping events for database 'pcu_rfid2'
--

--
-- Dumping routines for database 'pcu_rfid2'
--

--
-- Current Database: `pcu_rfid2`
--

USE `pcu_rfid2`;

--
-- Final view structure for view `v_active_rfid_cards`
--

/*!50001 DROP VIEW IF EXISTS `v_active_rfid_cards`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_general_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY INVOKER */
/*!50001 VIEW `v_active_rfid_cards` AS select `u`.`id` AS `user_id`,`u`.`student_id` AS `student_id`,`u`.`name` AS `name`,`u`.`email` AS `email`,`rc`.`rfid_uid` AS `rfid_uid`,`rc`.`registered_at` AS `registered_at`,`rc`.`registered_by` AS `registered_by`,`u`.`violation_count` AS `violation_count` from (`users` `u` join `rfid_cards` `rc` on(`u`.`id` = `rc`.`user_id`)) where `rc`.`is_active` = 1 */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;

--
-- Final view structure for view `v_students_complete`
--

/*!50001 DROP VIEW IF EXISTS `v_students_complete`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = cp850 */;
/*!50001 SET character_set_results     = cp850 */;
/*!50001 SET collation_connection      = cp850_general_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY INVOKER */
/*!50001 VIEW `v_students_complete` AS select `u`.`id` AS `id`,`u`.`student_id` AS `student_id`,`u`.`name` AS `name`,`u`.`email` AS `email`,`u`.`role` AS `role`,`u`.`status` AS `status`,`u`.`created_at` AS `created_at`,`u`.`last_login` AS `last_login`,`u`.`profile_picture` AS `profile_picture`,`u`.`profile_picture_uploaded_at` AS `profile_picture_uploaded_at`,`u`.`rfid_uid` AS `rfid_uid`,`u`.`rfid_registered_at` AS `rfid_registered_at`,`u`.`violation_count` AS `violation_count`,`sp`.`bio` AS `bio`,`sp`.`phone` AS `phone`,`sp`.`emergency_contact` AS `emergency_contact`,`sp`.`emergency_phone` AS `emergency_phone`,coalesce(`va`.`total_violations`,0) AS `total_violations`,`va`.`last_violation_date` AS `last_violation_date` from ((`users` `u` left join `student_profiles` `sp` on(`u`.`id` = `sp`.`user_id`)) left join (select `violations`.`user_id` AS `user_id`,count(0) AS `total_violations`,max(`violations`.`scanned_at`) AS `last_violation_date` from `violations` group by `violations`.`user_id`) `va` on(`u`.`id` = `va`.`user_id`)) where `u`.`role` = 'Student' */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-04-08 20:01:55
