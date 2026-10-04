-- Initial schema, version 1. Module tables only; the installer substitutes llx_.
CREATE TABLE IF NOT EXISTS llx_training_course_profile (
 rowid integer NOT NULL AUTO_INCREMENT,
 entity integer NOT NULL,
 fk_product integer NOT NULL,
 datec datetime NOT NULL,
 fk_user_author integer NOT NULL,
 PRIMARY KEY (rowid),
 UNIQUE KEY uk_training_profile_product (entity, fk_product),
 UNIQUE KEY uk_training_profile_entity_id (entity, rowid),
 CONSTRAINT fk_llx_training_profile_product FOREIGN KEY (fk_product) REFERENCES llx_product(rowid)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS llx_training_course_version (
 rowid integer NOT NULL AUTO_INCREMENT,
 entity integer NOT NULL,
 fk_course_profile integer NOT NULL,
 version_number integer NOT NULL,
 status varchar(16) NOT NULL DEFAULT 'draft',
 program_json text NOT NULL,
 duration_minutes integer NOT NULL,
 product_ref_snapshot varchar(128) NULL,
 product_label_snapshot varchar(255) NULL,
 description_snapshot text NULL,
 datec datetime NOT NULL,
 fk_user_author integer NOT NULL,
 published_at datetime NULL,
 fk_user_publisher integer NULL,
 PRIMARY KEY (rowid),
 UNIQUE KEY uk_training_version_number (entity, fk_course_profile, version_number),
 KEY idx_training_version_status (entity, status),
 CONSTRAINT fk_llx_training_version_profile FOREIGN KEY (entity, fk_course_profile) REFERENCES llx_training_course_profile(entity, rowid)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS llx_training_audit (
 rowid integer NOT NULL AUTO_INCREMENT,
 entity integer NOT NULL,
 object_type varchar(32) NOT NULL,
 fk_object integer NOT NULL,
 action varchar(32) NOT NULL,
 fk_user_actor integer NOT NULL,
 datec datetime NOT NULL,
 metadata_json text NOT NULL,
 PRIMARY KEY (rowid),
 KEY idx_training_audit_object (entity, object_type, fk_object, rowid)
) ENGINE=InnoDB;
