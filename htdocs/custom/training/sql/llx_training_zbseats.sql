-- Additive 0.6.0 schema. Reservation participants reuse standard Contacts.
CREATE TABLE IF NOT EXISTS llx_training_seat_hold (
 rowid integer NOT NULL AUTO_INCREMENT,
 entity integer NOT NULL,
 fk_session integer NOT NULL,
 request_key varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 request_hash char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 qty integer NOT NULL,
 status varchar(16) NOT NULL DEFAULT 'active',
 expires_utc datetime NOT NULL,
 datec datetime NOT NULL,
 fk_user_author integer NOT NULL,
 changed_at datetime NOT NULL,
 fk_user_modifier integer NOT NULL,
 reason text NULL,
 PRIMARY KEY (rowid),
 UNIQUE KEY uk_training_hold_request (entity, fk_session, request_key),
 UNIQUE KEY uk_training_hold_entity_id (entity, rowid),
 KEY idx_training_hold_capacity (entity, fk_session, status, expires_utc),
 CONSTRAINT fk_llx_training_hold_session FOREIGN KEY (entity, fk_session) REFERENCES llx_training_session(entity, rowid)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS llx_training_seat_member (
 rowid integer NOT NULL AUTO_INCREMENT,
 entity integer NOT NULL,
 fk_seat_hold integer NOT NULL,
 fk_socpeople integer NOT NULL,
 fk_enrollment integer NULL,
 PRIMARY KEY (rowid),
 UNIQUE KEY uk_training_hold_member (entity, fk_seat_hold, fk_socpeople),
 KEY idx_training_member_contact (entity, fk_socpeople),
 CONSTRAINT fk_llx_training_member_hold FOREIGN KEY (entity, fk_seat_hold) REFERENCES llx_training_seat_hold(entity, rowid),
 CONSTRAINT fk_llx_training_member_contact FOREIGN KEY (fk_socpeople) REFERENCES llx_socpeople(rowid),
 CONSTRAINT fk_llx_training_member_enrollment FOREIGN KEY (fk_enrollment) REFERENCES llx_training_enrollment(rowid)
) ENGINE=InnoDB;
