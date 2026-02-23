-- Create database
CREATE DATABASE IF NOT EXISTS aegis_node_db;
USE aegis_node_db;

-- Device info (optional but professional)
CREATE TABLE IF NOT EXISTS devices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    device_name VARCHAR(100) NOT NULL,
    location VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Live system status (ONE ROW UPDATED CONSTANTLY)
CREATE TABLE IF NOT EXISTS system_status (
    id INT AUTO_INCREMENT PRIMARY KEY,
    device_id INT,

    ultrasonic_distance_cm FLOAT NOT NULL,
    sound_db FLOAT NOT NULL,
    pir_status ENUM('STABLE', 'MOTION') NOT NULL,

    lock_state ENUM('HARD-LOCK', 'UNLOCKED') NOT NULL,

    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (device_id) REFERENCES devices(id)
);

-- Security decision logs (DISPLAYED IN TABLE)
CREATE TABLE IF NOT EXISTS security_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    device_id INT,

    decision VARCHAR(50) NOT NULL,
    description TEXT NOT NULL,
    lock_state ENUM('HARD-LOCK', 'UNLOCKED') NOT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (device_id) REFERENCES devices(id)
);



-- ADDED TABLES FOR AI
CREATE TABLE IF NOT EXISTS ai_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  prompt TEXT NOT NULL,
  response TEXT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS ai_memory (
  id INT AUTO_INCREMENT PRIMARY KEY,
  memory_summary TEXT NOT NULL,
  last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);