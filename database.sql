-- FieldPulse / FieldOps Kenya - Field Service Management Database Schema
-- Charset: utf8mb4

CREATE DATABASE IF NOT EXISTS `field_service_db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `field_service_db`;

-- 1. Users table (Admins, Dispatchers, Field Technicians)
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `email` VARCHAR(120) NOT NULL UNIQUE,
    `phone` VARCHAR(30) NOT NULL,
    `password` VARCHAR(255) NOT NULL,
    `role` ENUM('admin', 'dispatcher', 'technician', 'member') NOT NULL DEFAULT 'member',
    `trade_skills` VARCHAR(255) NULL, -- e.g. "Solar, Electrical, Inverters"
    `status` ENUM('available', 'on_job', 'off_duty', 'inactive') NOT NULL DEFAULT 'available',
    `avatar` VARCHAR(255) NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Customers table
CREATE TABLE IF NOT EXISTS `customers` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(120) NOT NULL,
    `contact_person` VARCHAR(100) NULL,
    `phone` VARCHAR(30) NOT NULL,
    `alternate_phone` VARCHAR(30) NULL,
    `email` VARCHAR(120) NULL,
    `estate_area` VARCHAR(150) NOT NULL, -- e.g. "Kilimani, Argwings Kodhek Rd"
    `address` TEXT NOT NULL,
    `landmark` VARCHAR(255) NULL, -- e.g. "Opposite Yaya Centre, Gate 4"
    `gps_coords` VARCHAR(100) NULL, -- e.g. "-1.2921, 36.7856"
    `customer_type` ENUM('residential', 'commercial', 'industrial') NOT NULL DEFAULT 'residential',
    `notes` TEXT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Customer Assets / Equipment Registry
CREATE TABLE IF NOT EXISTS `customer_assets` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `customer_id` INT NOT NULL,
    `asset_name` VARCHAR(150) NOT NULL, -- e.g. "Deye 8kW Hybrid Inverter"
    `category` VARCHAR(60) NOT NULL, -- e.g. "Solar", "CCTV", "HVAC", "Plumbing", "Electrical"
    `brand` VARCHAR(80) NULL,
    `model_number` VARCHAR(100) NULL,
    `serial_number` VARCHAR(100) NULL,
    `install_date` DATE NULL,
    `warranty_expiry` DATE NULL,
    `location_at_site` VARCHAR(150) NULL, -- e.g. "Main Power Room / Roof"
    `status` ENUM('active', 'needs_service', 'decommissioned') NOT NULL DEFAULT 'active',
    `notes` TEXT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Service Requests (Inquiries / Dispatch Queue)
CREATE TABLE IF NOT EXISTS `service_requests` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `ticket_no` VARCHAR(30) NOT NULL UNIQUE, -- e.g. "REQ-2026-001"
    `customer_id` INT NOT NULL,
    `asset_id` INT NULL,
    `trade_category` VARCHAR(60) NOT NULL, -- "Electrical", "Solar", "CCTV", "Plumbing", "Fibre", "HVAC", "Appliance", "Computer"
    `title` VARCHAR(200) NOT NULL,
    `description` TEXT NOT NULL,
    `priority` ENUM('low', 'normal', 'urgent', 'emergency') NOT NULL DEFAULT 'normal',
    `status` ENUM('new', 'assigned', 'in_progress', 'completed', 'cancelled') NOT NULL DEFAULT 'new',
    `preferred_date` DATE NULL,
    `preferred_time_slot` VARCHAR(50) NULL, -- e.g. "Morning (09:00 - 12:00)"
    `created_by` INT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`asset_id`) REFERENCES `customer_assets`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. Job Cards (Official Field Work Orders)
CREATE TABLE IF NOT EXISTS `job_cards` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `job_number` VARCHAR(30) NOT NULL UNIQUE, -- e.g. "JOB-2026-001"
    `request_id` INT NULL,
    `customer_id` INT NOT NULL,
    `technician_id` INT NOT NULL,
    `asset_id` INT NULL,
    `trade_category` VARCHAR(60) NOT NULL,
    `title` VARCHAR(200) NOT NULL,
    `job_description` TEXT NOT NULL,
    `scheduled_date` DATE NOT NULL,
    `scheduled_time` VARCHAR(50) NOT NULL, -- e.g. "10:00 AM"
    `estimated_duration` VARCHAR(50) NULL, -- e.g. "2 hours"
    `priority` ENUM('low', 'normal', 'urgent', 'emergency') NOT NULL DEFAULT 'normal',
    `status` ENUM('scheduled', 'en_route', 'in_progress', 'pending_parts', 'completed', 'signed_off', 'cancelled') NOT NULL DEFAULT 'scheduled',
    `diagnosis` TEXT NULL,
    `work_performed` TEXT NULL,
    `technician_notes` TEXT NULL,
    `customer_feedback` TEXT NULL,
    `signature_data` MEDIUMTEXT NULL, -- Base64 Data URL of canvas signature
    `signed_by_name` VARCHAR(100) NULL,
    `signed_at` DATETIME NULL,
    `started_at` DATETIME NULL,
    `completed_at` DATETIME NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`request_id`) REFERENCES `service_requests`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`technician_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT,
    FOREIGN KEY (`asset_id`) REFERENCES `customer_assets`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 6. Job Photos & Attachments
CREATE TABLE IF NOT EXISTS `job_photos` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `job_id` INT NOT NULL,
    `photo_path` VARCHAR(255) NOT NULL,
    `photo_type` ENUM('before', 'during', 'after', 'site_doc') NOT NULL DEFAULT 'during',
    `caption` VARCHAR(255) NULL,
    `uploaded_by` INT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`job_id`) REFERENCES `job_cards`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 7. Inventory & Parts Catalog
CREATE TABLE IF NOT EXISTS `inventory_parts` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `part_code` VARCHAR(50) NOT NULL UNIQUE,
    `part_name` VARCHAR(150) NOT NULL,
    `category` VARCHAR(60) NOT NULL,
    `unit` VARCHAR(30) NOT NULL DEFAULT 'pcs', -- pcs, meters, roll, box, kg
    `in_stock` INT NOT NULL DEFAULT 0,
    `min_stock_alert` INT NOT NULL DEFAULT 5,
    `unit_cost` DECIMAL(10,2) NULL, -- For internal cost reference (No billing/payment required)
    `description` TEXT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 8. Parts Used on Jobs
CREATE TABLE IF NOT EXISTS `job_parts_used` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `job_id` INT NOT NULL,
    `part_id` INT NOT NULL,
    `quantity` DECIMAL(8,2) NOT NULL DEFAULT 1,
    `notes` VARCHAR(255) NULL,
    `recorded_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`job_id`) REFERENCES `job_cards`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`part_id`) REFERENCES `inventory_parts`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 9. Job Activity Logs / Audit Trail
CREATE TABLE IF NOT EXISTS `job_logs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `job_id` INT NOT NULL,
    `user_id` INT NULL,
    `action` VARCHAR(100) NOT NULL,
    `notes` TEXT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`job_id`) REFERENCES `job_cards`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 10. Notifications
CREATE TABLE IF NOT EXISTS `notifications` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NULL, -- NULL means broadcast to all dispatchers/admins
    `type` VARCHAR(50) NOT NULL, -- "dispatch", "status_update", "signature", "stock_alert"
    `title` VARCHAR(150) NOT NULL,
    `message` TEXT NOT NULL,
    `link` VARCHAR(255) NULL,
    `is_read` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
