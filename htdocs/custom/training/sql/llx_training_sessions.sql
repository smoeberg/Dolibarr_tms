-- Additive schema for 0.2.0. No changes to existing catalog columns.
CREATE TABLE IF NOT EXISTS llx_training_session (
 rowid integer NOT NULL AUTO_INCREMENT,
 entity integer NOT NULL,
 ref varchar(64) NOT NULL,
 label varchar(255) NOT NULL,
 fk_course_version integer NOT NULL,
 status varchar(16) NOT NULL DEFAULT 'draft',
 capacity integer NOT NULL,
 timezone varchar(64) NOT NULL,
 datec datetime NOT NULL,
 fk_user_author integer NOT NULL,
 PRIMARY KEY (rowid),
 UNIQUE KEY uk_training_session_ref (entity, ref),
 UNIQUE KEY uk_training_session_entity_id (entity, rowid),
 CONSTRAINT fk_llx_training_session_version FOREIGN KEY (fk_course_version) REFERENCES llx_training_course_version(rowid)
) ENGINE=InnoDB;

-- Attendance is created after the enrollment/slot tables below, see end of file.

CREATE TABLE IF NOT EXISTS llx_training_session_slot (
 rowid integer NOT NULL AUTO_INCREMENT,
 entity integer NOT NULL,
 fk_session integer NOT NULL,
 position integer NOT NULL,
 start_utc datetime NOT NULL,
 end_utc datetime NOT NULL,
 PRIMARY KEY (rowid),
 UNIQUE KEY uk_training_slot_position (entity, fk_session, position),
 CONSTRAINT fk_llx_training_slot_session FOREIGN KEY (entity, fk_session) REFERENCES llx_training_session(entity, rowid)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS llx_training_learner (
 rowid integer NOT NULL AUTO_INCREMENT,
 entity integer NOT NULL,
 fk_socpeople integer NOT NULL,
 datec datetime NOT NULL,
 fk_user_author integer NOT NULL,
 PRIMARY KEY (rowid),
 UNIQUE KEY uk_training_learner_contact (entity, fk_socpeople),
 UNIQUE KEY uk_training_learner_entity_id (entity, rowid),
 CONSTRAINT fk_llx_training_learner_contact FOREIGN KEY (fk_socpeople) REFERENCES llx_socpeople(rowid)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS llx_training_enrollment (
 rowid integer NOT NULL AUTO_INCREMENT,
 entity integer NOT NULL,
 fk_session integer NOT NULL,
 fk_learner integer NOT NULL,
 status varchar(16) NOT NULL,
 datec datetime NOT NULL,
 fk_user_author integer NOT NULL,
 changed_at datetime NOT NULL,
 fk_user_modifier integer NOT NULL,
 cancellation_reason text NULL,
 PRIMARY KEY (rowid),
 UNIQUE KEY uk_training_enrollment_learner (entity, fk_session, fk_learner),
 KEY idx_training_enrollment_capacity (entity, fk_session, status),
 CONSTRAINT fk_llx_training_enrollment_session FOREIGN KEY (entity, fk_session) REFERENCES llx_training_session(entity, rowid),
 CONSTRAINT fk_llx_training_enrollment_learner FOREIGN KEY (entity, fk_learner) REFERENCES llx_training_learner(entity, rowid)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS llx_training_attendance (
 rowid integer NOT NULL AUTO_INCREMENT,
 entity integer NOT NULL,
 fk_session integer NOT NULL,
 fk_enrollment integer NOT NULL,
 fk_session_slot integer NOT NULL,
 status varchar(16) NOT NULL,
 arrival_utc datetime NULL,
 departure_utc datetime NULL,
 present_minutes integer NULL,
 late_minutes integer NULL,
 revision integer NOT NULL DEFAULT 1,
 datec datetime NOT NULL,
 fk_user_author integer NOT NULL,
 changed_at datetime NOT NULL,
 fk_user_modifier integer NOT NULL,
 PRIMARY KEY (rowid),
 UNIQUE KEY uk_training_attendance (entity, fk_enrollment, fk_session_slot),
 KEY idx_training_attendance_slot (entity, fk_session, fk_session_slot),
 CONSTRAINT fk_llx_training_attendance_session FOREIGN KEY (entity, fk_session) REFERENCES llx_training_session(entity, rowid),
 CONSTRAINT fk_llx_training_attendance_enrollment FOREIGN KEY (fk_enrollment) REFERENCES llx_training_enrollment(rowid),
 CONSTRAINT fk_llx_training_attendance_slot FOREIGN KEY (fk_session_slot) REFERENCES llx_training_session_slot(rowid)
) ENGINE=InnoDB;
