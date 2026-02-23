-- Insert device
INSERT INTO devices (device_name, location)
VALUES ('AEGIS-NODE v1.0', 'Main Entrance');

-- Initial system status
INSERT INTO system_status (
    device_id,
    ultrasonic_distance_cm,
    sound_db,
    pir_status,
    lock_state
) VALUES (
    1,
    120.5,
    32.8,
    'STABLE',
    'HARD-LOCK'
);

-- Sample security logs
INSERT INTO security_logs (
    device_id,
    decision,
    description,
    lock_state
) VALUES
(1, 'NO THREAT', 'Environment stable. No motion detected.', 'HARD-LOCK'),
(1, 'SOUND SPIKE', 'Abnormal sound detected. Monitoring.', 'HARD-LOCK'),
(1, 'MOTION DETECTED', 'PIR sensor triggered. Lock enforced.', 'HARD-LOCK');
