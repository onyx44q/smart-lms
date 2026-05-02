-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: May 01, 2026 at 06:50 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

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
(3, 'Software Engineering', NULL, 9, NULL);

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
(3, 1, 1, '2026-04-12 13:20:43');

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
(3, 1, 1, 5, 'Week 3 Intelligence Agents', 'pdf', 'uploads/notes/1776178025_Intelligent_Agents.pdf', '2026-04-14 14:47:05');

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
(29, 1, 'Excellent performance on this assessment. You have unlocked the next level of content. Priority gap detected: Practical Application is 80 points below your career target. Predicted grade: D.', 0, '2026-04-23 17:43:14');

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
(266, 61, 'What is the term for the environmental impact of AI systems?', 'Carbon footprint', 'Digital pollution', 'E-waste generation', 'All of the above', 'D', 'The environmental impact of AI systems includes carbon footprint, digital pollution, and e-waste generation.');

-- --------------------------------------------------------

--
-- Table structure for table `quizzes`
--

CREATE TABLE `quizzes` (
  `id` int(11) NOT NULL,
  `course_id` int(11) DEFAULT NULL,
  `unit_id` int(11) DEFAULT NULL,
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

INSERT INTO `quizzes` (`id`, `course_id`, `unit_id`, `title`, `difficulty`, `is_active`, `skill_name`, `topic`, `created_by`, `created_at`) VALUES
(4, 1, 1, 'sustainable ai Assessment', 'beginner', 1, 'General Aptitude', NULL, 5, '2026-04-13 20:26:20'),
(29, 1, 1, 'Artificial intelligence - sustainable ai', 'beginner', 1, 'Practical Application', 'sustainable ai', 5, '2026-04-14 14:34:31'),
(61, 1, 1, 'Artificial intelligence - sustainable ai (Core Theory)', 'beginner', 1, 'Core Theory', 'sustainable ai', 5, '2026-04-23 17:41:25');

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
(8, 1, 61, 100.00, 'Excellent performance on this assessment. You have unlocked the next level of content. Priority gap detected: Practical Application is 80 points below your career target.', 'advance', 'critical', 'D', 'advanced', 1, '2026-04-23 17:43:13');

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
(41, 8, 1, 266, 'D', 1, '2026-04-23 17:43:14');

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
  `skill_name` varchar(100) DEFAULT NULL,
  `mastery_level` decimal(5,2) DEFAULT 0.00,
  `last_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `student_mastery`
--

INSERT INTO `student_mastery` (`id`, `student_id`, `skill_name`, `mastery_level`, `last_updated`) VALUES
(1, 1, 'General Aptitude', 17.00, '2026-04-24 08:25:13'),
(2, 1, 'Core Theory', 46.00, '2026-04-23 17:43:13'),
(3, 1, 'Practical Application', 0.00, '2026-04-23 17:36:05'),
(7, 1, 'General Aptitude', 17.00, '2026-04-24 08:25:44'),
(8, 1, 'Practical Application', 0.00, '2026-04-24 08:25:44'),
(9, 1, 'Core Theory', 8.50, '2026-04-24 08:25:44'),
(10, 1, 'General Aptitude', 17.00, '2026-04-24 08:25:45'),
(11, 1, 'Practical Application', 0.00, '2026-04-24 08:25:45'),
(12, 1, 'Core Theory', 8.50, '2026-04-24 08:25:45'),
(13, 1, 'General Aptitude', 17.00, '2026-04-24 08:27:17'),
(14, 1, 'Practical Application', 0.00, '2026-04-24 08:27:17'),
(15, 1, 'Core Theory', 8.50, '2026-04-24 08:27:17'),
(16, 1, 'General Aptitude', 17.00, '2026-04-24 08:27:18'),
(17, 1, 'Practical Application', 0.00, '2026-04-24 08:27:18'),
(18, 1, 'Core Theory', 8.50, '2026-04-24 08:27:18'),
(19, 1, 'General Aptitude', 17.00, '2026-04-24 08:27:35'),
(20, 1, 'Practical Application', 0.00, '2026-04-24 08:27:35'),
(21, 1, 'Core Theory', 8.50, '2026-04-24 08:27:36'),
(22, 1, 'General Aptitude', 17.00, '2026-04-24 08:29:16'),
(23, 1, 'Practical Application', 0.00, '2026-04-24 08:29:16'),
(24, 1, 'Core Theory', 8.50, '2026-04-24 08:29:16'),
(25, 1, 'General Aptitude', 17.00, '2026-04-24 08:29:18'),
(26, 1, 'Practical Application', 0.00, '2026-04-24 08:29:19'),
(27, 1, 'Core Theory', 8.50, '2026-04-24 08:29:19'),
(28, 1, 'General Aptitude', 17.00, '2026-04-24 08:29:25'),
(29, 1, 'Practical Application', 0.00, '2026-04-24 08:29:25'),
(30, 1, 'Core Theory', 8.50, '2026-04-24 08:29:25'),
(31, 1, 'General Aptitude', 17.00, '2026-04-24 08:29:28'),
(32, 1, 'Practical Application', 0.00, '2026-04-24 08:29:29'),
(33, 1, 'Core Theory', 8.50, '2026-04-24 08:29:29'),
(34, 1, 'General Aptitude', 17.00, '2026-04-24 08:29:36'),
(35, 1, 'Practical Application', 0.00, '2026-04-24 08:29:36'),
(36, 1, 'Core Theory', 8.50, '2026-04-24 08:29:36'),
(37, 1, 'General Aptitude', 17.00, '2026-04-24 08:30:26'),
(38, 1, 'Practical Application', 0.00, '2026-04-24 08:30:26'),
(39, 1, 'Core Theory', 8.50, '2026-04-24 08:30:26'),
(40, 1, 'General Aptitude', 17.00, '2026-04-24 08:30:29'),
(41, 1, 'Practical Application', 0.00, '2026-04-24 08:30:29'),
(42, 1, 'Core Theory', 8.50, '2026-04-24 08:30:29'),
(43, 1, 'General Aptitude', 17.00, '2026-04-24 08:30:53'),
(44, 1, 'Practical Application', 0.00, '2026-04-24 08:30:53'),
(45, 1, 'Core Theory', 8.50, '2026-04-24 08:30:54'),
(46, 1, 'General Aptitude', 17.00, '2026-04-24 08:30:55'),
(47, 1, 'Practical Application', 0.00, '2026-04-24 08:30:55'),
(48, 1, 'Core Theory', 8.50, '2026-04-24 08:30:55'),
(49, 1, 'General Aptitude', 17.00, '2026-04-24 08:31:17'),
(50, 1, 'Practical Application', 0.00, '2026-04-24 08:31:17'),
(51, 1, 'Core Theory', 8.50, '2026-04-24 08:31:17'),
(52, 1, 'General Aptitude', 17.00, '2026-04-24 08:31:18'),
(53, 1, 'Practical Application', 0.00, '2026-04-24 08:31:18'),
(54, 1, 'Core Theory', 8.50, '2026-04-24 08:31:18'),
(55, 1, 'General Aptitude', 17.00, '2026-04-24 08:40:29'),
(56, 1, 'Practical Application', 0.00, '2026-04-24 08:40:29'),
(57, 1, 'Core Theory', 8.50, '2026-04-24 08:40:29'),
(58, 1, 'General Aptitude', 17.00, '2026-04-24 08:40:29'),
(59, 1, 'Practical Application', 0.00, '2026-04-24 08:40:29'),
(60, 1, 'Core Theory', 8.50, '2026-04-24 08:40:29'),
(61, 1, 'General Aptitude', 17.00, '2026-04-24 08:43:46'),
(62, 1, 'Practical Application', 0.00, '2026-04-24 08:43:46'),
(63, 1, 'Core Theory', 8.50, '2026-04-24 08:43:47'),
(64, 1, 'General Aptitude', 17.00, '2026-04-24 08:44:20'),
(65, 1, 'Practical Application', 0.00, '2026-04-24 08:44:20'),
(66, 1, 'Core Theory', 8.50, '2026-04-24 08:44:20'),
(67, 1, 'General Aptitude', 17.00, '2026-04-24 08:44:21'),
(68, 1, 'Practical Application', 0.00, '2026-04-24 08:44:21'),
(69, 1, 'Core Theory', 8.50, '2026-04-24 08:44:22'),
(70, 1, 'General Aptitude', 17.00, '2026-04-27 11:01:49'),
(71, 1, 'Practical Application', 0.00, '2026-04-27 11:01:50'),
(72, 1, 'Core Theory', 8.50, '2026-04-27 11:01:51'),
(73, 1, 'General Aptitude', 17.00, '2026-04-27 11:01:56'),
(74, 1, 'Practical Application', 0.00, '2026-04-27 11:01:57'),
(75, 1, 'Core Theory', 8.50, '2026-04-27 11:01:58'),
(76, 1, 'General Aptitude', 17.00, '2026-04-27 11:03:03'),
(77, 1, 'Practical Application', 0.00, '2026-04-27 11:03:04'),
(78, 1, 'Core Theory', 8.50, '2026-04-27 11:03:04'),
(79, 1, 'General Aptitude', 17.00, '2026-04-27 11:03:13'),
(80, 1, 'Practical Application', 0.00, '2026-04-27 11:03:13'),
(81, 1, 'Core Theory', 8.50, '2026-04-27 11:03:14'),
(82, 1, 'General Aptitude', 17.00, '2026-04-27 11:03:16'),
(83, 1, 'Practical Application', 0.00, '2026-04-27 11:03:17'),
(84, 1, 'Core Theory', 8.50, '2026-04-27 11:03:18'),
(85, 1, 'General Aptitude', 17.00, '2026-04-27 11:03:22'),
(86, 1, 'Practical Application', 0.00, '2026-04-27 11:03:22'),
(87, 1, 'Core Theory', 8.50, '2026-04-27 11:03:23'),
(88, 1, 'General Aptitude', 17.00, '2026-04-27 11:03:40'),
(89, 1, 'Practical Application', 0.00, '2026-04-27 11:03:40'),
(90, 1, 'Core Theory', 8.50, '2026-04-27 11:03:40'),
(91, 1, 'General Aptitude', 17.00, '2026-04-27 11:04:00'),
(92, 1, 'Practical Application', 0.00, '2026-04-27 11:04:00'),
(93, 1, 'Core Theory', 8.50, '2026-04-27 11:04:00'),
(94, 1, 'General Aptitude', 17.00, '2026-04-27 11:06:11'),
(95, 1, 'Practical Application', 0.00, '2026-04-27 11:06:11'),
(96, 1, 'Core Theory', 8.50, '2026-04-27 11:06:11'),
(97, 1, 'General Aptitude', 17.00, '2026-04-27 11:06:45'),
(98, 1, 'Practical Application', 0.00, '2026-04-27 11:06:45'),
(99, 1, 'Core Theory', 8.50, '2026-04-27 11:06:46'),
(100, 1, 'General Aptitude', 17.00, '2026-04-27 11:07:21'),
(101, 1, 'Practical Application', 0.00, '2026-04-27 11:07:21'),
(102, 1, 'Core Theory', 8.50, '2026-04-27 11:07:21'),
(103, 1, 'General Aptitude', 17.00, '2026-04-27 11:07:22'),
(104, 1, 'Practical Application', 0.00, '2026-04-27 11:07:22'),
(105, 1, 'Core Theory', 8.50, '2026-04-27 11:07:22'),
(106, 1, 'General Aptitude', 17.00, '2026-04-27 11:11:07'),
(107, 1, 'Practical Application', 0.00, '2026-04-27 11:11:07'),
(108, 1, 'Core Theory', 8.50, '2026-04-27 11:11:08'),
(109, 1, 'General Aptitude', 17.00, '2026-04-27 11:11:12'),
(110, 1, 'Practical Application', 0.00, '2026-04-27 11:11:12'),
(111, 1, 'Core Theory', 8.50, '2026-04-27 11:11:13'),
(112, 1, 'General Aptitude', 17.00, '2026-04-28 14:26:58'),
(113, 1, 'Practical Application', 0.00, '2026-04-28 14:26:59'),
(114, 1, 'Core Theory', 8.50, '2026-04-28 14:27:01'),
(115, 1, 'General Aptitude', 17.00, '2026-04-28 14:27:06'),
(116, 1, 'Practical Application', 0.00, '2026-04-28 14:27:07'),
(117, 1, 'Core Theory', 8.50, '2026-04-28 14:27:10'),
(118, 1, 'General Aptitude', 17.00, '2026-04-28 14:27:27'),
(119, 1, 'Practical Application', 0.00, '2026-04-28 14:27:27'),
(120, 1, 'Core Theory', 8.50, '2026-04-28 14:27:29'),
(121, 1, 'General Aptitude', 17.00, '2026-04-28 14:27:34'),
(122, 1, 'Practical Application', 0.00, '2026-04-28 14:27:35'),
(123, 1, 'Core Theory', 8.50, '2026-04-28 14:27:37'),
(124, 1, 'General Aptitude', 17.00, '2026-04-28 14:52:30'),
(125, 1, 'Practical Application', 0.00, '2026-04-28 14:52:31'),
(126, 1, 'Core Theory', 8.50, '2026-04-28 14:52:33'),
(127, 1, 'General Aptitude', 17.00, '2026-04-28 14:52:41'),
(128, 1, 'Practical Application', 0.00, '2026-04-28 14:52:45'),
(129, 1, 'Core Theory', 8.50, '2026-04-28 14:52:46'),
(130, 1, 'General Aptitude', 17.00, '2026-04-28 18:44:02'),
(131, 1, 'Practical Application', 0.00, '2026-04-28 18:44:02'),
(132, 1, 'Core Theory', 8.50, '2026-04-28 18:44:02'),
(133, 1, 'General Aptitude', 17.00, '2026-04-28 18:44:05'),
(134, 1, 'Practical Application', 0.00, '2026-04-28 18:44:05'),
(135, 1, 'Core Theory', 8.50, '2026-04-28 18:44:05'),
(136, 1, 'General Aptitude', 17.00, '2026-04-28 18:44:50'),
(137, 1, 'Practical Application', 0.00, '2026-04-28 18:44:50'),
(138, 1, 'Core Theory', 8.50, '2026-04-28 18:44:50'),
(139, 1, 'General Aptitude', 17.00, '2026-04-28 18:44:51'),
(140, 1, 'Practical Application', 0.00, '2026-04-28 18:44:51'),
(141, 1, 'Core Theory', 8.50, '2026-04-28 18:44:51'),
(142, 1, 'General Aptitude', 17.00, '2026-04-28 18:46:33'),
(143, 1, 'Practical Application', 0.00, '2026-04-28 18:46:33'),
(144, 1, 'Core Theory', 8.50, '2026-04-28 18:46:33'),
(145, 1, 'General Aptitude', 17.00, '2026-04-28 18:46:34'),
(146, 1, 'Practical Application', 0.00, '2026-04-28 18:46:34'),
(147, 1, 'Core Theory', 8.50, '2026-04-28 18:46:34'),
(148, 1, 'General Aptitude', 17.00, '2026-04-28 18:46:48'),
(149, 1, 'Practical Application', 0.00, '2026-04-28 18:46:48'),
(150, 1, 'Core Theory', 8.50, '2026-04-28 18:46:48'),
(151, 1, 'General Aptitude', 17.00, '2026-04-28 18:46:49'),
(152, 1, 'Practical Application', 0.00, '2026-04-28 18:46:49'),
(153, 1, 'Core Theory', 8.50, '2026-04-28 18:46:50'),
(154, 1, 'General Aptitude', 17.00, '2026-04-28 18:47:22'),
(155, 1, 'Practical Application', 0.00, '2026-04-28 18:47:22'),
(156, 1, 'Core Theory', 8.50, '2026-04-28 18:47:22'),
(157, 1, 'General Aptitude', 17.00, '2026-04-28 18:53:40'),
(158, 1, 'Practical Application', 0.00, '2026-04-28 18:53:40'),
(159, 1, 'Core Theory', 8.50, '2026-04-28 18:53:40'),
(160, 1, 'General Aptitude', 17.00, '2026-04-28 18:53:46'),
(161, 1, 'Practical Application', 0.00, '2026-04-28 18:53:46'),
(162, 1, 'Core Theory', 8.50, '2026-04-28 18:53:46'),
(163, 1, 'General Aptitude', 17.00, '2026-04-28 18:53:48'),
(164, 1, 'Practical Application', 0.00, '2026-04-28 18:53:49'),
(165, 1, 'Core Theory', 8.50, '2026-04-28 18:53:49'),
(166, 1, 'General Aptitude', 17.00, '2026-04-28 18:53:51'),
(167, 1, 'Practical Application', 0.00, '2026-04-28 18:53:51'),
(168, 1, 'Core Theory', 8.50, '2026-04-28 18:53:51'),
(169, 1, 'General Aptitude', 17.00, '2026-04-28 18:54:15'),
(170, 1, 'Practical Application', 0.00, '2026-04-28 18:54:15'),
(171, 1, 'Core Theory', 8.50, '2026-04-28 18:54:15'),
(172, 1, 'General Aptitude', 17.00, '2026-04-28 18:54:23'),
(173, 1, 'Practical Application', 0.00, '2026-04-28 18:54:23'),
(174, 1, 'Core Theory', 8.50, '2026-04-28 18:54:24'),
(175, 1, 'General Aptitude', 17.00, '2026-04-28 18:54:30'),
(176, 1, 'Practical Application', 0.00, '2026-04-28 18:54:30'),
(177, 1, 'Core Theory', 8.50, '2026-04-28 18:54:30'),
(178, 1, 'General Aptitude', 17.00, '2026-04-28 18:54:32'),
(179, 1, 'Practical Application', 0.00, '2026-04-28 18:54:33'),
(180, 1, 'Core Theory', 8.50, '2026-04-28 18:54:33'),
(181, 1, 'General Aptitude', 17.00, '2026-04-28 18:55:41'),
(182, 1, 'Practical Application', 0.00, '2026-04-28 18:55:42'),
(183, 1, 'Core Theory', 8.50, '2026-04-28 18:55:42'),
(184, 1, 'General Aptitude', 17.00, '2026-04-28 18:55:45'),
(185, 1, 'Practical Application', 0.00, '2026-04-28 18:55:45'),
(186, 1, 'Core Theory', 8.50, '2026-04-28 18:55:45'),
(187, 1, 'General Aptitude', 17.00, '2026-04-28 18:55:49'),
(188, 1, 'Practical Application', 0.00, '2026-04-28 18:55:49'),
(189, 1, 'Core Theory', 8.50, '2026-04-28 18:55:49'),
(190, 1, 'General Aptitude', 17.00, '2026-04-28 18:56:11'),
(191, 1, 'Practical Application', 0.00, '2026-04-28 18:56:11'),
(192, 1, 'Core Theory', 8.50, '2026-04-28 18:56:12'),
(193, 1, 'General Aptitude', 17.00, '2026-04-28 18:56:15'),
(194, 1, 'Practical Application', 0.00, '2026-04-28 18:56:15'),
(195, 1, 'Core Theory', 8.50, '2026-04-28 18:56:15'),
(196, 1, 'General Aptitude', 17.00, '2026-04-28 18:56:36'),
(197, 1, 'Practical Application', 0.00, '2026-04-28 18:56:36'),
(198, 1, 'Core Theory', 8.50, '2026-04-28 18:56:36'),
(199, 1, 'General Aptitude', 17.00, '2026-04-28 18:56:38'),
(200, 1, 'Practical Application', 0.00, '2026-04-28 18:56:39'),
(201, 1, 'Core Theory', 8.50, '2026-04-28 18:56:39'),
(202, 1, 'General Aptitude', 17.00, '2026-04-28 18:57:00'),
(203, 1, 'Practical Application', 0.00, '2026-04-28 18:57:00'),
(204, 1, 'Core Theory', 8.50, '2026-04-28 18:57:00'),
(205, 1, 'General Aptitude', 17.00, '2026-04-28 18:57:03'),
(206, 1, 'Practical Application', 0.00, '2026-04-28 18:57:03'),
(207, 1, 'Core Theory', 8.50, '2026-04-28 18:57:03'),
(208, 1, 'General Aptitude', 17.00, '2026-04-28 18:57:21'),
(209, 1, 'Practical Application', 0.00, '2026-04-28 18:57:21'),
(210, 1, 'Core Theory', 8.50, '2026-04-28 18:57:22'),
(211, 1, 'General Aptitude', 17.00, '2026-04-28 18:57:26'),
(212, 1, 'Practical Application', 0.00, '2026-04-28 18:57:26'),
(213, 1, 'Core Theory', 8.50, '2026-04-28 18:57:27'),
(214, 1, 'General Aptitude', 17.00, '2026-04-28 18:57:51'),
(215, 1, 'Practical Application', 0.00, '2026-04-28 18:57:51'),
(216, 1, 'Core Theory', 8.50, '2026-04-28 18:57:51'),
(217, 1, 'General Aptitude', 17.00, '2026-04-28 18:57:55'),
(218, 1, 'Practical Application', 0.00, '2026-04-28 18:57:56'),
(219, 1, 'Core Theory', 8.50, '2026-04-28 18:57:56'),
(220, 1, 'General Aptitude', 17.00, '2026-04-28 18:59:20'),
(221, 1, 'Practical Application', 0.00, '2026-04-28 18:59:21'),
(222, 1, 'Core Theory', 8.50, '2026-04-28 18:59:21'),
(223, 1, 'General Aptitude', 17.00, '2026-04-28 18:59:21'),
(224, 1, 'Practical Application', 0.00, '2026-04-28 18:59:22'),
(225, 1, 'Core Theory', 8.50, '2026-04-28 18:59:22'),
(226, 1, 'General Aptitude', 17.00, '2026-04-29 06:45:12'),
(227, 1, 'Practical Application', 0.00, '2026-04-29 06:45:13'),
(228, 1, 'Core Theory', 8.50, '2026-04-29 06:45:14'),
(229, 1, 'General Aptitude', 17.00, '2026-04-29 06:45:15'),
(230, 1, 'Practical Application', 0.00, '2026-04-29 06:45:17'),
(231, 1, 'Core Theory', 8.50, '2026-04-29 06:45:17'),
(232, 1, 'General Aptitude', 17.00, '2026-04-29 06:45:37'),
(233, 1, 'Practical Application', 0.00, '2026-04-29 06:45:38'),
(234, 1, 'Core Theory', 8.50, '2026-04-29 06:45:38'),
(235, 1, 'General Aptitude', 17.00, '2026-04-29 06:47:11'),
(236, 1, 'Practical Application', 0.00, '2026-04-29 06:47:11'),
(237, 1, 'Core Theory', 8.50, '2026-04-29 06:47:11'),
(238, 1, 'General Aptitude', 17.00, '2026-04-30 17:55:40'),
(239, 1, 'Practical Application', 0.00, '2026-04-30 17:55:40'),
(240, 1, 'Core Theory', 8.50, '2026-04-30 17:55:40'),
(241, 1, 'General Aptitude', 17.00, '2026-04-30 17:55:42'),
(242, 1, 'Practical Application', 0.00, '2026-04-30 17:55:42'),
(243, 1, 'Core Theory', 8.50, '2026-04-30 17:55:42');

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
(4, 1, 'final', 'exam', 60.00, 3, 5, '2026-04-28 18:56:59');

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
(3, 4, 1, 50.00, NULL, '2026-04-28 18:57:21');

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
(1, 1, 1, '2026-04-28 18:44:49');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('student','lecturer','admin') NOT NULL,
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
(9, 'Lenny', 'lenny@gmail.com', '$2y$10$zISpLktT6AecVDh54Ugvqer4pvCa6fzYAEJchUoAtFfY9KrnKPsWa', 'lecturer', 3, 'General Software Engineering', '2026-04-30 18:02:11');

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
  ADD KEY `student_id` (`student_id`);

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
-- AUTO_INCREMENT for table `courses`
--
ALTER TABLE `courses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `course_units`
--
ALTER TABLE `course_units`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `enrollments`
--
ALTER TABLE `enrollments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `materials`
--
ALTER TABLE `materials`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=30;

--
-- AUTO_INCREMENT for table `plagiarism_reports`
--
ALTER TABLE `plagiarism_reports`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `questions`
--
ALTER TABLE `questions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=267;

--
-- AUTO_INCREMENT for table `quizzes`
--
ALTER TABLE `quizzes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=62;

--
-- AUTO_INCREMENT for table `results`
--
ALTER TABLE `results`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=42;

--
-- AUTO_INCREMENT for table `student_marks`
--
ALTER TABLE `student_marks`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `student_mastery`
--
ALTER TABLE `student_mastery`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=244;

--
-- AUTO_INCREMENT for table `student_performance_logs`
--
ALTER TABLE `student_performance_logs`
  MODIFY `log_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `unit_assessments`
--
ALTER TABLE `unit_assessments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `unit_marks`
--
ALTER TABLE `unit_marks`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `unit_registrations`
--
ALTER TABLE `unit_registrations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

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
