-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Aug 20, 2024 at 08:02 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.1.25

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `edoc`
--

-- --------------------------------------------------------

--
-- Table structure for table `admin`
--

CREATE TABLE `admin` (
  `aemail` varchar(255) NOT NULL,
  `aname` varchar(255) DEFAULT NULL,
  `apassword` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `admin`
--

INSERT INTO `admin` (`aemail`, `aname`, `apassword`) VALUES
('admin@edoc.com', '', '123');

-- --------------------------------------------------------

--
-- Table structure for table `appointment`
--

CREATE TABLE `appointment` (
  `appoid` int(11) NOT NULL,
  `pid` int(10) DEFAULT NULL,
  `apponum` int(3) DEFAULT NULL,
  `scheduleid` int(10) DEFAULT NULL,
  `appodate` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `appointment`
--

INSERT INTO `appointment` (`appoid`, `pid`, `apponum`, `scheduleid`, `appodate`) VALUES
(1, 19, 1, 1, '2024-08-17'),
(2, 19, 1, 2, '2024-08-17'),
(3, 23, 2, 2, '2024-08-18'),
(4, 24, 3, 2, '2024-08-19'),
(5, 42, 4, 2, '2024-08-20');

-- --------------------------------------------------------

--
-- Table structure for table `archive`
--

CREATE TABLE `archive` (
  `id` int(11) NOT NULL,
  `pid` int(11) DEFAULT NULL,
  `pemail` varchar(255) DEFAULT NULL,
  `pname` varchar(255) DEFAULT NULL,
  `pgender` varchar(255) DEFAULT NULL,
  `pcity` varchar(255) DEFAULT NULL,
  `pdob` date DEFAULT NULL,
  `page` int(3) DEFAULT NULL,
  `ptel` varchar(15) DEFAULT NULL,
  `pcivil` varchar(255) DEFAULT NULL,
  `archived_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `bookings`
--

CREATE TABLE `bookings` (
  `id` int(11) NOT NULL,
  `pid` int(11) NOT NULL,
  `scheduleid` int(11) NOT NULL,
  `appodate` date DEFAULT NULL,
  `status` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `conditions`
--

CREATE TABLE `conditions` (
  `condition_id` int(11) NOT NULL,
  `condition_name` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `conditions`
--

INSERT INTO `conditions` (`condition_id`, `condition_name`) VALUES
(1, 'Anxiety'),
(2, 'Depression'),
(3, 'Bipolar Disorder'),
(4, 'Schizophrenia'),
(5, 'Obsessive-Compulsive Disorder (OCD)'),
(6, 'Post-Traumatic Stress Disorder (PTSD)'),
(7, 'Attention-Deficit/Hyperactivity Disorder (ADHD)'),
(8, 'Eating Disorders'),
(9, 'Borderline Personality Disorder'),
(10, 'Autism Spectrum Disorder'),
(11, 'Panic Disorder'),
(12, 'Social Anxiety Disorder'),
(13, 'Generalized Anxiety Disorder (GAD)'),
(14, 'Seasonal Affective Disorder'),
(15, 'Substance Use Disorder');

-- --------------------------------------------------------

--
-- Table structure for table `doctor`
--

CREATE TABLE `doctor` (
  `docid` int(11) NOT NULL,
  `docemail` varchar(255) DEFAULT NULL,
  `docname` varchar(255) DEFAULT NULL,
  `docpassword` varchar(255) DEFAULT NULL,
  `docnic` varchar(15) DEFAULT NULL,
  `doctel` varchar(15) DEFAULT NULL,
  `specialties` int(2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `doctor`
--

INSERT INTO `doctor` (`docid`, `docemail`, `docname`, `docpassword`, `docnic`, `doctel`, `specialties`) VALUES
(2, 'doctor@edoc.com', 'Gabriel', '123', '123', '09572341231', 23),
(3, 'doctor1@edoc.com', 'GAb', '$2y$10$PIz8wGdF307VNP35LKCYoeG0Zd8TZ8NrEG28JRGLaK.xLJHmwFAM6', '123', '09572341231', 23);

-- --------------------------------------------------------

--
-- Table structure for table `it`
--

CREATE TABLE `it` (
  `itEmail` varchar(255) NOT NULL,
  `itPassword` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `it`
--

INSERT INTO `it` (`itEmail`, `itPassword`) VALUES
('it@edoc.com', '123');

-- --------------------------------------------------------

--
-- Table structure for table `login_attempts`
--

CREATE TABLE `login_attempts` (
  `id` int(11) NOT NULL,
  `aemail` varchar(255) NOT NULL,
  `attempt_time` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `login_attempts`
--

INSERT INTO `login_attempts` (`id`, `aemail`, `attempt_time`) VALUES
(1, 'doctor1@edoc.com', '2024-08-17 10:55:31'),
(2, 'patient3@edoc.com', '2024-08-17 11:01:28'),
(3, 'patient555@edoc.com', '2024-08-19 07:29:38'),
(4, 'patien5555@edoc.com', '2024-08-19 07:29:52'),
(5, 'patient3@edoc.com', '2024-08-20 04:38:31'),
(6, 'it@edoc.com', '2024-08-20 05:30:14'),
(7, 'it@edoc.com', '2024-08-20 05:31:33'),
(8, 'it@edoc.com', '2024-08-20 05:31:39'),
(9, 'it@edoc.com', '2024-08-20 05:35:27'),
(10, 'it@edoc.com', '2024-08-20 05:35:30'),
(11, 'it@edoc.com', '2024-08-20 05:36:25'),
(12, 'it@edoc.com', '2024-08-20 05:36:28'),
(13, 'it@edoc.com', '2024-08-20 05:36:30'),
(14, 'it@edoc.com', '2024-08-20 05:38:58'),
(15, 'it@edoc.com', '2024-08-20 05:39:01'),
(16, 'it@edoc.com', '2024-08-20 05:42:42'),
(17, 'it@edoc.com', '2024-08-20 05:42:45'),
(18, 'it@edoc.com', '2024-08-20 05:44:13'),
(19, 'it@edoc.com', '2024-08-20 05:44:16'),
(20, 'it@edoc.com', '2024-08-20 05:44:18');

-- --------------------------------------------------------

--
-- Table structure for table `patient`
--

CREATE TABLE `patient` (
  `pid` int(11) NOT NULL,
  `pemail` varchar(255) DEFAULT NULL,
  `pname` varchar(255) DEFAULT NULL,
  `pgender` varchar(255) DEFAULT NULL,
  `ppassword` varchar(255) DEFAULT NULL,
  `pprovince` varchar(255) DEFAULT NULL,
  `pcity` varchar(255) DEFAULT NULL,
  `pbrgy` varchar(255) DEFAULT NULL,
  `pdob` date DEFAULT NULL,
  `page` int(3) DEFAULT NULL,
  `ptel` varchar(15) DEFAULT NULL,
  `pcivil` varchar(255) DEFAULT NULL,
  `pcase` varchar(255) DEFAULT NULL,
  `pcaseDesc` varchar(255) DEFAULT NULL,
  `archived` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `patient`
--

INSERT INTO `patient` (`pid`, `pemail`, `pname`, `pgender`, `ppassword`, `pprovince`, `pcity`, `pbrgy`, `pdob`, `page`, `ptel`, `pcivil`, `pcase`, `pcaseDesc`, `archived`) VALUES
(43, 'patient41@edoc.com', 'aasd asd', 'Female', '$2y$10$zmnihSrrBKcmOyEmOa0UPOHXDo9EQNawNUbvp8ccNpBlWfjCVlR9G', 'Metro Manila', 'Manila', '0', '2001-02-02', 23, '09123456782', 'Single', NULL, NULL, 0),
(44, 'patient42@edoc.com', 'asd zxc', 'Male', '$2y$10$4Wvu1LeGoZFOW7BIuSMoP.a3xhBxnKzT8Cat5zFzjULfYm8jR71Xm', 'Cavite', 'Tagaytay', 'Calabuso', '2009-02-02', 15, '09123456781', 'Single', NULL, NULL, 0),
(45, 'patient4111@edoc.com', 'asd zxc', 'Male', '$2y$10$LBao5RGhDpJGtkn038C9T.BIgsPBiXUZoAbfq8/oa4tPKQ3nUy9pK', 'Cavite', 'Dasmariñas', '0', '2009-02-02', 15, '09123456781', 'Single', NULL, NULL, 0);

-- --------------------------------------------------------

--
-- Table structure for table `schedule`
--

CREATE TABLE `schedule` (
  `scheduleid` int(11) NOT NULL,
  `docid` varchar(255) DEFAULT NULL,
  `title` varchar(255) DEFAULT NULL,
  `scheduledate` date DEFAULT NULL,
  `scheduletime` time DEFAULT NULL,
  `nop` int(4) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `schedule`
--

INSERT INTO `schedule` (`scheduleid`, `docid`, `title`, `scheduledate`, `scheduletime`, `nop`) VALUES
(1, '2', 'Therapy', '2024-08-18', '08:00:00', 10),
(2, '3', '123', '2024-08-21', '14:22:00', 11);

-- --------------------------------------------------------

--
-- Table structure for table `specialties`
--

CREATE TABLE `specialties` (
  `id` int(2) NOT NULL,
  `sname` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `specialties`
--

INSERT INTO `specialties` (`id`, `sname`) VALUES
(1, 'Accident and emergency medicine'),
(2, 'Allergology'),
(3, 'Anaesthetics'),
(4, 'Cardiology'),
(5, 'Child psychiatry'),
(6, 'Dental, oral and maxillo-facial surgery'),
(7, 'Dermatology'),
(8, 'General surgery'),
(9, 'Infectious diseases'),
(10, 'Internal medicine'),
(11, 'Laboratory medicine'),
(12, 'Neurology'),
(13, 'Neurosurgery'),
(14, 'Obstetrics and gynecology'),
(15, 'Occupational medicine'),
(16, 'Ophthalmology'),
(17, 'Orthopaedics'),
(18, 'Paediatric surgery'),
(19, 'Paediatrics'),
(20, 'Pathology'),
(21, 'Pharmacology'),
(22, 'Physical medicine and rehabilitation'),
(23, 'Psychiatry'),
(24, 'Public health and Preventive Medicine'),
(25, 'Radiology'),
(26, 'Radiotherapy'),
(27, 'Respiratory medicine'),
(28, 'Rheumatology'),
(29, 'Stomatology'),
(30, 'Thoracic surgery');

-- --------------------------------------------------------

--
-- Table structure for table `webuser`
--

CREATE TABLE `webuser` (
  `email` varchar(255) NOT NULL,
  `usertype` char(1) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `webuser`
--

INSERT INTO `webuser` (`email`, `usertype`) VALUES
('admin@edoc.com', 'a'),
('doctor@edoc.com', 'd'),
('doctor1@edoc.com', 'd'),
('it@edoc.com', 'i'),
('patient4@edoc.com', 'p'),
('patient41@edoc.com', 'p'),
('patient4111@edoc.com', 'p'),
('patient42@edoc.com', 'p');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `admin`
--
ALTER TABLE `admin`
  ADD PRIMARY KEY (`aemail`);

--
-- Indexes for table `appointment`
--
ALTER TABLE `appointment`
  ADD PRIMARY KEY (`appoid`);

--
-- Indexes for table `archive`
--
ALTER TABLE `archive`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `bookings`
--
ALTER TABLE `bookings`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `conditions`
--
ALTER TABLE `conditions`
  ADD PRIMARY KEY (`condition_id`);

--
-- Indexes for table `doctor`
--
ALTER TABLE `doctor`
  ADD PRIMARY KEY (`docid`);

--
-- Indexes for table `login_attempts`
--
ALTER TABLE `login_attempts`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `patient`
--
ALTER TABLE `patient`
  ADD PRIMARY KEY (`pid`);

--
-- Indexes for table `schedule`
--
ALTER TABLE `schedule`
  ADD PRIMARY KEY (`scheduleid`);

--
-- Indexes for table `specialties`
--
ALTER TABLE `specialties`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `webuser`
--
ALTER TABLE `webuser`
  ADD PRIMARY KEY (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `appointment`
--
ALTER TABLE `appointment`
  MODIFY `appoid` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `archive`
--
ALTER TABLE `archive`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `bookings`
--
ALTER TABLE `bookings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `conditions`
--
ALTER TABLE `conditions`
  MODIFY `condition_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `doctor`
--
ALTER TABLE `doctor`
  MODIFY `docid` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `login_attempts`
--
ALTER TABLE `login_attempts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT for table `patient`
--
ALTER TABLE `patient`
  MODIFY `pid` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=46;

--
-- AUTO_INCREMENT for table `schedule`
--
ALTER TABLE `schedule`
  MODIFY `scheduleid` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
