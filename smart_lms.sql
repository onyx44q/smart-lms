-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jul 16, 2026 at 01:21 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `smart_lms`
--

-- --------------------------------------------------------

--
-- Table structure for table `assignments`
--

CREATE TABLE `assignments` (
  `id` int(11) NOT NULL,
  `course_id` int(11) NOT NULL,
  `lecturer_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `max_words` int(11) NOT NULL DEFAULT 1000,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `assignments`
--

INSERT INTO `assignments` (`id`, `course_id`, `lecturer_id`, `title`, `description`, `due_date`, `max_words`, `created_at`) VALUES
(1, 1, 5, 'Work 11', 'their ideas', '2026-04-24', 10000, '2026-04-20 19:42:32');

-- --------------------------------------------------------

--
-- Table structure for table `assignment_submissions`
--

CREATE TABLE `assignment_submissions` (
  `id` int(11) NOT NULL,
  `assignment_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `submission_text` longtext NOT NULL,
  `file_path` varchar(500) DEFAULT NULL,
  `word_count` int(11) NOT NULL DEFAULT 0,
  `submitted_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `assignment_submissions`
--

INSERT INTO `assignment_submissions` (`id`, `assignment_id`, `student_id`, `submission_text`, `file_path`, `word_count`, `submitted_at`) VALUES
(1, 1, 1, 'my work is well done up to standards with the required information', 'uploads/assignments/1776796097_s1_a1.docx', 12, '2026-04-21 18:28:17');

-- --------------------------------------------------------

--
-- Table structure for table `attendance_records`
--

CREATE TABLE `attendance_records` (
  `id` int(11) NOT NULL,
  `session_id` int(11) NOT NULL COMMENT 'FK: attendance_sessions.id',
  `student_id` int(11) NOT NULL COMMENT 'FK: users.id (role=student)',
  `status` enum('present','absent') NOT NULL DEFAULT 'absent',
  `marked_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Present/absent record for each student per session.';

--
-- Dumping data for table `attendance_records`
--

INSERT INTO `attendance_records` (`id`, `session_id`, `student_id`, `status`, `marked_at`) VALUES
(1, 1, 1, 'present', '2026-05-09 20:46:04'),
(4, 2, 1, 'present', '2026-05-09 20:57:36'),
(7, 3, 1, 'absent', '2026-05-10 08:43:42');

-- --------------------------------------------------------

--
-- Table structure for table `attendance_sessions`
--

CREATE TABLE `attendance_sessions` (
  `id` int(11) NOT NULL,
  `unit_id` int(11) NOT NULL COMMENT 'FK: course_units.id',
  `lecturer_id` int(11) NOT NULL COMMENT 'FK: users.id (role=lecturer)',
  `session_date` date NOT NULL,
  `title` varchar(255) NOT NULL DEFAULT 'Lecture',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Each row is one lecture/session slot per unit per date.';

--
-- Dumping data for table `attendance_sessions`
--

INSERT INTO `attendance_sessions` (`id`, `unit_id`, `lecturer_id`, `session_date`, `title`, `created_at`) VALUES
(1, 1, 5, '2026-05-09', 'Lecture', '2026-05-09 20:45:18'),
(2, 2, 9, '2026-05-09', 'Lecture', '2026-05-09 20:57:17'),
(3, 2, 9, '2026-05-10', 'Lecture', '2026-05-10 08:25:43');

-- --------------------------------------------------------

--
-- Table structure for table `boarding_allocations`
--

CREATE TABLE `boarding_allocations` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `dorm_id` int(11) NOT NULL,
  `room_id` int(11) DEFAULT NULL,
  `bed_number` varchar(10) DEFAULT NULL,
  `academic_year` varchar(20) NOT NULL,
  `semester` varchar(30) NOT NULL DEFAULT 'Semester 1',
  `check_in_date` date DEFAULT NULL,
  `check_out_date` date DEFAULT NULL,
  `status` enum('active','vacated','transferred','pending') NOT NULL DEFAULT 'pending',
  `allocated_by` int(11) NOT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `boarding_dorms`
--

CREATE TABLE `boarding_dorms` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `gender` enum('male','female') NOT NULL,
  `capacity` int(11) NOT NULL DEFAULT 20,
  `floor_count` int(11) DEFAULT 1,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `boarding_dorms`
--

INSERT INTO `boarding_dorms` (`id`, `name`, `gender`, `capacity`, `floor_count`, `description`, `created_at`) VALUES
(1, 'Elgon', 'female', 40, 1, NULL, '2026-07-16 11:20:19'),
(2, 'Classic', 'female', 40, 1, NULL, '2026-07-16 11:20:19'),
(3, 'VIP', 'female', 30, 1, NULL, '2026-07-16 11:20:19'),
(4, 'Bakhita', 'female', 40, 1, NULL, '2026-07-16 11:20:19'),
(5, 'Stardorm', 'female', 40, 1, NULL, '2026-07-16 11:20:19'),
(6, 'Rhunda', 'female', 40, 1, NULL, '2026-07-16 11:20:19'),
(7, 'Lavington', 'female', 40, 1, NULL, '2026-07-16 11:20:19'),
(8, 'Ikolomani', 'female', 40, 1, NULL, '2026-07-16 11:20:19'),
(9, 'Highrise', 'female', 40, 1, NULL, '2026-07-16 11:20:19'),
(10, 'Chairlady', 'female', 40, 1, NULL, '2026-07-16 11:20:19'),
(11, 'Tanzania', 'female', 40, 1, NULL, '2026-07-16 11:20:19'),
(12, 'Ayomi', 'female', 40, 1, NULL, '2026-07-16 11:20:19'),
(13, 'Kaveve Kazoze', 'female', 40, 1, NULL, '2026-07-16 11:20:19'),
(14, 'Caren Muslims', 'female', 40, 1, NULL, '2026-07-16 11:20:19'),
(15, 'Amazon', 'female', 40, 1, NULL, '2026-07-16 11:20:19'),
(16, 'Lancaster 1', 'female', 40, 1, NULL, '2026-07-16 11:20:19'),
(17, 'Lancaster 2', 'female', 40, 1, NULL, '2026-07-16 11:20:19'),
(18, 'Lancaster 3', 'female', 40, 1, NULL, '2026-07-16 11:20:19'),
(19, 'Lancaster 4', 'female', 40, 1, NULL, '2026-07-16 11:20:19'),
(20, 'Lancaster 5', 'female', 40, 1, NULL, '2026-07-16 11:20:19'),
(21, 'Pentagon', 'male', 40, 1, NULL, '2026-07-16 11:20:19'),
(22, 'Babylon', 'male', 40, 1, NULL, '2026-07-16 11:20:19'),
(23, 'Kingstore', 'male', 40, 1, NULL, '2026-07-16 11:20:19'),
(24, 'Muslims (Easleigh)', 'male', 40, 1, NULL, '2026-07-16 11:20:19'),
(25, 'Westgate A', 'male', 40, 1, NULL, '2026-07-16 11:20:19'),
(26, 'Westgate B', 'male', 40, 1, NULL, '2026-07-16 11:20:19'),
(27, 'Statehouse (Boys)', 'male', 40, 1, NULL, '2026-07-16 11:20:19'),
(28, 'White House', 'male', 40, 1, NULL, '2026-07-16 11:20:19'),
(29, 'Muslim 2', 'male', 40, 1, NULL, '2026-07-16 11:20:19'),
(30, 'Admin Dorm', 'male', 30, 1, NULL, '2026-07-16 11:20:19'),
(31, 'Chiefs Dorm', 'male', 30, 1, NULL, '2026-07-16 11:20:19');

-- --------------------------------------------------------

--
-- Table structure for table `boarding_notices`
--

CREATE TABLE `boarding_notices` (
  `id` int(11) NOT NULL,
  `title` varchar(200) NOT NULL,
  `message` text NOT NULL,
  `target` enum('all','male','female','specific_dorm') DEFAULT 'all',
  `dorm_id` int(11) DEFAULT NULL,
  `priority` enum('normal','urgent','info') DEFAULT 'normal',
  `posted_by` int(11) NOT NULL,
  `is_active` tinyint(4) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `boarding_rooms`
--

CREATE TABLE `boarding_rooms` (
  `id` int(11) NOT NULL,
  `dorm_id` int(11) NOT NULL,
  `room_number` varchar(20) NOT NULL,
  `capacity` int(11) NOT NULL DEFAULT 4,
  `floor` int(11) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `courses`
--

CREATE TABLE `courses` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `lecturer_id` int(11) DEFAULT NULL,
  `skill_category` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `courses`
--

INSERT INTO `courses` (`id`, `title`, `description`, `lecturer_id`, `skill_category`) VALUES
(1, 'Data Science', NULL, 5, NULL),
(3, 'Software Engineering', NULL, 9, NULL),
(4, 'Computer science', NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `course_units`
--

CREATE TABLE `course_units` (
  `id` int(11) NOT NULL,
  `course_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `unit_code` varchar(50) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `lecturer_id` int(11) DEFAULT NULL COMMENT 'Lecturer who teaches this unit',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `course_units`
--

INSERT INTO `course_units` (`id`, `course_id`, `title`, `unit_code`, `description`, `lecturer_id`, `created_at`) VALUES
(1, 1, 'Artificial Intelligence', 'DSC-101', 'Foundations of AI, machine learning and intelligent agents', 5, '2026-04-28 18:39:22'),
(2, 3, 'Introduction', 'SF-101', 'This is introduction', 9, '2026-04-30 18:05:03');

-- --------------------------------------------------------

--
-- Table structure for table `enrollments`
--

CREATE TABLE `enrollments` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `course_id` int(11) NOT NULL,
  `enroll_date` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `enrollments`
--

INSERT INTO `enrollments` (`id`, `student_id`, `course_id`, `enroll_date`) VALUES
(3, 1, 1, '2026-04-12 13:20:43'),
(8, 1, 3, '2026-05-01 13:58:05');

-- --------------------------------------------------------

--
-- Table structure for table `fee_payments`
--

CREATE TABLE `fee_payments` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `fee_assignment_id` int(11) DEFAULT NULL,
  `amount_paid` decimal(12,2) NOT NULL,
  `payment_method` enum('cash','bank_transfer','mpesa','cheque','online','scholarship') NOT NULL DEFAULT 'cash',
  `transaction_ref` varchar(100) DEFAULT NULL,
  `receipt_number` varchar(50) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `payment_date` date NOT NULL,
  `recorded_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `fee_payments`
--

INSERT INTO `fee_payments` (`id`, `student_id`, `fee_assignment_id`, `amount_paid`, `payment_method`, `transaction_ref`, `receipt_number`, `notes`, `payment_date`, `recorded_by`, `created_at`) VALUES
(1, 1, NULL, 45000.00, 'cheque', 'QK-7', 'RCP-2026-00001', 'Well received', '2026-05-18', 12, '2026-05-18 09:33:08'),
(2, 1, NULL, 53000.00, 'cash', '', 'RCP-2026-00002', 'Well received', '2026-05-18', 12, '2026-05-18 09:43:10');

-- --------------------------------------------------------

--
-- Table structure for table `fee_reminders`
--

CREATE TABLE `fee_reminders` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `message` text NOT NULL,
  `sent_by` int(11) NOT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `fee_reminders`
--

INSERT INTO `fee_reminders` (`id`, `student_id`, `message`, `sent_by`, `is_read`, `created_at`) VALUES
(1, 1, 'Dear Onyx Courtney,\r\n\r\nYour current outstanding fee balance is KES 45,000. Please settle this amount by visiting the Finance Office or using the student portal.\r\n\r\nThank you.\r\nFinance Department', 12, 1, '2026-05-18 09:31:46'),
(2, 1, 'Dear Onyx Courtney,\r\n\r\nYour current outstanding fee balance is KES 3,500. Please settle this amount by visiting the Finance Office or using the student portal.\r\n\r\nThank you.\r\nFinance Department', 12, 1, '2026-05-18 09:37:18');

-- --------------------------------------------------------

--
-- Table structure for table `fee_structures`
--

CREATE TABLE `fee_structures` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `fee_category` enum('tuition','examination','library','accommodation','transport','medical','activity','other') NOT NULL DEFAULT 'tuition',
  `academic_year` varchar(20) NOT NULL,
  `semester` enum('Semester 1','Semester 2','Full Year','One Time') NOT NULL DEFAULT 'Semester 1',
  `course_id` int(11) DEFAULT NULL,
  `is_mandatory` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `fee_structures`
--

INSERT INTO `fee_structures` (`id`, `name`, `description`, `amount`, `fee_category`, `academic_year`, `semester`, `course_id`, `is_mandatory`, `created_by`, `created_at`) VALUES
(1, 'Semester 1 Tuition Fee 2025/26', 'Main tuition fee for Semester 1', 45000.00, 'tuition', '2025/2026', 'Semester 1', NULL, 1, NULL, '2026-05-17 18:34:20'),
(2, 'Semester 2 Tuition Fee 2025/26', 'Main tuition fee for Semester 2', 45000.00, 'tuition', '2025/2026', 'Semester 2', NULL, 1, NULL, '2026-05-17 18:34:20'),
(3, 'Examination Fee 2025/26', 'End of year examination registration', 3500.00, 'examination', '2025/2026', 'Full Year', NULL, 1, NULL, '2026-05-17 18:34:20'),
(4, 'Library Access Fee 2025/26', 'Annual library membership and resources', 1500.00, 'library', '2025/2026', 'Full Year', NULL, 1, NULL, '2026-05-17 18:34:20'),
(5, 'Student Activity Fee 2025/26', 'Student union and extracurricular', 1000.00, 'activity', '2025/2026', 'Full Year', NULL, 0, NULL, '2026-05-17 18:34:20');

-- --------------------------------------------------------

--
-- Table structure for table `hr_announcements`
--

CREATE TABLE `hr_announcements` (
  `id` int(11) NOT NULL,
  `title` varchar(200) NOT NULL,
  `message` text NOT NULL,
  `target_department` varchar(100) DEFAULT NULL,
  `priority` enum('normal','urgent','info') DEFAULT 'normal',
  `posted_by` int(11) NOT NULL,
  `is_active` tinyint(4) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `hr_leave_requests`
--

CREATE TABLE `hr_leave_requests` (
  `id` int(11) NOT NULL,
  `staff_id` int(11) NOT NULL,
  `leave_type` enum('annual','sick','maternity','paternity','emergency','unpaid','other') DEFAULT 'annual',
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `days_requested` int(11) NOT NULL DEFAULT 1,
  `reason` text DEFAULT NULL,
  `status` enum('pending','approved','rejected','cancelled') DEFAULT 'pending',
  `reviewed_by` int(11) DEFAULT NULL,
  `review_notes` text DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `hr_payroll`
--

CREATE TABLE `hr_payroll` (
  `id` int(11) NOT NULL,
  `staff_id` int(11) NOT NULL,
  `pay_period` varchar(30) NOT NULL,
  `basic_salary` decimal(12,2) DEFAULT 0.00,
  `allowances` decimal(12,2) DEFAULT 0.00,
  `overtime` decimal(12,2) DEFAULT 0.00,
  `gross_pay` decimal(12,2) DEFAULT 0.00,
  `paye` decimal(12,2) DEFAULT 0.00,
  `nhif` decimal(12,2) DEFAULT 0.00,
  `nssf` decimal(12,2) DEFAULT 0.00,
  `other_deductions` decimal(12,2) DEFAULT 0.00,
  `net_pay` decimal(12,2) DEFAULT 0.00,
  `payment_method` enum('bank_transfer','cash','mpesa','cheque') DEFAULT 'bank_transfer',
  `status` enum('draft','processed','paid') DEFAULT 'draft',
  `processed_by` int(11) DEFAULT NULL,
  `processed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `hr_performance`
--

CREATE TABLE `hr_performance` (
  `id` int(11) NOT NULL,
  `staff_id` int(11) NOT NULL,
  `review_period` varchar(30) NOT NULL,
  `attendance_score` decimal(4,1) DEFAULT 0.0,
  `performance_score` decimal(4,1) DEFAULT 0.0,
  `teamwork_score` decimal(4,1) DEFAULT 0.0,
  `initiative_score` decimal(4,1) DEFAULT 0.0,
  `overall_score` decimal(4,1) DEFAULT 0.0,
  `comments` text DEFAULT NULL,
  `reviewed_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `hr_staff`
--

CREATE TABLE `hr_staff` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `staff_no` varchar(30) DEFAULT NULL,
  `full_name` varchar(150) NOT NULL,
  `email` varchar(150) DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `gender` enum('male','female','other') DEFAULT NULL,
  `date_of_birth` date DEFAULT NULL,
  `national_id` varchar(30) DEFAULT NULL,
  `department` varchar(100) DEFAULT NULL,
  `job_title` varchar(150) DEFAULT NULL,
  `job_type` enum('full_time','part_time','contract','intern') DEFAULT 'full_time',
  `employment_date` date DEFAULT NULL,
  `termination_date` date DEFAULT NULL,
  `status` enum('active','on_leave','terminated','suspended') DEFAULT 'active',
  `basic_salary` decimal(12,2) DEFAULT 0.00,
  `allowances` decimal(12,2) DEFAULT 0.00,
  `deductions` decimal(12,2) DEFAULT 0.00,
  `bank_name` varchar(100) DEFAULT NULL,
  `bank_account` varchar(50) DEFAULT NULL,
  `emergency_contact` varchar(150) DEFAULT NULL,
  `emergency_phone` varchar(30) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `profile_notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `materials`
--

CREATE TABLE `materials` (
  `id` int(11) NOT NULL,
  `course_id` int(11) DEFAULT NULL,
  `unit_id` int(11) DEFAULT NULL,
  `lecturer_id` int(11) DEFAULT NULL,
  `title` varchar(255) DEFAULT NULL,
  `type` enum('pdf','video','word') DEFAULT NULL,
  `file_path` varchar(255) DEFAULT NULL,
  `upload_date` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `materials`
--

INSERT INTO `materials` (`id`, `course_id`, `unit_id`, `lecturer_id`, `title`, `type`, `file_path`, `upload_date`) VALUES
(1, 1, 1, 5, 'Week1', 'pdf', 'uploads/notes/1775987401_Sustainable_AI.pdf', '2026-04-12 09:50:01'),
(2, 1, 1, 5, 'week2', 'video', 'uploads/videos/1775990414_Presentations_mad_2025_11_16_022055.mp4', '2026-04-12 10:40:14'),
(3, 1, 1, 5, 'Week 3 Intelligence Agents', 'pdf', 'uploads/notes/1776178025_Intelligent_Agents.pdf', '2026-04-14 14:47:05'),
(6, 3, 2, 9, 'Requirements engineering', 'pdf', 'uploads/notes/1777612537_Lecture_3-_Requirements_Engineering.pdf', '2026-05-01 05:15:37');

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`id`, `user_id`, `message`, `is_read`, `created_at`) VALUES
(1, 1, 'A new quiz \"Artificial intelligence - sustainable ai\" has been published. Log in to take it now.', 0, '2026-04-13 20:03:19'),
(2, 1, 'A new quiz \"Artificial intelligence - sustainable ai\" has been published. Log in to take it now.', 0, '2026-04-13 20:07:52'),
(3, 1, 'A new quiz \"Artificial intelligence - sustainable ai\" has been published. Log in to take it now.', 0, '2026-04-13 20:11:10'),
(4, 1, 'A new quiz \"Artificial intelligence - sustainable ai\" has been published. Log in to take it now.', 0, '2026-04-13 20:16:42'),
(5, 1, 'A new quiz \"Artificial intelligence - sustainable ai\" has been published. Log in to take it now.', 0, '2026-04-13 20:17:22'),
(6, 1, 'A new quiz \"Artificial intelligence - sustainable ai\" has been published. Log in to take it now.', 0, '2026-04-13 20:22:20'),
(7, 1, 'A new quiz \"Artificial intelligence - sustainable ai\" has been published. Log in to take it now.', 0, '2026-04-13 20:22:27'),
(8, 1, 'A new quiz \"Artificial intelligence - sustainable ai\" has been published. Log in to take it now.', 0, '2026-04-13 20:22:31'),
(9, 1, 'A new quiz \"Artificial intelligence - sustainable ai\" has been published. Log in to take it now.', 0, '2026-04-13 20:26:02'),
(10, 1, 'A new quiz \"sustainable ai Assessment\" has been published. Log in to take it now.', 0, '2026-04-13 20:27:06'),
(11, 1, 'A new quiz \"sustainable ai Assessment\" has been published. Log in to take it now.', 0, '2026-04-13 20:27:18'),
(12, 1, 'Excellent performance on this assessment. You have unlocked the next level of content. Priority gap detected: Practical Application is 80 points below your career target. Predicted grade: D.', 0, '2026-04-13 20:28:18'),
(13, 1, 'Excellent performance on this assessment. You have unlocked the next level of content. Priority gap detected: Practical Application is 80 points below your career target. Predicted grade: D.', 0, '2026-04-13 20:30:22'),
(14, 1, 'A new quiz \"Artificial intelligence - sustainable ai\" has been published. Log in to take it now.', 0, '2026-04-14 13:55:13'),
(15, 1, 'A new quiz \"Artificial intelligence - sustainable ai\" has been published. Log in to take it now.', 0, '2026-04-14 14:35:17'),
(16, 1, 'A new quiz \"Artificial intelligence - sustainable ai\" has been published. Log in to take it now.', 0, '2026-04-14 14:35:20'),
(17, 1, 'A new quiz \"Artificial intelligence - sustainable ai\" has been published. Log in to take it now.', 0, '2026-04-14 14:35:22'),
(18, 1, 'Foundational gaps detected. You have been directed to revision materials to strengthen core concepts. Priority gap detected: Practical Application is 80 points below your career target. Predicted grade: F.', 0, '2026-04-14 14:41:10'),
(19, 1, 'Your submission for \"Work 11\" has been analysed. Plagiarism verdict: LOW RISK (overall score: 0%).', 0, '2026-04-20 19:45:09'),
(20, 1, 'Your submission for \"Work 11\" has been analysed. Plagiarism verdict: LOW RISK (overall score: 0%).', 0, '2026-04-21 18:28:17'),
(21, 1, 'A new quiz \"Artificial intelligence - sustainable ai (Theory)\" has been published. Log in to take it now.', 0, '2026-04-21 18:42:00'),
(22, 1, 'A new quiz \"Artificial intelligence - Intelligence Agents (Theory)\" has been published. Log in to take it now.', 0, '2026-04-21 18:42:28'),
(23, 1, 'Excellent performance on this assessment. You have unlocked the next level of content. Priority gap detected: Practical Application is 80 points below your career target. Predicted grade: F.', 0, '2026-04-21 18:44:59'),
(24, 1, 'Excellent performance on this assessment. You have unlocked the next level of content. Priority gap detected: Practical Application is 80 points below your career target. Predicted grade: F.', 0, '2026-04-21 18:48:22'),
(25, 1, 'Excellent performance on this assessment. You have unlocked the next level of content. Priority gap detected: Practical Application is 80 points below your career target. Predicted grade: D.', 0, '2026-04-21 18:50:08'),
(26, 1, 'A new quiz \"Artificial intelligence - Intelligence Agents (Practical Application)\" has been published. Log in to take it now.', 0, '2026-04-23 17:31:27'),
(27, 1, 'Foundational gaps detected. You have been directed to revision materials to strengthen core concepts. Priority gap detected: Practical Application is 80 points below your career target. Predicted grade: F.', 0, '2026-04-23 17:36:06'),
(28, 1, 'A new quiz \"Artificial intelligence - sustainable ai (Core Theory)\" has been published. Log in to take it now.', 0, '2026-04-23 17:41:34'),
(29, 1, 'Excellent performance on this assessment. You have unlocked the next level of content. Priority gap detected: Practical Application is 80 points below your career target. Predicted grade: D.', 0, '2026-04-23 17:43:14'),
(30, 1, 'A new quiz \"Introduction — Requirements engineering (Practical Application)\" has been published. Log in to take it now.', 0, '2026-05-01 10:32:48'),
(31, 1, 'A new quiz \"Introduction — Requirements engineering (Practical Application)\" has been published. Log in to take it now.', 0, '2026-05-01 10:32:50'),
(32, 1, 'A new quiz \"Introduction — Requirements engineering (Practical Application)\" has been published. Log in to take it now.', 0, '2026-05-01 10:33:01'),
(33, 1, 'A new quiz \"Introduction — Requirements engineering (Practical Application)\" has been published. Log in to take it now.', 0, '2026-05-01 10:33:03'),
(34, 1, 'A new quiz \"Introduction — Requirements engineering (General Aptitude)\" has been published. Log in to take it now.', 0, '2026-05-01 10:33:06'),
(35, 1, 'A new quiz \"Introduction — Requirements engineering (General Aptitude)\" has been published. Log in to take it now.', 0, '2026-05-01 10:33:09'),
(36, 1, 'Excellent performance on this assessment. You have unlocked the next level of content. Priority gap detected: Practical Application is 80 points below your career target. Predicted grade: D.', 0, '2026-05-01 11:00:25'),
(37, 1, 'Good effort. Reinforce your understanding before moving forward. Attempt 1 of 3. Priority gap detected: Practical Application is 80 points below your career target. Predicted grade: F.', 0, '2026-05-01 11:04:32'),
(38, 1, 'A new quiz \"Introduction — Requirements engineering (Core Theory)\" has been published. Log in to take it now.', 0, '2026-05-01 11:06:25'),
(39, 1, 'Excellent performance on this assessment. You have unlocked the next level of content. Priority gap detected: Practical Application is 76.8 points below your career target. Predicted grade: F.', 0, '2026-05-01 11:08:42');

-- --------------------------------------------------------

--
-- Table structure for table `plagiarism_reports`
--

CREATE TABLE `plagiarism_reports` (
  `id` int(11) NOT NULL,
  `submission_id` int(11) NOT NULL,
  `student_similarity_score` decimal(5,2) NOT NULL DEFAULT 0.00,
  `internet_similarity_score` decimal(5,2) NOT NULL DEFAULT 0.00,
  `overall_score` decimal(5,2) NOT NULL DEFAULT 0.00,
  `verdict` varchar(20) NOT NULL DEFAULT 'LOW RISK',
  `matched_students` longtext DEFAULT NULL COMMENT 'JSON array of peer matches',
  `flags` longtext DEFAULT NULL COMMENT 'JSON array of heuristic flags',
  `analysed_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `plagiarism_reports`
--

INSERT INTO `plagiarism_reports` (`id`, `submission_id`, `student_similarity_score`, `internet_similarity_score`, `overall_score`, `verdict`, `matched_students`, `flags`, `analysed_at`) VALUES
(2, 1, 0.00, 0.00, 0.00, 'LOW RISK', '[]', '[\"Submission too short for heuristic internet analysis (< 30 words).\"]', '2026-04-21 18:28:17');

-- --------------------------------------------------------

--
-- Table structure for table `questions`
--

CREATE TABLE `questions` (
  `id` int(11) NOT NULL,
  `quiz_id` int(11) DEFAULT NULL,
  `question_text` text NOT NULL,
  `option_a` text DEFAULT NULL,
  `option_b` text DEFAULT NULL,
  `option_c` text DEFAULT NULL,
  `option_d` text DEFAULT NULL,
  `correct_option` char(1) DEFAULT NULL,
  `explanation` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `questions`
--

INSERT INTO `questions` (`id`, `quiz_id`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`) VALUES
(1, 4, 'General principle of Sustainable ai (Concept 1)', 'Correct primary definition', 'Incorrect distractor 1', 'Incorrect distractor 2', 'Incorrect distractor 3', 'A', 'Automatic explanation for sustainable ai.'),
(2, 4, 'General principle of Sustainable ai (Concept 4)', 'Correct primary definition', 'Incorrect distractor 1', 'Incorrect distractor 2', 'Incorrect distractor 3', 'A', 'Automatic explanation for sustainable ai.'),
(3, 4, 'General principle of Sustainable ai (Concept 5)', 'Correct primary definition', 'Incorrect distractor 1', 'Incorrect distractor 2', 'Incorrect distractor 3', 'A', 'Automatic explanation for sustainable ai.'),
(4, 4, 'General principle of Sustainable ai (Concept 3)', 'Correct primary definition', 'Incorrect distractor 1', 'Incorrect distractor 2', 'Incorrect distractor 3', 'A', 'Automatic explanation for sustainable ai.'),
(5, 4, 'General principle of Sustainable ai (Concept 2)', 'Correct primary definition', 'Incorrect distractor 1', 'Incorrect distractor 2', 'Incorrect distractor 3', 'A', 'Automatic explanation for sustainable ai.'),
(89, 29, 'What is the primary goal of Sustainable AI?', 'To increase the speed of AI development', 'To minimize the environmental impact of AI systems', 'To maximize AI\'s economic benefits', 'To reduce AI\'s energy consumption', 'B', 'Sustainable AI aims to minimize the environmental impact of AI systems, including energy consumption, e-waste, and data storage.'),
(90, 29, 'Which of the following is an example of a sustainable AI practice?', 'Using AI to monitor and control industrial processes', 'Implementing AI-powered predictive maintenance', 'Using AI to optimize energy consumption in data centers', 'All of the above', 'D', 'All of the above options are examples of sustainable AI practices, as they aim to reduce energy consumption and minimize waste.'),
(91, 29, 'What is the term for the environmental impact of AI systems?', 'AI footprint', 'Digital carbon footprint', 'Sustainable AI impact', 'AI emissions', 'B', 'Digital carbon footprint refers to the environmental impact of AI systems, including energy consumption, e-waste, and data storage.'),
(92, 29, 'Which of the following is a benefit of Sustainable AI?', 'Increased energy consumption', 'Reduced e-waste', 'Improved AI performance', 'None of the above', 'B', 'Reduced e-waste is a benefit of Sustainable AI, as it aims to minimize waste and promote environmentally friendly practices.'),
(93, 29, 'What is the term for the process of designing AI systems that are environmentally friendly and sustainable?', 'Sustainable AI design', 'Green AI development', 'Environmental AI engineering', 'AI sustainability engineering', 'D', 'AI sustainability engineering refers to the process of designing AI systems that are environmentally friendly and sustainable.'),
(264, 61, 'What is the primary goal of Sustainable AI?', 'To increase computational power and reduce energy consumption', 'To minimize the environmental impact of AI systems', 'To improve AI model accuracy without considering energy efficiency', 'To reduce the cost of AI development', 'B', 'Sustainable AI aims to minimize the environmental impact of AI systems, including energy consumption and e-waste generation.'),
(265, 61, 'Which of the following is an example of Sustainable AI?', 'Training a large language model on a single GPU', 'Using a cloud-based AI platform with minimal energy efficiency', 'Implementing a federated learning approach to reduce data transfer', 'Deploying a deep learning model on a single server', 'C', 'Federated learning reduces the need for data transfer, thereby minimizing energy consumption and carbon footprint.'),
(266, 61, 'What is the term for the environmental impact of AI systems?', 'Carbon footprint', 'Digital pollution', 'E-waste generation', 'All of the above', 'D', 'The environmental impact of AI systems includes carbon footprint, digital pollution, and e-waste generation.'),
(300, 67, 'A software development company is building an e-commerce platform for a client. During a meeting with the client, the requirements engineer extracts the following non-functional requirement: \'The system should be able to handle at least 100 concurrent user sessions without a significant decrease in performance.\' Which of the following activities should the requirements engineer perform next?', 'Create a detailed design for the system architecture', 'Negotiate with the client to identify the possible risks and trade-offs associated with this requirement', 'Assume the requirement is feasible and move on to the next one', 'Assign a priority to this requirement without considering its feasibility', 'B', 'The requirements engineer should negotiate with the client to identify the possible risks and trade-offs associated with this requirement. This is because the requirement is non-functional and may have significant implications on the system design and development. The requirements engineer needs to understand the client\'s expectations and priorities to ensure that the system meets the necessary performance requirements.'),
(301, 67, 'A requirements engineer is working on a project to develop a mobile app for a popular restaurant chain. The restaurant wants the app to have a \'favorite dishes\' feature, where users can save their favorite dishes for easy access. However, the requirements engineer notices that the restaurant\'s database does not currently store user information, including favorite dishes. What should the requirements engineer do?', 'Modify the existing database schema to accommodate user information', 'Design a new database schema to store user information, including favorite dishes', 'Ignore the favorite dishes feature and focus on other requirements', 'Ask the restaurant to provide additional information about the user information storage requirements', 'A', 'The requirements engineer should modify the existing database schema to accommodate user information, including favorite dishes. This is because the favorite dishes feature requires the storage of user information, which is already a part of the system. Modifying the existing schema is a more efficient and cost-effective solution than designing a new schema or ignoring the feature.'),
(302, 67, 'A requirements engineer is conducting a requirements elicitation session with a group of stakeholders for a project to develop a smart home automation system. During the session, one stakeholder mentions that the system should be able to integrate with multiple smart home devices from different manufacturers. However, another stakeholder expresses concerns about the security risks associated with integrating multiple devices. What should the requirements engineer do?', 'Document the stakeholder\'s concerns and move on to the next requirement', 'Elicit more information from the stakeholders about the security risks and potential solutions', 'Assume that the system will be able to integrate with multiple devices without any security risks', 'Provide a solution to the security risks without further discussion with the stakeholders', 'B', 'The requirements engineer should elicit more information from the stakeholders about the security risks and potential solutions. This is because the requirements engineer needs to understand the stakeholders\' concerns and priorities to ensure that the system meets the necessary security requirements. Providing a solution without further discussion may not address the stakeholders\' concerns and may lead to misunderstandings.'),
(303, 67, 'A requirements engineer is working on a project to develop a project management tool for a construction company. The company wants the tool to have a feature that allows users to create and manage Gantt charts. However, the requirements engineer notices that the tool\'s current architecture is based on a relational database management system (RDBMS). What should the requirements engineer do?', 'Design a new architecture for the tool using a graph database management system', 'Modify the existing architecture to accommodate the Gantt chart feature', 'Ignore the Gantt chart feature and focus on other requirements', 'Ask the company to provide additional information about the Gantt chart feature requirements', 'B', 'The requirements engineer should modify the existing architecture to accommodate the Gantt chart feature. This is because the Gantt chart feature requires the storage and management of complex relationships between tasks, which can be efficiently handled by an RDBMS. Modifying the existing architecture is a more efficient and cost-effective solution than designing a new architecture or ignoring the feature.'),
(304, 67, 'A requirements engineer is conducting a requirements validation session with a group of stakeholders for a project to develop a healthcare information system. During the session, one stakeholder mentions that the system should be able to track patient medication histories. However, another stakeholder expresses concerns about the system\'s ability to handle patient confidentiality and data privacy. What should the requirements engineer do?', 'Document the stakeholder\'s concerns and move on to the next requirement', 'Elicit more information from the stakeholders about patient confidentiality and data privacy requirements', 'Assume that the system will be able to handle patient confidentiality and data privacy', 'Provide a solution to patient confidentiality and data privacy without further discussion with the stakeholders', 'B', 'The requirements engineer should elicit more information from the stakeholders about patient confidentiality and data privacy requirements. This is because the requirements engineer needs to understand the stakeholders\' concerns and priorities to ensure that the system meets the necessary confidentiality and data privacy requirements. Providing a solution without further discussion may not address the stakeholders\' concerns and may lead to misunderstandings.'),
(305, 68, 'A project team is responsible for developing a software system that requires real-time data processing. The stakeholders have agreed that the system must respond within 5 seconds to any user input. What type of requirement is this?', 'Functional requirement', 'Non-functional requirement', 'Quality attribute', 'Performance characteristic', 'B', 'This requirement is a non-functional requirement because it specifies a constraint on the system\'s performance rather than a specific function it should perform.'),
(306, 68, 'A software system\'s requirements are gathered and documented using use cases, user stories, and business process models. Which of the following is NOT a key aspect of requirements engineering in this scenario?', 'Analyzing the requirements for inconsistencies', 'Creating a requirements management plan', 'Developing a comprehensive test plan', 'Translating the requirements into code', 'D', 'Requirements engineering involves gathering, documenting, and analyzing requirements, but not translating them directly into code. That is a task for software development.'),
(307, 68, 'A project team is tasked with developing a software system that must meet the following non-functional requirement: \'The system must be accessible to users with visual impairments.\' What is the primary goal of this requirement?', 'To ensure the system is user-friendly', 'To improve the system\'s performance', 'To increase the system\'s security', 'To make the system accessible to users with disabilities', 'D', 'This requirement aims to make the system accessible to users with visual impairments, which is a key aspect of digital accessibility.'),
(308, 68, 'A software system\'s requirements are subject to change due to evolving business needs. What is the best practice for managing such changes?', 'Revising the requirements document', 'Ignoring the changes and proceeding with the original plan', 'Creating a new requirements document', 'Using a change management process', 'D', 'Using a change management process helps to track and manage changes to the requirements, ensuring that the changes are properly assessed and implemented.'),
(309, 68, 'A project team is working on a software system that must meet the following functional requirement: \'The system must allow users to create and manage their own accounts.\' What is the primary characteristic of this requirement?', 'It is a non-functional requirement', 'It is a quality attribute', 'It is a functional requirement', 'It is a performance characteristic', 'C', 'This requirement is a functional requirement because it specifies a specific function that the system must perform.'),
(315, 70, 'What is the primary goal of requirements elicitation in the requirements engineering process?', 'To identify and document functional requirements only', 'To capture the needs and desires of stakeholders in a comprehensive and unambiguous manner', 'To prioritize and evaluate existing requirements against project constraints', 'To validate and verify requirements against existing software solutions', 'B', 'Requirements elicitation is the process of gathering and documenting stakeholder needs and desires. Its primary goal is to capture these needs in a comprehensive and unambiguous manner, ensuring that all requirements are properly understood and documented.'),
(316, 70, 'What is the key difference between a functional requirement and a non-functional requirement?', 'Functional requirements describe what the system should do, while non-functional requirements describe how the system should behave', 'Functional requirements describe how the system should behave, while non-functional requirements describe what the system should do', 'Functional requirements are specific to user interactions, while non-functional requirements are general system characteristics', 'Functional requirements are general system characteristics, while non-functional requirements are specific to user interactions', 'A', 'Functional requirements describe what the system should do, while non-functional requirements describe how the system should behave, its performance, or other aspects not directly related to its functionality'),
(317, 70, 'What is the purpose of a requirements baseline in the requirements engineering process?', 'To establish a reference point for future requirements changes or updates', 'To document and track changes to existing requirements', 'To prioritize and allocate requirements to project tasks', 'To validate and verify requirements against project constraints', 'A', 'A requirements baseline is a reference point for future requirements changes or updates, providing a documented history of requirements evolution and ensuring consistency across the project lifecycle'),
(318, 70, 'Which of the following is an example of a non-functional requirement?', 'The system should display a list of available products for purchase', 'The system should respond to user input within 2 seconds', 'The system should authenticate user credentials', 'The system should provide a user manual', 'B', 'Non-functional requirements describe aspects of the system\'s behavior, such as performance, security, or usability. In this case, the system responding to user input within 2 seconds is a non-functional requirement'),
(319, 70, 'What is the term for the process of reviewing and evaluating requirements to identify inconsistencies, ambiguities, or omissions?', 'Requirements validation', 'Requirements verification', 'Requirements validation and verification', 'Requirements review and analysis', 'D', 'Requirements review and analysis involves examining requirements to identify inconsistencies, ambiguities, or omissions, ensuring that they are complete, consistent, and unambiguous');

-- --------------------------------------------------------

--
-- Table structure for table `quizzes`
--

CREATE TABLE `quizzes` (
  `id` int(11) NOT NULL,
  `course_id` int(11) DEFAULT NULL,
  `unit_id` int(11) DEFAULT NULL,
  `material_id` int(11) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `difficulty` enum('beginner','intermediate','advanced') DEFAULT 'beginner',
  `is_active` tinyint(1) DEFAULT 0,
  `skill_name` varchar(100) DEFAULT NULL,
  `topic` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `quizzes`
--

INSERT INTO `quizzes` (`id`, `course_id`, `unit_id`, `material_id`, `title`, `difficulty`, `is_active`, `skill_name`, `topic`, `created_by`, `created_at`) VALUES
(4, 1, 1, NULL, 'sustainable ai Assessment', 'beginner', 1, 'General Aptitude', NULL, 5, '2026-04-13 20:26:20'),
(29, 1, 1, NULL, 'Artificial intelligence - sustainable ai', 'beginner', 1, 'Practical Application', 'sustainable ai', 5, '2026-04-14 14:34:31'),
(61, 1, 1, NULL, 'Artificial intelligence - sustainable ai (Core Theory)', 'beginner', 1, 'Core Theory', 'sustainable ai', 5, '2026-04-23 17:41:25'),
(67, 3, 2, 6, 'Introduction — Requirements engineering (Practical Application)', 'intermediate', 1, 'Practical Application', 'Requirements engineering', 9, '2026-05-01 05:16:57'),
(68, 3, 2, 6, 'Introduction — Requirements engineering (General Aptitude)', 'intermediate', 1, 'General Aptitude', 'Requirements engineering', 9, '2026-05-01 05:18:00'),
(70, 3, 2, 6, 'Introduction — Requirements engineering (Core Theory)', 'advanced', 1, 'Core Theory', 'Requirements engineering', 9, '2026-05-01 11:06:09');

-- --------------------------------------------------------

--
-- Table structure for table `results`
--

CREATE TABLE `results` (
  `id` int(11) NOT NULL,
  `student_id` int(11) DEFAULT NULL,
  `quiz_id` int(11) DEFAULT NULL,
  `score` decimal(5,2) DEFAULT NULL,
  `recommendation` text DEFAULT NULL,
  `action_taken` varchar(20) DEFAULT NULL,
  `performance_band` varchar(20) DEFAULT NULL,
  `predicted_grade` char(1) DEFAULT NULL,
  `difficulty_next` varchar(20) DEFAULT NULL,
  `attempt_no` int(11) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `results`
--

INSERT INTO `results` (`id`, `student_id`, `quiz_id`, `score`, `recommendation`, `action_taken`, `performance_band`, `predicted_grade`, `difficulty_next`, `attempt_no`, `created_at`) VALUES
(1, 1, 4, 100.00, 'Excellent performance on this assessment. You have unlocked the next level of content. Priority gap detected: Practical Application is 80 points below your career target.', 'advance', 'critical', 'D', 'advanced', 1, '2026-04-13 20:28:17'),
(2, 1, 4, 100.00, 'Excellent performance on this assessment. You have unlocked the next level of content. Priority gap detected: Practical Application is 80 points below your career target.', 'advance', 'critical', 'D', 'advanced', 2, '2026-04-13 20:30:22'),
(3, 1, 29, 40.00, 'Foundational gaps detected. You have been directed to revision materials to strengthen core concepts. Priority gap detected: Practical Application is 80 points below your career target.', 'remedial', 'critical', 'F', 'beginner', 1, '2026-04-14 14:41:10'),
(4, 1, 57, 80.00, 'Excellent performance on this assessment. You have unlocked the next level of content. Priority gap detected: Practical Application is 80 points below your career target.', 'advance', 'critical', 'F', 'advanced', 1, '2026-04-21 18:44:58'),
(5, 1, 57, 80.00, 'Excellent performance on this assessment. You have unlocked the next level of content. Priority gap detected: Practical Application is 80 points below your career target.', 'advance', 'critical', 'F', 'advanced', 2, '2026-04-21 18:48:22'),
(6, 1, 56, 100.00, 'Excellent performance on this assessment. You have unlocked the next level of content. Priority gap detected: Practical Application is 80 points below your career target.', 'advance', 'critical', 'D', 'advanced', 1, '2026-04-21 18:50:08'),
(7, 1, 58, 25.00, 'Foundational gaps detected. You have been directed to revision materials to strengthen core concepts. Priority gap detected: Practical Application is 80 points below your career target.', 'remedial', 'critical', 'F', 'beginner', 1, '2026-04-23 17:36:05'),
(8, 1, 61, 100.00, 'Excellent performance on this assessment. You have unlocked the next level of content. Priority gap detected: Practical Application is 80 points below your career target.', 'advance', 'critical', 'D', 'advanced', 1, '2026-04-23 17:43:13'),
(9, 1, 68, 100.00, 'Excellent performance on this assessment. You have unlocked the next level of content. Priority gap detected: Practical Application is 80 points below your career target.', 'advance', 'critical', 'D', 'advanced', 1, '2026-05-01 11:00:25'),
(10, 1, 67, 60.00, 'Good effort. Reinforce your understanding before moving forward. Attempt 1 of 3. Priority gap detected: Practical Application is 80 points below your career target.', 'retry', 'critical', 'F', 'intermediate', 1, '2026-05-01 11:04:31'),
(11, 1, 70, 80.00, 'Excellent performance on this assessment. You have unlocked the next level of content. Priority gap detected: Practical Application is 76.8 points below your career target.', 'advance', 'critical', 'F', 'advanced', 1, '2026-05-01 11:08:42');

-- --------------------------------------------------------

--
-- Table structure for table `schedules`
--

CREATE TABLE `schedules` (
  `id` int(11) NOT NULL,
  `lecturer_id` int(11) NOT NULL,
  `course_id` int(11) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `meet_date` date NOT NULL,
  `meet_time` time NOT NULL,
  `meet_link` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `zoom_meeting_id` varchar(100) DEFAULT NULL COMMENT 'Zoom meeting numeric ID — used to cancel the meeting via API',
  `zoom_start_url` text DEFAULT NULL COMMENT 'Zoom host/start URL — shown only to the lecturer'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `schedules`
--

INSERT INTO `schedules` (`id`, `lecturer_id`, `course_id`, `title`, `description`, `meet_date`, `meet_time`, `meet_link`, `created_at`, `zoom_meeting_id`, `zoom_start_url`) VALUES
(2, 5, 1, 'Meeting 1', 'Ai essentials', '2026-04-19', '09:00:00', 'https://strathmore.zoom.us/j/94698562548?pwd=PEMBwblj0m4DQCRrcW6Pa6ZpPqAtU5.1', '2026-04-19 05:48:30', '', '');

-- --------------------------------------------------------

--
-- Table structure for table `skills`
--

CREATE TABLE `skills` (
  `skill_id` int(11) NOT NULL,
  `skill_name` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `skills`
--

INSERT INTO `skills` (`skill_id`, `skill_name`) VALUES
(1, 'General Aptitude'),
(2, 'Practical Application');

-- --------------------------------------------------------

--
-- Table structure for table `student_answers`
--

CREATE TABLE `student_answers` (
  `id` int(11) NOT NULL,
  `result_id` int(11) DEFAULT NULL,
  `student_id` int(11) DEFAULT NULL,
  `question_id` int(11) DEFAULT NULL,
  `chosen` char(1) DEFAULT NULL,
  `is_correct` tinyint(1) DEFAULT 0,
  `answered_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `student_answers`
--

INSERT INTO `student_answers` (`id`, `result_id`, `student_id`, `question_id`, `chosen`, `is_correct`, `answered_at`) VALUES
(1, 1, 1, 1, 'A', 1, '2026-04-13 20:28:17'),
(2, 1, 1, 2, 'A', 1, '2026-04-13 20:28:17'),
(3, 1, 1, 3, 'A', 1, '2026-04-13 20:28:17'),
(4, 1, 1, 4, 'A', 1, '2026-04-13 20:28:18'),
(5, 1, 1, 5, 'A', 1, '2026-04-13 20:28:18'),
(6, 2, 1, 1, 'A', 1, '2026-04-13 20:30:22'),
(7, 2, 1, 2, 'A', 1, '2026-04-13 20:30:22'),
(8, 2, 1, 3, 'A', 1, '2026-04-13 20:30:22'),
(9, 2, 1, 4, 'A', 1, '2026-04-13 20:30:22'),
(10, 2, 1, 5, 'A', 1, '2026-04-13 20:30:22'),
(11, 3, 1, 89, 'C', 0, '2026-04-14 14:41:10'),
(12, 3, 1, 90, 'D', 1, '2026-04-14 14:41:10'),
(13, 3, 1, 91, 'C', 0, '2026-04-14 14:41:10'),
(14, 3, 1, 92, 'B', 1, '2026-04-14 14:41:10'),
(15, 3, 1, 93, '', 0, '2026-04-14 14:41:10'),
(16, 4, 1, 241, 'B', 1, '2026-04-21 18:44:58'),
(17, 4, 1, 242, 'C', 1, '2026-04-21 18:44:58'),
(18, 4, 1, 243, 'B', 1, '2026-04-21 18:44:58'),
(19, 4, 1, 244, 'C', 0, '2026-04-21 18:44:58'),
(20, 4, 1, 245, 'D', 1, '2026-04-21 18:44:59'),
(21, 5, 1, 241, 'B', 1, '2026-04-21 18:48:22'),
(22, 5, 1, 242, 'C', 1, '2026-04-21 18:48:22'),
(23, 5, 1, 243, 'B', 1, '2026-04-21 18:48:22'),
(24, 5, 1, 244, 'C', 0, '2026-04-21 18:48:22'),
(25, 5, 1, 245, 'D', 1, '2026-04-21 18:48:22'),
(26, 6, 1, 236, 'B', 1, '2026-04-21 18:50:08'),
(27, 6, 1, 237, 'B', 1, '2026-04-21 18:50:08'),
(28, 6, 1, 238, 'B', 1, '2026-04-21 18:50:08'),
(29, 6, 1, 239, 'B', 1, '2026-04-21 18:50:08'),
(30, 6, 1, 240, 'B', 1, '2026-04-21 18:50:08'),
(31, 7, 1, 246, 'C', 0, '2026-04-23 17:36:05'),
(32, 7, 1, 247, 'C', 1, '2026-04-23 17:36:05'),
(33, 7, 1, 248, 'B', 0, '2026-04-23 17:36:06'),
(34, 7, 1, 249, 'C', 0, '2026-04-23 17:36:06'),
(35, 7, 1, 250, 'D', 0, '2026-04-23 17:36:06'),
(36, 7, 1, 251, 'B', 0, '2026-04-23 17:36:06'),
(37, 7, 1, 252, 'C', 1, '2026-04-23 17:36:06'),
(38, 7, 1, 253, 'A', 0, '2026-04-23 17:36:06'),
(39, 8, 1, 264, 'B', 1, '2026-04-23 17:43:13'),
(40, 8, 1, 265, 'C', 1, '2026-04-23 17:43:13'),
(41, 8, 1, 266, 'D', 1, '2026-04-23 17:43:14'),
(42, 9, 1, 305, 'B', 1, '2026-05-01 11:00:25'),
(43, 9, 1, 306, 'D', 1, '2026-05-01 11:00:25'),
(44, 9, 1, 307, 'D', 1, '2026-05-01 11:00:25'),
(45, 9, 1, 308, 'D', 1, '2026-05-01 11:00:25'),
(46, 9, 1, 309, 'C', 1, '2026-05-01 11:00:25'),
(47, 10, 1, 300, 'B', 1, '2026-05-01 11:04:32'),
(48, 10, 1, 301, 'D', 0, '2026-05-01 11:04:32'),
(49, 10, 1, 302, 'B', 1, '2026-05-01 11:04:32'),
(50, 10, 1, 303, 'D', 0, '2026-05-01 11:04:32'),
(51, 10, 1, 304, 'B', 1, '2026-05-01 11:04:32'),
(52, 11, 1, 315, 'B', 1, '2026-05-01 11:08:42'),
(53, 11, 1, 316, 'A', 1, '2026-05-01 11:08:42'),
(54, 11, 1, 317, 'A', 1, '2026-05-01 11:08:42'),
(55, 11, 1, 318, 'B', 1, '2026-05-01 11:08:42'),
(56, 11, 1, 319, 'C', 0, '2026-05-01 11:08:42');

-- --------------------------------------------------------

--
-- Table structure for table `student_fee_assignments`
--

CREATE TABLE `student_fee_assignments` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `fee_structure_id` int(11) NOT NULL,
  `total_amount` decimal(12,2) NOT NULL,
  `discount_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `discount_reason` varchar(255) DEFAULT NULL,
  `net_amount` decimal(12,2) NOT NULL,
  `academic_year` varchar(20) NOT NULL,
  `semester` varchar(50) NOT NULL,
  `due_date` date DEFAULT NULL,
  `status` enum('pending','partial','paid','overdue','waived') NOT NULL DEFAULT 'pending',
  `assigned_by` int(11) DEFAULT NULL,
  `assigned_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `student_fee_assignments`
--

INSERT INTO `student_fee_assignments` (`id`, `student_id`, `fee_structure_id`, `total_amount`, `discount_amount`, `discount_reason`, `net_amount`, `academic_year`, `semester`, `due_date`, `status`, `assigned_by`, `assigned_at`) VALUES
(1, 1, 1, 45000.00, 0.00, '', 45000.00, '2025/2026', 'Semester 1', '2026-06-18', 'pending', 12, '2026-05-18 09:31:14'),
(2, 1, 3, 3500.00, 0.00, '', 3500.00, '2025/2026', 'Full Year', '2026-06-18', 'pending', 12, '2026-05-18 09:36:20');

-- --------------------------------------------------------

--
-- Table structure for table `student_marks`
--

CREATE TABLE `student_marks` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `course_id` int(11) NOT NULL,
  `lecturer_id` int(11) NOT NULL,
  `exam_mark` decimal(5,2) DEFAULT NULL COMMENT 'Raw score achieved in exam',
  `exam_max` decimal(5,2) NOT NULL DEFAULT 70.00 COMMENT 'Max marks available for exam',
  `coursework_mark` decimal(5,2) DEFAULT NULL COMMENT 'Raw score achieved in coursework',
  `coursework_max` decimal(5,2) NOT NULL DEFAULT 30.00 COMMENT 'Max marks available for coursework',
  `remarks` varchar(255) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Stores exam and coursework marks per student per course';

--
-- Dumping data for table `student_marks`
--

INSERT INTO `student_marks` (`id`, `student_id`, `course_id`, `lecturer_id`, `exam_mark`, `exam_max`, `coursework_mark`, `coursework_max`, `remarks`, `updated_at`) VALUES
(1, 1, 1, 5, 56.00, 70.00, 22.00, 30.00, 'do well', '2026-04-27 11:06:44');

-- --------------------------------------------------------

--
-- Table structure for table `student_mastery`
--

CREATE TABLE `student_mastery` (
  `id` int(11) NOT NULL,
  `student_id` int(11) DEFAULT NULL,
  `course_id` int(11) DEFAULT NULL,
  `skill_name` varchar(100) DEFAULT NULL,
  `mastery_level` decimal(5,2) DEFAULT 0.00,
  `last_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `student_mastery`
--

INSERT INTO `student_mastery` (`id`, `student_id`, `course_id`, `skill_name`, `mastery_level`, `last_updated`) VALUES
(385, 1, NULL, 'General Aptitude', 25.50, '2026-05-18 09:24:32'),
(386, 1, NULL, 'Practical Application', 3.20, '2026-05-18 09:24:32'),
(387, 1, NULL, 'Core Theory', 17.00, '2026-05-18 09:24:33');

-- --------------------------------------------------------

--
-- Table structure for table `student_performance_logs`
--

CREATE TABLE `student_performance_logs` (
  `log_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `trajectory` varchar(50) DEFAULT NULL,
  `next_action` text DEFAULT NULL,
  `remedial_flag` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `unit_assessments`
--

CREATE TABLE `unit_assessments` (
  `id` int(11) NOT NULL,
  `unit_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL COMMENT 'e.g. CAT 1, Assignment, Final Exam',
  `type` enum('coursework','exam') NOT NULL DEFAULT 'coursework',
  `max_mark` decimal(6,2) NOT NULL DEFAULT 100.00,
  `sort_order` tinyint(3) NOT NULL DEFAULT 0,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `unit_assessments`
--

INSERT INTO `unit_assessments` (`id`, `unit_id`, `name`, `type`, `max_mark`, `sort_order`, `created_by`, `created_at`) VALUES
(1, 1, 'cat 1', 'coursework', 30.00, 1, 5, '2026-04-28 18:54:14'),
(3, 1, 'coursework', 'coursework', 30.00, 2, 5, '2026-04-28 18:56:35'),
(4, 1, 'final', 'exam', 60.00, 3, 5, '2026-04-28 18:56:59'),
(5, 2, 'cat 1', 'coursework', 30.00, 1, 9, '2026-05-01 11:49:12'),
(6, 2, 'cat 2', 'coursework', 30.00, 2, 9, '2026-05-01 11:49:27'),
(7, 2, 'assignment', 'coursework', 20.00, 3, 9, '2026-05-01 11:49:46'),
(8, 2, 'final', 'coursework', 59.50, 4, 9, '2026-05-01 11:50:08');

-- --------------------------------------------------------

--
-- Table structure for table `unit_marks`
--

CREATE TABLE `unit_marks` (
  `id` int(11) NOT NULL,
  `assessment_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `mark` decimal(6,2) DEFAULT NULL,
  `remarks` varchar(255) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `unit_marks`
--

INSERT INTO `unit_marks` (`id`, `assessment_id`, `student_id`, `mark`, `remarks`, `updated_at`) VALUES
(1, 1, 1, 28.00, NULL, '2026-04-28 18:57:19'),
(2, 3, 1, 30.00, NULL, '2026-04-28 18:57:20'),
(3, 4, 1, 50.00, NULL, '2026-04-28 18:57:21'),
(4, 5, 1, 27.50, 'Excellent', '2026-05-02 15:37:33'),
(5, 6, 1, 28.50, 'Excellent', '2026-05-02 15:37:33'),
(6, 7, 1, 19.50, 'Excellent', '2026-05-02 15:37:34'),
(7, 8, 1, 56.50, 'Excellent', '2026-05-02 15:37:34');

-- --------------------------------------------------------

--
-- Table structure for table `unit_registrations`
--

CREATE TABLE `unit_registrations` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `unit_id` int(11) NOT NULL,
  `registered_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `unit_registrations`
--

INSERT INTO `unit_registrations` (`id`, `student_id`, `unit_id`, `registered_at`) VALUES
(1, 1, 1, '2026-04-28 18:44:49'),
(4, 1, 2, '2026-05-01 10:58:05');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('student','lecturer','admin','financial_accountant','boarding_master','hr_manager') NOT NULL DEFAULT 'student',
  `course_id` int(11) DEFAULT NULL,
  `career_path` varchar(100) DEFAULT 'General Software Engineering',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `full_name`, `email`, `password`, `role`, `course_id`, `career_path`, `created_at`) VALUES
(1, 'Onyx Courtney', 'onyxcakech@gmail.com', '$2y$10$tqFkEQBk0iK.hsyVHVtis.bms/WrdhRdsLQ7ze/jO3kdaQDsph3tW', 'student', NULL, 'Software Development', '2026-04-11 20:58:11'),
(4, 'System Admin', 'admin@smartlms.com', '$2y$10$.0OcfG4sTRsAOCtxn8iar.okY99T57aQvvMlv.yNYBNdmCbfViUtm', 'admin', NULL, 'General Software Engineering', '2026-04-11 21:45:41'),
(5, 'Dr.smith', 'smith@gmail.com', '$2y$10$fZ1f6rlrYqK2LDDUM5DQxe2NWJbSRqjcVMchKntrQKTDgrf8Dk5hO', 'lecturer', 1, 'General Software Engineering', '2026-04-11 22:17:33'),
(9, 'Lenny', 'lenny@gmail.com', '$2y$10$zISpLktT6AecVDh54Ugvqer4pvCa6fzYAEJchUoAtFfY9KrnKPsWa', 'lecturer', 3, 'General Software Engineering', '2026-04-30 18:02:11'),
(12, 'Finance Office', 'finance@smartlms.com', '$2y$10$.A.HAqAdJVcR8Kqn/VFO7u/aDcEKYi0ZN6dEtj7IZ.HmMeyvyUC26', 'financial_accountant', NULL, 'General Software Engineering', '2026-05-18 09:29:22'),
(13, 'Boarding Master', 'boarding@smartlms.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'boarding_master', NULL, 'General Software Engineering', '2026-07-16 11:20:20'),
(14, 'HR Manager', 'hr@smartlms.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'hr_manager', NULL, 'General Software Engineering', '2026-07-16 11:20:20');

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_student_attendance`
-- (See below for the actual view)
--
CREATE TABLE `v_student_attendance` (
`student_id` int(11)
,`full_name` varchar(100)
,`unit_id` int(11)
,`unit_title` varchar(255)
,`unit_code` varchar(50)
,`course_title` varchar(255)
,`total_sessions` bigint(21)
,`attended` decimal(22,0)
,`absences` decimal(22,0)
,`absence_pct` decimal(27,1)
,`exam_status` varchar(8)
);

-- --------------------------------------------------------

--
-- Structure for view `v_student_attendance`
--
DROP TABLE IF EXISTS `v_student_attendance`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_student_attendance`  AS SELECT `ur`.`student_id` AS `student_id`, `u`.`full_name` AS `full_name`, `cu`.`id` AS `unit_id`, `cu`.`title` AS `unit_title`, `cu`.`unit_code` AS `unit_code`, `c`.`title` AS `course_title`, count(distinct `ats`.`id`) AS `total_sessions`, sum(case when `ar`.`status` = 'present' then 1 else 0 end) AS `attended`, sum(case when `ar`.`status` = 'absent' then 1 else 0 end) AS `absences`, round(sum(case when `ar`.`status` = 'absent' then 1 else 0 end) / nullif(count(distinct `ats`.`id`),0) * 100,1) AS `absence_pct`, CASE WHEN round(sum(case when `ar`.`status` = 'absent' then 1 else 0 end) / nullif(count(distinct `ats`.`id`),0) * 100,1) > 33.33 THEN 'BARRED' ELSE 'ELIGIBLE' END AS `exam_status` FROM (((((`unit_registrations` `ur` join `users` `u` on(`u`.`id` = `ur`.`student_id`)) join `course_units` `cu` on(`cu`.`id` = `ur`.`unit_id`)) join `courses` `c` on(`c`.`id` = `cu`.`course_id`)) left join `attendance_sessions` `ats` on(`ats`.`unit_id` = `cu`.`id`)) left join `attendance_records` `ar` on(`ar`.`session_id` = `ats`.`id` and `ar`.`student_id` = `ur`.`student_id`)) GROUP BY `ur`.`student_id`, `u`.`full_name`, `cu`.`id`, `cu`.`title`, `cu`.`unit_code`, `c`.`title` ;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `assignments`
--
ALTER TABLE `assignments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_course` (`course_id`),
  ADD KEY `idx_lecturer` (`lecturer_id`);

--
-- Indexes for table `assignment_submissions`
--
ALTER TABLE `assignment_submissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_submission` (`assignment_id`,`student_id`),
  ADD KEY `idx_student` (`student_id`);

--
-- Indexes for table `attendance_records`
--
ALTER TABLE `attendance_records`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_session_student` (`session_id`,`student_id`),
  ADD KEY `idx_student` (`student_id`),
  ADD KEY `idx_session` (`session_id`);

--
-- Indexes for table `attendance_sessions`
--
ALTER TABLE `attendance_sessions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_unit_date` (`unit_id`,`session_date`),
  ADD KEY `idx_unit` (`unit_id`),
  ADD KEY `idx_lecturer` (`lecturer_id`);

--
-- Indexes for table `boarding_allocations`
--
ALTER TABLE `boarding_allocations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_stu_year_sem` (`student_id`,`academic_year`,`semester`),
  ADD KEY `dorm_id` (`dorm_id`),
  ADD KEY `room_id` (`room_id`),
  ADD KEY `student_id` (`student_id`);

--
-- Indexes for table `boarding_dorms`
--
ALTER TABLE `boarding_dorms`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `boarding_notices`
--
ALTER TABLE `boarding_notices`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `boarding_rooms`
--
ALTER TABLE `boarding_rooms`
  ADD PRIMARY KEY (`id`),
  ADD KEY `dorm_id` (`dorm_id`);

--
-- Indexes for table `courses`
--
ALTER TABLE `courses`
  ADD PRIMARY KEY (`id`),
  ADD KEY `lecturer_id` (`lecturer_id`);

--
-- Indexes for table `course_units`
--
ALTER TABLE `course_units`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_course` (`course_id`),
  ADD KEY `idx_lecturer` (`lecturer_id`);

--
-- Indexes for table `enrollments`
--
ALTER TABLE `enrollments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `course_id` (`course_id`);

--
-- Indexes for table `fee_payments`
--
ALTER TABLE `fee_payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_student` (`student_id`),
  ADD KEY `idx_date` (`payment_date`),
  ADD KEY `idx_receipt` (`receipt_number`);

--
-- Indexes for table `fee_reminders`
--
ALTER TABLE `fee_reminders`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_student` (`student_id`);

--
-- Indexes for table `fee_structures`
--
ALTER TABLE `fee_structures`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_course` (`course_id`),
  ADD KEY `idx_year` (`academic_year`);

--
-- Indexes for table `hr_announcements`
--
ALTER TABLE `hr_announcements`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `hr_leave_requests`
--
ALTER TABLE `hr_leave_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `staff_id` (`staff_id`),
  ADD KEY `status` (`status`);

--
-- Indexes for table `hr_payroll`
--
ALTER TABLE `hr_payroll`
  ADD PRIMARY KEY (`id`),
  ADD KEY `staff_id` (`staff_id`),
  ADD KEY `pay_period` (`pay_period`);

--
-- Indexes for table `hr_performance`
--
ALTER TABLE `hr_performance`
  ADD PRIMARY KEY (`id`),
  ADD KEY `staff_id` (`staff_id`);

--
-- Indexes for table `hr_staff`
--
ALTER TABLE `hr_staff`
  ADD PRIMARY KEY (`id`),
  ADD KEY `department` (`department`),
  ADD KEY `status` (`status`);

--
-- Indexes for table `materials`
--
ALTER TABLE `materials`
  ADD PRIMARY KEY (`id`),
  ADD KEY `lecturer_id` (`lecturer_id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `plagiarism_reports`
--
ALTER TABLE `plagiarism_reports`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_report` (`submission_id`);

--
-- Indexes for table `questions`
--
ALTER TABLE `questions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `quiz_id` (`quiz_id`);

--
-- Indexes for table `quizzes`
--
ALTER TABLE `quizzes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `course_id` (`course_id`);

--
-- Indexes for table `results`
--
ALTER TABLE `results`
  ADD PRIMARY KEY (`id`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `quiz_id` (`quiz_id`);

--
-- Indexes for table `schedules`
--
ALTER TABLE `schedules`
  ADD PRIMARY KEY (`id`),
  ADD KEY `lecturer_id` (`lecturer_id`),
  ADD KEY `course_id` (`course_id`),
  ADD KEY `idx_zoom_meeting_id` (`zoom_meeting_id`);

--
-- Indexes for table `skills`
--
ALTER TABLE `skills`
  ADD PRIMARY KEY (`skill_id`),
  ADD UNIQUE KEY `skill_name` (`skill_name`);

--
-- Indexes for table `student_answers`
--
ALTER TABLE `student_answers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `result_id` (`result_id`),
  ADD KEY `question_id` (`question_id`);

--
-- Indexes for table `student_fee_assignments`
--
ALTER TABLE `student_fee_assignments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_stu_fee` (`student_id`,`fee_structure_id`),
  ADD KEY `idx_student` (`student_id`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `student_marks`
--
ALTER TABLE `student_marks`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_student_course` (`student_id`,`course_id`),
  ADD KEY `idx_course` (`course_id`),
  ADD KEY `idx_student` (`student_id`),
  ADD KEY `idx_lecturer` (`lecturer_id`);

--
-- Indexes for table `student_mastery`
--
ALTER TABLE `student_mastery`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_student_course_skill` (`student_id`,`course_id`,`skill_name`),
  ADD UNIQUE KEY `uniq_student_skill` (`student_id`,`skill_name`),
  ADD KEY `idx_course` (`course_id`);

--
-- Indexes for table `student_performance_logs`
--
ALTER TABLE `student_performance_logs`
  ADD PRIMARY KEY (`log_id`);

--
-- Indexes for table `unit_assessments`
--
ALTER TABLE `unit_assessments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_unit` (`unit_id`);

--
-- Indexes for table `unit_marks`
--
ALTER TABLE `unit_marks`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_mark` (`assessment_id`,`student_id`),
  ADD KEY `idx_student` (`student_id`);

--
-- Indexes for table `unit_registrations`
--
ALTER TABLE `unit_registrations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_reg` (`student_id`,`unit_id`),
  ADD KEY `ur_unit` (`unit_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `fk_course` (`course_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `assignments`
--
ALTER TABLE `assignments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `assignment_submissions`
--
ALTER TABLE `assignment_submissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `attendance_records`
--
ALTER TABLE `attendance_records`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `attendance_sessions`
--
ALTER TABLE `attendance_sessions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `boarding_allocations`
--
ALTER TABLE `boarding_allocations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `boarding_dorms`
--
ALTER TABLE `boarding_dorms`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=32;

--
-- AUTO_INCREMENT for table `boarding_notices`
--
ALTER TABLE `boarding_notices`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `boarding_rooms`
--
ALTER TABLE `boarding_rooms`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `courses`
--
ALTER TABLE `courses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `course_units`
--
ALTER TABLE `course_units`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `enrollments`
--
ALTER TABLE `enrollments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `fee_payments`
--
ALTER TABLE `fee_payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `fee_reminders`
--
ALTER TABLE `fee_reminders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `fee_structures`
--
ALTER TABLE `fee_structures`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `hr_announcements`
--
ALTER TABLE `hr_announcements`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `hr_leave_requests`
--
ALTER TABLE `hr_leave_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `hr_payroll`
--
ALTER TABLE `hr_payroll`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `hr_performance`
--
ALTER TABLE `hr_performance`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `hr_staff`
--
ALTER TABLE `hr_staff`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `materials`
--
ALTER TABLE `materials`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=40;

--
-- AUTO_INCREMENT for table `plagiarism_reports`
--
ALTER TABLE `plagiarism_reports`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `questions`
--
ALTER TABLE `questions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=320;

--
-- AUTO_INCREMENT for table `quizzes`
--
ALTER TABLE `quizzes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=71;

--
-- AUTO_INCREMENT for table `results`
--
ALTER TABLE `results`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `schedules`
--
ALTER TABLE `schedules`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `skills`
--
ALTER TABLE `skills`
  MODIFY `skill_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `student_answers`
--
ALTER TABLE `student_answers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=57;

--
-- AUTO_INCREMENT for table `student_fee_assignments`
--
ALTER TABLE `student_fee_assignments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `student_marks`
--
ALTER TABLE `student_marks`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `student_mastery`
--
ALTER TABLE `student_mastery`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=691;

--
-- AUTO_INCREMENT for table `student_performance_logs`
--
ALTER TABLE `student_performance_logs`
  MODIFY `log_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `unit_assessments`
--
ALTER TABLE `unit_assessments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `unit_marks`
--
ALTER TABLE `unit_marks`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- AUTO_INCREMENT for table `unit_registrations`
--
ALTER TABLE `unit_registrations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `courses`
--
ALTER TABLE `courses`
  ADD CONSTRAINT `courses_ibfk_1` FOREIGN KEY (`lecturer_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `course_units`
--
ALTER TABLE `course_units`
  ADD CONSTRAINT `cu_course` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `cu_lecturer` FOREIGN KEY (`lecturer_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `enrollments`
--
ALTER TABLE `enrollments`
  ADD CONSTRAINT `enrollments_ibfk_1` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `materials`
--
ALTER TABLE `materials`
  ADD CONSTRAINT `materials_ibfk_1` FOREIGN KEY (`lecturer_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `questions`
--
ALTER TABLE `questions`
  ADD CONSTRAINT `fk_question_quiz` FOREIGN KEY (`quiz_id`) REFERENCES `quizzes` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `quizzes`
--
ALTER TABLE `quizzes`
  ADD CONSTRAINT `fk_quiz_course` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `results`
--
ALTER TABLE `results`
  ADD CONSTRAINT `fk_res_student` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `schedules`
--
ALTER TABLE `schedules`
  ADD CONSTRAINT `sch_course` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `sch_lecturer` FOREIGN KEY (`lecturer_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `student_answers`
--
ALTER TABLE `student_answers`
  ADD CONSTRAINT `sa_result` FOREIGN KEY (`result_id`) REFERENCES `results` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `sa_student` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `student_mastery`
--
ALTER TABLE `student_mastery`
  ADD CONSTRAINT `student_mastery_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `unit_assessments`
--
ALTER TABLE `unit_assessments`
  ADD CONSTRAINT `ua_unit` FOREIGN KEY (`unit_id`) REFERENCES `course_units` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `unit_marks`
--
ALTER TABLE `unit_marks`
  ADD CONSTRAINT `um_assessment` FOREIGN KEY (`assessment_id`) REFERENCES `unit_assessments` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `um_student` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `unit_registrations`
--
ALTER TABLE `unit_registrations`
  ADD CONSTRAINT `ur_student` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `ur_unit` FOREIGN KEY (`unit_id`) REFERENCES `course_units` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `fk_course` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
