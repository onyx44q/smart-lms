-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Apr 19, 2026 at 08:20 AM
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
(1, 'Artificial intelligence', NULL, 5, NULL),
(3, 'Software Engineering', NULL, NULL, NULL);

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
  `lecturer_id` int(11) DEFAULT NULL,
  `title` varchar(255) DEFAULT NULL,
  `type` enum('video','pdf') DEFAULT NULL,
  `file_path` varchar(255) DEFAULT NULL,
  `upload_date` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `materials`
--

INSERT INTO `materials` (`id`, `course_id`, `lecturer_id`, `title`, `type`, `file_path`, `upload_date`) VALUES
(1, 1, 5, 'Week1', 'pdf', 'uploads/notes/1775987401_Sustainable_AI.pdf', '2026-04-12 09:50:01'),
(2, 1, 5, 'week2', 'video', 'uploads/videos/1775990414_Presentations_mad_2025_11_16_022055.mp4', '2026-04-12 10:40:14'),
(3, 1, 5, 'Week 3 Intelligence Agents', 'pdf', 'uploads/notes/1776178025_Intelligent_Agents.pdf', '2026-04-14 14:47:05');

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
(18, 1, 'Foundational gaps detected. You have been directed to revision materials to strengthen core concepts. Priority gap detected: Practical Application is 80 points below your career target. Predicted grade: F.', 0, '2026-04-14 14:41:10');

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
(93, 29, 'What is the term for the process of designing AI systems that are environmentally friendly and sustainable?', 'Sustainable AI design', 'Green AI development', 'Environmental AI engineering', 'AI sustainability engineering', 'D', 'AI sustainability engineering refers to the process of designing AI systems that are environmentally friendly and sustainable.');

-- --------------------------------------------------------

--
-- Table structure for table `quizzes`
--

CREATE TABLE `quizzes` (
  `id` int(11) NOT NULL,
  `course_id` int(11) DEFAULT NULL,
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

INSERT INTO `quizzes` (`id`, `course_id`, `title`, `difficulty`, `is_active`, `skill_name`, `topic`, `created_by`, `created_at`) VALUES
(4, 1, 'sustainable ai Assessment', 'beginner', 1, NULL, NULL, 5, '2026-04-13 20:26:20'),
(29, 1, 'Artificial intelligence - sustainable ai', 'beginner', 1, NULL, 'sustainable ai', 5, '2026-04-14 14:34:31');

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
(3, 1, 29, 40.00, 'Foundational gaps detected. You have been directed to revision materials to strengthen core concepts. Priority gap detected: Practical Application is 80 points below your career target.', 'remedial', 'critical', 'F', 'beginner', 1, '2026-04-14 14:41:10');

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
(15, 3, 1, 93, '', 0, '2026-04-14 14:41:10');

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
(1, 1, 'General Aptitude', 0.00, '2026-04-11 20:58:11'),
(2, 1, 'Core Theory', 12.00, '2026-04-14 14:41:10'),
(3, 1, 'Practical Application', 0.00, '2026-04-11 20:58:11');

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
(5, 'Dr.smith', 'smith@gmail.com', '$2y$10$fZ1f6rlrYqK2LDDUM5DQxe2NWJbSRqjcVMchKntrQKTDgrf8Dk5hO', 'lecturer', 1, 'General Software Engineering', '2026-04-11 22:17:33');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `courses`
--
ALTER TABLE `courses`
  ADD PRIMARY KEY (`id`),
  ADD KEY `lecturer_id` (`lecturer_id`);

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
-- Indexes for table `student_answers`
--
ALTER TABLE `student_answers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `result_id` (`result_id`),
  ADD KEY `question_id` (`question_id`);

--
-- Indexes for table `student_mastery`
--
ALTER TABLE `student_mastery`
  ADD PRIMARY KEY (`id`),
  ADD KEY `student_id` (`student_id`);

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
-- AUTO_INCREMENT for table `courses`
--
ALTER TABLE `courses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `enrollments`
--
ALTER TABLE `enrollments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `materials`
--
ALTER TABLE `materials`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `questions`
--
ALTER TABLE `questions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=236;

--
-- AUTO_INCREMENT for table `quizzes`
--
ALTER TABLE `quizzes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=56;

--
-- AUTO_INCREMENT for table `results`
--
ALTER TABLE `results`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `schedules`
--
ALTER TABLE `schedules`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `student_answers`
--
ALTER TABLE `student_answers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `student_mastery`
--
ALTER TABLE `student_mastery`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `courses`
--
ALTER TABLE `courses`
  ADD CONSTRAINT `courses_ibfk_1` FOREIGN KEY (`lecturer_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

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
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `fk_course` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
