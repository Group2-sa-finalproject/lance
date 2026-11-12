-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Nov 12, 2026 at 09:44 AM
-- Server version: 10.11.16-MariaDB
-- PHP Version: 8.3.36

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `waste_system`
--

-- --------------------------------------------------------

--
-- Table structure for table `collection_approval_history`
--

CREATE TABLE `collection_approval_history` (
  `id` int(11) NOT NULL,
  `schedule_id` int(11) NOT NULL,
  `admin_id` int(11) NOT NULL,
  `approved_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `collection_approval_history`
--

INSERT INTO `collection_approval_history` (`id`, `schedule_id`, `admin_id`, `approved_at`) VALUES
(1, 9, 1, '2026-11-12 07:28:47');

-- --------------------------------------------------------

--
-- Table structure for table `collection_routes`
--

CREATE TABLE `collection_routes` (
  `id` int(11) NOT NULL,
  `route_name` varchar(100) NOT NULL,
  `route_type` enum('regular','special') NOT NULL DEFAULT 'regular',
  `collector_id` int(11) DEFAULT NULL,
  `status` enum('pending','assigned','in_progress','completed') NOT NULL DEFAULT 'pending',
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `collection_routes`
--

INSERT INTO `collection_routes` (`id`, `route_name`, `route_type`, `collector_id`, `status`, `created_by`, `created_at`) VALUES
(8, 'Regular Collection Route - 2026-11-07 04:59', 'regular', 17, 'assigned', 1, '2026-11-07 03:59:56'),
(9, 'Regular Collection Route - 2026-11-07 07:16', 'regular', 5, 'assigned', 1, '2026-11-07 06:16:08'),
(10, 'Regular Collection Route - 2026-11-07 07:16', 'regular', NULL, 'pending', 1, '2026-11-07 06:16:13'),
(11, 'Regular Collection Route - 2026-11-09 04:38', 'regular', NULL, 'pending', 1, '2026-11-09 03:38:11'),
(12, 'Regular Collection Route - 2026-11-09 04:39', 'regular', 17, 'assigned', 1, '2026-11-09 03:39:32');

-- --------------------------------------------------------

--
-- Table structure for table `collection_schedules`
--

CREATE TABLE `collection_schedules` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `collector_id` int(11) DEFAULT NULL,
  `notes` text NOT NULL,
  `collection_date` date NOT NULL,
  `requested_time` time NOT NULL,
  `collection_address` varchar(255) NOT NULL,
  `status` enum('Rejected','Approved','Completed','Pending') NOT NULL DEFAULT 'Pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `collection_schedules`
--

INSERT INTO `collection_schedules` (`id`, `user_id`, `collector_id`, `notes`, `collection_date`, `requested_time`, `collection_address`, `status`, `created_at`) VALUES
(5, 4, NULL, 'basura', '2026-11-28', '19:24:00', 'muntinlupa', 'Completed', '2026-11-02 06:19:15'),
(6, 15, NULL, 'Andami na', '2026-11-18', '13:00:00', 'Kanto lang po', 'Approved', '2026-11-03 02:45:53'),
(7, 15, NULL, 'Andami na naman', '2026-11-30', '17:00:00', 'Kanto lang po', 'Rejected', '2026-11-03 02:46:20'),
(8, 15, NULL, 'andami amo', '2026-11-30', '20:00:00', 'kanto', 'Pending', '2026-11-03 02:59:05'),
(9, 15, NULL, 'hi', '2026-11-13', '07:00:00', 'j', 'Approved', '2026-11-03 03:19:28'),
(10, 15, 5, 'hello dami na nito lude', '2026-11-07', '18:06:00', 'sa bahay ko po', 'Approved', '2026-11-05 14:41:24'),
(11, 19, NULL, '', '2026-12-01', '08:00:00', 'sa bahay ko po', 'Rejected', '2026-11-07 05:53:22'),
(12, 19, 5, '', '2026-12-01', '08:00:00', 'saamin po lods', 'Approved', '2026-11-07 05:56:06');

-- --------------------------------------------------------

--
-- Table structure for table `collector_account`
--

CREATE TABLE `collector_account` (
  `ID` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `username` int(11) NOT NULL,
  `password` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `collector_account`
--

INSERT INTO `collector_account` (`ID`, `name`, `username`, `password`) VALUES
(1, 'lanceallen', 1111, '123');

-- --------------------------------------------------------

--
-- Table structure for table `collector_locations`
--

CREATE TABLE `collector_locations` (
  `id` int(11) NOT NULL,
  `collector_id` int(11) NOT NULL,
  `latitude` decimal(10,8) NOT NULL,
  `longitude` decimal(11,8) NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `collector_locations`
--

INSERT INTO `collector_locations` (`id`, `collector_id`, `latitude`, `longitude`, `updated_at`) VALUES
(1, 5, 15.21866674, 120.58426006, '2026-11-07 06:15:06'),
(4, 17, 15.15219260, 120.59981047, '2026-11-09 03:41:36');

-- --------------------------------------------------------

--
-- Table structure for table `eco_points`
--

CREATE TABLE `eco_points` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `collection_id` int(11) NOT NULL,
  `points` int(11) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `eco_points`
--

INSERT INTO `eco_points` (`id`, `user_id`, `collection_id`, `points`, `description`, `created_at`) VALUES
(1, 19, 1, 5, 'Properly segregated household waste', '2026-11-09 03:35:47');

-- --------------------------------------------------------

--
-- Table structure for table `email_otps`
--

CREATE TABLE `email_otps` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `otp_code` varchar(255) NOT NULL,
  `expires_at` datetime NOT NULL,
  `attempts` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `email_otps`
--

INSERT INTO `email_otps` (`id`, `user_id`, `otp_code`, `expires_at`, `attempts`, `created_at`) VALUES
(2, 20, '$2y$10$MrJUCpLP7fQiF0eAtnn4Q.778SoR7VKl9UEnrD8u6F3/MZiu/9tru', '2026-11-09 03:30:26', 0, '2026-11-09 02:25:26'),
(4, 22, '$2y$10$y23yGtnJGQ5PUfsjArc1HOdeA5/3CdHJUuIO5Jq6DsgalBHnL.zXi', '2026-11-12 08:27:25', 0, '2026-11-12 07:22:25');

-- --------------------------------------------------------

--
-- Table structure for table `feedback`
--

CREATE TABLE `feedback` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `feedback_type` varchar(100) NOT NULL,
  `message` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `feedback`
--

INSERT INTO `feedback` (`id`, `user_id`, `feedback_type`, `message`, `created_at`) VALUES
(1, 4, 'Suggestion', 'eerrrr', '2026-11-04 03:01:37'),
(2, 4, 'Suggestion', '2e2qe22', '2026-11-04 03:02:27'),
(3, 4, 'Suggestion', 'dsffsf', '2026-11-04 03:11:44'),
(4, 4, 'Complaint', 'ydyryry', '2026-11-04 03:13:13'),
(5, 4, 'Complaint', 'oiu9iuio', '2026-11-04 03:13:59'),
(6, 4, 'Satisfaction', 'okay', '2026-11-04 03:15:00');

-- --------------------------------------------------------

--
-- Table structure for table `households`
--

CREATE TABLE `households` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `house_address` varchar(255) NOT NULL,
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `households`
--

INSERT INTO `households` (`id`, `user_id`, `house_address`, `latitude`, `longitude`, `created_at`) VALUES
(1, 4, 'jalibi', 15.22124164, 120.57309493, '2026-11-01 08:04:49'),
(2, 14, 'house 2', 15.22087144, 120.57558983, '2026-11-01 08:40:16'),
(3, 15, 'dau', 15.17986722, 120.58676559, '2026-11-02 03:46:23'),
(4, 16, 'jalibi', 15.22162427, 120.57529449, '2026-11-03 00:40:53'),
(5, 19, 'jalibi', 15.22162427, 120.57529449, '2026-11-09 03:29:59');

-- --------------------------------------------------------

--
-- Table structure for table `resident_payments`
--

CREATE TABLE `resident_payments` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `status` enum('Paid','Unpaid') NOT NULL DEFAULT 'Unpaid',
  `paid_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `route_stops`
--

CREATE TABLE `route_stops` (
  `id` int(11) NOT NULL,
  `route_id` int(11) NOT NULL,
  `household_id` int(11) NOT NULL,
  `stop_order` int(11) NOT NULL,
  `status` enum('pending','collected') NOT NULL DEFAULT 'pending',
  `collected_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `route_stops`
--

INSERT INTO `route_stops` (`id`, `route_id`, `household_id`, `stop_order`, `status`, `collected_at`) VALUES
(20, 8, 1, 1, 'pending', NULL),
(21, 8, 2, 2, 'pending', NULL),
(22, 8, 3, 3, 'pending', NULL),
(23, 8, 4, 4, 'pending', NULL),
(24, 9, 1, 1, 'pending', NULL),
(25, 9, 2, 2, 'pending', NULL),
(26, 9, 3, 3, 'pending', NULL),
(27, 9, 4, 4, 'pending', NULL),
(28, 10, 1, 1, 'pending', NULL),
(29, 10, 2, 2, 'pending', NULL),
(30, 10, 3, 3, 'pending', NULL),
(31, 10, 4, 4, 'pending', NULL),
(32, 11, 1, 1, 'pending', NULL),
(33, 11, 2, 2, 'pending', NULL),
(34, 11, 3, 3, 'pending', NULL),
(35, 11, 4, 4, 'pending', NULL),
(36, 11, 5, 5, 'pending', NULL),
(37, 12, 1, 1, 'pending', NULL),
(38, 12, 2, 2, 'pending', NULL),
(39, 12, 3, 3, 'pending', NULL),
(40, 12, 4, 4, 'pending', NULL),
(41, 12, 5, 5, 'pending', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `subscriptions`
--

CREATE TABLE `subscriptions` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `service_fee` decimal(10,2) NOT NULL DEFAULT 150.00,
  `months` int(11) NOT NULL DEFAULT 1,
  `original_amount` decimal(10,2) NOT NULL DEFAULT 150.00,
  `points_redeemed` int(11) NOT NULL DEFAULT 0,
  `discount_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total_amount` decimal(10,2) NOT NULL DEFAULT 150.00,
  `amount_paid` decimal(10,2) NOT NULL DEFAULT 0.00,
  `balance_due` decimal(10,2) NOT NULL DEFAULT 150.00,
  `subscription_status` varchar(50) NOT NULL DEFAULT 'Pending Approval',
  `payment_status` varchar(50) NOT NULL DEFAULT 'Unpaid',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `subscriptions`
--

INSERT INTO `subscriptions` (`id`, `user_id`, `service_fee`, `months`, `original_amount`, `points_redeemed`, `discount_amount`, `total_amount`, `amount_paid`, `balance_due`, `subscription_status`, `payment_status`, `created_at`) VALUES
(7, 19, 150.00, 1, 150.00, 0, 0.00, 150.00, 150.00, 0.00, 'Active', 'Paid', '2026-11-09 03:30:15');

-- --------------------------------------------------------

--
-- Table structure for table `subscription_approval_history`
--

CREATE TABLE `subscription_approval_history` (
  `id` int(11) NOT NULL,
  `subscription_id` int(11) NOT NULL,
  `admin_id` int(11) NOT NULL,
  `approved_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `subscription_point_redemptions`
--

CREATE TABLE `subscription_point_redemptions` (
  `id` int(11) NOT NULL,
  `subscription_id` int(11) DEFAULT NULL,
  `user_id` int(11) NOT NULL,
  `points_redeemed` int(11) NOT NULL,
  `discount_amount` decimal(10,2) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `fullname` varchar(255) NOT NULL,
  `users` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` varchar(11) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `email_verified` tinyint(1) NOT NULL DEFAULT 0,
  `email_verified_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `fullname`, `users`, `password`, `role`, `email`, `email_verified`, `email_verified_at`) VALUES
(1, 'Micah galang', 'micahg', '$2y$10$/bzjTXhaS3gfBZqIfhZMyOgXhPq6v/s03Jc9VKuWAzztUVmEGm7Re', 'admin', NULL, 0, NULL),
(2, 'james', 'jamest', '$2y$10$0ScarMneqO1XlbiaUGglmunlkEZeJXLI7KuzYBO7l1f1x3Wi9Fhny', 'residents', NULL, 0, NULL),
(3, 'Eryza', 'ery', '$2y$10$X.fXmiVtW/S9sp1Pwgn8cOa6va7EnRCms59MdlVCdxyKvRBkRUxG6', 'residents', NULL, 0, NULL),
(4, 'lance', 'allen', '$2y$10$jiEGRpWsdtAwCV7bPBJ1G.ldlSqobIx64rmQBOT9h.EIc0ilwYoCu', 'residents', NULL, 0, NULL),
(5, 'lancee', 'allenz', '$2y$10$kdk2tdutAuiy5qQpkPZFZuwCetnBNgebSSmOBVn.eR02bNOHUvakG', 'collector', NULL, 0, NULL),
(6, 'Vincent Natividad', 'vince', '$2y$10$pUoJndwNX5n4FLjN1EOa0.uLFYJnCpTPS1QcH/yb9fT/Aq0Dy5Lg.', 'residents', NULL, 0, NULL),
(7, 'Vincent Natividad', 'vincent', '$2y$10$2GAmEsQJ2e/A6NjZmXX1G.LekFkvf7tZpXtsy.qQJECOzimYce9u2', 'collector', NULL, 0, NULL),
(12, 'Vincent Natividad', 'admin', '$2y$10$bUpkMh3ghhoYT1lxeBfwj.k4dXudnFo2euXvYHk7w5J7UCaf9q5z2', 'admin', NULL, 0, NULL),
(13, 'Vincent Natividad', 'resident123', '$2y$10$rm78gk81F9.x0ZuKIPatPev8mQltr/TwNxW7dN9jxesr2IHC1YgNS', 'residents', NULL, 0, NULL),
(14, 'res1', 'res1', '$2y$10$x5NkfogxHIoqrz7eKiIrxew1FJCRG6Cg7Gv86yY0T.VhnsXF/2fIu', 'residents', NULL, 0, NULL),
(15, 'lancee', 'res2', '$2y$10$EaqCE9qoiXScp/12Gn1iaOWNMKX4BSWukJbbhIHLnevBCaaWbIkfy', 'residents', NULL, 0, NULL),
(16, 'resident', 'resident', '$2y$10$Ri0Htxi2tV7PGcgHGtuZF.guHDDZ3w9YEahj9TziEFMFd9VmXDai6', 'residents', NULL, 0, NULL),
(17, 'collector', 'col', '$2y$10$Z6Bsw5I1do3oGf2ryTV8HeY8vu9DUPJeGOulxKZ0JmjqkmenHvEoi', 'collector', NULL, 0, NULL),
(18, 'tisting', 'res3', '$2y$10$Tlq8.MQXCqaFLvyYPyqyUuYU4aOnoC1DwsQiYfh2H6SlP7sbH.gEC', 'residents', NULL, 0, NULL),
(19, 'lance allen', 'resident4', '$2y$10$3QXcMmhhurkc0GxMYOpDd.SnsGxj7wg68Un7JL8YTGk9NhwqdprCa', 'residents', 'lanceallendelacruz38@gmail.com', 1, '2026-11-07 13:52:38'),
(20, 'ryan', 'ryan123', '$2y$10$EaAVqpZU40ZSbRq775gaG.5mEEZYucywibzAMgscYCcmKxciBuuSy', 'residents', 'redhairshank66@gmail.com', 0, NULL),
(21, 'lance allen dela cruz', 'ryandave123', '$2y$10$qzrfJGRuFi9ypdtwb/sG0ukPMYSGPdyN7MraViqlwr.MQfVpOVGDO', 'residents', 'aragondave2@gmail.com', 1, '2026-11-09 10:29:09'),
(22, 'dave', 'dave', '$2y$10$CuVqVTeJJ0wwGctdQadj7OJxJktj5E9pNBYnuHTEHEvZS1Ne4VQ12', 'residents', 'rosialdadave75@gmail.com', 0, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `waste_collections`
--

CREATE TABLE `waste_collections` (
  `id` int(11) NOT NULL,
  `household_id` int(11) NOT NULL,
  `collector_id` int(11) DEFAULT NULL,
  `collection_date` date NOT NULL,
  `segregation_status` enum('Properly Segregated','Not Properly Segregated') DEFAULT NULL,
  `collection_status` enum('Pending','Collected') DEFAULT 'Pending',
  `eco_points_awarded` int(11) DEFAULT 0,
  `collected_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `waste_collections`
--

INSERT INTO `waste_collections` (`id`, `household_id`, `collector_id`, `collection_date`, `segregation_status`, `collection_status`, `eco_points_awarded`, `collected_at`, `created_at`) VALUES
(1, 5, 17, '2026-11-09', 'Properly Segregated', 'Collected', 5, '2026-11-09 11:35:47', '2026-11-09 03:35:47');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `collection_approval_history`
--
ALTER TABLE `collection_approval_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_schedule_id` (`schedule_id`),
  ADD KEY `idx_admin_id` (`admin_id`);

--
-- Indexes for table `collection_routes`
--
ALTER TABLE `collection_routes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `collection_routes_ibfk_1` (`collector_id`);

--
-- Indexes for table `collection_schedules`
--
ALTER TABLE `collection_schedules`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_collection_schedules_collector_id` (`collector_id`);

--
-- Indexes for table `collector_account`
--
ALTER TABLE `collector_account`
  ADD PRIMARY KEY (`ID`);

--
-- Indexes for table `collector_locations`
--
ALTER TABLE `collector_locations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `collector_id` (`collector_id`);

--
-- Indexes for table `eco_points`
--
ALTER TABLE `eco_points`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `collection_id` (`collection_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `email_otps`
--
ALTER TABLE `email_otps`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `feedback`
--
ALTER TABLE `feedback`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `households`
--
ALTER TABLE `households`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_id` (`user_id`);

--
-- Indexes for table `resident_payments`
--
ALTER TABLE `resident_payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `route_stops`
--
ALTER TABLE `route_stops`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `route_id` (`route_id`,`stop_order`),
  ADD UNIQUE KEY `route_id_2` (`route_id`,`household_id`),
  ADD KEY `household_id` (`household_id`);

--
-- Indexes for table `subscriptions`
--
ALTER TABLE `subscriptions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_id` (`user_id`);

--
-- Indexes for table `subscription_approval_history`
--
ALTER TABLE `subscription_approval_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `subscription_id` (`subscription_id`),
  ADD KEY `admin_id` (`admin_id`);

--
-- Indexes for table `subscription_point_redemptions`
--
ALTER TABLE `subscription_point_redemptions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_redemption_user` (`user_id`),
  ADD KEY `idx_redemption_subscription` (`subscription_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `waste_collections`
--
ALTER TABLE `waste_collections`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_household_collection` (`household_id`,`collection_date`),
  ADD KEY `collector_id` (`collector_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `collection_approval_history`
--
ALTER TABLE `collection_approval_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `collection_routes`
--
ALTER TABLE `collection_routes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `collection_schedules`
--
ALTER TABLE `collection_schedules`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `collector_account`
--
ALTER TABLE `collector_account`
  MODIFY `ID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `collector_locations`
--
ALTER TABLE `collector_locations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=35;

--
-- AUTO_INCREMENT for table `eco_points`
--
ALTER TABLE `eco_points`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `email_otps`
--
ALTER TABLE `email_otps`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `feedback`
--
ALTER TABLE `feedback`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `households`
--
ALTER TABLE `households`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `resident_payments`
--
ALTER TABLE `resident_payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `route_stops`
--
ALTER TABLE `route_stops`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=42;

--
-- AUTO_INCREMENT for table `subscriptions`
--
ALTER TABLE `subscriptions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `subscription_approval_history`
--
ALTER TABLE `subscription_approval_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `subscription_point_redemptions`
--
ALTER TABLE `subscription_point_redemptions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=23;

--
-- AUTO_INCREMENT for table `waste_collections`
--
ALTER TABLE `waste_collections`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `collection_approval_history`
--
ALTER TABLE `collection_approval_history`
  ADD CONSTRAINT `fk_approval_schedule` FOREIGN KEY (`schedule_id`) REFERENCES `collection_schedules` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `collection_routes`
--
ALTER TABLE `collection_routes`
  ADD CONSTRAINT `collection_routes_ibfk_1` FOREIGN KEY (`collector_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `collection_routes_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `collection_schedules`
--
ALTER TABLE `collection_schedules`
  ADD CONSTRAINT `fk_collection_schedules_collector` FOREIGN KEY (`collector_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `collector_locations`
--
ALTER TABLE `collector_locations`
  ADD CONSTRAINT `collector_locations_ibfk_1` FOREIGN KEY (`collector_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `eco_points`
--
ALTER TABLE `eco_points`
  ADD CONSTRAINT `eco_points_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `eco_points_ibfk_2` FOREIGN KEY (`collection_id`) REFERENCES `waste_collections` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `households`
--
ALTER TABLE `households`
  ADD CONSTRAINT `households_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `resident_payments`
--
ALTER TABLE `resident_payments`
  ADD CONSTRAINT `resident_payments_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `route_stops`
--
ALTER TABLE `route_stops`
  ADD CONSTRAINT `route_stops_ibfk_1` FOREIGN KEY (`route_id`) REFERENCES `collection_routes` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `route_stops_ibfk_2` FOREIGN KEY (`household_id`) REFERENCES `households` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `subscriptions`
--
ALTER TABLE `subscriptions`
  ADD CONSTRAINT `subscriptions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `subscription_point_redemptions`
--
ALTER TABLE `subscription_point_redemptions`
  ADD CONSTRAINT `fk_redemption_subscription` FOREIGN KEY (`subscription_id`) REFERENCES `subscriptions` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_redemption_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `waste_collections`
--
ALTER TABLE `waste_collections`
  ADD CONSTRAINT `waste_collections_ibfk_1` FOREIGN KEY (`household_id`) REFERENCES `households` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `waste_collections_ibfk_2` FOREIGN KEY (`collector_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
