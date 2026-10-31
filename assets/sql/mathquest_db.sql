CREATE DATABASE mathquest;
USE mathquest;

/*Teachers Table*/
CREATE TABLE teachers (
  teacher_id INT PRIMARY KEY AUTO_INCREMENT,
  first_name VARCHAR(50) NOT NULL,
  middle_name VARCHAR(50),
  last_name VARCHAR(50) NOT NULL,
  position ENUM('Teacher I','Teacher II','Teacher III','Teacher IV','Teacher V','Teacher VI','Master Teacher I','Master Teacher II','Master Teacher III','Master Teacher IV','Master Teacher V','Master Teacher VI'),
  email VARCHAR(100) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  profile_image VARCHAR(255) DEFAULT NULL
);

/*Sections Table*/
CREATE TABLE sections (
    section_id INT AUTO_INCREMENT PRIMARY KEY,
    teacher_id INT NOT NULL,
    section_name VARCHAR(50) NOT NULL,
    school_year VARCHAR(9) NOT NULL,
    date_created TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    archived TINYINT(1) DEFAULT 0,
    FOREIGN KEY (teacher_id) REFERENCES teachers(teacher_id) ON DELETE CASCADE
);

/*Students Table*/
CREATE TABLE students (
    student_id INT AUTO_INCREMENT PRIMARY KEY,
    section_id INT NOT NULL,
    lrn VARCHAR(12) NOT NULL,
    first_name VARCHAR(50) NOT NULL,
    middle_name VARCHAR(50),
    last_name VARCHAR(50) NOT NULL,
    passcode VARCHAR(6) NOT NULL UNIQUE,
    theta FLOAT DEFAULT 0,
    p_value FLOAT DEFAULT 0.5,
    current_points INT DEFAULT 0,
    current_level INT DEFAULT 1,
    learner_category ENUM('Slow Learner','Average Learner','Fast Learner') DEFAULT 'Average Learner',
    assigned_set ENUM('Easy','Medium','Hard') DEFAULT 'Medium',
    date_created TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    archived TINYINT(1) DEFAULT 0,
    FOREIGN KEY (section_id) REFERENCES sections(section_id) ON DELETE CASCADE
);

/*Student Archives Table*/
CREATE TABLE student_archives (
    archive_id INT AUTO_INCREMENT PRIMARY KEY,
    student_id VARCHAR(20) NOT NULL,
    lrn VARCHAR(20) NOT NULL,
    first_name VARCHAR(50) NOT NULL,
    middle_name VARCHAR(50),
    last_name VARCHAR(50) NOT NULL,
    section_id INT NOT NULL,
    school_year VARCHAR(9) NOT NULL,
    passcode VARCHAR(10) NOT NULL,
    learner_category VARCHAR(20) DEFAULT 'Average Learner',
    archived_at DATETIME NOT NULL,
    FOREIGN KEY (section_id) REFERENCES sections(section_id)
);

/*Academic Settings Table*/
CREATE TABLE academic_settings (
    setting_id INT PRIMARY KEY AUTO_INCREMENT,
    school_year VARCHAR(9) DEFAULT NULL,
    current_quarter ENUM('1st','2nd','3rd','4th') DEFAULT NULL,
    current_week INT(2) DEFAULT NULL,
    last_updated DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_by INT,
    FOREIGN KEY (updated_by) REFERENCES teachers(teacher_id)
);

/*Learning Materials Table*/
CREATE TABLE learning_materials (
    material_id INT PRIMARY KEY AUTO_INCREMENT,
    teacher_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    quarter ENUM('1st','2nd','3rd','4th') NOT NULL,
    week INT(2) NOT NULL,
    description TEXT,
    type ENUM('Module','Presentation','Video','Image','Website/Link') NOT NULL,
    file_path VARCHAR(500),
    url VARCHAR(500),
    uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (teacher_id) REFERENCES teachers(teacher_id)
);

/*Quizzes Table*/
CREATE TABLE quizzes (
    quiz_id INT AUTO_INCREMENT PRIMARY KEY,
    teacher_id INT NOT NULL,
    quarter ENUM('1st','2nd','3rd','4th') NOT NULL,
    week INT(2) NOT NULL,
    quiz_type ENUM('Mixed','Easy','Medium','Hard') NOT NULL,
    score DECIMAL(5,2) DEFAULT NULL,
    date_created TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (teacher_id) REFERENCES teachers(teacher_id) ON DELETE CASCADE
);

/*Quiz Sections Table*/
CREATE TABLE quiz_sections (
    quiz_section_id INT AUTO_INCREMENT PRIMARY KEY,
    quiz_id INT NOT NULL,
    section_id INT NOT NULL,
    assigned_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    due_date DATETIME,
    is_active BOOLEAN DEFAULT TRUE,
    FOREIGN KEY (quiz_id) REFERENCES quizzes(quiz_id) ON DELETE CASCADE,
    FOREIGN KEY (section_id) REFERENCES sections(section_id) ON DELETE CASCADE
);

/*Questions Table*/
CREATE TABLE questions (
    question_id INT PRIMARY KEY AUTO_INCREMENT,
    content TEXT NOT NULL,
    content_image VARCHAR(255),
    option_a TEXT,
    option_a_image VARCHAR(255),
    option_b TEXT,
    option_b_image VARCHAR(255),
    option_c TEXT,
    option_c_image VARCHAR(255),
    option_d TEXT,
    option_d_image VARCHAR(255),
    correct_option CHAR(255) NOT NULL, 
    difficulty ENUM('Easy','Medium','Hard') NOT NULL,
    points INT DEFAULT 1,
    hint TEXT
);

/*Quiz Questions Table*/
CREATE TABLE quiz_questions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    quiz_id INT NOT NULL,
    question_id INT NOT NULL,
    FOREIGN KEY (quiz_id) REFERENCES quizzes(quiz_id)
        ON DELETE CASCADE,
    FOREIGN KEY (question_id) REFERENCES questions(question_id)
        ON DELETE CASCADE
);

/*Quiz Attempts Table*/
CREATE TABLE quiz_attempts (
    attempt_id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    quiz_id INT NOT NULL,
    is_completed BOOLEAN DEFAULT FALSE,
    timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(student_id),
    FOREIGN KEY (quiz_id) REFERENCES quizzes(quiz_id)
);

/*Quiz Logs Table*/
CREATE TABLE quiz_logs (
    quizlog_id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    quiz_id INT NOT NULL,
    question_id INT NOT NULL,
    is_correct TINYINT(1) NOT NULL,
    old_theta FLOAT,
    new_theta FLOAT,
    timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(student_id),
    FOREIGN KEY (quiz_id) REFERENCES quizzes(quiz_id),
    FOREIGN KEY (question_id) REFERENCES questions(question_id)
);

/*Student Quizzes Table*/
CREATE TABLE student_quizzes (
    student_quiz_id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    quiz_id INT NOT NULL,
    score DECIMAL(5,2) DEFAULT NULL,
    date_taken DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(student_id),
    FOREIGN KEY (quiz_id) REFERENCES quizzes(quiz_id)
);

/*Student Badges Table*/
CREATE TABLE student_badges (
    student_badge_id INT PRIMARY KEY AUTO_INCREMENT,
    student_id INT NOT NULL,
    badge_name VARCHAR(50) NOT NULL,
    date_awarded DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(student_id)
);
