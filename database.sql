-- Hostel Mess Management System Database
-- Home & Search Module Tables

CREATE DATABASE IF NOT EXISTS hostel_mess;
USE hostel_mess;

-- Students Table
CREATE TABLE IF NOT EXISTS students (
    id INT PRIMARY KEY AUTO_INCREMENT,
    account_number VARCHAR(50) UNIQUE NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(100),
    phone VARCHAR(15),
    photo_path VARCHAR(255),
    hostel_name VARCHAR(100) NOT NULL,
    room_number VARCHAR(20) NOT NULL,
    food_preference ENUM('veg', 'non-veg') NOT NULL,
    registration_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status ENUM('active', 'inactive') DEFAULT 'active'
);

-- Meal Records Table: track each meal usage (breakfast/lunch/dinner)
CREATE TABLE IF NOT EXISTS meal_records (
    id INT PRIMARY KEY AUTO_INCREMENT,
    student_id INT NOT NULL,
    account_number VARCHAR(50) NOT NULL,
    meal_type ENUM('breakfast','lunch','dinner') NOT NULL,
    food_type VARCHAR(20) DEFAULT NULL,
    meal_date DATE DEFAULT NULL,
    meal_time DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
);

-- Staff Table: store mess staff members
CREATE TABLE IF NOT EXISTS staff (
    id INT PRIMARY KEY AUTO_INCREMENT,
    full_name VARCHAR(120) NOT NULL,
    role VARCHAR(80) NOT NULL,
    phone VARCHAR(20),
    email VARCHAR(120),
    food_preference ENUM('veg','non-veg') DEFAULT 'veg',
    joined_at DATE,
    active ENUM('yes','no') DEFAULT 'yes'
);

-- Feedback Table: students submit ratings and comments
CREATE TABLE IF NOT EXISTS feedback (
    id INT PRIMARY KEY AUTO_INCREMENT,
    student_id INT,
    account_number VARCHAR(50),
    rating TINYINT NOT NULL DEFAULT 5,
    quality TINYINT DEFAULT 0,
    hygiene TINYINT DEFAULT 0,
    service TINYINT DEFAULT 0,
    comments TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE SET NULL
);

-- Staff Feedback Table: feedback specifically for staff members
CREATE TABLE IF NOT EXISTS staff_feedback (
    id INT PRIMARY KEY AUTO_INCREMENT,
    staff_id INT NOT NULL,
    account_number VARCHAR(50) NOT NULL,
    quality TINYINT DEFAULT 0,
    hygiene TINYINT DEFAULT 0,
    service TINYINT DEFAULT 0,
    staff_rating TINYINT DEFAULT 0,
    comments TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE CASCADE
);

-- Menu Table: daily/weekly menu entries
CREATE TABLE IF NOT EXISTS menus (
    id INT PRIMARY KEY AUTO_INCREMENT,
    menu_date DATE NOT NULL,
    meal_type ENUM('breakfast','lunch','dinner') NOT NULL,
    food_type ENUM('veg','non-veg') NOT NULL,
    items VARCHAR(255),
    image VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY (menu_date, meal_type)
);

-- Meal Interest Table: students mark interested/not-interested for a menu item
CREATE TABLE IF NOT EXISTS menu_interest (
    id INT PRIMARY KEY AUTO_INCREMENT,
    account_number VARCHAR(50) NOT NULL,
    menu_date DATE NOT NULL,
    meal_type ENUM('breakfast','lunch','dinner') NOT NULL,
    interest ENUM('interested','not_interested') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY (account_number, menu_date, meal_type)
);

-- Billing Table: record bills and payment status
CREATE TABLE IF NOT EXISTS billing (
    id INT PRIMARY KEY AUTO_INCREMENT,
    student_id INT NOT NULL,
    account_number VARCHAR(50) NOT NULL,
    amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    due_date DATE,
    status ENUM('unpaid','paid','partial') DEFAULT 'unpaid',
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
);



CREATE TABLE IF NOT EXISTS menu (
    id INT AUTO_INCREMENT PRIMARY KEY,
    day ENUM('today','tomorrow') NOT NULL,
    meal_type ENUM('veg','nonveg') NOT NULL,
    menu_items VARCHAR(255) NOT NULL
);

CREATE TABLE IF NOT EXISTS meal_feedback (
    id INT AUTO_INCREMENT PRIMARY KEY,
    account_number VARCHAR(50) NOT NULL,
    menu_id INT NOT NULL,
    meal ENUM('breakfast','lunch','dinner'),
    food_type ENUM('veg','nonveg'),
    feedback ENUM('like','dislike') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_vote (account_number, meal)
);

-- Fix for existing databases: Add missing columns if they don't exist
ALTER TABLE menus 
ADD COLUMN IF NOT EXISTS food_type ENUM('veg','non-veg') NOT NULL AFTER meal_type,
ADD COLUMN IF NOT EXISTS items VARCHAR(255) AFTER food_type,
ADD COLUMN IF NOT EXISTS image VARCHAR(255) AFTER items;

ALTER TABLE meal_feedback 
ADD COLUMN IF NOT EXISTS meal ENUM('breakfast','lunch','dinner') AFTER menu_id,
ADD COLUMN IF NOT EXISTS food_type ENUM('veg','nonveg') AFTER meal;

ALTER TABLE meal_feedback 
ADD UNIQUE KEY IF NOT EXISTS unique_vote (account_number, meal);

-- Fix for existing databases: Add food_type column to meal_records if it doesn't exist
ALTER TABLE meal_records 
ADD COLUMN IF NOT EXISTS food_type VARCHAR(20) DEFAULT NULL AFTER meal_type;

-- Fix for existing databases: Add meal_date column to meal_records if it doesn't exist
ALTER TABLE meal_records 
ADD COLUMN IF NOT EXISTS meal_date DATE DEFAULT NULL AFTER food_type;
