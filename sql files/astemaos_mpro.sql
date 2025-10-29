-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Oct 12, 2025 at 04:10 PM
-- Server version: 10.11.14-MariaDB-cll-lve
-- PHP Version: 8.4.11

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `astemaos_mpro`
--

-- --------------------------------------------------------

--
-- Table structure for table `address`
--

CREATE TABLE `address` (
  `id` int(11) NOT NULL,
  `city` varchar(40) NOT NULL,
  `sub_city` varchar(40) NOT NULL,
  `last_update_code` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `admins`
--

CREATE TABLE `admins` (
  `id` int(11) NOT NULL,
  `name` varchar(100) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `password` varchar(50) DEFAULT NULL,
  `more_info` varchar(255) DEFAULT NULL COMMENT 'more information about the admin(workers)'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `backed_notify`
--

CREATE TABLE `backed_notify` (
  `user_id` int(11) NOT NULL,
  `goods_id` int(11) NOT NULL,
  `is_notified` tinyint(4) NOT NULL COMMENT 'it checks whether the user is notified or not when the goods back to stock\r\n'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `brand`
--

CREATE TABLE `brand` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `category`
--

CREATE TABLE `category` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `image_url` varchar(100) NOT NULL,
  `is_available` tinyint(1) NOT NULL DEFAULT 0,
  `last_update_code` int(11) NOT NULL,
  `priority` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `comments`
--

CREATE TABLE `comments` (
  `id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `comment` int(11) NOT NULL,
  `star_value` int(11) NOT NULL,
  `goods_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `credit`
--

CREATE TABLE `credit` (
  `id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `due_date` date NOT NULL,
  `total` int(11) NOT NULL,
  `paid` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `customer`
--

CREATE TABLE `customer` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `phone` varchar(40) NOT NULL,
  `shop_name` varchar(100) NOT NULL,
  `password` varchar(40) NOT NULL,
  `registered_by` int(11) NOT NULL,
  `address_id` int(11) NOT NULL,
  `specific_address` varchar(50) NOT NULL,
  `location` varchar(100) NOT NULL,
  `location_description` text NOT NULL,
  `register_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `user_type` varchar(50) NOT NULL DEFAULT '0',
  `firebase_code` text NOT NULL DEFAULT '0',
  `fast_delivery_value` int(11) NOT NULL DEFAULT -1,
  `use_telegram` int(11) NOT NULL DEFAULT 0,
  `total_credit` int(11) NOT NULL DEFAULT 0,
  `total_unpaid` int(11) NOT NULL DEFAULT 0,
  `latitude` decimal(12,2) DEFAULT NULL,
  `longitude` decimal(12,2) DEFAULT NULL,
  `permitted_credit` int(11) NOT NULL,
  `delivery_time_info` varchar(200) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `customer_address`
--

CREATE TABLE `customer_address` (
  `id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `address_id` int(11) NOT NULL,
  `address_name` varchar(150) DEFAULT NULL,
  `is_main_address` tinyint(1) NOT NULL DEFAULT 0,
  `priority` int(11) NOT NULL DEFAULT 0,
  `last_update_time` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `customer_contact_uploads`
--

CREATE TABLE `customer_contact_uploads` (
  `id` bigint(20) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `contact_id` bigint(20) NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `phone` varchar(50) NOT NULL,
  `normalized_phone` varchar(50) DEFAULT NULL,
  `additional_info` text DEFAULT NULL,
  `device_created_at_ms` bigint(20) NOT NULL,
  `device_created_at` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `customer_sms_uploads`
--

CREATE TABLE `customer_sms_uploads` (
  `id` bigint(20) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `message_id` bigint(20) NOT NULL,
  `sender_name` varchar(255) DEFAULT NULL,
  `sender_phone` varchar(50) NOT NULL,
  `received_at_ms` bigint(20) NOT NULL,
  `received_at` datetime NOT NULL,
  `content` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `customer_activity_uploads`
--

CREATE TABLE `customer_activity_uploads` (
  `id` bigint(20) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `activity_id` bigint(20) NOT NULL,
  `type` varchar(100) NOT NULL,
  `target_id` varchar(255) DEFAULT NULL,
  `metadata` text DEFAULT NULL,
  `started_at_ms` bigint(20) NOT NULL,
  `started_at` datetime NOT NULL,
  `duration_millis` bigint(20) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `deliver_time`
--

CREATE TABLE `deliver_time` (
  `id` int(11) NOT NULL,
  `address_id` int(11) DEFAULT NULL,
  `when_to_deliver` varchar(255) DEFAULT NULL,
  `deliver_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `active_deliver` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `goods`
--

CREATE TABLE `goods` (
  `id` int(11) NOT NULL,
  `category_id` int(11) NOT NULL,
  `brand_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text NOT NULL,
  `priority` int(11) NOT NULL,
  `show_in_home` tinyint(1) NOT NULL,
  `image_url` varchar(100) NOT NULL,
  `last_update_code` int(11) NOT NULL,
  `last_update` timestamp NOT NULL DEFAULT current_timestamp(),
  `star_value` decimal(10,0) NOT NULL,
  `tiktok_url` varchar(100) NOT NULL,
  `commission` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `manual_sell`
--

CREATE TABLE `manual_sell` (
  `id` int(11) NOT NULL,
  `goods_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `price` int(11) NOT NULL,
  `additional_info` varchar(100) NOT NULL DEFAULT 'no',
  `selling_time` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_closed` tinyint(4) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `new_goods`
--

CREATE TABLE `new_goods` (
  `id` int(11) NOT NULL,
  `name` varchar(50) NOT NULL,
  `price` int(11) NOT NULL,
  `supplier_id` int(11) NOT NULL COMMENT 'this used to control expireDate in telegram...supplierId 1 is always on it is used for manual order',
  `shop_id` int(11) NOT NULL COMMENT 'this used to show order shop',
  `profit` int(11) NOT NULL,
  `image_url` varchar(100) NOT NULL,
  `store` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notification`
--

CREATE TABLE `notification` (
  `id` int(11) NOT NULL,
  `title` varchar(200) NOT NULL,
  `body` text NOT NULL,
  `goodsList` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ordered_list`
--

CREATE TABLE `ordered_list` (
  `id` int(11) NOT NULL,
  `orders_id` int(11) DEFAULT NULL,
  `supplier_goods_id` int(11) DEFAULT NULL,
  `goods_id` int(11) DEFAULT NULL,
  `quantity` int(11) DEFAULT NULL,
  `each_price` decimal(12,2) DEFAULT NULL,
  `eligible_for_credit` tinyint(4) DEFAULT 1,
  `status` tinyint(4) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `orders`
--

CREATE TABLE `orders` (
  `id` int(11) NOT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `total_price` decimal(12,2) DEFAULT NULL,
  `profit` int(11) DEFAULT 0,
  `unpaid_cash` int(11) DEFAULT 0,
  `unpaid_credit` int(11) DEFAULT 0,
  `cash_amount` decimal(12,2) DEFAULT 0,
  `credit_amount` decimal(12,2) DEFAULT NULL,
  `order_time` timestamp NOT NULL DEFAULT current_timestamp(),
  `deliver_time` timestamp NULL DEFAULT NULL,
  `deliver_status` tinyint(4) DEFAULT 1 COMMENT 'ordered=1\r\nfast =2\r\npick-up =3\r\nprepared =4\r\nshipped =5\r\ndelivered =6\r\ncancelled =7\r\nchecked =8\r\nmixed =9',
  `comment` varchar(500) NOT NULL DEFAULT 'no'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `paid_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `amount` int(11) NOT NULL,
  `through` varchar(50) NOT NULL,
  `additional_info` varchar(200) NOT NULL,
  `credit_left_after_payment` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `purchase_goods`
--

CREATE TABLE `purchase_goods` (
  `id` int(11) NOT NULL,
  `goods_id` int(11) DEFAULT NULL,
  `admins_id` int(11) DEFAULT NULL COMMENT 'this is the purchaser',
  `quantity` int(11) DEFAULT NULL,
  `each_price` int(11) DEFAULT NULL,
  `privious_price` int(11) DEFAULT NULL COMMENT 'this is the price of store',
  `closed` tinyint(4) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `settings`
--

CREATE TABLE `settings` (
  `setting_key` varchar(50) NOT NULL,
  `value` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `supplier`
--

CREATE TABLE `supplier` (
  `shop_id` int(11) NOT NULL,
  `shop_name` varchar(50) NOT NULL,
  `shop_detail` varchar(150) NOT NULL,
  `shop_type` varchar(50) NOT NULL,
  `address_id` int(11) DEFAULT NULL,
  `phone` varchar(20) NOT NULL,
  `priority` int(11) NOT NULL,
  `password` varchar(20) NOT NULL,
  `image` varchar(100) NOT NULL,
  `isVisible` int(11) NOT NULL,
  `last_update` timestamp NOT NULL DEFAULT current_timestamp() COMMENT 'this is the last date the shop owner update... to show them to retailers',
  `last_update_code` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `supplier_goods`
--

CREATE TABLE `supplier_goods` (
  `id` int(11) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `goods_id` int(11) NOT NULL,
  `price` int(11) NOT NULL,
  `discount_start` int(11) NOT NULL,
  `discount_price` int(11) NOT NULL,
  `min_order` int(11) NOT NULL DEFAULT 1,
  `is_available_for_credit` int(11) NOT NULL DEFAULT 0,
  `is_available` int(11) NOT NULL,
  `last_update_code` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `supplier_order`
--

CREATE TABLE `supplier_order` (
  `id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `total_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `order_time` datetime NOT NULL,
  `comment` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `supplier_order_list`
--

CREATE TABLE `supplier_order_list` (
  `id` int(11) NOT NULL,
  `supplier_order_id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `goods_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 0,
  `price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `terms_conditions`
--

CREATE TABLE `terms_conditions` (
  `terms_id` int(11) NOT NULL,
  `terms_detail` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_activity`
--

CREATE TABLE `user_activity` (
  `id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `user_phone_time` varchar(20) NOT NULL,
  `server_time` timestamp NOT NULL DEFAULT current_timestamp(),
  `activity_type` int(11) NOT NULL,
  `inserted_time` varchar(20) NOT NULL,
  `additional_info` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `address`
--
ALTER TABLE `address`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `admins`
--
ALTER TABLE `admins`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `backed_notify`
--
ALTER TABLE `backed_notify`
  ADD KEY `goods_id` (`goods_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `brand`
--
ALTER TABLE `brand`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `category`
--
ALTER TABLE `category`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `comments`
--
ALTER TABLE `comments`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `credit`
--
ALTER TABLE `credit`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `customer`
--
ALTER TABLE `customer`
  ADD PRIMARY KEY (`id`),
  ADD KEY `registered_by` (`registered_by`),
  ADD KEY `address_id` (`address_id`);

--
-- Indexes for table `customer_address`
--
ALTER TABLE `customer_address`
  ADD PRIMARY KEY (`id`),
  ADD KEY `customer_id` (`customer_id`),
  ADD KEY `address_id` (`address_id`);

--
-- Indexes for table `customer_contact_uploads`
--
ALTER TABLE `customer_contact_uploads`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_customer_contact` (`customer_id`,`contact_id`),
  ADD KEY `customer_contact_uploads_customer_idx` (`customer_id`);

--
-- Indexes for table `customer_sms_uploads`
--
ALTER TABLE `customer_sms_uploads`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_customer_sms` (`customer_id`,`message_id`),
  ADD KEY `customer_sms_uploads_customer_idx` (`customer_id`);

--
-- Indexes for table `customer_activity_uploads`
--
ALTER TABLE `customer_activity_uploads`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_customer_activity` (`customer_id`,`activity_id`),
  ADD KEY `customer_activity_uploads_customer_idx` (`customer_id`);

--
-- Indexes for table `deliver_time`
--
ALTER TABLE `deliver_time`
  ADD PRIMARY KEY (`id`),
  ADD KEY `address_id` (`address_id`);

--
-- Indexes for table `goods`
--
ALTER TABLE `goods`
  ADD PRIMARY KEY (`id`),
  ADD KEY `category_id` (`category_id`),
  ADD KEY `brand_id` (`brand_id`);

--
-- Indexes for table `manual_sell`
--
ALTER TABLE `manual_sell`
  ADD PRIMARY KEY (`id`),
  ADD KEY `goods_id` (`goods_id`);

--
-- Indexes for table `new_goods`
--
ALTER TABLE `new_goods`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `notification`
--
ALTER TABLE `notification`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `ordered_list`
--
ALTER TABLE `ordered_list`
  ADD PRIMARY KEY (`id`),
  ADD KEY `orders_id` (`orders_id`),
  ADD KEY `goods_id` (`supplier_goods_id`);

--
-- Indexes for table `orders`
--
ALTER TABLE `orders`
  ADD PRIMARY KEY (`id`),
  ADD KEY `customer_id` (`customer_id`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `purchase_goods`
--
ALTER TABLE `purchase_goods`
  ADD PRIMARY KEY (`id`),
  ADD KEY `goods_id` (`goods_id`),
  ADD KEY `admins_id` (`admins_id`);

--
-- Indexes for table `settings`
--
ALTER TABLE `settings`
  ADD PRIMARY KEY (`setting_key`);

--
-- Indexes for table `supplier`
--
ALTER TABLE `supplier`
  ADD PRIMARY KEY (`shop_id`),
  ADD KEY `address_id` (`address_id`);

--
-- Indexes for table `supplier_goods`
--
ALTER TABLE `supplier_goods`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `supplier_order`
--
ALTER TABLE `supplier_order`
  ADD PRIMARY KEY (`id`),
  ADD KEY `customer_id` (`customer_id`);

--
-- Indexes for table `supplier_order_list`
--
ALTER TABLE `supplier_order_list`
  ADD PRIMARY KEY (`id`),
  ADD KEY `supplier_order_id` (`supplier_order_id`),
  ADD KEY `goods_id` (`goods_id`),
  ADD KEY `order_id` (`order_id`);

--
-- Indexes for table `terms_conditions`
--
ALTER TABLE `terms_conditions`
  ADD PRIMARY KEY (`terms_id`);

--
-- Indexes for table `user_activity`
--
ALTER TABLE `user_activity`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `address`
--
ALTER TABLE `address`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `admins`
--
ALTER TABLE `admins`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `brand`
--
ALTER TABLE `brand`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `category`
--
ALTER TABLE `category`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `comments`
--
ALTER TABLE `comments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `credit`
--
ALTER TABLE `credit`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `customer`
--
ALTER TABLE `customer`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `customer_address`
--
ALTER TABLE `customer_address`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `customer_contact_uploads`
--
ALTER TABLE `customer_contact_uploads`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `customer_sms_uploads`
--
ALTER TABLE `customer_sms_uploads`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `customer_activity_uploads`
--
ALTER TABLE `customer_activity_uploads`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `deliver_time`
--
ALTER TABLE `deliver_time`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `goods`
--
ALTER TABLE `goods`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `manual_sell`
--
ALTER TABLE `manual_sell`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `new_goods`
--
ALTER TABLE `new_goods`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notification`
--
ALTER TABLE `notification`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ordered_list`
--
ALTER TABLE `ordered_list`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `orders`
--
ALTER TABLE `orders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchase_goods`
--
ALTER TABLE `purchase_goods`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `supplier`
--
ALTER TABLE `supplier`
  MODIFY `shop_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `supplier_goods`
--
ALTER TABLE `supplier_goods`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `supplier_order`
--
ALTER TABLE `supplier_order`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `supplier_order_list`
--
ALTER TABLE `supplier_order_list`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `terms_conditions`
--
ALTER TABLE `terms_conditions`
  MODIFY `terms_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user_activity`
--
ALTER TABLE `user_activity`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `backed_notify`
--
ALTER TABLE `backed_notify`
  ADD CONSTRAINT `backed_notify_ibfk_1` FOREIGN KEY (`goods_id`) REFERENCES `goods` (`id`),
  ADD CONSTRAINT `backed_notify_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `user` (`id`);

--
-- Constraints for table `customer`
--
ALTER TABLE `customer`
  ADD CONSTRAINT `customer_ibfk_2` FOREIGN KEY (`address_id`) REFERENCES `address` (`id`);

--
-- Constraints for table `customer_address`
--
ALTER TABLE `customer_address`
  ADD CONSTRAINT `customer_address_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customer` (`id`),
  ADD CONSTRAINT `customer_address_ibfk_2` FOREIGN KEY (`address_id`) REFERENCES `address` (`id`);

--
-- Constraints for table `customer_contact_uploads`
--
ALTER TABLE `customer_contact_uploads`
  ADD CONSTRAINT `customer_contact_uploads_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customer` (`id`);

--
-- Constraints for table `customer_sms_uploads`
--
ALTER TABLE `customer_sms_uploads`
  ADD CONSTRAINT `customer_sms_uploads_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customer` (`id`);

--
-- Constraints for table `customer_activity_uploads`
--
ALTER TABLE `customer_activity_uploads`
  ADD CONSTRAINT `customer_activity_uploads_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customer` (`id`);

--
-- Constraints for table `deliver_time`
--
ALTER TABLE `deliver_time`
  ADD CONSTRAINT `deliver_time_ibfk_1` FOREIGN KEY (`address_id`) REFERENCES `address` (`id`);

--
-- Constraints for table `supplier`
--
ALTER TABLE `supplier`
  ADD CONSTRAINT `supplier_ibfk_1` FOREIGN KEY (`address_id`) REFERENCES `address` (`id`);

--
-- Constraints for table `goods`
--
ALTER TABLE `goods`
  ADD CONSTRAINT `goods_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `category` (`id`),
  ADD CONSTRAINT `goods_ibfk_2` FOREIGN KEY (`brand_id`) REFERENCES `brand` (`id`);

--
-- Constraints for table `manual_sell`
--
ALTER TABLE `manual_sell`
  ADD CONSTRAINT `manual_sell_ibfk_1` FOREIGN KEY (`goods_id`) REFERENCES `goods` (`id`);

--
-- Constraints for table `ordered_list`
--
ALTER TABLE `ordered_list`
  ADD CONSTRAINT `ordered_list_ibfk_1` FOREIGN KEY (`orders_id`) REFERENCES `orders` (`id`);

--
-- Constraints for table `supplier_order`
--
ALTER TABLE `supplier_order`
  ADD CONSTRAINT `supplier_order_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customer` (`id`);

--
-- Constraints for table `supplier_order_list`
--
ALTER TABLE `supplier_order_list`
  ADD CONSTRAINT `supplier_order_list_ibfk_1` FOREIGN KEY (`supplier_order_id`) REFERENCES `supplier_order` (`id`),
  ADD CONSTRAINT `supplier_order_list_ibfk_2` FOREIGN KEY (`goods_id`) REFERENCES `goods` (`id`);

--
-- Constraints for table `purchase_goods`
--
ALTER TABLE `purchase_goods`
  ADD CONSTRAINT `purchase_goods_ibfk_1` FOREIGN KEY (`goods_id`) REFERENCES `goods` (`id`),
  ADD CONSTRAINT `purchase_goods_ibfk_2` FOREIGN KEY (`admins_id`) REFERENCES `admins` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
