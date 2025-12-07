-- phpMyAdmin SQL Dump
-- version 4.9.0.1
-- https://www.phpmyadmin.net/
--
-- Host: sql110.infinityfree.com
-- Generation Time: Dec 06, 2025 at 09:19 PM
-- Server version: 11.4.7-MariaDB
-- PHP Version: 7.2.22

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `if0_40278066_co_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `accountant_transactions`
--

CREATE TABLE `accountant_transactions` (
  `id` int(11) NOT NULL,
  `transaction_type` enum('collection_from_sales_rep','expense','income','transfer','other') NOT NULL COMMENT 'نوع المعاملة',
  `amount` decimal(15,2) NOT NULL COMMENT 'المبلغ',
  `sales_rep_id` int(11) DEFAULT NULL COMMENT 'معرف المندوب (للتحصيل)',
  `description` text NOT NULL COMMENT 'الوصف',
  `reference_number` varchar(50) DEFAULT NULL COMMENT 'رقم مرجعي',
  `payment_method` enum('cash','bank_transfer','check','other') DEFAULT 'cash' COMMENT 'طريقة الدفع',
  `status` enum('pending','approved','rejected') DEFAULT 'approved' COMMENT 'الحالة',
  `approved_by` int(11) DEFAULT NULL COMMENT 'من وافق',
  `approved_at` timestamp NULL DEFAULT NULL COMMENT 'تاريخ الموافقة',
  `notes` text DEFAULT NULL COMMENT 'ملاحظات إضافية',
  `created_by` int(11) NOT NULL COMMENT 'من أنشأ السجل',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() COMMENT 'تاريخ الإنشاء',
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp() COMMENT 'تاريخ التحديث'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول المعاملات المحاسبية';

-- --------------------------------------------------------

--
-- Table structure for table `advance_requests`
--

CREATE TABLE `advance_requests` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `requested_month` int(2) NOT NULL,
  `requested_year` int(4) NOT NULL,
  `salary_id` int(11) DEFAULT NULL,
  `reason` text DEFAULT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `approvals`
--

CREATE TABLE `approvals` (
  `id` int(11) NOT NULL,
  `type` enum('financial','sales','production','inventory','other','return_request','collection','salary','warehouse_transfer','exchange_request','invoice_return_company','salary_modification') NOT NULL,
  `reference_id` int(11) NOT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `requested_by` int(11) NOT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approval_notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `approved_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `attendance`
--

CREATE TABLE `attendance` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `date` date NOT NULL,
  `check_in` time DEFAULT NULL,
  `check_out` time DEFAULT NULL,
  `status` enum('present','absent','late','half_day') DEFAULT 'present',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `attendance_event_notification_logs`
--

CREATE TABLE `attendance_event_notification_logs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `attendance_record_id` int(11) DEFAULT NULL,
  `event_type` enum('checkin','checkout') NOT NULL,
  `sent_date` date NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `attendance_monthly_report_logs`
--

CREATE TABLE `attendance_monthly_report_logs` (
  `id` int(11) NOT NULL,
  `month_key` char(7) NOT NULL COMMENT 'YYYY-MM',
  `month_number` tinyint(2) NOT NULL,
  `year_number` smallint(4) NOT NULL,
  `sent_via` varchar(32) NOT NULL COMMENT 'telegram_auto, telegram_manual, manual_export, ...',
  `triggered_by` int(11) DEFAULT NULL COMMENT 'المستخدم الذي أنشأ التقرير يدوياً (إن وجد)',
  `sent_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `report_snapshot` longtext DEFAULT NULL COMMENT 'نسخة JSON من التقرير'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `attendance_notification_logs`
--

CREATE TABLE `attendance_notification_logs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `notification_kind` enum('checkin','checkout') NOT NULL,
  `sent_date` date NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `attendance_records`
--

CREATE TABLE `attendance_records` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `date` date NOT NULL,
  `check_in_time` datetime NOT NULL,
  `check_out_time` datetime DEFAULT NULL,
  `delay_minutes` int(11) DEFAULT 0,
  `work_hours` decimal(5,2) DEFAULT 0.00,
  `photo_path` varchar(255) DEFAULT NULL,
  `checkout_photo_path` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

CREATE TABLE `audit_logs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `action` varchar(100) NOT NULL,
  `entity_type` varchar(50) DEFAULT NULL,
  `entity_id` int(11) DEFAULT NULL,
  `old_value` longtext DEFAULT NULL,
  `new_value` longtext DEFAULT NULL,
  `old_data` text DEFAULT NULL,
  `new_data` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `backups`
--

CREATE TABLE `backups` (
  `id` int(11) NOT NULL,
  `filename` varchar(255) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_size` bigint(20) DEFAULT 0,
  `backup_type` enum('full','incremental','manual') DEFAULT 'full',
  `status` enum('pending','completed','failed') DEFAULT 'pending',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `barcode_scans`
--

CREATE TABLE `barcode_scans` (
  `id` int(11) NOT NULL,
  `batch_number_id` int(11) NOT NULL,
  `scanned_by` int(11) DEFAULT NULL,
  `scan_location` varchar(255) DEFAULT NULL,
  `scan_type` enum('in','out','verification','sale','return') DEFAULT 'verification',
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `batches`
--

CREATE TABLE `batches` (
  `id` int(11) NOT NULL,
  `product_id` int(11) DEFAULT NULL,
  `batch_number` varchar(100) NOT NULL,
  `production_date` date NOT NULL,
  `expiry_date` date DEFAULT NULL,
  `quantity` int(11) NOT NULL DEFAULT 0,
  `status` enum('in_production','completed','cancelled') DEFAULT 'in_production',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `batch_numbers`
--

CREATE TABLE `batch_numbers` (
  `id` int(11) NOT NULL,
  `batch_number` varchar(100) NOT NULL,
  `product_id` int(11) NOT NULL,
  `production_id` int(11) DEFAULT NULL,
  `production_date` date NOT NULL,
  `honey_supplier_id` int(11) DEFAULT NULL,
  `honey_variety` varchar(50) DEFAULT NULL COMMENT 'نوع العسل المستخدم',
  `packaging_materials` text DEFAULT NULL COMMENT 'JSON array of packaging material IDs',
  `packaging_supplier_id` int(11) DEFAULT NULL,
  `all_suppliers` text DEFAULT NULL COMMENT 'JSON array of all suppliers with their materials',
  `workers` text DEFAULT NULL COMMENT 'JSON array of worker IDs',
  `quantity` int(11) NOT NULL DEFAULT 1,
  `status` enum('in_production','completed','in_stock','sold','expired') DEFAULT 'in_production',
  `expiry_date` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `batch_packaging`
--

CREATE TABLE `batch_packaging` (
  `id` int(11) NOT NULL,
  `batch_id` int(11) NOT NULL,
  `packaging_material_id` int(11) DEFAULT NULL,
  `packaging_name` varchar(255) DEFAULT NULL,
  `unit` varchar(50) DEFAULT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `quantity_used` decimal(12,3) NOT NULL DEFAULT 0.000,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `batch_raw_materials`
--

CREATE TABLE `batch_raw_materials` (
  `id` int(11) NOT NULL,
  `batch_id` int(11) NOT NULL,
  `raw_material_id` int(11) DEFAULT NULL,
  `material_name` varchar(255) DEFAULT NULL,
  `unit` varchar(50) DEFAULT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `quantity_used` decimal(12,3) NOT NULL DEFAULT 0.000,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `batch_suppliers`
--

CREATE TABLE `batch_suppliers` (
  `id` int(11) NOT NULL,
  `batch_id` int(11) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `supplier_name` varchar(255) DEFAULT NULL,
  `role` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `batch_workers`
--

CREATE TABLE `batch_workers` (
  `id` int(11) NOT NULL,
  `batch_id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `worker_name` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `beeswax_product_templates`
--

CREATE TABLE `beeswax_product_templates` (
  `id` int(11) NOT NULL,
  `product_name` varchar(255) NOT NULL,
  `beeswax_weight` decimal(10,2) NOT NULL COMMENT 'وزن شمع العسل (كجم)',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `beeswax_stock`
--

CREATE TABLE `beeswax_stock` (
  `id` int(11) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `weight` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'الوزن (كجم)',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `blocked_ips`
--

CREATE TABLE `blocked_ips` (
  `id` int(11) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `blocked_until` timestamp NULL DEFAULT NULL,
  `blocked_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cash_register_additions`
--

CREATE TABLE `cash_register_additions` (
  `id` int(11) NOT NULL,
  `sales_rep_id` int(11) NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `description` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `collections`
--

CREATE TABLE `collections` (
  `id` int(11) NOT NULL,
  `collection_number` varchar(50) DEFAULT NULL,
  `customer_id` int(11) NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `date` date NOT NULL,
  `payment_method` enum('cash','bank_transfer','check','other') DEFAULT 'cash',
  `reference_number` varchar(50) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `collected_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `customers`
--

CREATE TABLE `customers` (
  `id` int(11) NOT NULL,
  `rep_id` int(11) DEFAULT NULL,
  `created_from_pos` tinyint(1) NOT NULL DEFAULT 0,
  `created_by_admin` tinyint(1) NOT NULL DEFAULT 0,
  `name` varchar(100) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `location_captured_at` datetime DEFAULT NULL,
  `balance` decimal(15,2) DEFAULT 0.00,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `customer_orders`
--

CREATE TABLE `customer_orders` (
  `id` int(11) NOT NULL,
  `order_number` varchar(50) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `sales_rep_id` int(11) DEFAULT NULL,
  `order_type` enum('sales_rep','company') DEFAULT 'sales_rep',
  `order_date` date NOT NULL,
  `delivery_date` date DEFAULT NULL,
  `subtotal` decimal(15,2) NOT NULL DEFAULT 0.00,
  `discount_amount` decimal(15,2) DEFAULT 0.00,
  `total_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `priority` enum('low','normal','high','urgent') DEFAULT 'normal',
  `status` enum('pending','confirmed','in_production','ready','delivered','cancelled') DEFAULT 'pending',
  `notes` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `customer_order_items`
--

CREATE TABLE `customer_order_items` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `product_id` int(11) DEFAULT NULL,
  `template_id` int(11) DEFAULT NULL,
  `product_name` varchar(255) DEFAULT NULL,
  `quantity` decimal(10,2) NOT NULL,
  `unit_price` decimal(15,2) NOT NULL,
  `total_price` decimal(15,2) NOT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `customer_purchase_history`
--

CREATE TABLE `customer_purchase_history` (
  `id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `invoice_id` int(11) NOT NULL,
  `invoice_number` varchar(100) NOT NULL,
  `invoice_date` date NOT NULL,
  `invoice_total` decimal(15,2) NOT NULL DEFAULT 0.00,
  `paid_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `invoice_status` varchar(32) DEFAULT NULL,
  `return_total` decimal(15,2) NOT NULL DEFAULT 0.00,
  `return_count` int(11) NOT NULL DEFAULT 0,
  `exchange_total` decimal(15,2) NOT NULL DEFAULT 0.00,
  `exchange_count` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `daily_backup_sent_log`
--

CREATE TABLE `daily_backup_sent_log` (
  `id` int(11) NOT NULL,
  `sent_date` date NOT NULL,
  `sent_at` datetime NOT NULL,
  `backup_file_path` varchar(512) DEFAULT NULL,
  `backup_id` int(11) DEFAULT NULL,
  `status` enum('sent','failed') DEFAULT 'sent',
  `error_message` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `damaged_returns`
--

CREATE TABLE `damaged_returns` (
  `id` int(11) NOT NULL,
  `return_id` int(11) NOT NULL,
  `return_item_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `batch_number_id` int(11) DEFAULT NULL,
  `quantity` decimal(10,2) NOT NULL,
  `damage_reason` text DEFAULT NULL,
  `invoice_id` int(11) DEFAULT NULL,
  `invoice_number` varchar(100) DEFAULT NULL,
  `return_date` date DEFAULT NULL,
  `return_transaction_number` varchar(100) DEFAULT NULL,
  `approval_status` varchar(50) DEFAULT 'pending',
  `sales_rep_id` int(11) DEFAULT NULL,
  `sales_rep_name` varchar(255) DEFAULT NULL,
  `product_name` varchar(255) DEFAULT NULL,
  `batch_number` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `derivatives_product_templates`
--

CREATE TABLE `derivatives_product_templates` (
  `id` int(11) NOT NULL,
  `product_name` varchar(255) NOT NULL,
  `derivative_type` varchar(100) NOT NULL COMMENT 'نوع المشتق المستخدم',
  `derivative_weight` decimal(10,2) NOT NULL COMMENT 'وزن المشتق (كجم)',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `derivatives_stock`
--

CREATE TABLE `derivatives_stock` (
  `id` int(11) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `derivative_type` varchar(100) NOT NULL COMMENT 'نوع المشتق',
  `weight` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'الوزن (كجم)',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `factory_waste_packaging`
--

CREATE TABLE `factory_waste_packaging` (
  `id` int(11) NOT NULL,
  `packaging_damage_log_id` int(11) DEFAULT NULL,
  `tool_type` varchar(255) NOT NULL,
  `damaged_quantity` decimal(10,2) NOT NULL,
  `unit` varchar(50) DEFAULT NULL,
  `added_date` date NOT NULL,
  `recorded_by_user_id` int(11) DEFAULT NULL,
  `recorded_by_user_name` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `factory_waste_products`
--

CREATE TABLE `factory_waste_products` (
  `id` int(11) NOT NULL,
  `damaged_return_id` int(11) DEFAULT NULL,
  `product_id` int(11) NOT NULL,
  `product_name` varchar(255) NOT NULL,
  `product_code` varchar(100) DEFAULT NULL,
  `batch_number` varchar(100) DEFAULT NULL,
  `batch_number_id` int(11) DEFAULT NULL,
  `damaged_quantity` decimal(10,2) NOT NULL,
  `waste_value` decimal(10,2) DEFAULT 0.00,
  `source` varchar(100) DEFAULT 'damaged_returns',
  `transaction_number` varchar(100) DEFAULT NULL,
  `added_date` date NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `factory_waste_raw_materials`
--

CREATE TABLE `factory_waste_raw_materials` (
  `id` int(11) NOT NULL,
  `raw_material_damage_log_id` int(11) DEFAULT NULL,
  `material_name` varchar(255) NOT NULL,
  `wasted_quantity` decimal(10,2) NOT NULL,
  `unit` varchar(50) DEFAULT NULL,
  `waste_reason` text DEFAULT NULL,
  `added_date` date NOT NULL,
  `recorded_by_user_id` int(11) DEFAULT NULL,
  `recorded_by_user_name` varchar(255) DEFAULT NULL,
  `waste_value` decimal(10,2) DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `financial_transactions`
--

CREATE TABLE `financial_transactions` (
  `id` int(11) NOT NULL,
  `type` enum('expense','income','transfer','payment') NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `description` text NOT NULL,
  `reference_number` varchar(50) DEFAULT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `approved_by` int(11) DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `finished_products`
--

CREATE TABLE `finished_products` (
  `id` int(11) NOT NULL,
  `batch_id` int(11) NOT NULL,
  `product_id` int(11) DEFAULT NULL,
  `product_name` varchar(255) NOT NULL,
  `batch_number` varchar(100) NOT NULL,
  `production_date` date NOT NULL,
  `expiry_date` date DEFAULT NULL,
  `quantity_produced` int(11) NOT NULL DEFAULT 0,
  `unit_price` decimal(12,2) DEFAULT NULL COMMENT 'سعر الوحدة من القالب',
  `total_price` decimal(12,2) DEFAULT NULL COMMENT 'السعر الإجمالي (unit_price × quantity_produced)',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `group_chat_messages`
--

CREATE TABLE `group_chat_messages` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `message` text NOT NULL,
  `parent_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `honey_filtration`
--

CREATE TABLE `honey_filtration` (
  `id` int(11) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `raw_honey_quantity` decimal(10,2) NOT NULL,
  `filtered_honey_quantity` decimal(10,2) NOT NULL,
  `filtration_loss` decimal(10,2) NOT NULL COMMENT 'الكمية المفقودة (0.5%)',
  `filtration_date` date NOT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `honey_stock`
--

CREATE TABLE `honey_stock` (
  `id` int(11) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `honey_variety` enum('سدر','جبلي','حبة البركة','موالح','نوارة برسيم','أخرى') DEFAULT 'أخرى' COMMENT 'نوع العسل',
  `raw_honey_quantity` decimal(10,2) NOT NULL DEFAULT 0.00,
  `filtered_honey_quantity` decimal(10,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `inventory_movements`
--

CREATE TABLE `inventory_movements` (
  `id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `warehouse_id` int(11) DEFAULT NULL,
  `type` enum('in','out','adjustment','transfer') NOT NULL,
  `quantity` decimal(10,2) NOT NULL,
  `quantity_before` decimal(10,2) NOT NULL,
  `quantity_after` decimal(10,2) NOT NULL,
  `reference_type` varchar(50) DEFAULT NULL COMMENT 'production, sales, purchase, etc.',
  `reference_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `invoices`
--

CREATE TABLE `invoices` (
  `id` int(11) NOT NULL,
  `invoice_number` varchar(50) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `sales_rep_id` int(11) DEFAULT NULL,
  `date` date NOT NULL,
  `due_date` date DEFAULT NULL,
  `subtotal` decimal(15,2) NOT NULL DEFAULT 0.00,
  `tax_rate` decimal(5,2) DEFAULT 0.00,
  `tax_amount` decimal(15,2) DEFAULT 0.00,
  `discount_amount` decimal(15,2) DEFAULT 0.00,
  `total_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `paid_amount` decimal(15,2) DEFAULT 0.00,
  `remaining_amount` decimal(15,2) DEFAULT 0.00,
  `status` enum('draft','sent','paid','partial','overdue','cancelled') DEFAULT 'draft',
  `paid_from_credit` tinyint(1) DEFAULT 0,
  `credit_used` decimal(15,2) DEFAULT 0.00 COMMENT 'المبلغ المخصوم من الرصيد الدائن',
  `amount_added_to_sales` decimal(15,2) DEFAULT NULL COMMENT 'المبلغ المضاف إلى خزنة المندوب (بعد خصم الرصيد الدائن)',
  `notes` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `invoice_items`
--

CREATE TABLE `invoice_items` (
  `id` int(11) NOT NULL,
  `invoice_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `quantity` decimal(10,2) NOT NULL,
  `unit_price` decimal(15,2) NOT NULL,
  `total_price` decimal(15,2) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `local_collections`
--

CREATE TABLE `local_collections` (
  `id` int(11) NOT NULL,
  `collection_number` varchar(50) DEFAULT NULL COMMENT 'رقم التحصيل',
  `customer_id` int(11) NOT NULL COMMENT 'معرف العميل المحلي',
  `amount` decimal(15,2) NOT NULL COMMENT 'المبلغ المحصل',
  `date` date NOT NULL COMMENT 'تاريخ التحصيل',
  `payment_method` enum('cash','bank','cheque','other') DEFAULT 'cash' COMMENT 'طريقة الدفع',
  `reference_number` varchar(50) DEFAULT NULL COMMENT 'رقم مرجعي',
  `collected_by` int(11) NOT NULL COMMENT 'من قام بالتحصيل',
  `status` enum('pending','approved','rejected') DEFAULT 'pending' COMMENT 'حالة التحصيل',
  `approved_by` int(11) DEFAULT NULL COMMENT 'من وافق على التحصيل',
  `approved_at` timestamp NULL DEFAULT NULL COMMENT 'تاريخ الموافقة',
  `notes` text DEFAULT NULL COMMENT 'ملاحظات',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول التحصيلات للعملاء المحليين';

-- --------------------------------------------------------

--
-- Table structure for table `local_customers`
--

CREATE TABLE `local_customers` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `balance` decimal(15,2) DEFAULT 0.00 COMMENT 'رصيد العميل (موجب = دين، سالب = رصيد دائن)',
  `status` enum('active','inactive') DEFAULT 'active',
  `created_by` int(11) NOT NULL COMMENT 'المستخدم الذي أضاف العميل',
  `latitude` decimal(10,8) DEFAULT NULL COMMENT 'خط العرض',
  `longitude` decimal(11,8) DEFAULT NULL COMMENT 'خط الطول',
  `location_captured_at` datetime DEFAULT NULL COMMENT 'تاريخ تحديد الموقع',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول العملاء المحليين (منفصل عن عملاء المندوبين)';

-- --------------------------------------------------------

--
-- Table structure for table `local_invoices`
--

CREATE TABLE `local_invoices` (
  `id` int(11) NOT NULL,
  `invoice_number` varchar(50) NOT NULL COMMENT 'رقم الفاتورة',
  `customer_id` int(11) NOT NULL COMMENT 'معرف العميل المحلي',
  `date` date NOT NULL COMMENT 'تاريخ الفاتورة',
  `due_date` date DEFAULT NULL,
  `subtotal` decimal(15,2) NOT NULL DEFAULT 0.00,
  `tax_rate` decimal(5,2) DEFAULT 0.00,
  `tax_amount` decimal(15,2) DEFAULT 0.00,
  `discount_amount` decimal(15,2) DEFAULT 0.00,
  `total_amount` decimal(15,2) NOT NULL DEFAULT 0.00 COMMENT 'إجمالي المبلغ',
  `paid_amount` decimal(15,2) NOT NULL DEFAULT 0.00 COMMENT 'المبلغ المدفوع',
  `remaining_amount` decimal(15,2) DEFAULT 0.00 COMMENT 'المبلغ المتبقي',
  `status` enum('draft','sent','partial','paid','cancelled') DEFAULT 'draft' COMMENT 'حالة الفاتورة',
  `notes` text DEFAULT NULL COMMENT 'ملاحظات',
  `created_by` int(11) DEFAULT NULL COMMENT 'من أنشأ الفاتورة',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول الفواتير للعملاء المحليين';

-- --------------------------------------------------------

--
-- Table structure for table `local_invoice_items`
--

CREATE TABLE `local_invoice_items` (
  `id` int(11) NOT NULL,
  `invoice_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `batch_number` varchar(255) DEFAULT NULL,
  `batch_id` int(11) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `quantity` decimal(10,2) NOT NULL,
  `unit_price` decimal(15,2) NOT NULL,
  `total_price` decimal(15,2) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `local_returns`
--

CREATE TABLE `local_returns` (
  `id` int(11) NOT NULL,
  `return_number` varchar(50) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `return_date` date NOT NULL,
  `refund_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `refund_method` enum('cash','credit') DEFAULT 'credit',
  `status` enum('pending','approved','rejected','processed','completed') DEFAULT 'pending',
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `processed_by` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `local_return_items`
--

CREATE TABLE `local_return_items` (
  `id` int(11) NOT NULL,
  `return_id` int(11) NOT NULL,
  `invoice_id` int(11) DEFAULT NULL,
  `invoice_item_id` int(11) DEFAULT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` decimal(10,2) NOT NULL,
  `unit_price` decimal(15,2) NOT NULL,
  `total_price` decimal(15,2) NOT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `login_attempts`
--

CREATE TABLE `login_attempts` (
  `id` int(11) NOT NULL,
  `username` varchar(50) DEFAULT NULL,
  `ip_address` varchar(45) NOT NULL,
  `user_agent` text DEFAULT NULL,
  `success` tinyint(1) DEFAULT 0,
  `failure_reason` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `messages`
--

CREATE TABLE `messages` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `message_text` longtext NOT NULL,
  `reply_to` int(11) DEFAULT NULL,
  `edited` tinyint(1) NOT NULL DEFAULT 0,
  `deleted` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `read_by_count` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `message_reads`
--

CREATE TABLE `message_reads` (
  `id` int(11) NOT NULL,
  `message_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `read_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `mixed_nuts`
--

CREATE TABLE `mixed_nuts` (
  `id` int(11) NOT NULL,
  `batch_name` varchar(255) NOT NULL COMMENT 'اسم الخلطة',
  `supplier_id` int(11) NOT NULL COMMENT 'المورد الخاص بالخلطة',
  `total_quantity` decimal(10,3) NOT NULL DEFAULT 0.000 COMMENT 'إجمالي الوزن',
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `mixed_nuts_ingredients`
--

CREATE TABLE `mixed_nuts_ingredients` (
  `id` int(11) NOT NULL,
  `mixed_nuts_id` int(11) NOT NULL COMMENT 'رقم الخلطة',
  `nuts_stock_id` int(11) NOT NULL COMMENT 'رقم المكسرات المنفردة',
  `quantity` decimal(10,3) NOT NULL COMMENT 'الكمية المستخدمة',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `role` enum('manager','accountant','sales','production','all') DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `type` enum('info','success','warning','error') DEFAULT 'info',
  `link` varchar(255) DEFAULT NULL,
  `read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notification_settings`
--

CREATE TABLE `notification_settings` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `role` enum('manager','accountant','sales','production','all') DEFAULT NULL,
  `notification_type` varchar(50) NOT NULL,
  `telegram_enabled` tinyint(1) DEFAULT 0,
  `telegram_chat_id` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `nuts_stock`
--

CREATE TABLE `nuts_stock` (
  `id` int(11) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `nut_type` varchar(100) NOT NULL COMMENT 'نوع المكسرات (لوز، جوز، فستق، إلخ)',
  `quantity` decimal(10,3) NOT NULL DEFAULT 0.000 COMMENT 'الكمية بالكيلوجرام',
  `unit_price` decimal(10,2) DEFAULT NULL COMMENT 'سعر الكيلو',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `olive_oil_product_templates`
--

CREATE TABLE `olive_oil_product_templates` (
  `id` int(11) NOT NULL,
  `product_name` varchar(255) NOT NULL,
  `olive_oil_quantity` decimal(10,2) NOT NULL COMMENT 'كمية زيت الزيتون (لتر)',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `olive_oil_stock`
--

CREATE TABLE `olive_oil_stock` (
  `id` int(11) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `quantity` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'الكمية (لتر)',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `packaging_damage_logs`
--

CREATE TABLE `packaging_damage_logs` (
  `id` int(11) NOT NULL,
  `material_id` int(11) NOT NULL,
  `material_name` varchar(255) DEFAULT NULL,
  `source_table` enum('packaging_materials','products') NOT NULL DEFAULT 'packaging_materials',
  `quantity_before` decimal(15,4) DEFAULT 0.0000,
  `damaged_quantity` decimal(15,4) NOT NULL,
  `quantity_after` decimal(15,4) DEFAULT 0.0000,
  `unit` varchar(50) DEFAULT NULL,
  `reason` text DEFAULT NULL,
  `recorded_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `packaging_materials`
--

CREATE TABLE `packaging_materials` (
  `id` int(11) NOT NULL,
  `material_id` varchar(50) NOT NULL COMMENT 'معرف فريد مثل PKG-001',
  `name` varchar(255) NOT NULL COMMENT 'اسم مأخوذ من type + specifications',
  `alias` varchar(255) DEFAULT NULL,
  `type` varchar(100) NOT NULL COMMENT 'نوع الأداة مثل: عبوات زجاجية',
  `specifications` varchar(255) DEFAULT NULL COMMENT 'المواصفات مثل: برطمان 720م دائري',
  `quantity` decimal(10,2) NOT NULL DEFAULT 0.00,
  `unit` varchar(50) DEFAULT 'قطعة',
  `unit_price` decimal(10,2) DEFAULT 0.00,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `packaging_usage_logs`
--

CREATE TABLE `packaging_usage_logs` (
  `id` int(11) NOT NULL,
  `material_id` int(11) NOT NULL,
  `material_name` varchar(255) DEFAULT NULL,
  `material_code` varchar(100) DEFAULT NULL,
  `source_table` enum('packaging_materials','products') NOT NULL DEFAULT 'packaging_materials',
  `quantity_before` decimal(15,4) DEFAULT 0.0000,
  `quantity_used` decimal(15,4) NOT NULL,
  `quantity_after` decimal(15,4) DEFAULT 0.0000,
  `unit` varchar(50) DEFAULT NULL,
  `used_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payment_reminders`
--

CREATE TABLE `payment_reminders` (
  `id` int(11) NOT NULL,
  `payment_schedule_id` int(11) NOT NULL,
  `reminder_type` enum('before_due','on_due','after_due','custom') DEFAULT 'before_due',
  `reminder_date` date NOT NULL,
  `days_before_due` int(11) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `sent_to` enum('sales_rep','customer','both') DEFAULT 'sales_rep',
  `sent_status` enum('pending','sent','failed') DEFAULT 'pending',
  `sent_at` timestamp NULL DEFAULT NULL,
  `sent_method` enum('notification','telegram','sms','email') DEFAULT 'notification',
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payment_schedules`
--

CREATE TABLE `payment_schedules` (
  `id` int(11) NOT NULL,
  `sale_id` int(11) DEFAULT NULL,
  `invoice_id` int(11) DEFAULT NULL,
  `customer_id` int(11) NOT NULL,
  `sales_rep_id` int(11) NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `due_date` date NOT NULL,
  `payment_date` date DEFAULT NULL,
  `status` enum('pending','paid','overdue','cancelled') DEFAULT 'pending',
  `installment_number` int(11) DEFAULT 1,
  `total_installments` int(11) DEFAULT 1,
  `notes` text DEFAULT NULL,
  `reminder_sent` tinyint(1) DEFAULT 0,
  `reminder_sent_at` timestamp NULL DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `permissions`
--

CREATE TABLE `permissions` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `category` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `production`
--

CREATE TABLE `production` (
  `id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `customer_order_item_id` int(11) DEFAULT NULL,
  `quantity` decimal(10,2) NOT NULL,
  `production_date` date NOT NULL,
  `worker_id` int(11) NOT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('pending','in_progress','completed','cancelled') DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `production_line_id` int(11) DEFAULT NULL COMMENT 'خط الإنتاج'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `production_lines`
--

CREATE TABLE `production_lines` (
  `id` int(11) NOT NULL,
  `line_name` varchar(100) NOT NULL,
  `product_id` int(11) NOT NULL,
  `target_quantity` decimal(10,2) DEFAULT 0.00,
  `priority` enum('low','medium','high') DEFAULT 'medium',
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `worker_id` int(11) DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('pending','active','completed','paused','cancelled') DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `production_materials`
--

CREATE TABLE `production_materials` (
  `id` int(11) NOT NULL,
  `production_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity_used` decimal(10,2) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `supplier_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `production_monthly_report_logs`
--

CREATE TABLE `production_monthly_report_logs` (
  `id` int(11) NOT NULL,
  `month_key` varchar(7) NOT NULL,
  `month_number` tinyint(2) NOT NULL,
  `year_number` smallint(4) NOT NULL,
  `sent_via` varchar(40) NOT NULL DEFAULT 'telegram_auto',
  `triggered_by` int(11) DEFAULT NULL,
  `report_snapshot` longtext DEFAULT NULL,
  `sent_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `production_supply_logs`
--

CREATE TABLE `production_supply_logs` (
  `id` int(11) NOT NULL,
  `material_category` varchar(50) NOT NULL,
  `material_label` varchar(190) DEFAULT NULL,
  `stock_source` varchar(80) DEFAULT NULL,
  `stock_id` int(11) DEFAULT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `supplier_name` varchar(190) DEFAULT NULL,
  `quantity` decimal(12,3) NOT NULL DEFAULT 0.000,
  `unit` varchar(20) DEFAULT 'كجم',
  `details` text DEFAULT NULL,
  `recorded_by` int(11) DEFAULT NULL,
  `recorded_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `products`
--

CREATE TABLE `products` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `category` varchar(50) DEFAULT NULL,
  `product_type` enum('internal','external') DEFAULT 'internal',
  `external_channel` enum('company','delegate','other') DEFAULT NULL,
  `warehouse_id` int(11) DEFAULT NULL,
  `unit_price` decimal(15,2) NOT NULL DEFAULT 0.00,
  `quantity` decimal(10,2) NOT NULL DEFAULT 0.00,
  `unit` varchar(20) DEFAULT 'piece',
  `min_stock` decimal(10,2) DEFAULT 0.00,
  `min_stock_level` decimal(10,2) DEFAULT 0.00,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `product_specifications`
--

CREATE TABLE `product_specifications` (
  `id` int(11) NOT NULL,
  `product_name` varchar(255) NOT NULL,
  `raw_materials` longtext DEFAULT NULL,
  `packaging` longtext DEFAULT NULL,
  `notes` longtext DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `product_templates`
--

CREATE TABLE `product_templates` (
  `id` int(11) NOT NULL,
  `template_name` varchar(255) NOT NULL COMMENT 'اسم القالب',
  `product_id` int(11) DEFAULT NULL,
  `product_name` varchar(255) NOT NULL COMMENT 'اسم المنتج',
  `honey_quantity` decimal(10,3) NOT NULL DEFAULT 0.000 COMMENT 'كمية العسل بالجرام',
  `template_type` varchar(50) DEFAULT 'general',
  `source_template_id` int(11) DEFAULT NULL,
  `main_supplier_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `details_json` longtext DEFAULT NULL,
  `unit_price` decimal(12,2) DEFAULT NULL COMMENT 'سعر الوحدة بالجنيه',
  `carton_type` enum('kilo','half','quarter','third') DEFAULT NULL COMMENT 'نوع الكرتونة',
  `target_quantity` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'الكمية المستهدفة',
  `unit` varchar(50) DEFAULT 'قطعة' COMMENT 'الوحدة',
  `description` text DEFAULT NULL COMMENT 'الوصف',
  `status` enum('active','inactive') DEFAULT 'active',
  `created_by` int(11) NOT NULL COMMENT 'منشئ القالب',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `product_template_materials`
--

CREATE TABLE `product_template_materials` (
  `id` int(11) NOT NULL,
  `template_id` int(11) NOT NULL COMMENT 'القالب',
  `material_type` enum('honey','other','ingredient') NOT NULL DEFAULT 'other' COMMENT 'نوع المادة',
  `material_name` varchar(255) NOT NULL COMMENT 'اسم المادة',
  `material_id` int(11) DEFAULT NULL COMMENT 'معرف المادة (supplier_id للعسل أو product_id للمواد الأخرى)',
  `quantity_per_unit` decimal(10,3) NOT NULL COMMENT 'الكمية لكل وحدة منتج',
  `unit` varchar(50) DEFAULT 'كجم' COMMENT 'وحدة القياس',
  `is_required` tinyint(1) DEFAULT 1 COMMENT 'هل المادة مطلوبة',
  `notes` text DEFAULT NULL,
  `sort_order` int(11) DEFAULT 0 COMMENT 'ترتيب العرض',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `product_template_packaging`
--

CREATE TABLE `product_template_packaging` (
  `id` int(11) NOT NULL,
  `template_id` int(11) NOT NULL COMMENT 'القالب',
  `packaging_material_id` int(11) DEFAULT NULL COMMENT 'مادة التعبئة',
  `packaging_name` varchar(255) DEFAULT NULL COMMENT 'اسم أداة التعبئة',
  `quantity_per_unit` decimal(10,3) NOT NULL DEFAULT 1.000 COMMENT 'الكمية لكل وحدة منتج',
  `unit` varchar(50) DEFAULT 'قطعة' COMMENT 'الوحدة',
  `is_required` tinyint(1) DEFAULT 1 COMMENT 'هل أداة التعبئة مطلوبة',
  `notes` text DEFAULT NULL,
  `sort_order` int(11) DEFAULT 0 COMMENT 'ترتيب العرض',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `product_template_raw_materials`
--

CREATE TABLE `product_template_raw_materials` (
  `id` int(11) NOT NULL,
  `template_id` int(11) NOT NULL,
  `material_name` varchar(255) NOT NULL COMMENT 'اسم المادة (مثل: مكسرات، لوز، إلخ)',
  `material_type` varchar(100) DEFAULT NULL COMMENT 'نوع المادة (مثل: honey_raw, honey_filtered)',
  `quantity_per_unit` decimal(10,3) NOT NULL DEFAULT 0.000 COMMENT 'الكمية بالجرام',
  `unit` varchar(50) DEFAULT 'جرام',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `pwa_splash_sessions`
--

CREATE TABLE `pwa_splash_sessions` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `session_token` varchar(64) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `expires_at` timestamp NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `raw_material_damage_logs`
--

CREATE TABLE `raw_material_damage_logs` (
  `id` int(11) NOT NULL,
  `material_category` varchar(50) NOT NULL COMMENT 'نوع الخام (honey, olive_oil, beeswax, derivatives, nuts)',
  `stock_id` int(11) DEFAULT NULL COMMENT 'معرف السجل في جدول المخزون',
  `supplier_id` int(11) DEFAULT NULL COMMENT 'المورد المرتبط',
  `item_label` varchar(255) NOT NULL COMMENT 'اسم المادة التالفة',
  `variety` varchar(255) DEFAULT NULL COMMENT 'النوع/الصنف (اختياري)',
  `quantity` decimal(12,3) NOT NULL DEFAULT 0.000 COMMENT 'الكمية التالفة',
  `unit` varchar(20) NOT NULL DEFAULT 'كجم' COMMENT 'وحدة القياس',
  `reason` text DEFAULT NULL COMMENT 'سبب التلف',
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `reader_access_log`
--

CREATE TABLE `reader_access_log` (
  `id` int(11) NOT NULL,
  `session_id` varchar(64) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `batch_number` varchar(255) DEFAULT NULL,
  `status` enum('success','not_found','error','rate_limited','invalid') NOT NULL DEFAULT 'success',
  `message` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `remember_tokens`
--

CREATE TABLE `remember_tokens` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `token` varchar(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_used` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `reports`
--

CREATE TABLE `reports` (
  `id` int(11) NOT NULL,
  `type` varchar(50) NOT NULL,
  `title` varchar(255) NOT NULL,
  `filters` text DEFAULT NULL,
  `file_path` varchar(255) DEFAULT NULL,
  `file_type` enum('pdf','excel','csv') DEFAULT 'pdf',
  `telegram_sent` tinyint(1) DEFAULT 0,
  `status` enum('pending','generated','sent','deleted') DEFAULT 'pending',
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `request_usage`
--

CREATE TABLE `request_usage` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `ip_address` varchar(45) NOT NULL,
  `method` varchar(10) NOT NULL,
  `path` text DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `request_usage_alerts`
--

CREATE TABLE `request_usage_alerts` (
  `id` int(11) NOT NULL,
  `identifier_type` enum('user','ip') NOT NULL,
  `identifier_value` varchar(255) NOT NULL,
  `window_start` datetime NOT NULL,
  `window_end` datetime NOT NULL,
  `request_count` int(11) NOT NULL,
  `threshold` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `returns`
--

CREATE TABLE `returns` (
  `id` int(11) NOT NULL,
  `return_number` varchar(50) NOT NULL,
  `sale_id` int(11) DEFAULT NULL,
  `invoice_id` int(11) DEFAULT NULL,
  `customer_id` int(11) NOT NULL,
  `sales_rep_id` int(11) DEFAULT NULL,
  `return_date` date NOT NULL,
  `return_type` enum('full','partial') DEFAULT 'full',
  `reason` enum('defective','wrong_item','customer_request','other') DEFAULT 'customer_request',
  `reason_description` text DEFAULT NULL,
  `refund_amount` decimal(15,2) DEFAULT 0.00,
  `refund_method` enum('cash','credit','exchange','company_request') DEFAULT 'cash',
  `status` enum('pending','approved','rejected','processed') DEFAULT 'pending',
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `return_items`
--

CREATE TABLE `return_items` (
  `id` int(11) NOT NULL,
  `return_id` int(11) NOT NULL,
  `invoice_item_id` int(11) DEFAULT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` decimal(10,2) NOT NULL,
  `unit_price` decimal(15,2) NOT NULL,
  `total_price` decimal(15,2) NOT NULL,
  `condition` enum('new','used','damaged','defective') DEFAULT 'new',
  `is_damaged` tinyint(1) NOT NULL DEFAULT 0,
  `batch_number_id` int(11) DEFAULT NULL,
  `batch_number` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `role_permissions`
--

CREATE TABLE `role_permissions` (
  `id` int(11) NOT NULL,
  `role` enum('manager','accountant','sales','production') NOT NULL,
  `permission_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `salaries`
--

CREATE TABLE `salaries` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `month` date NOT NULL,
  `hourly_rate` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total_hours` decimal(10,2) NOT NULL DEFAULT 0.00,
  `base_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `bonuses` decimal(15,2) DEFAULT 0.00,
  `collections_bonus` decimal(10,2) DEFAULT 0.00 COMMENT 'مكافآت التحصيلات 2%',
  `collections_amount` decimal(10,2) DEFAULT 0.00 COMMENT 'إجمالي مبالغ التحصيلات للمندوب',
  `deductions` decimal(15,2) DEFAULT 0.00,
  `advances_deduction` decimal(10,2) DEFAULT 0.00 COMMENT 'خصم السلف',
  `total_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `notes` text DEFAULT NULL COMMENT 'ملاحظات',
  `accumulated_amount` decimal(10,2) DEFAULT 0.00 COMMENT 'المبلغ التراكمي',
  `paid_amount` decimal(10,2) DEFAULT 0.00 COMMENT 'المبلغ المدفوع',
  `status` enum('pending','approved','paid') DEFAULT 'pending',
  `approved_by` int(11) DEFAULT NULL,
  `paid_at` timestamp NULL DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `salary_advances`
--

CREATE TABLE `salary_advances` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL COMMENT 'الموظف',
  `amount` decimal(10,2) NOT NULL COMMENT 'مبلغ السلفة',
  `reason` text DEFAULT NULL COMMENT 'سبب السلفة',
  `request_date` date NOT NULL COMMENT 'تاريخ الطلب',
  `status` enum('pending','accountant_approved','manager_approved','rejected') DEFAULT 'pending' COMMENT 'حالة الطلب',
  `accountant_approved_by` int(11) DEFAULT NULL,
  `accountant_approved_at` timestamp NULL DEFAULT NULL,
  `manager_approved_by` int(11) DEFAULT NULL,
  `manager_approved_at` timestamp NULL DEFAULT NULL,
  `deducted_from_salary_id` int(11) DEFAULT NULL COMMENT 'الراتب الذي تم خصم السلفة منه',
  `total_salary_before_advance` decimal(10,2) DEFAULT NULL COMMENT 'الراتب الإجمالي قبل خصم السلفة',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `salary_settlements`
--

CREATE TABLE `salary_settlements` (
  `id` int(11) NOT NULL,
  `salary_id` int(11) NOT NULL COMMENT 'معرف الراتب',
  `user_id` int(11) NOT NULL COMMENT 'معرف الموظف',
  `settlement_amount` decimal(10,2) NOT NULL COMMENT 'مبلغ التسوية',
  `previous_accumulated` decimal(10,2) DEFAULT 0.00 COMMENT 'المبلغ التراكمي السابق',
  `remaining_after_settlement` decimal(10,2) DEFAULT 0.00 COMMENT 'المتبقي بعد التسوية',
  `settlement_type` enum('full','partial') DEFAULT 'partial' COMMENT 'نوع التسوية',
  `settlement_date` date NOT NULL COMMENT 'تاريخ التسوية',
  `notes` text DEFAULT NULL COMMENT 'ملاحظات',
  `created_by` int(11) DEFAULT NULL COMMENT 'من قام بالتسوية',
  `invoice_path` varchar(500) DEFAULT NULL COMMENT 'مسار فاتورة PDF',
  `telegram_sent` tinyint(1) DEFAULT 0 COMMENT 'تم الإرسال إلى تليجرام',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sales`
--

CREATE TABLE `sales` (
  `id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` decimal(10,2) NOT NULL,
  `price` decimal(15,2) NOT NULL,
  `total` decimal(15,2) NOT NULL,
  `date` date NOT NULL,
  `salesperson_id` int(11) NOT NULL,
  `status` enum('pending','approved','rejected','completed') DEFAULT 'pending',
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sales_batch_numbers`
--

CREATE TABLE `sales_batch_numbers` (
  `id` int(11) NOT NULL,
  `sale_id` int(11) DEFAULT NULL,
  `invoice_item_id` int(11) DEFAULT NULL,
  `batch_number_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sales_returns`
--

CREATE TABLE `sales_returns` (
  `id` int(11) NOT NULL,
  `return_number` varchar(50) NOT NULL,
  `invoice_id` int(11) DEFAULT NULL,
  `sale_id` int(11) DEFAULT NULL,
  `customer_id` int(11) NOT NULL,
  `sales_rep_id` int(11) NOT NULL,
  `return_date` date NOT NULL,
  `return_type` enum('full','partial') DEFAULT 'full',
  `reason` enum('defective','wrong_item','customer_request','other') DEFAULT 'customer_request',
  `reason_description` text DEFAULT NULL,
  `refund_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `refund_method` enum('cash','credit','exchange') DEFAULT 'cash',
  `status` enum('pending','approved','rejected','processed') DEFAULT 'pending',
  `approved_by` int(11) DEFAULT NULL,
  `processed_by` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sesame_stock`
--

CREATE TABLE `sesame_stock` (
  `id` int(11) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `quantity` decimal(10,3) NOT NULL DEFAULT 0.000 COMMENT 'الكمية بالكيلوجرام',
  `converted_to_tahini_quantity` decimal(10,3) NOT NULL DEFAULT 0.000 COMMENT 'إجمالي كمية التحويل إلى طحينة بالكيلوجرام',
  `unit_price` decimal(10,2) DEFAULT NULL COMMENT 'سعر الكيلو',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `shipping_companies`
--

CREATE TABLE `shipping_companies` (
  `id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL,
  `contact_person` varchar(100) DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `email` varchar(120) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `balance` decimal(15,2) NOT NULL DEFAULT 0.00,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `shipping_company_orders`
--

CREATE TABLE `shipping_company_orders` (
  `id` int(11) NOT NULL,
  `order_number` varchar(50) NOT NULL,
  `shipping_company_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `invoice_id` int(11) DEFAULT NULL,
  `total_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `status` enum('assigned','in_transit','delivered','cancelled') NOT NULL DEFAULT 'assigned',
  `handed_over_at` timestamp NULL DEFAULT NULL,
  `delivered_at` timestamp NULL DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `shipping_company_order_items`
--

CREATE TABLE `shipping_company_order_items` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `batch_id` int(11) DEFAULT NULL,
  `quantity` decimal(10,2) NOT NULL,
  `unit_price` decimal(15,2) NOT NULL,
  `total_price` decimal(15,2) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `suppliers`
--

CREATE TABLE `suppliers` (
  `id` int(11) NOT NULL,
  `supplier_code` varchar(20) DEFAULT NULL,
  `type` enum('honey','packaging','nuts','olive_oil','derivatives','beeswax','sesame') DEFAULT NULL,
  `name` varchar(100) NOT NULL,
  `contact_person` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `balance` decimal(15,2) DEFAULT 0.00,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `supplier_balance_history`
--

CREATE TABLE `supplier_balance_history` (
  `id` int(11) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `change_amount` decimal(15,2) NOT NULL,
  `previous_balance` decimal(15,2) NOT NULL DEFAULT 0.00,
  `new_balance` decimal(15,2) NOT NULL DEFAULT 0.00,
  `type` enum('topup','payment') NOT NULL,
  `notes` text DEFAULT NULL,
  `reference_id` int(11) DEFAULT NULL COMMENT 'ID of related record (financial transaction, etc)',
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `system_daily_jobs`
--

CREATE TABLE `system_daily_jobs` (
  `job_key` varchar(120) NOT NULL,
  `last_sent_at` datetime DEFAULT NULL,
  `last_notified_at` datetime DEFAULT NULL,
  `last_notification_hash` varchar(128) DEFAULT NULL,
  `last_file_path` varchar(512) DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `system_scheduled_jobs`
--

CREATE TABLE `system_scheduled_jobs` (
  `job_key` varchar(120) NOT NULL,
  `scheduled_at` datetime NOT NULL,
  `executed_at` datetime DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `system_settings`
--

CREATE TABLE `system_settings` (
  `id` int(11) NOT NULL,
  `key` varchar(100) NOT NULL,
  `value` text DEFAULT NULL,
  `description` text DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tahini_stock`
--

CREATE TABLE `tahini_stock` (
  `id` int(11) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `quantity` decimal(10,3) NOT NULL DEFAULT 0.000 COMMENT 'الكمية بالكيلوجرام',
  `unit_price` decimal(10,2) DEFAULT NULL COMMENT 'سعر الكيلو',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tasks`
--

CREATE TABLE `tasks` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `assigned_to` int(11) DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `priority` enum('low','normal','high','urgent') DEFAULT 'normal',
  `status` enum('pending','received','in_progress','completed','cancelled') DEFAULT 'pending',
  `due_date` date DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `received_at` timestamp NULL DEFAULT NULL,
  `started_at` timestamp NULL DEFAULT NULL,
  `related_type` varchar(50) DEFAULT NULL,
  `related_id` int(11) DEFAULT NULL,
  `product_id` int(11) DEFAULT NULL,
  `quantity` decimal(10,2) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `telegram_invoices`
--

CREATE TABLE `telegram_invoices` (
  `id` int(11) NOT NULL,
  `invoice_id` int(11) DEFAULT NULL COMMENT 'معرف الفاتورة من جدول invoices',
  `invoice_number` varchar(100) DEFAULT NULL COMMENT 'رقم الفاتورة',
  `invoice_type` varchar(50) DEFAULT 'sales_pos_invoice' COMMENT 'نوع الفاتورة (sales_pos_invoice, manager_pos_invoice, etc.)',
  `token` varchar(32) NOT NULL COMMENT 'معرف أمني للوصول للفاتورة',
  `html_content` longtext NOT NULL COMMENT 'محتوى HTML للفاتورة',
  `relative_path` varchar(255) DEFAULT NULL COMMENT 'مسار الملف النسبي (إن وجد)',
  `filename` varchar(255) DEFAULT NULL COMMENT 'اسم الملف',
  `summary` text DEFAULT NULL COMMENT 'ملخص الفاتورة (JSON)',
  `telegram_sent` tinyint(1) DEFAULT 0 COMMENT 'تم الإرسال إلى Telegram',
  `sent_at` datetime DEFAULT NULL COMMENT 'تاريخ الإرسال إلى Telegram',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول تخزين الفواتير المرسلة إلى Telegram';

-- --------------------------------------------------------

--
-- Table structure for table `unified_product_templates`
--

CREATE TABLE `unified_product_templates` (
  `id` int(11) NOT NULL,
  `product_name` varchar(255) NOT NULL COMMENT 'اسم المنتج',
  `created_by` int(11) DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `main_supplier_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `form_payload` longtext DEFAULT NULL COMMENT 'JSON يحتوي جميع مدخلات النموذج',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) DEFAULT NULL,
  `role` enum('accountant','sales','production','manager') NOT NULL,
  `webauthn_enabled` tinyint(1) DEFAULT 0,
  `full_name` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `profile_photo` longtext DEFAULT NULL,
  `hourly_rate` decimal(10,2) NOT NULL DEFAULT 0.00,
  `status` enum('active','inactive') DEFAULT 'active',
  `warning_count` int(11) DEFAULT 0,
  `delay_count` int(11) DEFAULT 0,
  `can_check_in` tinyint(1) DEFAULT 1,
  `can_check_out` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `password_hash`, `role`, `webauthn_enabled`, `full_name`, `phone`, `profile_photo`, `hourly_rate`, `status`, `warning_count`, `delay_count`, `can_check_in`, `can_check_out`, `created_at`, `updated_at`) VALUES
(1, 'admin', '$2y$10$cIXyIRjYeF.YHoKQfUIgluq8mWJ.qNlvr7iz0c1QcWbRrdI0yqNHS', 'manager', 0, 'المدير العام', '01000000004', NULL, '0.00', 'active', 0, 0, 1, 1, '2025-11-04 06:14:12', '2025-12-01 20:53:04');

-- --------------------------------------------------------

--
-- Table structure for table `user_permissions`
--

CREATE TABLE `user_permissions` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `permission_id` int(11) NOT NULL,
  `granted` tinyint(1) DEFAULT 1,
  `granted_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_status`
--

CREATE TABLE `user_status` (
  `user_id` int(11) NOT NULL,
  `last_seen` datetime NOT NULL DEFAULT current_timestamp(),
  `is_online` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `vehicles`
--

CREATE TABLE `vehicles` (
  `id` int(11) NOT NULL,
  `vehicle_number` varchar(50) NOT NULL,
  `vehicle_type` varchar(100) DEFAULT NULL,
  `model` varchar(100) DEFAULT NULL,
  `year` year(4) DEFAULT NULL,
  `driver_id` int(11) DEFAULT NULL COMMENT 'مندوب المبيعات صاحب السيارة',
  `status` enum('active','inactive','maintenance') DEFAULT 'active',
  `notes` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `vehicle_inventory`
--

CREATE TABLE `vehicle_inventory` (
  `id` int(11) NOT NULL,
  `vehicle_id` int(11) NOT NULL,
  `warehouse_id` int(11) DEFAULT NULL,
  `product_id` int(11) NOT NULL,
  `product_name` varchar(255) DEFAULT NULL,
  `product_category` varchar(100) DEFAULT NULL,
  `product_unit` varchar(50) DEFAULT NULL,
  `product_unit_price` decimal(15,2) DEFAULT NULL,
  `manager_unit_price` decimal(15,2) DEFAULT NULL,
  `finished_batch_id` int(11) DEFAULT NULL,
  `finished_batch_number` varchar(100) DEFAULT NULL,
  `finished_production_date` date DEFAULT NULL,
  `finished_quantity_produced` decimal(12,2) DEFAULT NULL,
  `finished_workers` text DEFAULT NULL,
  `product_snapshot` longtext DEFAULT NULL,
  `quantity` decimal(10,2) NOT NULL DEFAULT 0.00,
  `last_updated_by` int(11) DEFAULT NULL,
  `last_updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `warehouses`
--

CREATE TABLE `warehouses` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `location` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `warehouse_type` enum('main','vehicle') DEFAULT 'main',
  `vehicle_id` int(11) DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `warehouse_transfers`
--

CREATE TABLE `warehouse_transfers` (
  `id` int(11) NOT NULL,
  `transfer_number` varchar(50) NOT NULL,
  `from_warehouse_id` int(11) NOT NULL,
  `to_warehouse_id` int(11) NOT NULL,
  `transfer_date` date NOT NULL,
  `transfer_type` enum('to_vehicle','from_vehicle','between_warehouses') DEFAULT 'to_vehicle',
  `reason` text DEFAULT NULL,
  `status` enum('pending','approved','rejected','completed','cancelled') DEFAULT 'pending',
  `requested_by` int(11) NOT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `warehouse_transfer_items`
--

CREATE TABLE `warehouse_transfer_items` (
  `id` int(11) NOT NULL,
  `transfer_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `batch_id` int(11) DEFAULT NULL,
  `batch_number` varchar(100) DEFAULT NULL,
  `quantity` decimal(10,2) NOT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `webauthn_credentials`
--

CREATE TABLE `webauthn_credentials` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `credential_id` varchar(255) NOT NULL,
  `public_key` text NOT NULL,
  `counter` bigint(20) DEFAULT 0,
  `device_name` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_used` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `accountant_transactions`
--
ALTER TABLE `accountant_transactions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `transaction_type` (`transaction_type`),
  ADD KEY `sales_rep_id` (`sales_rep_id`),
  ADD KEY `status` (`status`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `approved_by` (`approved_by`),
  ADD KEY `created_at` (`created_at`),
  ADD KEY `reference_number` (`reference_number`);

--
-- Indexes for table `advance_requests`
--
ALTER TABLE `advance_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `salary_id` (`salary_id`),
  ADD KEY `approved_by` (`approved_by`),
  ADD KEY `status` (`status`),
  ADD KEY `requested_month_year` (`requested_month`,`requested_year`);

--
-- Indexes for table `approvals`
--
ALTER TABLE `approvals`
  ADD PRIMARY KEY (`id`),
  ADD KEY `type` (`type`,`reference_id`),
  ADD KEY `requested_by` (`requested_by`),
  ADD KEY `approved_by` (`approved_by`),
  ADD KEY `status` (`status`);

--
-- Indexes for table `attendance`
--
ALTER TABLE `attendance`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_date` (`user_id`,`date`),
  ADD KEY `date` (`date`);

--
-- Indexes for table `attendance_event_notification_logs`
--
ALTER TABLE `attendance_event_notification_logs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_user_event_date` (`user_id`,`event_type`,`sent_date`),
  ADD KEY `attendance_record_idx` (`attendance_record_id`);

--
-- Indexes for table `attendance_monthly_report_logs`
--
ALTER TABLE `attendance_monthly_report_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `month_key_idx` (`month_key`),
  ADD KEY `sent_via_idx` (`sent_via`);

--
-- Indexes for table `attendance_notification_logs`
--
ALTER TABLE `attendance_notification_logs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_kind_date` (`user_id`,`notification_kind`,`sent_date`);

--
-- Indexes for table `attendance_records`
--
ALTER TABLE `attendance_records`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `date` (`date`),
  ADD KEY `user_date` (`user_id`,`date`),
  ADD KEY `check_in_time` (`check_in_time`);

--
-- Indexes for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `action` (`action`),
  ADD KEY `entity_type` (`entity_type`,`entity_id`),
  ADD KEY `created_at` (`created_at`);

--
-- Indexes for table `backups`
--
ALTER TABLE `backups`
  ADD PRIMARY KEY (`id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `status` (`status`);

--
-- Indexes for table `barcode_scans`
--
ALTER TABLE `barcode_scans`
  ADD PRIMARY KEY (`id`),
  ADD KEY `batch_number_id` (`batch_number_id`),
  ADD KEY `scanned_by` (`scanned_by`),
  ADD KEY `scan_type` (`scan_type`),
  ADD KEY `created_at` (`created_at`);

--
-- Indexes for table `batches`
--
ALTER TABLE `batches`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_batch_number` (`batch_number`),
  ADD KEY `product_id` (`product_id`);

--
-- Indexes for table `batch_numbers`
--
ALTER TABLE `batch_numbers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `batch_number` (`batch_number`),
  ADD KEY `product_id` (`product_id`),
  ADD KEY `production_id` (`production_id`),
  ADD KEY `production_date` (`production_date`),
  ADD KEY `honey_supplier_id` (`honey_supplier_id`),
  ADD KEY `packaging_supplier_id` (`packaging_supplier_id`),
  ADD KEY `status` (`status`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `batch_packaging`
--
ALTER TABLE `batch_packaging`
  ADD PRIMARY KEY (`id`),
  ADD KEY `batch_id` (`batch_id`),
  ADD KEY `packaging_material_id` (`packaging_material_id`);

--
-- Indexes for table `batch_raw_materials`
--
ALTER TABLE `batch_raw_materials`
  ADD PRIMARY KEY (`id`),
  ADD KEY `batch_id` (`batch_id`),
  ADD KEY `raw_material_id` (`raw_material_id`);

--
-- Indexes for table `batch_suppliers`
--
ALTER TABLE `batch_suppliers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `batch_id` (`batch_id`),
  ADD KEY `supplier_id` (`supplier_id`);

--
-- Indexes for table `batch_workers`
--
ALTER TABLE `batch_workers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `batch_id` (`batch_id`),
  ADD KEY `employee_id` (`employee_id`);

--
-- Indexes for table `beeswax_product_templates`
--
ALTER TABLE `beeswax_product_templates`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `beeswax_stock`
--
ALTER TABLE `beeswax_stock`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `supplier_id` (`supplier_id`);

--
-- Indexes for table `blocked_ips`
--
ALTER TABLE `blocked_ips`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ip_address` (`ip_address`),
  ADD KEY `blocked_until` (`blocked_until`),
  ADD KEY `blocked_by` (`blocked_by`);

--
-- Indexes for table `cash_register_additions`
--
ALTER TABLE `cash_register_additions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `sales_rep_id` (`sales_rep_id`),
  ADD KEY `created_at` (`created_at`),
  ADD KEY `cash_register_additions_ibfk_2` (`created_by`);

--
-- Indexes for table `collections`
--
ALTER TABLE `collections`
  ADD PRIMARY KEY (`id`),
  ADD KEY `customer_id` (`customer_id`),
  ADD KEY `collected_by` (`collected_by`),
  ADD KEY `date` (`date`);

--
-- Indexes for table `customers`
--
ALTER TABLE `customers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `name` (`name`),
  ADD KEY `fk_customers_created_by` (`created_by`),
  ADD KEY `rep_id` (`rep_id`);

--
-- Indexes for table `customer_orders`
--
ALTER TABLE `customer_orders`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `order_number` (`order_number`),
  ADD KEY `customer_id` (`customer_id`),
  ADD KEY `sales_rep_id` (`sales_rep_id`),
  ADD KEY `order_date` (`order_date`),
  ADD KEY `status` (`status`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `customer_order_items`
--
ALTER TABLE `customer_order_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `order_id` (`order_id`),
  ADD KEY `product_id` (`product_id`);

--
-- Indexes for table `customer_purchase_history`
--
ALTER TABLE `customer_purchase_history`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `customer_invoice_unique` (`customer_id`,`invoice_id`),
  ADD KEY `customer_invoice_date_idx` (`customer_id`,`invoice_date`),
  ADD KEY `invoice_date_idx` (`invoice_date`);

--
-- Indexes for table `daily_backup_sent_log`
--
ALTER TABLE `daily_backup_sent_log`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_sent_date` (`sent_date`),
  ADD KEY `sent_at` (`sent_at`);

--
-- Indexes for table `damaged_returns`
--
ALTER TABLE `damaged_returns`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_return_id` (`return_id`),
  ADD KEY `idx_return_item_id` (`return_item_id`),
  ADD KEY `idx_product_id` (`product_id`),
  ADD KEY `idx_batch_number_id` (`batch_number_id`),
  ADD KEY `idx_sales_rep_id` (`sales_rep_id`),
  ADD KEY `idx_approval_status` (`approval_status`);

--
-- Indexes for table `derivatives_product_templates`
--
ALTER TABLE `derivatives_product_templates`
  ADD PRIMARY KEY (`id`),
  ADD KEY `derivative_type` (`derivative_type`);

--
-- Indexes for table `derivatives_stock`
--
ALTER TABLE `derivatives_stock`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `supplier_derivative` (`supplier_id`,`derivative_type`),
  ADD KEY `supplier_id` (`supplier_id`),
  ADD KEY `derivative_type` (`derivative_type`);

--
-- Indexes for table `factory_waste_packaging`
--
ALTER TABLE `factory_waste_packaging`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_packaging_damage_log_id` (`packaging_damage_log_id`),
  ADD KEY `idx_recorded_by_user_id` (`recorded_by_user_id`),
  ADD KEY `idx_added_date` (`added_date`);

--
-- Indexes for table `factory_waste_products`
--
ALTER TABLE `factory_waste_products`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_product_id` (`product_id`),
  ADD KEY `idx_batch_number_id` (`batch_number_id`),
  ADD KEY `idx_damaged_return_id` (`damaged_return_id`),
  ADD KEY `idx_added_date` (`added_date`);

--
-- Indexes for table `factory_waste_raw_materials`
--
ALTER TABLE `factory_waste_raw_materials`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_raw_material_damage_log_id` (`raw_material_damage_log_id`),
  ADD KEY `idx_recorded_by_user_id` (`recorded_by_user_id`),
  ADD KEY `idx_added_date` (`added_date`);

--
-- Indexes for table `financial_transactions`
--
ALTER TABLE `financial_transactions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `supplier_id` (`supplier_id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `approved_by` (`approved_by`),
  ADD KEY `status` (`status`),
  ADD KEY `created_at` (`created_at`);

--
-- Indexes for table `finished_products`
--
ALTER TABLE `finished_products`
  ADD PRIMARY KEY (`id`),
  ADD KEY `batch_id` (`batch_id`),
  ADD KEY `product_id` (`product_id`),
  ADD KEY `batch_number` (`batch_number`);

--
-- Indexes for table `group_chat_messages`
--
ALTER TABLE `group_chat_messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_group_chat_user` (`user_id`),
  ADD KEY `idx_group_chat_parent` (`parent_id`),
  ADD KEY `idx_group_chat_created` (`created_at`);

--
-- Indexes for table `honey_filtration`
--
ALTER TABLE `honey_filtration`
  ADD PRIMARY KEY (`id`),
  ADD KEY `supplier_id` (`supplier_id`),
  ADD KEY `filtration_date` (`filtration_date`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `honey_stock`
--
ALTER TABLE `honey_stock`
  ADD PRIMARY KEY (`id`),
  ADD KEY `supplier_id_idx` (`supplier_id`),
  ADD KEY `honey_variety` (`honey_variety`),
  ADD KEY `supplier_variety` (`supplier_id`,`honey_variety`);

--
-- Indexes for table `inventory_movements`
--
ALTER TABLE `inventory_movements`
  ADD PRIMARY KEY (`id`),
  ADD KEY `product_id` (`product_id`),
  ADD KEY `warehouse_id` (`warehouse_id`),
  ADD KEY `reference` (`reference_type`,`reference_id`),
  ADD KEY `type` (`type`),
  ADD KEY `created_at` (`created_at`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `invoices`
--
ALTER TABLE `invoices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `invoice_number` (`invoice_number`),
  ADD KEY `customer_id` (`customer_id`),
  ADD KEY `sales_rep_id` (`sales_rep_id`),
  ADD KEY `date` (`date`),
  ADD KEY `due_date` (`due_date`),
  ADD KEY `status` (`status`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `invoice_items`
--
ALTER TABLE `invoice_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `invoice_id` (`invoice_id`),
  ADD KEY `product_id` (`product_id`);

--
-- Indexes for table `local_collections`
--
ALTER TABLE `local_collections`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `collection_number` (`collection_number`),
  ADD KEY `customer_id` (`customer_id`),
  ADD KEY `collected_by` (`collected_by`),
  ADD KEY `approved_by` (`approved_by`),
  ADD KEY `date` (`date`),
  ADD KEY `status` (`status`);

--
-- Indexes for table `local_customers`
--
ALTER TABLE `local_customers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `name` (`name`),
  ADD KEY `status` (`status`),
  ADD KEY `balance` (`balance`);

--
-- Indexes for table `local_invoices`
--
ALTER TABLE `local_invoices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `invoice_number` (`invoice_number`),
  ADD KEY `customer_id` (`customer_id`),
  ADD KEY `date` (`date`),
  ADD KEY `status` (`status`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `local_invoice_items`
--
ALTER TABLE `local_invoice_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `invoice_id` (`invoice_id`),
  ADD KEY `product_id` (`product_id`);

--
-- Indexes for table `local_returns`
--
ALTER TABLE `local_returns`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `return_number` (`return_number`),
  ADD KEY `customer_id` (`customer_id`),
  ADD KEY `status` (`status`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `approved_by` (`approved_by`);

--
-- Indexes for table `local_return_items`
--
ALTER TABLE `local_return_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `return_id` (`return_id`),
  ADD KEY `invoice_id` (`invoice_id`),
  ADD KEY `invoice_item_id` (`invoice_item_id`),
  ADD KEY `product_id` (`product_id`);

--
-- Indexes for table `login_attempts`
--
ALTER TABLE `login_attempts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ip_address` (`ip_address`),
  ADD KEY `username` (`username`),
  ADD KEY `created_at` (`created_at`),
  ADD KEY `success` (`success`);

--
-- Indexes for table `messages`
--
ALTER TABLE `messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `messages_user_id_idx` (`user_id`),
  ADD KEY `messages_reply_to_idx` (`reply_to`);

--
-- Indexes for table `message_reads`
--
ALTER TABLE `message_reads`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `message_reads_unique` (`message_id`,`user_id`),
  ADD KEY `message_reads_user_idx` (`user_id`);

--
-- Indexes for table `mixed_nuts`
--
ALTER TABLE `mixed_nuts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `supplier_id` (`supplier_id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `mixed_nuts_ingredients`
--
ALTER TABLE `mixed_nuts_ingredients`
  ADD PRIMARY KEY (`id`),
  ADD KEY `mixed_nuts_id` (`mixed_nuts_id`),
  ADD KEY `nuts_stock_id` (`nuts_stock_id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `role` (`role`),
  ADD KEY `read` (`read`),
  ADD KEY `created_at` (`created_at`);

--
-- Indexes for table `notification_settings`
--
ALTER TABLE `notification_settings`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `role` (`role`),
  ADD KEY `notification_type` (`notification_type`);

--
-- Indexes for table `nuts_stock`
--
ALTER TABLE `nuts_stock`
  ADD PRIMARY KEY (`id`),
  ADD KEY `supplier_id_idx` (`supplier_id`),
  ADD KEY `nut_type` (`nut_type`),
  ADD KEY `supplier_nut` (`supplier_id`,`nut_type`);

--
-- Indexes for table `olive_oil_product_templates`
--
ALTER TABLE `olive_oil_product_templates`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `olive_oil_stock`
--
ALTER TABLE `olive_oil_stock`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `supplier_id` (`supplier_id`);

--
-- Indexes for table `packaging_damage_logs`
--
ALTER TABLE `packaging_damage_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `material_id` (`material_id`),
  ADD KEY `recorded_by` (`recorded_by`);

--
-- Indexes for table `packaging_materials`
--
ALTER TABLE `packaging_materials`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `material_id` (`material_id`),
  ADD KEY `type` (`type`),
  ADD KEY `status` (`status`);

--
-- Indexes for table `packaging_usage_logs`
--
ALTER TABLE `packaging_usage_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `material_id` (`material_id`),
  ADD KEY `used_by` (`used_by`),
  ADD KEY `source_table` (`source_table`);

--
-- Indexes for table `payment_reminders`
--
ALTER TABLE `payment_reminders`
  ADD PRIMARY KEY (`id`),
  ADD KEY `payment_schedule_id` (`payment_schedule_id`),
  ADD KEY `reminder_date` (`reminder_date`),
  ADD KEY `sent_status` (`sent_status`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `payment_schedules`
--
ALTER TABLE `payment_schedules`
  ADD PRIMARY KEY (`id`),
  ADD KEY `sale_id` (`sale_id`),
  ADD KEY `invoice_id` (`invoice_id`),
  ADD KEY `customer_id` (`customer_id`),
  ADD KEY `sales_rep_id` (`sales_rep_id`),
  ADD KEY `due_date` (`due_date`),
  ADD KEY `status` (`status`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `permissions`
--
ALTER TABLE `permissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`),
  ADD KEY `category` (`category`);

--
-- Indexes for table `production`
--
ALTER TABLE `production`
  ADD PRIMARY KEY (`id`),
  ADD KEY `product_id` (`product_id`),
  ADD KEY `worker_id` (`worker_id`),
  ADD KEY `production_date` (`production_date`),
  ADD KEY `customer_order_item_id` (`customer_order_item_id`),
  ADD KEY `production_line_id` (`production_line_id`);

--
-- Indexes for table `production_lines`
--
ALTER TABLE `production_lines`
  ADD PRIMARY KEY (`id`),
  ADD KEY `product_id` (`product_id`),
  ADD KEY `worker_id` (`worker_id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `status` (`status`);

--
-- Indexes for table `production_materials`
--
ALTER TABLE `production_materials`
  ADD PRIMARY KEY (`id`),
  ADD KEY `production_id` (`production_id`),
  ADD KEY `product_id` (`product_id`),
  ADD KEY `supplier_id` (`supplier_id`);

--
-- Indexes for table `production_monthly_report_logs`
--
ALTER TABLE `production_monthly_report_logs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `production_monthly_report_logs_unique` (`month_key`,`sent_via`),
  ADD KEY `production_monthly_report_logs_month_idx` (`month_number`,`year_number`);

--
-- Indexes for table `production_supply_logs`
--
ALTER TABLE `production_supply_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `production_supply_logs_category_date_idx` (`material_category`,`recorded_at`),
  ADD KEY `production_supply_logs_supplier_idx` (`supplier_id`),
  ADD KEY `production_supply_logs_recorded_at_idx` (`recorded_at`);

--
-- Indexes for table `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`id`),
  ADD KEY `name` (`name`),
  ADD KEY `category` (`category`),
  ADD KEY `warehouse_id` (`warehouse_id`);

--
-- Indexes for table `product_specifications`
--
ALTER TABLE `product_specifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `updated_by` (`updated_by`);

--
-- Indexes for table `product_templates`
--
ALTER TABLE `product_templates`
  ADD PRIMARY KEY (`id`),
  ADD KEY `product_id` (`product_id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `status` (`status`),
  ADD KEY `source_template_id` (`source_template_id`),
  ADD KEY `main_supplier_id` (`main_supplier_id`);

--
-- Indexes for table `product_template_materials`
--
ALTER TABLE `product_template_materials`
  ADD PRIMARY KEY (`id`),
  ADD KEY `template_id` (`template_id`),
  ADD KEY `material_type` (`material_type`);

--
-- Indexes for table `product_template_packaging`
--
ALTER TABLE `product_template_packaging`
  ADD PRIMARY KEY (`id`),
  ADD KEY `template_id` (`template_id`),
  ADD KEY `packaging_material_id` (`packaging_material_id`);

--
-- Indexes for table `product_template_raw_materials`
--
ALTER TABLE `product_template_raw_materials`
  ADD PRIMARY KEY (`id`),
  ADD KEY `template_id` (`template_id`);

--
-- Indexes for table `pwa_splash_sessions`
--
ALTER TABLE `pwa_splash_sessions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `session_token` (`session_token`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `expires_at` (`expires_at`);

--
-- Indexes for table `raw_material_damage_logs`
--
ALTER TABLE `raw_material_damage_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `material_category` (`material_category`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `created_at` (`created_at`);

--
-- Indexes for table `reader_access_log`
--
ALTER TABLE `reader_access_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_session` (`session_id`),
  ADD KEY `idx_ip` (`ip_address`),
  ADD KEY `idx_created_at` (`created_at`);

--
-- Indexes for table `remember_tokens`
--
ALTER TABLE `remember_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_id_token` (`user_id`,`token`),
  ADD KEY `token` (`token`),
  ADD KEY `expires_at` (`expires_at`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `reports`
--
ALTER TABLE `reports`
  ADD PRIMARY KEY (`id`),
  ADD KEY `type` (`type`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `status` (`status`);

--
-- Indexes for table `request_usage`
--
ALTER TABLE `request_usage`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_date` (`user_id`,`created_at`),
  ADD KEY `idx_ip_date` (`ip_address`,`created_at`),
  ADD KEY `idx_created_at` (`created_at`);

--
-- Indexes for table `request_usage_alerts`
--
ALTER TABLE `request_usage_alerts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_identifier_window` (`identifier_type`,`identifier_value`,`window_start`,`window_end`);

--
-- Indexes for table `returns`
--
ALTER TABLE `returns`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `return_number` (`return_number`),
  ADD KEY `sale_id` (`sale_id`),
  ADD KEY `invoice_id` (`invoice_id`),
  ADD KEY `customer_id` (`customer_id`),
  ADD KEY `sales_rep_id` (`sales_rep_id`),
  ADD KEY `return_date` (`return_date`),
  ADD KEY `status` (`status`),
  ADD KEY `approved_by` (`approved_by`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `return_items`
--
ALTER TABLE `return_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `return_id` (`return_id`),
  ADD KEY `product_id` (`product_id`),
  ADD KEY `batch_number_id` (`batch_number_id`),
  ADD KEY `idx_invoice_item_id` (`invoice_item_id`),
  ADD KEY `idx_batch_number_id` (`batch_number_id`);

--
-- Indexes for table `role_permissions`
--
ALTER TABLE `role_permissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `role_permission` (`role`,`permission_id`),
  ADD KEY `permission_id` (`permission_id`);

--
-- Indexes for table `salaries`
--
ALTER TABLE `salaries`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `month` (`month`),
  ADD KEY `status` (`status`),
  ADD KEY `salaries_ibfk_2` (`approved_by`),
  ADD KEY `salaries_ibfk_3` (`created_by`);

--
-- Indexes for table `salary_advances`
--
ALTER TABLE `salary_advances`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `status` (`status`),
  ADD KEY `request_date` (`request_date`),
  ADD KEY `accountant_approved_by` (`accountant_approved_by`),
  ADD KEY `manager_approved_by` (`manager_approved_by`),
  ADD KEY `deducted_from_salary_id` (`deducted_from_salary_id`);

--
-- Indexes for table `salary_settlements`
--
ALTER TABLE `salary_settlements`
  ADD PRIMARY KEY (`id`),
  ADD KEY `salary_id` (`salary_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `settlement_date` (`settlement_date`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `sales`
--
ALTER TABLE `sales`
  ADD PRIMARY KEY (`id`),
  ADD KEY `customer_id` (`customer_id`),
  ADD KEY `product_id` (`product_id`),
  ADD KEY `salesperson_id` (`salesperson_id`),
  ADD KEY `approved_by` (`approved_by`),
  ADD KEY `date` (`date`),
  ADD KEY `status` (`status`);

--
-- Indexes for table `sales_batch_numbers`
--
ALTER TABLE `sales_batch_numbers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `sale_id` (`sale_id`),
  ADD KEY `invoice_item_id` (`invoice_item_id`),
  ADD KEY `batch_number_id` (`batch_number_id`);

--
-- Indexes for table `sales_returns`
--
ALTER TABLE `sales_returns`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `return_number` (`return_number`),
  ADD KEY `invoice_id` (`invoice_id`),
  ADD KEY `sale_id` (`sale_id`),
  ADD KEY `customer_id` (`customer_id`),
  ADD KEY `sales_rep_id` (`sales_rep_id`),
  ADD KEY `status` (`status`),
  ADD KEY `sales_returns_ibfk_5` (`approved_by`),
  ADD KEY `sales_returns_ibfk_6` (`processed_by`),
  ADD KEY `sales_returns_ibfk_7` (`created_by`);

--
-- Indexes for table `sesame_stock`
--
ALTER TABLE `sesame_stock`
  ADD PRIMARY KEY (`id`),
  ADD KEY `supplier_id_idx` (`supplier_id`);

--
-- Indexes for table `shipping_companies`
--
ALTER TABLE `shipping_companies`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`),
  ADD KEY `status` (`status`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `updated_by` (`updated_by`);

--
-- Indexes for table `shipping_company_orders`
--
ALTER TABLE `shipping_company_orders`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `order_number` (`order_number`),
  ADD KEY `shipping_company_id` (`shipping_company_id`),
  ADD KEY `customer_id` (`customer_id`),
  ADD KEY `invoice_id` (`invoice_id`),
  ADD KEY `status` (`status`),
  ADD KEY `shipping_company_orders_created_fk` (`created_by`),
  ADD KEY `shipping_company_orders_updated_fk` (`updated_by`);

--
-- Indexes for table `shipping_company_order_items`
--
ALTER TABLE `shipping_company_order_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `order_id` (`order_id`),
  ADD KEY `product_id` (`product_id`),
  ADD KEY `batch_id` (`batch_id`);

--
-- Indexes for table `suppliers`
--
ALTER TABLE `suppliers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `supplier_code` (`supplier_code`),
  ADD KEY `name` (`name`);

--
-- Indexes for table `supplier_balance_history`
--
ALTER TABLE `supplier_balance_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `supplier_id` (`supplier_id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `type` (`type`),
  ADD KEY `created_at` (`created_at`);

--
-- Indexes for table `system_daily_jobs`
--
ALTER TABLE `system_daily_jobs`
  ADD PRIMARY KEY (`job_key`);

--
-- Indexes for table `system_scheduled_jobs`
--
ALTER TABLE `system_scheduled_jobs`
  ADD PRIMARY KEY (`job_key`);

--
-- Indexes for table `system_settings`
--
ALTER TABLE `system_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `key` (`key`);

--
-- Indexes for table `tahini_stock`
--
ALTER TABLE `tahini_stock`
  ADD PRIMARY KEY (`id`),
  ADD KEY `supplier_id_idx` (`supplier_id`);

--
-- Indexes for table `tasks`
--
ALTER TABLE `tasks`
  ADD PRIMARY KEY (`id`),
  ADD KEY `assigned_to` (`assigned_to`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `status` (`status`),
  ADD KEY `priority` (`priority`),
  ADD KEY `due_date` (`due_date`),
  ADD KEY `tasks_ibfk_3` (`product_id`);

--
-- Indexes for table `telegram_invoices`
--
ALTER TABLE `telegram_invoices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `token` (`token`),
  ADD KEY `invoice_id` (`invoice_id`),
  ADD KEY `invoice_number` (`invoice_number`),
  ADD KEY `invoice_type` (`invoice_type`),
  ADD KEY `created_at` (`created_at`);

--
-- Indexes for table `unified_product_templates`
--
ALTER TABLE `unified_product_templates`
  ADD PRIMARY KEY (`id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `status` (`status`),
  ADD KEY `main_supplier_id` (`main_supplier_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD KEY `role` (`role`),
  ADD KEY `idx_id_status` (`id`,`status`);

--
-- Indexes for table `user_permissions`
--
ALTER TABLE `user_permissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_permission` (`user_id`,`permission_id`),
  ADD KEY `permission_id` (`permission_id`),
  ADD KEY `granted_by` (`granted_by`);

--
-- Indexes for table `user_status`
--
ALTER TABLE `user_status`
  ADD PRIMARY KEY (`user_id`);

--
-- Indexes for table `vehicles`
--
ALTER TABLE `vehicles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `vehicle_number` (`vehicle_number`),
  ADD KEY `driver_id` (`driver_id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `vehicle_inventory`
--
ALTER TABLE `vehicle_inventory`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `vehicle_product_batch_unique` (`vehicle_id`,`product_id`,`finished_batch_id`),
  ADD KEY `product_id` (`product_id`),
  ADD KEY `warehouse_id` (`warehouse_id`),
  ADD KEY `vehicle_inventory_ibfk_4` (`last_updated_by`);

--
-- Indexes for table `warehouses`
--
ALTER TABLE `warehouses`
  ADD PRIMARY KEY (`id`),
  ADD KEY `name` (`name`),
  ADD KEY `warehouse_type` (`warehouse_type`),
  ADD KEY `vehicle_id` (`vehicle_id`);

--
-- Indexes for table `warehouse_transfers`
--
ALTER TABLE `warehouse_transfers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `transfer_number` (`transfer_number`),
  ADD KEY `from_warehouse_id` (`from_warehouse_id`),
  ADD KEY `to_warehouse_id` (`to_warehouse_id`),
  ADD KEY `transfer_date` (`transfer_date`),
  ADD KEY `status` (`status`),
  ADD KEY `requested_by` (`requested_by`),
  ADD KEY `approved_by` (`approved_by`);

--
-- Indexes for table `warehouse_transfer_items`
--
ALTER TABLE `warehouse_transfer_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `transfer_id` (`transfer_id`),
  ADD KEY `product_id` (`product_id`),
  ADD KEY `batch_id` (`batch_id`),
  ADD KEY `batch_number` (`batch_number`);

--
-- Indexes for table `webauthn_credentials`
--
ALTER TABLE `webauthn_credentials`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `credential_id` (`credential_id`),
  ADD KEY `user_id` (`user_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `accountant_transactions`
--
ALTER TABLE `accountant_transactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `advance_requests`
--
ALTER TABLE `advance_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `approvals`
--
ALTER TABLE `approvals`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `attendance`
--
ALTER TABLE `attendance`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `attendance_event_notification_logs`
--
ALTER TABLE `attendance_event_notification_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `attendance_monthly_report_logs`
--
ALTER TABLE `attendance_monthly_report_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `attendance_notification_logs`
--
ALTER TABLE `attendance_notification_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `attendance_records`
--
ALTER TABLE `attendance_records`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `backups`
--
ALTER TABLE `backups`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `barcode_scans`
--
ALTER TABLE `barcode_scans`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `batches`
--
ALTER TABLE `batches`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `batch_numbers`
--
ALTER TABLE `batch_numbers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `batch_packaging`
--
ALTER TABLE `batch_packaging`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `batch_raw_materials`
--
ALTER TABLE `batch_raw_materials`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `batch_suppliers`
--
ALTER TABLE `batch_suppliers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `batch_workers`
--
ALTER TABLE `batch_workers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `beeswax_product_templates`
--
ALTER TABLE `beeswax_product_templates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `beeswax_stock`
--
ALTER TABLE `beeswax_stock`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `blocked_ips`
--
ALTER TABLE `blocked_ips`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `cash_register_additions`
--
ALTER TABLE `cash_register_additions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `collections`
--
ALTER TABLE `collections`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `customers`
--
ALTER TABLE `customers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `customer_orders`
--
ALTER TABLE `customer_orders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `customer_order_items`
--
ALTER TABLE `customer_order_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `customer_purchase_history`
--
ALTER TABLE `customer_purchase_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `daily_backup_sent_log`
--
ALTER TABLE `daily_backup_sent_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `damaged_returns`
--
ALTER TABLE `damaged_returns`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `derivatives_product_templates`
--
ALTER TABLE `derivatives_product_templates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `derivatives_stock`
--
ALTER TABLE `derivatives_stock`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `factory_waste_packaging`
--
ALTER TABLE `factory_waste_packaging`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `factory_waste_products`
--
ALTER TABLE `factory_waste_products`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `factory_waste_raw_materials`
--
ALTER TABLE `factory_waste_raw_materials`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `financial_transactions`
--
ALTER TABLE `financial_transactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `finished_products`
--
ALTER TABLE `finished_products`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `group_chat_messages`
--
ALTER TABLE `group_chat_messages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `honey_filtration`
--
ALTER TABLE `honey_filtration`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `honey_stock`
--
ALTER TABLE `honey_stock`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `inventory_movements`
--
ALTER TABLE `inventory_movements`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `invoices`
--
ALTER TABLE `invoices`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `invoice_items`
--
ALTER TABLE `invoice_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `local_collections`
--
ALTER TABLE `local_collections`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `local_customers`
--
ALTER TABLE `local_customers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `local_invoices`
--
ALTER TABLE `local_invoices`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `local_invoice_items`
--
ALTER TABLE `local_invoice_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `local_returns`
--
ALTER TABLE `local_returns`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `local_return_items`
--
ALTER TABLE `local_return_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `login_attempts`
--
ALTER TABLE `login_attempts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `messages`
--
ALTER TABLE `messages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `message_reads`
--
ALTER TABLE `message_reads`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `mixed_nuts`
--
ALTER TABLE `mixed_nuts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `mixed_nuts_ingredients`
--
ALTER TABLE `mixed_nuts_ingredients`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notification_settings`
--
ALTER TABLE `notification_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `nuts_stock`
--
ALTER TABLE `nuts_stock`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `olive_oil_product_templates`
--
ALTER TABLE `olive_oil_product_templates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `olive_oil_stock`
--
ALTER TABLE `olive_oil_stock`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `packaging_damage_logs`
--
ALTER TABLE `packaging_damage_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `packaging_materials`
--
ALTER TABLE `packaging_materials`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `packaging_usage_logs`
--
ALTER TABLE `packaging_usage_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payment_reminders`
--
ALTER TABLE `payment_reminders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payment_schedules`
--
ALTER TABLE `payment_schedules`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `permissions`
--
ALTER TABLE `permissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `production`
--
ALTER TABLE `production`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `production_lines`
--
ALTER TABLE `production_lines`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `production_materials`
--
ALTER TABLE `production_materials`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `production_monthly_report_logs`
--
ALTER TABLE `production_monthly_report_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `production_supply_logs`
--
ALTER TABLE `production_supply_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `product_specifications`
--
ALTER TABLE `product_specifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `product_templates`
--
ALTER TABLE `product_templates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `product_template_materials`
--
ALTER TABLE `product_template_materials`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `product_template_packaging`
--
ALTER TABLE `product_template_packaging`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `product_template_raw_materials`
--
ALTER TABLE `product_template_raw_materials`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pwa_splash_sessions`
--
ALTER TABLE `pwa_splash_sessions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=486;

--
-- AUTO_INCREMENT for table `raw_material_damage_logs`
--
ALTER TABLE `raw_material_damage_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `reader_access_log`
--
ALTER TABLE `reader_access_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `remember_tokens`
--
ALTER TABLE `remember_tokens`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `reports`
--
ALTER TABLE `reports`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `request_usage`
--
ALTER TABLE `request_usage`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `request_usage_alerts`
--
ALTER TABLE `request_usage_alerts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `returns`
--
ALTER TABLE `returns`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `return_items`
--
ALTER TABLE `return_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `role_permissions`
--
ALTER TABLE `role_permissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `salaries`
--
ALTER TABLE `salaries`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `salary_advances`
--
ALTER TABLE `salary_advances`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `salary_settlements`
--
ALTER TABLE `salary_settlements`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sales`
--
ALTER TABLE `sales`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sales_batch_numbers`
--
ALTER TABLE `sales_batch_numbers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sales_returns`
--
ALTER TABLE `sales_returns`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sesame_stock`
--
ALTER TABLE `sesame_stock`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `shipping_companies`
--
ALTER TABLE `shipping_companies`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `shipping_company_orders`
--
ALTER TABLE `shipping_company_orders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `shipping_company_order_items`
--
ALTER TABLE `shipping_company_order_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `suppliers`
--
ALTER TABLE `suppliers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `supplier_balance_history`
--
ALTER TABLE `supplier_balance_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `system_settings`
--
ALTER TABLE `system_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tahini_stock`
--
ALTER TABLE `tahini_stock`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tasks`
--
ALTER TABLE `tasks`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `telegram_invoices`
--
ALTER TABLE `telegram_invoices`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `unified_product_templates`
--
ALTER TABLE `unified_product_templates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `user_permissions`
--
ALTER TABLE `user_permissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `vehicles`
--
ALTER TABLE `vehicles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `vehicle_inventory`
--
ALTER TABLE `vehicle_inventory`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `warehouses`
--
ALTER TABLE `warehouses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `warehouse_transfers`
--
ALTER TABLE `warehouse_transfers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `warehouse_transfer_items`
--
ALTER TABLE `warehouse_transfer_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `webauthn_credentials`
--
ALTER TABLE `webauthn_credentials`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `accountant_transactions`
--
ALTER TABLE `accountant_transactions`
  ADD CONSTRAINT `accountant_transactions_ibfk_1` FOREIGN KEY (`sales_rep_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `accountant_transactions_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `accountant_transactions_ibfk_3` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `advance_requests`
--
ALTER TABLE `advance_requests`
  ADD CONSTRAINT `advance_requests_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `advance_requests_ibfk_2` FOREIGN KEY (`salary_id`) REFERENCES `salaries` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `advance_requests_ibfk_3` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `approvals`
--
ALTER TABLE `approvals`
  ADD CONSTRAINT `approvals_ibfk_1` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `approvals_ibfk_2` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `attendance`
--
ALTER TABLE `attendance`
  ADD CONSTRAINT `attendance_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `attendance_event_notification_logs`
--
ALTER TABLE `attendance_event_notification_logs`
  ADD CONSTRAINT `attendance_event_log_record_fk` FOREIGN KEY (`attendance_record_id`) REFERENCES `attendance_records` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `attendance_event_log_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `attendance_records`
--
ALTER TABLE `attendance_records`
  ADD CONSTRAINT `attendance_records_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD CONSTRAINT `audit_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `backups`
--
ALTER TABLE `backups`
  ADD CONSTRAINT `backups_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `barcode_scans`
--
ALTER TABLE `barcode_scans`
  ADD CONSTRAINT `barcode_scans_ibfk_1` FOREIGN KEY (`batch_number_id`) REFERENCES `batch_numbers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `barcode_scans_ibfk_2` FOREIGN KEY (`scanned_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `batch_numbers`
--
ALTER TABLE `batch_numbers`
  ADD CONSTRAINT `batch_numbers_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `batch_numbers_ibfk_2` FOREIGN KEY (`production_id`) REFERENCES `production` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `batch_numbers_ibfk_3` FOREIGN KEY (`honey_supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `batch_numbers_ibfk_4` FOREIGN KEY (`packaging_supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `batch_numbers_ibfk_5` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `beeswax_stock`
--
ALTER TABLE `beeswax_stock`
  ADD CONSTRAINT `beeswax_stock_ibfk_1` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `blocked_ips`
--
ALTER TABLE `blocked_ips`
  ADD CONSTRAINT `blocked_ips_ibfk_1` FOREIGN KEY (`blocked_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `cash_register_additions`
--
ALTER TABLE `cash_register_additions`
  ADD CONSTRAINT `cash_register_additions_ibfk_1` FOREIGN KEY (`sales_rep_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `cash_register_additions_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `collections`
--
ALTER TABLE `collections`
  ADD CONSTRAINT `collections_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `collections_ibfk_2` FOREIGN KEY (`collected_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `customers`
--
ALTER TABLE `customers`
  ADD CONSTRAINT `fk_customers_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `customer_orders`
--
ALTER TABLE `customer_orders`
  ADD CONSTRAINT `customer_orders_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `customer_orders_ibfk_2` FOREIGN KEY (`sales_rep_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `customer_orders_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `customer_order_items`
--
ALTER TABLE `customer_order_items`
  ADD CONSTRAINT `customer_order_items_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `customer_orders` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `customer_order_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `derivatives_stock`
--
ALTER TABLE `derivatives_stock`
  ADD CONSTRAINT `derivatives_stock_ibfk_1` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `financial_transactions`
--
ALTER TABLE `financial_transactions`
  ADD CONSTRAINT `financial_transactions_ibfk_1` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `financial_transactions_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `financial_transactions_ibfk_3` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `group_chat_messages`
--
ALTER TABLE `group_chat_messages`
  ADD CONSTRAINT `fk_group_chat_parent` FOREIGN KEY (`parent_id`) REFERENCES `group_chat_messages` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_group_chat_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `honey_filtration`
--
ALTER TABLE `honey_filtration`
  ADD CONSTRAINT `honey_filtration_ibfk_1` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `honey_filtration_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `honey_stock`
--
ALTER TABLE `honey_stock`
  ADD CONSTRAINT `honey_stock_ibfk_1` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `inventory_movements`
--
ALTER TABLE `inventory_movements`
  ADD CONSTRAINT `inventory_movements_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `inventory_movements_ibfk_2` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `inventory_movements_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `invoices`
--
ALTER TABLE `invoices`
  ADD CONSTRAINT `invoices_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `invoices_ibfk_2` FOREIGN KEY (`sales_rep_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `invoices_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `invoice_items`
--
ALTER TABLE `invoice_items`
  ADD CONSTRAINT `invoice_items_ibfk_1` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `invoice_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `local_collections`
--
ALTER TABLE `local_collections`
  ADD CONSTRAINT `local_collections_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `local_customers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `local_collections_ibfk_2` FOREIGN KEY (`collected_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `local_collections_ibfk_3` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `local_customers`
--
ALTER TABLE `local_customers`
  ADD CONSTRAINT `local_customers_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `local_invoices`
--
ALTER TABLE `local_invoices`
  ADD CONSTRAINT `local_invoices_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `local_customers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `local_invoices_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `local_returns`
--
ALTER TABLE `local_returns`
  ADD CONSTRAINT `local_returns_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `local_customers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `local_returns_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `local_returns_ibfk_3` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `local_return_items`
--
ALTER TABLE `local_return_items`
  ADD CONSTRAINT `local_return_items_ibfk_1` FOREIGN KEY (`return_id`) REFERENCES `local_returns` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `local_return_items_ibfk_2` FOREIGN KEY (`invoice_id`) REFERENCES `local_invoices` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `local_return_items_ibfk_3` FOREIGN KEY (`invoice_item_id`) REFERENCES `local_invoice_items` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `local_return_items_ibfk_4` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `messages`
--
ALTER TABLE `messages`
  ADD CONSTRAINT `messages_reply_fk` FOREIGN KEY (`reply_to`) REFERENCES `messages` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `messages_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `message_reads`
--
ALTER TABLE `message_reads`
  ADD CONSTRAINT `message_reads_message_fk` FOREIGN KEY (`message_id`) REFERENCES `messages` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `message_reads_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `mixed_nuts`
--
ALTER TABLE `mixed_nuts`
  ADD CONSTRAINT `mixed_nuts_ibfk_1` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `mixed_nuts_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `mixed_nuts_ingredients`
--
ALTER TABLE `mixed_nuts_ingredients`
  ADD CONSTRAINT `mixed_nuts_ingredients_ibfk_1` FOREIGN KEY (`mixed_nuts_id`) REFERENCES `mixed_nuts` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `mixed_nuts_ingredients_ibfk_2` FOREIGN KEY (`nuts_stock_id`) REFERENCES `nuts_stock` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `notification_settings`
--
ALTER TABLE `notification_settings`
  ADD CONSTRAINT `notification_settings_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `nuts_stock`
--
ALTER TABLE `nuts_stock`
  ADD CONSTRAINT `nuts_stock_ibfk_1` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `olive_oil_stock`
--
ALTER TABLE `olive_oil_stock`
  ADD CONSTRAINT `olive_oil_stock_ibfk_1` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `payment_reminders`
--
ALTER TABLE `payment_reminders`
  ADD CONSTRAINT `payment_reminders_ibfk_1` FOREIGN KEY (`payment_schedule_id`) REFERENCES `payment_schedules` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `payment_reminders_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `payment_schedules`
--
ALTER TABLE `payment_schedules`
  ADD CONSTRAINT `payment_schedules_ibfk_1` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `payment_schedules_ibfk_2` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `payment_schedules_ibfk_3` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `payment_schedules_ibfk_4` FOREIGN KEY (`sales_rep_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `payment_schedules_ibfk_5` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `production`
--
ALTER TABLE `production`
  ADD CONSTRAINT `production_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `production_ibfk_2` FOREIGN KEY (`worker_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `production_ibfk_line` FOREIGN KEY (`production_line_id`) REFERENCES `production_lines` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `production_ibfk_order_item` FOREIGN KEY (`customer_order_item_id`) REFERENCES `customer_order_items` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `production_lines`
--
ALTER TABLE `production_lines`
  ADD CONSTRAINT `production_lines_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `production_lines_ibfk_2` FOREIGN KEY (`worker_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `production_lines_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `production_materials`
--
ALTER TABLE `production_materials`
  ADD CONSTRAINT `production_materials_ibfk_1` FOREIGN KEY (`production_id`) REFERENCES `production` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `production_materials_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `production_materials_ibfk_3` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `products`
--
ALTER TABLE `products`
  ADD CONSTRAINT `products_ibfk_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `product_specifications`
--
ALTER TABLE `product_specifications`
  ADD CONSTRAINT `product_specifications_created_fk` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `product_specifications_updated_fk` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `product_templates`
--
ALTER TABLE `product_templates`
  ADD CONSTRAINT `product_templates_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `product_template_materials`
--
ALTER TABLE `product_template_materials`
  ADD CONSTRAINT `product_template_materials_ibfk_1` FOREIGN KEY (`template_id`) REFERENCES `product_templates` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `product_template_packaging`
--
ALTER TABLE `product_template_packaging`
  ADD CONSTRAINT `product_template_packaging_ibfk_1` FOREIGN KEY (`template_id`) REFERENCES `product_templates` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `product_template_packaging_ibfk_2` FOREIGN KEY (`packaging_material_id`) REFERENCES `packaging_materials` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `pwa_splash_sessions`
--
ALTER TABLE `pwa_splash_sessions`
  ADD CONSTRAINT `pwa_splash_sessions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `remember_tokens`
--
ALTER TABLE `remember_tokens`
  ADD CONSTRAINT `remember_tokens_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `reports`
--
ALTER TABLE `reports`
  ADD CONSTRAINT `reports_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `returns`
--
ALTER TABLE `returns`
  ADD CONSTRAINT `returns_ibfk_1` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `returns_ibfk_2` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `returns_ibfk_3` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `returns_ibfk_4` FOREIGN KEY (`sales_rep_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `returns_ibfk_5` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `returns_ibfk_6` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `return_items`
--
ALTER TABLE `return_items`
  ADD CONSTRAINT `return_items_ibfk_1` FOREIGN KEY (`return_id`) REFERENCES `returns` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `return_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `return_items_ibfk_3` FOREIGN KEY (`batch_number_id`) REFERENCES `batch_numbers` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `return_items_ibfk_batch` FOREIGN KEY (`batch_number_id`) REFERENCES `batch_numbers` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `return_items_ibfk_invoice_item` FOREIGN KEY (`invoice_item_id`) REFERENCES `invoice_items` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `role_permissions`
--
ALTER TABLE `role_permissions`
  ADD CONSTRAINT `role_permissions_ibfk_1` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `salaries`
--
ALTER TABLE `salaries`
  ADD CONSTRAINT `salaries_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `salaries_ibfk_2` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `salaries_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `salary_advances`
--
ALTER TABLE `salary_advances`
  ADD CONSTRAINT `salary_advances_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `salary_advances_ibfk_2` FOREIGN KEY (`accountant_approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `salary_advances_ibfk_3` FOREIGN KEY (`manager_approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `salary_advances_ibfk_4` FOREIGN KEY (`deducted_from_salary_id`) REFERENCES `salaries` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `salary_settlements`
--
ALTER TABLE `salary_settlements`
  ADD CONSTRAINT `salary_settlements_ibfk_1` FOREIGN KEY (`salary_id`) REFERENCES `salaries` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `salary_settlements_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `salary_settlements_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `sales`
--
ALTER TABLE `sales`
  ADD CONSTRAINT `sales_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `sales_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `sales_ibfk_3` FOREIGN KEY (`salesperson_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `sales_ibfk_4` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `sales_batch_numbers`
--
ALTER TABLE `sales_batch_numbers`
  ADD CONSTRAINT `sales_batch_numbers_ibfk_1` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `sales_batch_numbers_ibfk_2` FOREIGN KEY (`invoice_item_id`) REFERENCES `invoice_items` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `sales_batch_numbers_ibfk_3` FOREIGN KEY (`batch_number_id`) REFERENCES `batch_numbers` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `sales_returns`
--
ALTER TABLE `sales_returns`
  ADD CONSTRAINT `sales_returns_ibfk_1` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `sales_returns_ibfk_2` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `sales_returns_ibfk_3` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `sales_returns_ibfk_4` FOREIGN KEY (`sales_rep_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `sales_returns_ibfk_5` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `sales_returns_ibfk_6` FOREIGN KEY (`processed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `sales_returns_ibfk_7` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `sesame_stock`
--
ALTER TABLE `sesame_stock`
  ADD CONSTRAINT `sesame_stock_ibfk_1` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `shipping_companies`
--
ALTER TABLE `shipping_companies`
  ADD CONSTRAINT `shipping_companies_created_fk` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `shipping_companies_updated_fk` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `shipping_company_orders`
--
ALTER TABLE `shipping_company_orders`
  ADD CONSTRAINT `shipping_company_orders_company_fk` FOREIGN KEY (`shipping_company_id`) REFERENCES `shipping_companies` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `shipping_company_orders_created_fk` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `shipping_company_orders_customer_fk` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `shipping_company_orders_invoice_fk` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `shipping_company_orders_updated_fk` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `shipping_company_order_items`
--
ALTER TABLE `shipping_company_order_items`
  ADD CONSTRAINT `shipping_company_order_items_order_fk` FOREIGN KEY (`order_id`) REFERENCES `shipping_company_orders` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `shipping_company_order_items_product_fk` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `supplier_balance_history`
--
ALTER TABLE `supplier_balance_history`
  ADD CONSTRAINT `supplier_balance_history_ibfk_1` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `supplier_balance_history_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `tasks`
--
ALTER TABLE `tasks`
  ADD CONSTRAINT `tasks_ibfk_1` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `tasks_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tasks_ibfk_3` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `telegram_invoices`
--
ALTER TABLE `telegram_invoices`
  ADD CONSTRAINT `telegram_invoices_ibfk_1` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `user_permissions`
--
ALTER TABLE `user_permissions`
  ADD CONSTRAINT `user_permissions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `user_permissions_ibfk_2` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `user_permissions_ibfk_3` FOREIGN KEY (`granted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `user_status`
--
ALTER TABLE `user_status`
  ADD CONSTRAINT `user_status_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `vehicles`
--
ALTER TABLE `vehicles`
  ADD CONSTRAINT `vehicles_ibfk_1` FOREIGN KEY (`driver_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `vehicles_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `warehouse_transfers`
--
ALTER TABLE `warehouse_transfers`
  ADD CONSTRAINT `warehouse_transfers_ibfk_1` FOREIGN KEY (`from_warehouse_id`) REFERENCES `warehouses` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `warehouse_transfers_ibfk_2` FOREIGN KEY (`to_warehouse_id`) REFERENCES `warehouses` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `warehouse_transfers_ibfk_3` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `warehouse_transfers_ibfk_4` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `warehouse_transfer_items`
--
ALTER TABLE `warehouse_transfer_items`
  ADD CONSTRAINT `warehouse_transfer_items_ibfk_1` FOREIGN KEY (`transfer_id`) REFERENCES `warehouse_transfers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `warehouse_transfer_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `webauthn_credentials`
--
ALTER TABLE `webauthn_credentials`
  ADD CONSTRAINT `webauthn_credentials_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
