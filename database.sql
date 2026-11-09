-- Lab 03 - Clinic Appointment Booking
-- Import with: mysql -u root -p < database.sql
-- Requires MySQL 5.7+ or MariaDB 10.2+ (stored generated column).
-- Use fictional patient data only.

CREATE DATABASE IF NOT EXISTS lab03_clinic_appointments
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE lab03_clinic_appointments;

CREATE TABLE IF NOT EXISTS appointments (
  appointment_id   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  patient_name     VARCHAR(80)  NOT NULL,
  email            VARCHAR(150) NULL,
  phone            CHAR(9)      NOT NULL,
  department       VARCHAR(40)  NOT NULL,
  doctor           VARCHAR(60)  NOT NULL,
  appointment_date DATE         NOT NULL,
  time_slot        VARCHAR(11)  NOT NULL,            -- e.g. 09:00-10:00
  reason           VARCHAR(500) NOT NULL,
  consent_given    TINYINT(1)   NOT NULL DEFAULT 0,
  status           ENUM('Pending','Confirmed','Cancelled','Completed') NOT NULL DEFAULT 'Pending',
  created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  -- Double-booking guard: one non-cancelled booking per doctor + date + slot.
  -- Cancelled rows get NULL, so the slot becomes free again.
  slot_key         VARCHAR(90)  GENERATED ALWAYS AS
                     (IF(status = 'Cancelled', NULL, CONCAT(doctor, '|', appointment_date, '|', time_slot))) STORED,
  PRIMARY KEY (appointment_id),
  UNIQUE KEY uq_doctor_slot (slot_key)
) ENGINE=InnoDB;

-- Verification queries
-- SELECT * FROM appointments ORDER BY appointment_id DESC;
-- SELECT doctor, appointment_date, time_slot, COUNT(*) FROM appointments
--   WHERE status <> 'Cancelled' GROUP BY doctor, appointment_date, time_slot HAVING COUNT(*) > 1;  -- 0 rows
